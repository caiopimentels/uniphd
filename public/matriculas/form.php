<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();
exigirMatriculas();

$pdo = getDB();
$config = buscarConfiguracoes($pdo);
$descontoMax = usuarioDescontoMax();

$turmaIdInicial = (int) ($_GET['turma_id'] ?? 0);
$alunoIdInicial = (int) ($_GET['aluno_id'] ?? 0);

// Matricular a partir de uma vaga reservada (botão na lista da turma): a
// reserva já guarda a vaga e some quando a matrícula é feita.
$reservaId = (int) ($_POST['reserva_id'] ?? $_GET['reserva'] ?? 0);
$reserva = null;
if ($reservaId) {
    $stmt = $pdo->prepare('SELECT * FROM turma_reservas WHERE id = ?');
    $stmt->execute([$reservaId]);
    $reserva = $stmt->fetch() ?: null;
    if ($reserva) {
        $turmaIdInicial = $turmaIdInicial ?: (int) $reserva['turma_id'];
        $alunoIdInicial = $alunoIdInicial ?: (int) $reserva['aluno_id'];
    }
}

$erros = [];
$dados = [
    'turma_id' => $turmaIdInicial,
    'aluno_id' => $alunoIdInicial,
    'data_matricula' => date('Y-m-d'),
    'desconto_percentual' => 0,
    'desconto_pontualidade_tipo' => 'nenhum',
    'desconto_pontualidade_valor' => 0,
    'desconto_pontualidade_dias' => 0,
    'num_parcelas' => '',
    'primeiro_vencimento' => '',
    'taxa_matricula' => 0,
    'forma_pagamento_id' => 0,
    'observacao' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dados = [
        'turma_id' => (int) ($_POST['turma_id'] ?? 0),
        'aluno_id' => (int) ($_POST['aluno_id'] ?? 0),
        'data_matricula' => input('data_matricula') ?: date('Y-m-d'),
        'desconto_percentual' => decimalInput('desconto_percentual', 0),
        'desconto_pontualidade_tipo' => in_array(input('desconto_pontualidade_tipo'), ['valor', 'percentual'], true) ? input('desconto_pontualidade_tipo') : 'nenhum',
        'desconto_pontualidade_valor' => decimalInput('desconto_pontualidade_valor', 0),
        'desconto_pontualidade_dias' => (int) ($_POST['desconto_pontualidade_dias'] ?? 0),
        'num_parcelas' => (int) ($_POST['num_parcelas'] ?? 0),
        'primeiro_vencimento' => input('primeiro_vencimento'),
        'taxa_matricula' => decimalInput('taxa_matricula', 0),
        'forma_pagamento_id' => (int) ($_POST['forma_pagamento_id'] ?? 0),
        'observacao' => input('observacao'),
    ];

    $stmt = $pdo->prepare('SELECT * FROM alunos WHERE id = ?');
    $stmt->execute([$dados['aluno_id']]);
    $aluno = $stmt->fetch();

    if (!$aluno) {
        $erros[] = 'Escolha o aluno.';
    } elseif (!$aluno['ativo']) {
        $erros[] = 'Este aluno está inativo. Reative o cadastro antes de matricular.';
    }

    // Inadimplente (parcela vencida): conforme Configurações › Matrículas,
    // bloqueia ou exige autorização de um administrador — a do próprio
    // usuário (se for admin), a digitada aqui ou um pedido já aprovado.
    $autorizacaoInadimplente = null;
    $liberacaoUsada = null;
    $regraInadimplente = regraMatriculaInadimplente($pdo);
    $atrasoAluno = $aluno && $regraInadimplente !== 'permitir' ? valorAtrasoAluno($pdo, (int) $aluno['id']) : 0.0;
    if ($atrasoAluno > 0) {
        $aviso = $aluno['nome'] . ' tem ' . formatMoney($atrasoAluno) . ' em atraso.';
        if ($regraInadimplente === 'bloquear') {
            $erros[] = $aviso . ' A matrícula de alunos inadimplentes está bloqueada — regularize o financeiro antes.';
        } elseif (usuarioEhAdmin()) {
            $autorizacaoInadimplente = usuarioAtualNome();
        } elseif (input('admin_email') !== '') {
            $admin = validarAdministradorPresente($pdo, input('admin_email'), (string) ($_POST['admin_senha'] ?? ''));
            if ($admin['ok']) {
                $autorizacaoInadimplente = $admin['nome'];
            } else {
                $erros[] = $admin['mensagem'];
            }
        } elseif ($liberacao = liberacaoAprovadaAluno($pdo, (int) $aluno['id'])) {
            $liberacaoUsada = (int) $liberacao['id'];
            $autorizacaoInadimplente = $liberacao['decidido_por_nome'] ?: 'administrador';
        } else {
            $erros[] = $aviso . ' Para matricular, um administrador precisa autorizar: digite o e-mail e a senha dele no quadro do aluno, ou clique em "Pedir liberação".';
        }
    }
    if (!$dados['turma_id']) {
        $erros[] = 'Escolha a turma.';
    }
    if ($dados['desconto_percentual'] < 0 || $dados['desconto_percentual'] > 100) {
        $erros[] = 'O desconto deve estar entre 0% e 100%.';
    } elseif ($dados['desconto_percentual'] > $descontoMax) {
        $erros[] = 'Seu perfil pode conceder no máximo ' . formatPercent($descontoMax) . ' de desconto.';
    }
    if ($dados['num_parcelas'] < 1 || $dados['num_parcelas'] > 120) {
        $erros[] = 'O número de mensalidades deve estar entre 1 e 120.';
    }
    if (!$dados['primeiro_vencimento']) {
        $erros[] = 'Informe o vencimento da primeira mensalidade.';
    }
    if ($dados['taxa_matricula'] < 0) {
        $erros[] = 'A taxa de matrícula não pode ser negativa.';
    }
    if ($dados['desconto_pontualidade_tipo'] === 'nenhum') {
        $dados['desconto_pontualidade_valor'] = 0;
        $dados['desconto_pontualidade_dias'] = 0;
    } else {
        if ($dados['desconto_pontualidade_valor'] <= 0) {
            $erros[] = 'Informe o valor do desconto por pontualidade (ou escolha "Sem desconto").';
        }
        if ($dados['desconto_pontualidade_tipo'] === 'percentual' && $dados['desconto_pontualidade_valor'] > 100) {
            $erros[] = 'O desconto por pontualidade não pode passar de 100%.';
        }
        if ($dados['desconto_pontualidade_dias'] < 0 || $dados['desconto_pontualidade_dias'] > 31) {
            $erros[] = 'O prazo do desconto por pontualidade vai de 0 (até o vencimento) a 31 dias antes.';
        }
    }

    if (!$erros) {
        $pdo->beginTransaction();
        try {
            // Trava a linha da turma até o commit: duas pessoas matriculando
            // ao mesmo tempo na última vaga não podem as duas passar na
            // conferência de vagas.
            $stmt = $pdo->prepare(
                'SELECT t.*, s.nome AS situacao_nome, s.comportamento
                 FROM turmas t JOIN situacoes_turma s ON s.id = t.situacao_id
                 WHERE t.id = ? FOR UPDATE'
            );
            $stmt->execute([$dados['turma_id']]);
            $turma = $stmt->fetch();

            // A reserva escolhida (ou uma feita para este aluno) libera a
            // vaga que ela mesma guardava.
            $stmt = $pdo->prepare('SELECT id FROM turma_reservas WHERE turma_id = ? AND (id = ? OR aluno_id = ?) ORDER BY id = ? DESC LIMIT 1');
            $stmt->execute([$dados['turma_id'], $reservaId, $dados['aluno_id'], $reservaId]);
            $reservaUsada = (int) $stmt->fetchColumn();
            $ocupadas = vagasOcupadasTurma($pdo, $dados['turma_id'], 0, $reservaUsada);

            $stmt = $pdo->prepare('SELECT status FROM matriculas WHERE turma_id = ? AND aluno_id = ?');
            $stmt->execute([$dados['turma_id'], $dados['aluno_id']]);
            $matriculaExistente = $stmt->fetchColumn();

            if (!$turma) {
                $erros[] = 'Turma não encontrada.';
            } elseif (!in_array($turma['comportamento'], COMPORTAMENTOS_TURMA_ABERTA, true)) {
                $erros[] = 'Esta turma está como "' . $turma['situacao_nome'] . '" e não aceita novas matrículas.';
            } elseif ($ocupadas >= (int) $turma['vagas']) {
                $erros[] = 'A turma está lotada (' . $ocupadas . '/' . (int) $turma['vagas'] . ', contando as vagas reservadas).';
            }
            // A pontualidade também é desconto concedido: entra na mesma
            // alçada do perfil, convertida em % da mensalidade já com bolsa.
            if ($turma && $dados['desconto_pontualidade_tipo'] !== 'nenhum') {
                $mensalidadeFinal = valorMensalidadeComDesconto((float) $turma['valor_mensalidade'], $dados['desconto_percentual']);
                $percentualPontualidade = $dados['desconto_pontualidade_tipo'] === 'percentual'
                    ? $dados['desconto_pontualidade_valor']
                    : ($mensalidadeFinal > 0 ? $dados['desconto_pontualidade_valor'] / $mensalidadeFinal * 100 : 100);
                if ($dados['desconto_pontualidade_tipo'] === 'valor' && $dados['desconto_pontualidade_valor'] > $mensalidadeFinal) {
                    $erros[] = 'O desconto por pontualidade (' . formatMoney($dados['desconto_pontualidade_valor']) . ') é maior que a mensalidade (' . formatMoney($mensalidadeFinal) . ').';
                } elseif ($percentualPontualidade > $descontoMax) {
                    $erros[] = 'O desconto por pontualidade equivale a ' . formatPercent($percentualPontualidade) . ' da mensalidade; seu perfil pode conceder até ' . formatPercent($descontoMax) . '.';
                }
            }
            if ($matriculaExistente) {
                $erros[] = 'Este aluno já tem uma matrícula (' . mb_strtolower(matriculaStatusLabel($matriculaExistente)) . ') nesta turma.';
            }

            if ($erros) {
                $pdo->rollBack();
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO matriculas
                        (turma_id, aluno_id, data_matricula, valor_mensalidade, desconto_percentual,
                         desconto_pontualidade_tipo, desconto_pontualidade_valor, desconto_pontualidade_dias, num_parcelas,
                         primeiro_vencimento, taxa_matricula, forma_pagamento_id, observacao, usuario_id)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
                );
                $stmt->execute([
                    $turma['id'], $aluno['id'], $dados['data_matricula'], $turma['valor_mensalidade'],
                    $dados['desconto_percentual'], $dados['desconto_pontualidade_tipo'], $dados['desconto_pontualidade_valor'],
                    $dados['desconto_pontualidade_dias'], $dados['num_parcelas'], $dados['primeiro_vencimento'],
                    $dados['taxa_matricula'], $dados['forma_pagamento_id'] ?: null, $dados['observacao'] ?: null,
                    usuarioAtualId(),
                ]);
                $matriculaId = (int) $pdo->lastInsertId();
                if ($reservaUsada) {
                    $pdo->prepare('DELETE FROM turma_reservas WHERE id = ?')->execute([$reservaUsada]);
                }

                $stmt = $pdo->prepare('SELECT * FROM matriculas WHERE id = ?');
                $stmt->execute([$matriculaId]);
                gerarCobrancasMatricula($pdo, $stmt->fetch(), $turma['nome'], usuarioAtualId());

                if ($liberacaoUsada) {
                    $pdo->prepare("UPDATE liberacoes_inadimplencia SET status = 'usada', usado_em = NOW() WHERE id = ?")->execute([$liberacaoUsada]);
                }
                $pdo->commit();
                avisarMinimoTurma($pdo, (int) $turma['id']);

                registrarAtividade('matriculas', 'matricula', 'Aluno ' . $aluno['nome'] . ' matriculado na turma ' . $turma['nome']
                    . ($autorizacaoInadimplente ? ' (inadimplente: ' . formatMoney($atrasoAluno) . ' em atraso — autorizado por ' . $autorizacaoInadimplente . ')' : ''));

                flash('success', 'Matrícula realizada. ' . $dados['num_parcelas'] . ' mensalidade(s) lançada(s) em contas a receber.');
                redirect('/matriculas/visualizar.php?id=' . $matriculaId);
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}

// Só turmas que ainda aceitam aluno; a escolhida via URL entra mesmo se
// estiver lotada, para a mensagem de erro fazer sentido no formulário.
$stmt = $pdo->prepare(
    "SELECT t.id, t.nome, t.vagas, t.data_inicio, t.data_termino, t.duracao_meses, t.duracao_unidade, t.valor_mensalidade, c.nome AS curso_nome,
            t.portal_taxa_matricula, t.portal_num_parcelas, t.portal_dia_vencimento,
            ((SELECT COUNT(*) FROM matriculas m WHERE m.turma_id = t.id AND " . SQL_MATRICULA_OCUPA_VAGA . ") + " . SQL_RESERVAS_TURMA . ") AS ocupadas
     FROM turmas t
     JOIN cursos c ON c.id = t.curso_id
     JOIN situacoes_turma s ON s.id = t.situacao_id
     WHERE s.comportamento IN ('aberta', 'em_andamento') OR t.id = ?
     ORDER BY c.nome, t.data_inicio DESC"
);
$stmt->execute([$dados['turma_id']]);
$turmas = $stmt->fetchAll();

// Atraso de cada aluno e pedidos de liberação: o quadro de inadimplência
// aparece assim que o aluno é escolhido.
$stmt = $pdo->prepare(
    "SELECT a.id, a.nome, a.cpf,
            (SELECT COALESCE(SUM(cr.valor), 0) FROM contas_receber cr WHERE cr.aluno_id = a.id AND cr.status = 'pendente' AND cr.data_vencimento < CURDATE()) AS atraso,
            (SELECT l.status FROM liberacoes_inadimplencia l WHERE l.aluno_id = a.id AND l.status IN ('pendente', 'aprovada', 'negada')
              AND l.criado_em >= NOW() - INTERVAL 2 DAY ORDER BY l.id DESC LIMIT 1) AS liberacao
     FROM alunos a WHERE a.ativo = 1 OR a.id = ? ORDER BY a.nome"
);
$stmt->execute([$dados['aluno_id']]);
$alunos = $stmt->fetchAll();

$formasPagamento = $pdo->query('SELECT id, nome FROM formas_pagamento WHERE ativo = 1 ORDER BY nome')->fetchAll();

// A busca de aluno e de turma roda no navegador sobre estas listas; os
// cadastros rápidos acrescentam nelas o que for criado na hora.
$alunosJson = array_map(fn ($a) => [
    'id' => (int) $a['id'],
    'nome' => $a['nome'],
    'cpf' => $a['cpf'],
    'atraso' => (float) $a['atraso'],
    'liberacao' => $a['liberacao'],
], $alunos);
$regraInadimplente = regraMatriculaInadimplente($pdo);
$turmasJson = array_map(fn ($t) => [
    'id' => (int) $t['id'],
    'nome' => $t['nome'],
    'curso_nome' => $t['curso_nome'],
    'vagas' => (int) $t['vagas'],
    'ocupadas' => (int) $t['ocupadas'],
    'inicio' => $t['data_inicio'],
    'termino' => $t['data_termino'],
    // Teto de mensalidades (1 em curso de dias) e o texto da duração.
    'meses' => parcelasPadraoTurma($t),
    'unidade' => $t['duracao_unidade'],
    'duracao' => textoDuracaoTurma($t),
    'mensalidade' => (float) $t['valor_mensalidade'],
    'taxa' => (float) $t['portal_taxa_matricula'],
    'parcelas' => (int) $t['portal_num_parcelas'],
    'dia' => (int) $t['portal_dia_vencimento'],
], $turmas);

$podeCriarAluno = usuarioPodeCadastrarAluno();
$podeCriarTurma = usuarioPodeCriarTurma();
$cursosAtivos = $podeCriarTurma ? $pdo->query('SELECT id, nome FROM cursos WHERE ativo = 1 ORDER BY nome')->fetchAll() : [];
$professoresAtivos = $podeCriarTurma ? $pdo->query('SELECT id, nome FROM professores WHERE ativo = 1 ORDER BY nome')->fetchAll() : [];

$voltar = $turmaIdInicial ? '/turmas/visualizar.php?id=' . $turmaIdInicial
    : ($alunoIdInicial ? '/alunos/form.php?id=' . $alunoIdInicial . '&tab=matriculas' : '/turmas/listar.php');

$pageTitle = 'Nova matrícula';
$pageBreadcrumb = 'Acadêmico · Matrículas';
$activeMenu = 'turmas';
$pageAction = '<a href="' . e($voltar) . '" class="btn btn-outline-secondary">Cancelar</a>'
    . '<button type="submit" form="form-principal" class="btn btn-brand"><i class="bi bi-check-lg"></i> Matricular</button>';
require __DIR__ . '/../../includes/layout_header.php';
?>

<?php if ($erros): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($erros as $erro): ?><li><?= e($erro) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<?php if ($reserva): ?>
    <div class="alert alert-info"><i class="bi bi-bookmark-check"></i> Matrícula na vaga reservada para <strong><?= e($reserva['nome']) ?></strong><?= $reserva['telefone'] ? ' (' . e($reserva['telefone']) . ')' : '' ?>.
        <?= $reserva['aluno_id'] ? '' : 'Pesquise o aluno abaixo ou cadastre com o botão <i class="bi bi-person-plus"></i>.' ?> Ao matricular, a reserva sai da lista.</div>
<?php endif; ?>

<form method="post" id="form-principal">
<input type="hidden" name="reserva_id" value="<?= $reserva ? (int) $reserva['id'] : '' ?>">
<div class="row g-4">
<div class="col-lg-7">
    <div class="secao">
        <div class="secao-titulo"><i class="bi bi-person-plus"></i> Aluno e turma</div>
        <div class="row g-3">
            <div class="col-12">
                <label class="form-label">Aluno *</label>
                <div class="d-flex gap-2 align-items-start">
                    <div class="typeahead flex-grow-1">
                        <input type="text" class="form-control" id="aluno-busca" autocomplete="off" placeholder="Pesquise pelo nome ou CPF do aluno...">
                        <div class="typeahead-lista d-none" id="aluno-lista"></div>
                    </div>
                    <?php if ($podeCriarAluno): ?>
                        <button type="button" class="btn btn-outline-secondary" onclick="abrirModal('modal-novo-aluno')" title="Cadastrar aluno novo">
                            <i class="bi bi-person-plus"></i>
                        </button>
                    <?php endif; ?>
                </div>
                <input type="hidden" name="aluno_id" id="aluno_id" value="<?= $dados['aluno_id'] ?: '' ?>">
                <div id="quadro-inadimplente" class="mt-2"></div>
            </div>
            <div class="col-12">
                <label class="form-label">Turma *</label>
                <div class="d-flex gap-2 align-items-start">
                    <div class="typeahead flex-grow-1">
                        <input type="text" class="form-control" id="turma-busca" autocomplete="off" placeholder="Pesquise pelo curso ou nome da turma...">
                        <div class="typeahead-lista d-none" id="turma-lista"></div>
                    </div>
                    <?php if ($podeCriarTurma): ?>
                        <button type="button" class="btn btn-outline-secondary" onclick="abrirModal('modal-nova-turma')" title="Criar turma nova">
                            <i class="bi bi-plus-lg"></i>
                        </button>
                    <?php endif; ?>
                </div>
                <input type="hidden" name="turma_id" id="turma_id" value="<?= $dados['turma_id'] ?: '' ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">Data da matrícula</label>
                <input type="date" name="data_matricula" class="form-control" value="<?= e($dados['data_matricula']) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">Forma de pagamento prevista</label>
                <select name="forma_pagamento_id" class="form-select">
                    <option value="">-</option>
                    <?php foreach ($formasPagamento as $f): ?>
                        <option value="<?= (int) $f['id'] ?>" <?= $dados['forma_pagamento_id'] === (int) $f['id'] ? 'selected' : '' ?>><?= e($f['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12">
                <label class="form-label">Observação</label>
                <input type="text" name="observacao" class="form-control" maxlength="255" value="<?= e($dados['observacao']) ?>">
            </div>
        </div>
    </div>

    <div class="secao">
        <div class="secao-titulo"><i class="bi bi-cash-coin"></i> Condições financeiras</div>
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label">Mensalidade da turma</label>
                <input type="text" id="mensalidade_turma" class="form-control" disabled value="-">
            </div>
            <div class="col-md-4">
                <label class="form-label">Desconto fixo / bolsa (%)</label>
                <input type="text" name="desconto_percentual" id="desconto_percentual" class="form-control"
                       value="<?= e(number_format((float) $dados['desconto_percentual'], 2, ',', '')) ?>" oninput="atualizarPrevia()">
                <div class="form-text">Seu limite: <?= formatPercent($descontoMax) ?></div>
            </div>
            <div class="col-md-4">
                <label class="form-label">Taxa de matrícula</label>
                <input type="text" name="taxa_matricula" id="taxa_matricula" class="form-control"
                       value="<?= e(number_format((float) $dados['taxa_matricula'], 2, ',', '')) ?>" oninput="atualizarPrevia()">
                <div class="form-text">0 = sem taxa. Vence na data da matrícula.</div>
            </div>
            <div class="col-md-4">
                <label class="form-label">Nº de mensalidades *</label>
                <input type="number" name="num_parcelas" id="num_parcelas" class="form-control" min="1" max="120" required
                       value="<?= e((string) $dados['num_parcelas']) ?>" oninput="atualizarPrevia()">
                <div class="form-text" id="dica_parcelas"></div>
            </div>
            <div class="col-md-4">
                <label class="form-label">1º vencimento *</label>
                <input type="date" name="primeiro_vencimento" id="primeiro_vencimento" class="form-control" required
                       value="<?= e($dados['primeiro_vencimento']) ?>" oninput="atualizarPrevia()">
                <div class="form-text">As demais vencem no mesmo dia, mês a mês.</div>
            </div>
        </div>
    </div>

    <div class="secao">
        <div class="secao-titulo"><i class="bi bi-alarm"></i> Desconto por pagamento em dia (pontualidade)</div>
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label">Tipo</label>
                <select name="desconto_pontualidade_tipo" id="desconto_pontualidade_tipo" class="form-select" onchange="atualizarPontualidade()">
                    <option value="nenhum" <?= $dados['desconto_pontualidade_tipo'] === 'nenhum' ? 'selected' : '' ?>>Sem desconto</option>
                    <option value="valor" <?= $dados['desconto_pontualidade_tipo'] === 'valor' ? 'selected' : '' ?>>Valor fixo (R$)</option>
                    <option value="percentual" <?= $dados['desconto_pontualidade_tipo'] === 'percentual' ? 'selected' : '' ?>>Percentual (%)</option>
                </select>
            </div>
            <div class="col-md-4 bloco-pontualidade">
                <label class="form-label" id="rotulo_pontualidade_valor">Desconto</label>
                <input type="text" name="desconto_pontualidade_valor" id="desconto_pontualidade_valor" class="form-control"
                       value="<?= e(number_format((float) $dados['desconto_pontualidade_valor'], 2, ',', '')) ?>" oninput="atualizarPrevia()">
            </div>
            <div class="col-md-4 bloco-pontualidade">
                <label class="form-label">Válido até</label>
                <div class="input-group">
                    <input type="number" name="desconto_pontualidade_dias" id="desconto_pontualidade_dias" class="form-control" min="0" max="31"
                           value="<?= (int) $dados['desconto_pontualidade_dias'] ?>" oninput="atualizarPrevia()">
                    <span class="input-group-text">dias antes</span>
                </div>
                <div class="form-text">0 = até o dia do vencimento.</div>
            </div>
            <div class="col-12 bloco-pontualidade">
                <div class="form-text">
                    Vale para cada mensalidade (não para a taxa de matrícula) e é impresso no boleto. Pagando depois do prazo, o aluno paga o valor cheio.
                    Diferente do desconto fixo/bolsa, que reduz a mensalidade sempre.
                </div>
            </div>
        </div>
    </div>
</div>

<div class="col-lg-5">
    <div class="secao">
        <div class="secao-titulo"><i class="bi bi-receipt"></i> Prévia da cobrança</div>
        <div class="card"><div class="card-body">
            <div class="d-flex justify-content-between"><span>Mensalidade com desconto</span><strong id="previa_mensalidade">-</strong></div>
            <div class="d-flex justify-content-between"><span>Mensalidades</span><span id="previa_qtd">-</span></div>
            <div class="d-flex justify-content-between"><span>Taxa de matrícula</span><span id="previa_taxa">-</span></div>
            <div class="d-flex justify-content-between text-success d-none" id="linha_pontualidade"><span>Pagando em dia</span><strong id="previa_pontualidade">-</strong></div>
            <hr>
            <div class="d-flex justify-content-between fs-5"><span>Total do contrato</span><strong id="previa_total">-</strong></div>
            <div class="small text-muted mt-2" id="previa_periodo"></div>
        </div></div>
    </div>
</div>
</div>
</form>

<script src="/assets/js/busca.js"></script>
<script>
const turmas = <?= json_encode($turmasJson, JSON_UNESCAPED_UNICODE) ?>;
const DIA_VENCIMENTO_PADRAO = <?= (int) ($config['dia_vencimento_padrao'] ?? 10) ?>;
const PARCELAS_DIGITADAS = <?= $dados['num_parcelas'] ? 'true' : 'false' ?>;
const VENCIMENTO_DIGITADO = <?= $dados['primeiro_vencimento'] ? 'true' : 'false' ?>;
const TAXA_DIGITADA = <?= $_SERVER['REQUEST_METHOD'] === 'POST' ? 'true' : 'false' ?>;

function lerDecimal(valor) {
    return parseFloat(String(valor || '0').replace(/\./g, '').replace(',', '.')) || 0;
}

function formatarMoeda(valor) {
    return valor.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
}

function paraISO(data) {
    return data.getFullYear() + '-' + String(data.getMonth() + 1).padStart(2, '0') + '-' + String(data.getDate()).padStart(2, '0');
}

function deISO(texto) {
    const [a, m, d] = texto.split('-').map(Number);
    return new Date(a, m - 1, d);
}

// Mesma regra de somarMeses() no PHP: 31/01 + 1 mês = 28/02, sem pular mês.
function somarMeses(data, meses) {
    const alvo = new Date(data.getFullYear(), data.getMonth() + meses, 1);
    const ultimoDia = new Date(alvo.getFullYear(), alvo.getMonth() + 1, 0).getDate();
    alvo.setDate(Math.min(data.getDate(), ultimoDia));
    return alvo;
}

function turmaSelecionada() {
    const id = document.getElementById('turma_id').value;
    return turmas.find((t) => String(t.id) === String(id)) || null;
}

// Ao escolher a turma, sugere: 1º vencimento no dia padrão a partir do
// início das aulas (ou de hoje, se já começou) e quantas mensalidades
// faltam até o término — quem entra no meio paga só o restante.
function aoTrocarTurma(inicial) {
    const turma = turmaSelecionada();
    if (turma) {
        const hoje = new Date(); hoje.setHours(0, 0, 0, 0);
        // Turma sem data definida: conta a partir de hoje, pela duração.
        const inicio = turma.inicio ? deISO(turma.inicio) : hoje;
        const base = inicio > hoje ? inicio : hoje;
        const termino = turma.termino ? deISO(turma.termino) : somarMeses(base, parseInt(turma.meses, 10));

        // Dia de vencimento, nº de mensalidades e valor da matrícula vêm do
        // cadastro da turma (a secretaria pode mudar aqui).
        const dia = turma.dia || DIA_VENCIMENTO_PADRAO;
        let vencimento = new Date(base.getFullYear(), base.getMonth(), dia);
        if (vencimento < base) {
            vencimento = new Date(base.getFullYear(), base.getMonth() + 1, dia);
        }
        // Curso em dias: uma cobrança só, vencendo no início (ou hoje).
        if (turma.unidade === 'dia') {
            vencimento = base;
        }

        let restantes = 0;
        while (somarMeses(vencimento, restantes) < termino) {
            restantes++;
        }
        restantes = Math.max(1, Math.min(restantes, turma.parcelas || parseInt(turma.meses, 10)));
        if (!(inicial && TAXA_DIGITADA)) {
            document.getElementById('taxa_matricula').value = (turma.taxa || 0).toFixed(2).replace('.', ',');
        }

        if (!(inicial && VENCIMENTO_DIGITADO)) {
            document.getElementById('primeiro_vencimento').value = paraISO(vencimento);
        }
        if (!(inicial && PARCELAS_DIGITADAS)) {
            document.getElementById('num_parcelas').value = restantes;
        }
        document.getElementById('dica_parcelas').textContent = 'Turma de ' + (turma.duracao || turma.meses + ' meses') + '; sugerido: ' + restantes + '.';
    }
    atualizarPrevia();
}

function atualizarPrevia() {
    const turma = turmaSelecionada();
    const desconto = lerDecimal(document.getElementById('desconto_percentual').value);
    const taxa = lerDecimal(document.getElementById('taxa_matricula').value);
    const qtd = parseInt(document.getElementById('num_parcelas').value, 10) || 0;
    const primeiro = document.getElementById('primeiro_vencimento').value;

    if (!turma) {
        ['mensalidade_turma'].forEach(id => document.getElementById(id).value = '-');
        ['previa_mensalidade', 'previa_qtd', 'previa_taxa', 'previa_total'].forEach(id => document.getElementById(id).textContent = '-');
        document.getElementById('previa_periodo').textContent = '';
        return;
    }

    const mensalidade = parseFloat(turma.mensalidade) || 0;
    const comDesconto = Math.round(mensalidade * (1 - desconto / 100) * 100) / 100;

    document.getElementById('mensalidade_turma').value = formatarMoeda(mensalidade);
    document.getElementById('previa_mensalidade').textContent = formatarMoeda(comDesconto);
    document.getElementById('previa_qtd').textContent = qtd + ' × ' + formatarMoeda(comDesconto);
    document.getElementById('previa_taxa').textContent = taxa > 0 ? formatarMoeda(taxa) : 'Sem taxa';
    document.getElementById('previa_total').textContent = formatarMoeda(comDesconto * qtd + taxa);

    // Mesma conta de descontoPontualidadeParcela() no PHP.
    const tipoPontualidade = document.getElementById('desconto_pontualidade_tipo').value;
    const valorPontualidade = lerDecimal(document.getElementById('desconto_pontualidade_valor').value);
    const diasPontualidade = parseInt(document.getElementById('desconto_pontualidade_dias').value, 10) || 0;
    const linhaPontualidade = document.getElementById('linha_pontualidade');
    if (tipoPontualidade !== 'nenhum' && valorPontualidade > 0) {
        const descontoParcela = tipoPontualidade === 'valor'
            ? Math.min(valorPontualidade, comDesconto)
            : Math.round(comDesconto * valorPontualidade) / 100;
        document.getElementById('previa_pontualidade').textContent =
            formatarMoeda(comDesconto - descontoParcela) + ' até ' + (diasPontualidade ? diasPontualidade + ' dia(s) antes' : 'o vencimento');
        linhaPontualidade.classList.remove('d-none');
    } else {
        linhaPontualidade.classList.add('d-none');
    }

    if (primeiro && qtd > 0) {
        const ultimo = somarMeses(deISO(primeiro), qtd - 1);
        document.getElementById('previa_periodo').textContent =
            'Vencimentos de ' + deISO(primeiro).toLocaleDateString('pt-BR') + ' a ' + ultimo.toLocaleDateString('pt-BR') + '.';
    }
}

/* ------------------- busca de aluno e de turma (typeahead) ------------------- */

const alunos = <?= json_encode($alunosJson, JSON_UNESCAPED_UNICODE) ?>;

const buscaAluno = montarBusca({
    campo: 'aluno-busca', lista: 'aluno-lista', oculto: 'aluno_id',
    itens: () => alunos,
    combina: (a, termo, digitos) => normalizar(a.nome).includes(termo) || (digitos && String(a.cpf || '').replace(/\D/g, '').includes(digitos)),
    titulo: (a) => a.nome,
    detalhe: (a) => a.cpf ? 'CPF ' + a.cpf : 'Sem CPF cadastrado',
    vazio: 'Nenhum aluno encontrado.<?= $podeCriarAluno ? ' Use o botão <i class="bi bi-person-plus"></i> para cadastrar.' : '' ?>',
    aoEscolher: () => atualizarInadimplencia(),
});

/* -------------------- aluno inadimplente (parcela vencida) -------------------- */

const REGRA_INADIMPLENTE = <?= json_encode($regraInadimplente) ?>;
const USUARIO_ADMIN = <?= usuarioEhAdmin() ? 'true' : 'false' ?>;
function atualizarInadimplencia() {
    const quadro = document.getElementById('quadro-inadimplente');
    const aluno = alunos.find((a) => String(a.id) === document.getElementById('aluno_id').value);
    if (!aluno || !aluno.atraso || REGRA_INADIMPLENTE === 'permitir') {
        quadro.innerHTML = '';
        return;
    }
    const valor = formatarMoeda(aluno.atraso);
    if (REGRA_INADIMPLENTE === 'bloquear') {
        quadro.innerHTML = '<div class="alert alert-danger py-2 mb-0"><i class="bi bi-slash-circle"></i> <strong>' + escapeHtml(aluno.nome) + '</strong> tem <strong>' + valor
            + '</strong> em atraso. A matrícula de alunos inadimplentes está bloqueada — regularize o financeiro antes.</div>';
        return;
    }
    if (USUARIO_ADMIN) {
        quadro.innerHTML = '<div class="alert alert-warning py-2 mb-0"><i class="bi bi-exclamation-triangle"></i> Aluno com <strong>' + valor
            + '</strong> em atraso. Como você é administrador, a matrícula fica registrada como autorizada por você.</div>';
        return;
    }
    if (aluno.liberacao === 'aprovada') {
        quadro.innerHTML = '<div class="alert alert-success py-2 mb-0"><i class="bi bi-check-circle"></i> Aluno com ' + valor
            + ' em atraso, mas um administrador <strong>liberou</strong> a matrícula. Pode matricular.</div>';
        return;
    }
    const pedido = aluno.liberacao === 'pendente'
        ? '<span class="text-muted small"><i class="bi bi-hourglass-split"></i> Pedido enviado — aguardando um administrador. <a href="#" onclick="location.reload(); return false;">Atualizar</a></span>'
        : '<button type="button" class="btn btn-sm btn-outline-secondary" onclick="pedirLiberacao()"><i class="bi bi-send"></i> Pedir liberação ao administrador</button>'
            + (aluno.liberacao === 'negada' ? ' <span class="small text-danger">O último pedido foi negado.</span>' : '');
    quadro.innerHTML = '<div class="alert alert-warning mb-0">'
        + '<div class="mb-2"><i class="bi bi-exclamation-triangle"></i> <strong>' + escapeHtml(aluno.nome) + '</strong> tem <strong>' + valor
        + '</strong> em atraso. Para matricular, um administrador precisa autorizar:</div>'
        + '<div class="row g-2 align-items-end mb-2">'
        + '<div class="col-md-5"><label class="form-label small mb-1">E-mail do administrador</label><input type="email" name="admin_email" class="form-control form-control-sm" autocomplete="off"></div>'
        + '<div class="col-md-4"><label class="form-label small mb-1">Senha</label><input type="password" name="admin_senha" class="form-control form-control-sm" autocomplete="new-password"></div>'
        + '<div class="col-md-3 small text-muted">e clique em Matricular</div></div>'
        + '<div>ou ' + pedido + '</div></div>';
}

function pedirLiberacao() {
    const alunoId = document.getElementById('aluno_id').value;
    const motivo = prompt('Motivo do pedido (opcional) — o administrador vê junto com o pedido:', '') ?? null;
    if (motivo === null) return;
    const dados = new URLSearchParams({ aluno_id: alunoId, turma_id: document.getElementById('turma_id').value, motivo: motivo });
    fetch('/matriculas/pedir_liberacao.php', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: dados })
        .then((r) => r.json())
        .then((r) => {
            if (!r.sucesso) throw new Error(r.mensagem);
            const aluno = alunos.find((a) => String(a.id) === alunoId);
            if (aluno) aluno.liberacao = 'pendente';
            atualizarInadimplencia();
        })
        .catch((erro) => alert(erro.message || 'Não foi possível enviar o pedido.'));
}

const buscaTurma = montarBusca({
    campo: 'turma-busca', lista: 'turma-lista', oculto: 'turma_id',
    itens: () => turmas,
    combina: (t, termo) => normalizar(t.curso_nome + ' ' + t.nome).includes(termo),
    titulo: (t) => t.curso_nome + ' · ' + t.nome,
    detalhe: (t) => t.ocupadas + '/' + t.vagas + ' alunos · início ' + (t.inicio ? deISO(t.inicio).toLocaleDateString('pt-BR') : 'a definir') + ' · ' + formatarMoeda(t.mensalidade) + '/mês',
    bloqueado: (t) => t.ocupadas >= t.vagas && String(t.id) !== document.getElementById('turma_id').value ? 'Lotada (' + t.ocupadas + '/' + t.vagas + ')' : '',
    vazio: 'Nenhuma turma aberta encontrada.<?= $podeCriarTurma ? ' Use o botão <i class="bi bi-plus-lg"></i> para criar.' : '' ?>',
    aoEscolher: () => aoTrocarTurma(),
});

/* ----------------------------- cadastros rápidos ----------------------------- */

function abrirModal(id) {
    const modal = document.getElementById(id);
    modal.querySelector('.alert-danger').classList.add('d-none');
    bootstrap.Modal.getOrCreateInstance(modal).show();
}

function enviarCadastroRapido(url, campos, modalId, aoCriar) {
    const modal = document.getElementById(modalId);
    const erro = modal.querySelector('.alert-danger');
    erro.classList.add('d-none');

    const dados = new URLSearchParams();
    campos.forEach((nome) => dados.append(nome, modal.querySelector('[name="' + nome + '"]').value));

    fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
        body: dados.toString(),
    })
        .then((r) => r.json())
        .then((resposta) => {
            if (!resposta.sucesso) {
                erro.textContent = resposta.mensagem || 'Não foi possível salvar.';
                erro.classList.remove('d-none');
                return;
            }
            aoCriar(resposta);
            modal.querySelectorAll('input:not([type=hidden])').forEach((i) => { i.value = i.defaultValue; });
            bootstrap.Modal.getOrCreateInstance(modal).hide();
        })
        .catch(() => {
            erro.textContent = 'Erro de rede ao salvar.';
            erro.classList.remove('d-none');
        });
}

function salvarNovoAluno() {
    const cpf = document.querySelector('#modal-novo-aluno [name="cpf"]');
    if (cpf.value.trim()) {
        validarCampoDocumento(cpf);
        if (cpf.classList.contains('is-invalid')) return;
    }
    enviarCadastroRapido('/alunos/criar_rapido.php', ['nome', 'cpf', 'telefone', 'email'], 'modal-novo-aluno', (r) => {
        alunos.push(r.aluno);
        buscaAluno.escolher(r.aluno.id);
    });
}

function salvarNovaTurma() {
    enviarCadastroRapido('/turmas/criar_rapida.php',
        ['curso_id', 'professor_id', 'nome', 'vagas', 'data_inicio', 'duracao_meses', 'duracao_unidade', 'valor_mensalidade'],
        'modal-nova-turma', (r) => {
            turmas.push(r.turma);
            buscaTurma.escolher(r.turma.id);
        });
}

function atualizarPontualidade() {
    const tipo = document.getElementById('desconto_pontualidade_tipo').value;
    document.querySelectorAll('.bloco-pontualidade').forEach((el) => { el.style.display = tipo === 'nenhum' ? 'none' : ''; });
    document.getElementById('rotulo_pontualidade_valor').textContent = tipo === 'percentual' ? 'Desconto (%)' : 'Desconto (R$ por mensalidade)';
    atualizarPrevia();
}

atualizarPontualidade();
aoTrocarTurma(true);
atualizarInadimplencia();
<?php if ($reserva && !$dados['aluno_id']): ?>
document.getElementById('aluno-busca').value = <?= json_encode($reserva['nome'], JSON_UNESCAPED_UNICODE) ?>;
<?php endif; ?>
</script>

<?php if ($podeCriarAluno): ?>
<div class="modal fade" id="modal-novo-aluno" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Novo aluno</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-danger d-none"></div>
                <div class="row g-2">
                    <div class="col-12">
                        <label class="form-label">Nome *</label>
                        <input type="text" class="form-control" name="nome">
                    </div>
                    <div class="col-6">
                        <label class="form-label">CPF</label>
                        <input type="text" class="form-control" name="cpf" onblur="if (this.value.trim()) validarCampoDocumento(this)" oninput="this.classList.remove('is-invalid')">
                        <div class="invalid-feedback">CPF inválido.</div>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Telefone</label>
                        <input type="text" class="form-control" name="telefone">
                    </div>
                    <div class="col-12">
                        <label class="form-label">E-mail</label>
                        <input type="email" class="form-control" name="email">
                    </div>
                </div>
                <div class="form-text mt-2">Endereço, RG e telefones extras podem ser completados depois no cadastro do aluno.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-brand" onclick="salvarNovoAluno()"><i class="bi bi-check-lg"></i> Salvar aluno</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($podeCriarTurma): ?>
<div class="modal fade" id="modal-nova-turma" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Nova turma</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-danger d-none"></div>
                <?php if (!$cursosAtivos): ?>
                    <div class="alert alert-warning">Nenhum curso ativo. <a href="/cursos/form.php" target="_blank" rel="noopener">Cadastre um curso</a> primeiro.</div>
                <?php endif; ?>
                <div class="row g-2">
                    <div class="col-md-6">
                        <label class="form-label">Curso *</label>
                        <select class="form-select" name="curso_id">
                            <option value="">Selecione...</option>
                            <?php foreach ($cursosAtivos as $c): ?>
                                <option value="<?= (int) $c['id'] ?>"><?= e($c['nome']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Nome da turma *</label>
                        <input type="text" class="form-control" name="nome" maxlength="100" placeholder="Ex: 2026/1 - Noite">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Professor responsável</label>
                        <select class="form-select" name="professor_id">
                            <option value="">A definir</option>
                            <?php foreach ($professoresAtivos as $p): ?>
                                <option value="<?= (int) $p['id'] ?>"><?= e($p['nome']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Nº máximo de alunos *</label>
                        <input type="number" class="form-control" name="vagas" min="1">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Data de início *</label>
                        <input type="date" class="form-control" name="data_inicio">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Duração *</label>
                        <div class="input-group">
                            <input type="number" class="form-control" name="duracao_meses" min="1">
                            <select class="form-select" name="duracao_unidade" style="max-width: 95px;">
                                <option value="mes">meses</option>
                                <option value="dia">dias</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Mensalidade *</label>
                        <input type="text" class="form-control" name="valor_mensalidade" placeholder="0,00">
                    </div>
                </div>
                <div class="form-text mt-2">A turma é criada na situação padrão. Horário, sala e observações podem ser completados depois na tela da turma.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-brand" onclick="salvarNovaTurma()"><i class="bi bi-check-lg"></i> Criar turma</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
