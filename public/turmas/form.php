<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();
exigirCursos();

$pdo = getDB();
$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
// Turma nova: criar_turma; turma existente: editar_turma.
$id ? exigirPermissaoAcao('editar_turma', 'Seu perfil não pode editar turmas.')
    : exigirPermissaoAcao('criar_turma', 'Seu perfil não pode cadastrar turmas.');
$turma = null;
$alunosAtivos = 0;

if ($id) {
    $stmt = $pdo->prepare('SELECT t.*, s.comportamento AS comportamento_atual FROM turmas t JOIN situacoes_turma s ON s.id = t.situacao_id WHERE t.id = ?');
    $stmt->execute([$id]);
    $turma = $stmt->fetch();
    if (!$turma) {
        flash('danger', 'Turma não encontrada.');
        redirect('/turmas/listar.php');
    }

    $alunosAtivos = vagasOcupadasTurma($pdo, $id);
}

$erros = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cursoId = (int) ($_POST['curso_id'] ?? 0);
    $professorId = (int) ($_POST['professor_id'] ?? 0) ?: null;
    $nome = input('nome');
    $vagas = (int) ($_POST['vagas'] ?? 0);
    // "A definir": a turma começa quando atingir o mínimo de alunos.
    $semData = input('quando_comeca') === 'minimo';
    $dataInicio = $semData ? null : input('data_inicio');
    $minimoAlunos = (int) ($_POST['minimo_alunos'] ?? 0) ?: null;
    $duracaoMeses = (int) ($_POST['duracao_meses'] ?? 0);
    $duracaoUnidade = input('duracao_unidade') === 'dia' ? 'dia' : 'mes';
    // Valor do curso (sem a matrícula); a mensalidade sai de total ÷ parcelas.
    $valorTotal = decimalInput('valor_total', 0);
    $parceria = mb_substr(input('parceria'), 0, 255);
    $horario = input('horario');
    $local = input('local');
    $situacaoId = (int) ($_POST['situacao_id'] ?? 0);
    $dataConclusao = input('data_conclusao') ?: null;
    $observacao = input('observacao');

    // Agenda (gera o calendário de aulas) e portal do aluno.
    // Cada dia marcado tem o seu horário (ex.: Seg a Sex 19h, Sáb 12h).
    $agenda = [];
    $horaInvalida = false;
    foreach (array_keys(diasSemanaNomes()) as $dia) {
        if (empty($_POST['dia_ativo'][$dia])) {
            continue;
        }
        $inicioDia = preg_match('/^\d{2}:\d{2}$/', (string) ($_POST['dia_inicio'][$dia] ?? '')) ? $_POST['dia_inicio'][$dia] : '';
        $fimDia = preg_match('/^\d{2}:\d{2}$/', (string) ($_POST['dia_fim'][$dia] ?? '')) ? $_POST['dia_fim'][$dia] : '';
        if (($inicioDia && !$fimDia) || ($fimDia && $inicioDia && $fimDia <= $inicioDia)) {
            $horaInvalida = true;
        }
        $agenda[] = ['dia' => $dia, 'inicio' => $inicioDia, 'fim' => $fimDia];
    }
    $diasSemana = array_column($agenda, 'dia');
    // Resumo (turmas antigas e telas que só leem um horário): o do 1º dia.
    $horaInicio = $agenda[0]['inicio'] ?? '';
    $horaFim = $agenda[0]['fim'] ?? '';
    $agendaJson = $agenda ? json_encode($agenda) : null;
    $portal = [
        'portal_publicar' => isset($_POST['portal_publicar']) ? 1 : 0,
        'portal_descricao' => trim((string) ($_POST['portal_descricao'] ?? '')),
        'portal_inscricoes_ate' => input('portal_inscricoes_ate'),
        'portal_modo_vaga' => array_key_exists(input('portal_modo_vaga'), modosVagaPortal()) ? input('portal_modo_vaga') : 'reserva',
        'portal_prazo_pagamento_dias' => (int) ($_POST['portal_prazo_pagamento_dias'] ?? 3),
        'portal_taxa_matricula' => decimalInput('portal_taxa_matricula', 0),
        'portal_num_parcelas' => (int) ($_POST['portal_num_parcelas'] ?? 0) ?: null,
        'portal_dia_vencimento' => (int) ($_POST['portal_dia_vencimento'] ?? 10),
        'portal_forma_pagamento_id' => (int) ($_POST['portal_forma_pagamento_id'] ?? 0) ?: null,
        'forma_mensalidade_id' => (int) ($_POST['forma_mensalidade_id'] ?? 0) ?: null,
    ];
    $parcelasTurma = $portal['portal_num_parcelas'] ?: parcelasPadraoTurma(['duracao_meses' => $duracaoMeses, 'duracao_unidade' => $duracaoUnidade]);
    $valorMensalidade = mensalidadeDoTotal($valorTotal, max(1, $parcelasTurma));
    if ($diasSemana) {
        $horario = mb_substr(textoAgendaTurma(['agenda' => $agendaJson]), 0, 100);
    }

    if (!$cursoId) {
        $erros[] = 'Escolha o curso da turma.';
    }
    if ($nome === '') {
        $erros[] = 'Dê um nome à turma (ex.: "2026/1 - Noite").';
    }
    if ($vagas < 1) {
        $erros[] = 'O número máximo de alunos deve ser pelo menos 1.';
    } elseif ($vagas < $alunosAtivos) {
        $erros[] = "A turma já tem {$alunosAtivos} vaga(s) ocupada(s) entre matrículas e reservas — o máximo não pode ficar abaixo disso.";
    }
    if (!$semData && !$dataInicio) {
        $erros[] = 'Informe a data de início — ou marque "A definir" para começar quando atingir o mínimo de alunos.';
    }
    if ($semData && !$minimoAlunos) {
        $erros[] = 'Turma sem data: informe o mínimo de alunos para ela começar.';
    }
    if ($minimoAlunos !== null && ($minimoAlunos < 1 || $minimoAlunos > $vagas)) {
        $erros[] = 'O mínimo de alunos precisa ficar entre 1 e o número máximo de alunos.';
    }
    if ($duracaoMeses < 1) {
        $erros[] = 'Informe a duração da turma (pelo menos 1).';
    }
    if ($valorTotal < 0) {
        $erros[] = 'O valor do curso não pode ser negativo.';
    }
    $stmt = $pdo->prepare('SELECT * FROM situacoes_turma WHERE id = ?');
    $stmt->execute([$situacaoId]);
    $situacao = $stmt->fetch();
    if (!$situacao) {
        $erros[] = 'Escolha a situação da turma.';
    } elseif (!usuarioPodeConcluirTurma() && $situacaoId !== (int) ($turma['situacao_id'] ?? situacaoPadraoTurmaId($pdo))
        && ($situacao['comportamento'] === 'concluida' || ($turma['comportamento_atual'] ?? '') === 'concluida')) {
        $erros[] = 'Seu perfil não pode concluir nem reabrir turmas.';
    } elseif ($situacao['comportamento'] === 'concluida' && $semData) {
        $erros[] = 'Para concluir a turma, informe a data de início (marque "Em uma data definida") — ela sai no certificado.';
    } elseif ($situacao['comportamento'] === 'concluida' && ($erroData = validarDataConclusaoTurma($dataConclusao, $dataInicio))) {
        $erros[] = 'Turma concluída: ' . $erroData;
    }
    if ($horaInvalida) {
        $erros[] = 'Confira o horário das aulas: em cada dia, o término precisa ser depois do início.';
    }
    if ($portal['portal_dia_vencimento'] < 1 || $portal['portal_dia_vencimento'] > 28) {
        $erros[] = 'O dia de vencimento das mensalidades vai de 1 a 28.';
    }
    if ($portal['portal_taxa_matricula'] < 0) {
        $erros[] = 'O valor da matrícula não pode ser negativo.';
    }
    if ($portal['portal_num_parcelas'] !== null && ($portal['portal_num_parcelas'] < 1 || $portal['portal_num_parcelas'] > 120)) {
        $erros[] = 'O número de mensalidades vai de 1 a 120.';
    }
    if ($portal['portal_publicar']) {
        if ($portal['portal_prazo_pagamento_dias'] < 1 || $portal['portal_prazo_pagamento_dias'] > 30) {
            $erros[] = 'O prazo para pagar a inscrição vai de 1 a 30 dias.';
        }
        // No site, quem confirma a inscrição é a matrícula (ou, sem taxa, a
        // 1ª mensalidade): essa precisa ser paga online (boleto / Pix).
        if ($portal['portal_taxa_matricula'] > 0 && !formaCobraOnline($pdo, $portal['portal_forma_pagamento_id'])) {
            $erros[] = 'Inscrição pelo portal: a matrícula precisa ser cobrada por boleto ou Pix (é o pagamento que confirma a vaga).';
        } elseif ($portal['portal_taxa_matricula'] <= 0 && $valorTotal > 0 && !formaCobraOnline($pdo, $portal['forma_mensalidade_id'])) {
            $erros[] = 'Inscrição pelo portal sem taxa de matrícula: a 1ª mensalidade confirma a vaga, então as mensalidades precisam ser por boleto ou Pix — ou cobre uma matrícula por boleto/Pix.';
        }
    }
    $novaImagem = null;
    if (!$erros && ($_FILES['portal_imagem']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $upload = salvarImagemPortal($_FILES['portal_imagem'], 'turma');
        if ($upload['ok']) {
            $novaImagem = $upload['url'];
        } else {
            $erros[] = 'Imagem de divulgação: ' . $upload['mensagem'];
        }
    }
    if ($id && $cursoId !== (int) $turma['curso_id']) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM matriculas WHERE turma_id = ?');
        $stmt->execute([$id]);
        if ((int) $stmt->fetchColumn() > 0) {
            $erros[] = 'Não é possível trocar o curso de uma turma que já tem matrículas.';
        }
    }

    if (!$erros) {
        $campos = [
            $cursoId, $professorId, $nome, $vagas, $dataInicio, $duracaoMeses, $valorMensalidade,
            $horario ?: null, $local ?: null, $observacao ?: null, $valorTotal, $parceria ?: null,
        ];

        $pdo->beginTransaction();

        $resumoSituacao = '';
        if ($id) {
            $stmt = $pdo->prepare(
                'UPDATE turmas SET curso_id=?, professor_id=?, nome=?, vagas=?, data_inicio=?, duracao_meses=?, valor_mensalidade=?,
                    horario=?, local=?, observacao=?, valor_total=?, parceria=?, duracao_unidade=? WHERE id=?'
            );
            $stmt->execute([...$campos, $duracaoUnidade, $id]);
            $operacao = 'edicao';

            if ($situacaoId !== (int) $turma['situacao_id']) {
                $resultado = aplicarSituacaoTurma($pdo, $id, $situacao, $dataConclusao);
                if (!$resultado['ok']) {
                    $pdo->rollBack();
                    $erros[] = $resultado['mensagem'];
                }
                $resumoSituacao = resumoSituacaoTurma($resultado['concluidas'], $resultado['canceladas'], $resultado['reativadas']);
            }
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO turmas (curso_id, professor_id, nome, vagas, data_inicio, duracao_meses, valor_mensalidade, horario, local, observacao, valor_total, parceria, situacao_id, duracao_unidade)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $stmt->execute([...$campos, $situacaoId, $duracaoUnidade]);
            $id = (int) $pdo->lastInsertId();
            $operacao = 'criacao';
        }
    }

    if (!$erros) {
        $imagemAtual = $turma['portal_imagem'] ?? null;
        $imagem = $novaImagem ?? (isset($_POST['remover_imagem']) ? null : $imagemAtual);
        $stmt = $pdo->prepare(
            'UPDATE turmas SET dias_semana = ?, hora_inicio = ?, hora_fim = ?, agenda = ?, minimo_alunos = ?, portal_publicar = ?, portal_descricao = ?, portal_imagem = ?,
                portal_inscricoes_ate = ?, portal_modo_vaga = ?, portal_prazo_pagamento_dias = ?, portal_taxa_matricula = ?,
                portal_num_parcelas = ?, portal_dia_vencimento = ?, portal_forma_pagamento_id = ?, forma_mensalidade_id = ?, data_conclusao = ? WHERE id = ?'
        );
        $stmt->execute([
            $diasSemana ? implode(',', $diasSemana) : null, $horaInicio ?: null, $horaFim ?: null, $agendaJson, $minimoAlunos,
            $portal['portal_publicar'], $portal['portal_descricao'] ?: null, $imagem, $portal['portal_inscricoes_ate'] ?: null,
            $portal['portal_modo_vaga'], $portal['portal_prazo_pagamento_dias'], $portal['portal_taxa_matricula'],
            $portal['portal_num_parcelas'], $portal['portal_dia_vencimento'], $portal['portal_forma_pagamento_id'], $portal['forma_mensalidade_id'],
            $situacao['comportamento'] === 'concluida' ? $dataConclusao : null, $id,
        ]);
        $pdo->commit();
        if ($imagemAtual && $imagemAtual !== $imagem) {
            apagarImagemPortal($imagemAtual);
        }

        registrarAtividade('turmas', $operacao, ($operacao === 'criacao' ? 'Turma criada: ' : 'Turma editada: ') . $nome);

        flash('success', 'Turma salva com sucesso.' . $resumoSituacao);
        redirect('/turmas/visualizar.php?id=' . $id);
    }

    $turma = [
        'curso_id' => $cursoId, 'professor_id' => $professorId, 'nome' => $nome, 'vagas' => $vagas,
        'data_inicio' => $dataInicio, 'duracao_meses' => $duracaoMeses, 'duracao_unidade' => $duracaoUnidade, 'valor_mensalidade' => $valorMensalidade, 'valor_total' => $valorTotal, 'parceria' => $parceria,
        'horario' => $horario, 'local' => $local, 'situacao_id' => $situacaoId, 'observacao' => $observacao, 'data_conclusao' => $dataConclusao,
        'dias_semana' => implode(',', $diasSemana), 'hora_inicio' => $horaInicio, 'hora_fim' => $horaFim,
        'agenda' => $agendaJson, 'minimo_alunos' => $minimoAlunos, 'sem_data' => $semData,
    ] + $portal + ($turma ?? []);
    if ($novaImagem) {
        apagarImagemPortal($novaImagem);
    }
}

if (!$id && !$turma) {
    $turma = ['curso_id' => (int) ($_GET['curso_id'] ?? 0), 'situacao_id' => situacaoPadraoTurmaId($pdo)];
}

$stmt = $pdo->prepare('SELECT id, nome, comportamento FROM situacoes_turma WHERE ativo = 1 OR id = ? ORDER BY ordem, nome');
$stmt->execute([(int) ($turma['situacao_id'] ?? 0)]);
$situacoes = $stmt->fetchAll();

// Curso/professor inativo continua aparecendo se já é o da turma, para a
// edição não "trocar sozinha" o vínculo existente.
$stmt = $pdo->prepare('SELECT id, nome FROM cursos WHERE ativo = 1 OR id = ? ORDER BY nome');
$stmt->execute([(int) ($turma['curso_id'] ?? 0)]);
$cursos = $stmt->fetchAll();

$stmt = $pdo->prepare('SELECT id, nome FROM professores WHERE ativo = 1 OR id = ? ORDER BY nome');
$stmt->execute([(int) ($turma['professor_id'] ?? 0)]);
$professores = $stmt->fetchAll();

// Formas de pagamento: as de boleto/Pix o portal cobra sozinho; as outras
// (cartão, dinheiro...) o aluno paga na secretaria.
$formasCobranca = $pdo->query("SELECT id, nome, instrumento, instrumento IN ('boleto', 'boleto_pix', 'pix') AS online FROM formas_pagamento WHERE ativo = 1 ORDER BY instrumento = 'manual', nome")->fetchAll();
$temFormaOnline = (bool) array_filter($formasCobranca, fn ($f) => $f['online']);
$opcoesForma = function (?int $escolhida) use ($formasCobranca): string {
    $html = '<option value="">Selecione...</option>';
    foreach ($formasCobranca as $f) {
        $html .= '<option value="' . (int) $f['id'] . '"' . ((int) $escolhida === (int) $f['id'] ? ' selected' : '') . '>'
            . e(rotuloFormaPagamento($f)) . '</option>';
    }
    return $html;
};
// Turma antiga (antes do valor total): total = mensalidade × parcelas.
if ($turma && !isset($turma['valor_total']) && isset($turma['valor_mensalidade'])) {
    $turma['valor_total'] = (float) $turma['valor_mensalidade'] * ((int) ($turma['portal_num_parcelas'] ?? 0) ?: parcelasPadraoTurma($turma + ['duracao_meses' => 1]));
}
$agendaAtual = agendaTurma($turma);
$turmaSemData = $turma['sem_data'] ?? ($id && empty($turma['data_inicio']));
$cursosJson = array_map(fn ($c) => ['id' => (int) $c['id'], 'nome' => $c['nome']], $cursos);
$cursoAtualNome = '';
foreach ($cursos as $c) {
    if ((int) $c['id'] === (int) ($turma['curso_id'] ?? 0)) {
        $cursoAtualNome = $c['nome'];
    }
}

$voltar = $id ? '/turmas/visualizar.php?id=' . $id : '/turmas/listar.php';
$pageTitle = $id ? 'Editar turma' : 'Nova turma';
$pageBreadcrumb = 'Acadêmico · Turmas';
$activeMenu = 'turmas';
$pageAction = '<a href="' . e($voltar) . '" class="btn btn-outline-secondary">Cancelar</a>'
    . '<button type="submit" form="form-principal" class="btn btn-brand"><i class="bi bi-check-lg"></i> Salvar</button>';
require __DIR__ . '/../../includes/layout_header.php';
?>

<?php if ($erros): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($erros as $erro): ?><li><?= e($erro) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<?php if (!$cursos): ?>
    <div class="alert alert-warning">Cadastre um curso antes de abrir uma turma. <a href="/cursos/form.php">Cadastrar curso</a></div>
<?php endif; ?>

<form method="post" id="form-principal" enctype="multipart/form-data">
<div class="secao" style="max-width: 900px;">
    <div class="secao-titulo"><i class="bi bi-people"></i> Dados da turma</div>
    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label">Curso *</label>
            <div class="typeahead">
                <input type="text" class="form-control" id="curso-busca" autocomplete="off" placeholder="Digite para pesquisar o curso..." value="<?= e($cursoAtualNome) ?>">
                <div class="typeahead-lista d-none" id="curso-lista"></div>
            </div>
            <input type="hidden" name="curso_id" id="curso_id" value="<?= (int) ($turma['curso_id'] ?? 0) ?: '' ?>">
        </div>
        <div class="col-md-6">
            <label class="form-label">Nome da turma *</label>
            <input type="text" name="nome" class="form-control" required maxlength="100" placeholder="Ex: 2026/1 - Noite" value="<?= e($turma['nome'] ?? '') ?>">
        </div>
        <div class="col-md-6">
            <label class="form-label">Professor responsável</label>
            <select name="professor_id" class="form-select">
                <option value="">A definir</option>
                <?php foreach ($professores as $p): ?>
                    <option value="<?= (int) $p['id'] ?>" <?= (int) ($turma['professor_id'] ?? 0) === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['nome']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label">Nº máximo de alunos *</label>
            <input type="number" name="vagas" class="form-control" required min="1" value="<?= e((string) ($turma['vagas'] ?? '')) ?>">
            <?php if ($id): ?><div class="form-text"><?= $alunosAtivos ?> ocupada(s) hoje (matrículas + reservas).</div><?php endif; ?>
        </div>
        <div class="col-md-3">
            <label class="form-label">Mínimo de alunos</label>
            <input type="number" name="minimo_alunos" id="minimo_alunos" class="form-control" min="1" value="<?= e((string) ($turma['minimo_alunos'] ?? '')) ?>" placeholder="Opcional">
            <div class="form-text">Para a turma começar.</div>
        </div>
        <div class="col-md-3">
            <label class="form-label">Situação</label>
            <select name="situacao_id" id="situacao_id" class="form-select" onchange="atualizarConclusao()">
                <?php foreach ($situacoes as $s): ?>
                    <option value="<?= (int) $s['id'] ?>" data-comportamento="<?= e($s['comportamento']) ?>" <?= (int) ($turma['situacao_id'] ?? 0) === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['nome']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3" id="campo-conclusao">
            <label class="form-label">Data de conclusão *</label>
            <input type="date" name="data_conclusao" id="data_conclusao" class="form-control" max="<?= date('Y-m-d') ?>" value="<?= e($turma['data_conclusao'] ?? date('Y-m-d')) ?>">
            <div class="form-text">Sai no certificado como término.</div>
        </div>
    </div>
</div>

<div class="secao" style="max-width: 900px;">
    <div class="secao-titulo"><i class="bi bi-calendar-range"></i> Período e valor</div>
    <div class="row g-3">
        <div class="col-12">
            <label class="form-label">Quando a turma começa?</label>
            <div class="d-flex flex-wrap gap-4">
                <div class="form-check">
                    <input type="radio" class="form-check-input" name="quando_comeca" id="comeca_data" value="data" <?= $turmaSemData ? '' : 'checked' ?> onchange="atualizarQuandoComeca()">
                    <label class="form-check-label" for="comeca_data">Em uma data definida</label>
                </div>
                <div class="form-check">
                    <input type="radio" class="form-check-input" name="quando_comeca" id="comeca_minimo" value="minimo" <?= $turmaSemData ? 'checked' : '' ?> onchange="atualizarQuandoComeca()">
                    <label class="form-check-label" for="comeca_minimo">A definir — quando atingir o mínimo de alunos</label>
                </div>
            </div>
            <div class="form-text" id="explica_sem_data">A turma fica aberta para matrícula e inscrição pelo site. Quando atingir o mínimo de alunos ativos, o sistema avisa (sininho) para você definir a data de início aqui.</div>
        </div>
        <div class="col-md-3 so-com-data">
            <label class="form-label">Data de início *</label>
            <input type="date" name="data_inicio" id="data_inicio" class="form-control" value="<?= e($turma['data_inicio'] ?? '') ?>" oninput="atualizarTermino()">
        </div>
        <div class="col-md-3">
            <label class="form-label">Duração *</label>
            <div class="input-group">
                <input type="number" name="duracao_meses" id="duracao_meses" class="form-control" required min="1" value="<?= e((string) ($turma['duracao_meses'] ?? '')) ?>" oninput="atualizarTermino()">
                <select name="duracao_unidade" id="duracao_unidade" class="form-select" style="max-width: 100px;" onchange="atualizarTermino()">
                    <option value="mes" <?= ($turma['duracao_unidade'] ?? 'mes') === 'mes' ? 'selected' : '' ?>>meses</option>
                    <option value="dia" <?= ($turma['duracao_unidade'] ?? '') === 'dia' ? 'selected' : '' ?>>dias</option>
                </select>
            </div>
            <div class="form-text">Ex.: fim de semana = 2 dias.</div>
        </div>
        <div class="col-md-3 so-com-data">
            <label class="form-label">Término previsto</label>
            <input type="text" id="data_termino" class="form-control" disabled value="-">
        </div>
        <div class="col-12"><hr class="my-1"><div class="fw-semibold">Matrícula</div></div>
        <div class="col-md-3">
            <label class="form-label">Valor da matrícula</label>
            <input type="text" name="portal_taxa_matricula" class="form-control" value="<?= e(number_format((float) ($turma['portal_taxa_matricula'] ?? 0), 2, ',', '')) ?>" oninput="atualizarValores()">
            <div class="form-text">0 = sem taxa. Fica fora do valor do curso. No site, é ela que confirma a vaga.</div>
        </div>
        <div class="col-md-3">
            <label class="form-label">Matrícula paga com</label>
            <select name="portal_forma_pagamento_id" class="form-select"><?= $opcoesForma(isset($turma['portal_forma_pagamento_id']) ? (int) $turma['portal_forma_pagamento_id'] : null) ?></select>
        </div>
        <div class="col-12"><hr class="my-1"><div class="fw-semibold">Curso (mensalidades)</div></div>
        <div class="col-md-3">
            <label class="form-label">Valor total do curso *</label>
            <input type="text" name="valor_total" id="valor_total" class="form-control" required value="<?= e(number_format((float) ($turma['valor_total'] ?? 0), 2, ',', '')) ?>" oninput="atualizarValores()">
            <?php if ($id): ?><div class="form-text">Mudar aqui só vale para novas matrículas.</div><?php endif; ?>
        </div>
        <div class="col-md-3">
            <label class="form-label">Dividido em</label>
            <div class="input-group">
                <input type="number" name="portal_num_parcelas" id="portal_num_parcelas" class="form-control" min="1" max="120" placeholder="<?= !empty($turma['duracao_meses']) ? parcelasPadraoTurma($turma) : '' ?>" value="<?= e((string) ($turma['portal_num_parcelas'] ?? '')) ?>" oninput="atualizarValores()">
                <span class="input-group-text">x</span>
            </div>
            <div class="form-text">Em branco: uma por mês de duração (curso em dias: uma só).</div>
        </div>
        <div class="col-md-3">
            <label class="form-label">Mensalidades pagas com</label>
            <select name="forma_mensalidade_id" class="form-select"><?= $opcoesForma(isset($turma['forma_mensalidade_id']) ? (int) $turma['forma_mensalidade_id'] : null) ?></select>
            <div class="form-text">Cartão/dinheiro: no portal aparece "pague na secretaria".</div>
        </div>
        <div class="col-md-3">
            <label class="form-label">Dia de vencimento</label>
            <input type="number" name="portal_dia_vencimento" class="form-control" min="1" max="28" value="<?= (int) ($turma['portal_dia_vencimento'] ?? 10) ?>">
        </div>
        <div class="col-12">
            <div class="small" id="resumo_valores"></div>
            <?php if (!$temFormaOnline): ?><div class="form-text text-danger">Para cobrar pelo portal, cadastre uma forma de pagamento "Boleto", "Boleto + Pix" ou "Pix" ligada à conta do Itaú.</div><?php endif; ?>
        </div>
    </div>
</div>

<div class="secao" style="max-width: 900px;">
    <div class="secao-titulo"><i class="bi bi-geo-alt"></i> Onde e quando</div>
    <div class="row g-3">
        <div class="col-12">
            <label class="form-label">Dias e horário das aulas</label>
            <div class="form-text mt-0 mb-2">Marque os dias e o horário de cada um. Cada dia pode ter um horário diferente (ex.: segunda a sexta 19h, sábado 12h).</div>
            <div class="border rounded" style="max-width: 520px;">
                <?php foreach (diasSemanaNomes() as $numero => $nomeDia): $hDia = $agendaAtual[$numero] ?? null; ?>
                    <div class="d-flex align-items-center gap-2 px-3 py-2 linha-dia <?= $numero > 1 ? 'border-top' : '' ?>">
                        <div class="form-check mb-0" style="width: 120px;">
                            <input type="checkbox" class="form-check-input dia-ativo" name="dia_ativo[<?= $numero ?>]" value="1" id="dia_<?= $numero ?>" data-dia="<?= $numero ?>" <?= $hDia ? 'checked' : '' ?>>
                            <label class="form-check-label" for="dia_<?= $numero ?>"><?= $nomeDia ?></label>
                        </div>
                        <input type="time" name="dia_inicio[<?= $numero ?>]" class="form-control form-control-sm hora-dia" style="width: 110px;" value="<?= e($hDia['inicio'] ?? '') ?>" aria-label="Início <?= $nomeDia ?>">
                        <span class="text-muted small hora-dia-sep">às</span>
                        <input type="time" name="dia_fim[<?= $numero ?>]" class="form-control form-control-sm hora-dia" style="width: 110px;" value="<?= e($hDia['fim'] ?? '') ?>" aria-label="Fim <?= $nomeDia ?>">
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="form-text">Ao marcar um dia, ele já vem com o horário do último dia preenchido. Com os dias marcados, o sistema monta o calendário de aulas (tela da turma › Calendário).</div>
        </div>
        <div class="col-md-6">
            <label class="form-label">Texto de dias e horário <span class="text-muted small">(se não marcar os dias)</span></label>
            <input type="text" name="horario" class="form-control" maxlength="100" placeholder="Ex: Seg e Qua, 19h às 22h" value="<?= e($turma['horario'] ?? '') ?>">
        </div>
        <div class="col-md-6">
            <label class="form-label">Local / sala</label>
            <input type="text" name="local" class="form-control" maxlength="100" placeholder="Ex: Bloco B, sala 204" value="<?= e($turma['local'] ?? '') ?>">
        </div>
        <div class="col-12">
            <label class="form-label">Observação</label>
            <input type="text" name="observacao" class="form-control" maxlength="255" value="<?= e($turma['observacao'] ?? '') ?>">
        </div>
        <div class="col-12">
            <label class="form-label">Parceria</label>
            <input type="text" name="parceria" class="form-control" maxlength="255" placeholder="Ex.: em parceria com a Faculdade X (em branco = sem parceria)" value="<?= e($turma['parceria'] ?? '') ?>">
            <div class="form-text">Sai no certificado onde o modelo tiver o marcador <code>{parceria}</code> (Acadêmico › Documentos). Sem parceria, o marcador fica em branco.</div>
        </div>
    </div>
</div>

<div class="secao" style="max-width: 900px;">
    <div class="secao-titulo"><i class="bi bi-globe2"></i> Portal do aluno — divulgação e inscrição online</div>
    <div class="form-check form-switch mb-3">
        <input type="checkbox" class="form-check-input" role="switch" name="portal_publicar" id="portal_publicar" <?= !empty($turma['portal_publicar']) ? 'checked' : '' ?> onchange="atualizarPortal()">
        <label class="form-check-label fw-semibold" for="portal_publicar">Mostrar esta turma no portal e aceitar inscrições online</label>
    </div>
    <div id="bloco-portal" class="row g-3">
        <div class="col-md-8">
            <label class="form-label">Texto de divulgação</label>
            <textarea name="portal_descricao" class="form-control" rows="5" placeholder="O que o aluno vai aprender, para quem é o curso, diferenciais... (em branco, usa a descrição do curso)"><?= e($turma['portal_descricao'] ?? '') ?></textarea>
        </div>
        <div class="col-md-4">
            <label class="form-label">Imagem de divulgação</label>
            <?php if (!empty($turma['portal_imagem'])): ?>
                <img src="<?= e($turma['portal_imagem']) ?>" alt="" class="w-100 rounded mb-2" style="aspect-ratio: 16/9; object-fit: cover;">
                <div class="form-check mb-1"><input type="checkbox" class="form-check-input" name="remover_imagem" id="remover_imagem"><label class="form-check-label small" for="remover_imagem">Remover imagem</label></div>
            <?php endif; ?>
            <input type="file" name="portal_imagem" class="form-control" accept="image/*">
            <div class="form-text">Formato deitado (16:9), ex.: 1280 × 720. Até 5 MB.</div>
        </div>

        <div class="col-md-6">
            <label class="form-label">Quando a vaga fica garantida?</label>
            <select name="portal_modo_vaga" class="form-select" id="portal_modo_vaga" onchange="atualizarPortal()">
                <?php foreach (modosVagaPortal() as $chave => $rotulo): ?>
                    <option value="<?= e($chave) ?>" <?= ($turma['portal_modo_vaga'] ?? 'reserva') === $chave ? 'selected' : '' ?>><?= e($rotulo) ?></option>
                <?php endforeach; ?>
            </select>
            <div class="form-text" id="explica_modo"></div>
        </div>
        <div class="col-md-3 so-com-pagamento">
            <label class="form-label">Prazo para pagar (dias)</label>
            <input type="number" name="portal_prazo_pagamento_dias" class="form-control" min="1" max="30" value="<?= (int) ($turma['portal_prazo_pagamento_dias'] ?? 3) ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label">Inscrições até</label>
            <input type="date" name="portal_inscricoes_ate" class="form-control" value="<?= e($turma['portal_inscricoes_ate'] ?? '') ?>">
            <div class="form-text">Em branco: enquanto a turma aceitar matrícula.</div>
        </div>

        <div class="col-12 form-text">
            <i class="bi bi-info-circle"></i> O site mostra as vagas que sobram depois das matrículas e das vagas reservadas pela secretaria
            (as reservas são feitas na tela da turma, em "Alunos da turma").
        </div>
    </div>
</div>
</form>

<script src="/assets/js/busca.js"></script>
<script>
const cursos = <?= json_encode($cursosJson, JSON_UNESCAPED_UNICODE) ?>;
montarBusca({
    campo: 'curso-busca', lista: 'curso-lista', oculto: 'curso_id',
    itens: () => cursos,
    combina: (c, termo) => normalizar(c.nome).includes(termo),
    titulo: (c) => c.nome,
    detalhe: () => '',
    vazio: 'Nenhum curso encontrado.',
});
document.getElementById('form-principal').addEventListener('submit', (ev) => {
    if (!document.getElementById('curso_id').value) {
        ev.preventDefault();
        alert('Escolha o curso na lista (digite parte do nome e clique nele).');
        document.getElementById('curso-busca').focus();
    }
});

// Turma concluída pede a data de conclusão (vai para o certificado).
function atualizarConclusao() {
    const opcao = document.getElementById('situacao_id').selectedOptions[0];
    const concluida = opcao && opcao.dataset.comportamento === 'concluida';
    document.getElementById('campo-conclusao').style.display = concluida ? '' : 'none';
    document.getElementById('data_conclusao').required = concluida;
}
atualizarConclusao();

// Sem data: esconde início/término e exige o mínimo de alunos.
function atualizarQuandoComeca() {
    const semData = document.getElementById('comeca_minimo').checked;
    document.querySelectorAll('.so-com-data').forEach((el) => { el.style.display = semData ? 'none' : ''; });
    document.getElementById('explica_sem_data').style.display = semData ? '' : 'none';
    document.getElementById('data_inicio').required = !semData;
    document.getElementById('minimo_alunos').required = semData;
}
atualizarQuandoComeca();

// Dias da aula: o horário só vale para dia marcado; marcando um dia novo,
// ele herda o horário do último dia preenchido.
function atualizarDia(caixa) {
    const linha = caixa.closest('.linha-dia');
    const horas = linha.querySelectorAll('.hora-dia');
    horas.forEach((h) => { h.disabled = !caixa.checked; });
    linha.querySelector('.hora-dia-sep').style.opacity = caixa.checked ? 1 : .4;
}
document.querySelectorAll('.dia-ativo').forEach((caixa) => {
    atualizarDia(caixa);
    caixa.addEventListener('change', () => {
        const [inicio, fim] = caixa.closest('.linha-dia').querySelectorAll('.hora-dia');
        if (caixa.checked && !inicio.value) {
            const modelo = [...document.querySelectorAll('.dia-ativo:checked')].reverse()
                .map((c) => c.closest('.linha-dia').querySelectorAll('.hora-dia'))
                .find((h) => h[0].value && h[0] !== inicio);
            if (modelo) { inicio.value = modelo[0].value; fim.value = modelo[1].value; }
        }
        atualizarDia(caixa);
    });
});

// Mesma regra da coluna gerada no banco (data_inicio + INTERVAL n MONTH/DAY):
// em meses, se o dia não existe no mês final (ex.: 31), cai no último dia do
// mês. O término mostrado é o último dia de aula (o dia anterior).
function atualizarTermino() {
    const inicio = document.getElementById('data_inicio').value;
    const meses = parseInt(document.getElementById('duracao_meses').value, 10);
    const emDias = document.getElementById('duracao_unidade').value === 'dia';
    const saida = document.getElementById('data_termino');
    if (!inicio || !meses || meses < 1) {
        saida.value = '-';
        return;
    }
    const [ano, mes, dia] = inicio.split('-').map(Number);
    if (emDias) {
        saida.value = new Date(ano, mes - 1, dia + meses - 1).toLocaleDateString('pt-BR');
        return;
    }
    const alvo = new Date(ano, mes - 1 + meses, 1);
    const ultimoDia = new Date(alvo.getFullYear(), alvo.getMonth() + 1, 0).getDate();
    alvo.setDate(Math.min(dia, ultimoDia));
    saida.value = alvo.toLocaleDateString('pt-BR');
}
atualizarTermino();

const EXPLICA_MODO = {
    reserva: 'A inscrição segura a vaga durante o prazo. Não pagou até lá: a inscrição é cancelada e a vaga volta.',
    apos_pagamento: 'Inscrever-se não segura vaga: entra quem pagar primeiro. Quem pagar com a turma já cheia fica na lista de espera e você é avisado.',
    inscricao: 'A vaga é do aluno assim que ele se inscreve; as cobranças são lançadas e ele paga depois, como numa matrícula feita pela secretaria.'
};
// Resumo: "Curso R$ 1.000,00 em 3x de R$ 333,33 (+ matrícula R$ 100,00)".
function atualizarValores() {
    const ler = (v) => parseFloat(String(v || '0').replace(/\./g, '').replace(',', '.')) || 0;
    const moeda = (v) => v.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
    const total = ler(document.getElementById('valor_total').value);
    const taxa = ler(document.querySelector('[name="portal_taxa_matricula"]').value);
    const campo = document.getElementById('portal_num_parcelas');
    const n = parseInt(campo.value || campo.placeholder, 10) || 1;
    const parcela = Math.floor(Math.round(total * 100) / n) / 100;
    const ultima = Math.round(total * 100 - Math.round(parcela * 100) * (n - 1)) / 100;
    document.getElementById('resumo_valores').innerHTML = total > 0
        ? 'Curso <strong>' + moeda(total) + '</strong> em <strong>' + n + 'x de ' + moeda(parcela) + '</strong>'
            + (Math.abs(ultima - parcela) > 0.001 ? ' (a última de ' + moeda(ultima) + ')' : '')
            + (taxa > 0 ? ' + matrícula de ' + moeda(taxa) + ' · total para o aluno ' + moeda(total + taxa) : '') + '.'
        : (taxa > 0 ? 'Só a matrícula: ' + moeda(taxa) + '.' : 'Curso gratuito.');
}
atualizarValores();

function atualizarPortal() {
    const ligado = document.getElementById('portal_publicar').checked;
    document.getElementById('bloco-portal').style.display = ligado ? '' : 'none';
    const modo = document.getElementById('portal_modo_vaga').value;
    document.getElementById('explica_modo').textContent = EXPLICA_MODO[modo];
    document.querySelectorAll('.so-com-pagamento').forEach((el) => { el.style.display = modo === 'inscricao' ? 'none' : ''; });
}
atualizarPortal();

</script>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
