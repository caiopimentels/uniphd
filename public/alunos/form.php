<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();
exigirAlunos();

$pdo = getDB();
$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
$aluno = null;
$tabAtiva = $_GET['tab'] ?? 'dados';
$telefonesExtras = [];

if ($id) {
    $stmt = $pdo->prepare('SELECT * FROM alunos WHERE id = ?');
    $stmt->execute([$id]);
    $aluno = $stmt->fetch();
    if ($aluno) {
        sincronizarDocumentosAluno($pdo, (int) $aluno['id']);
    }
    if (!$aluno) {
        flash('danger', 'Aluno não encontrado.');
        redirect('/alunos/listar.php');
    }

    $stmt = $pdo->prepare('SELECT telefone FROM aluno_telefones WHERE aluno_id = ? ORDER BY id');
    $stmt->execute([$id]);
    $telefonesExtras = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $stmt = $pdo->prepare('SELECT * FROM aluno_enderecos WHERE aluno_id = ? ORDER BY principal DESC, id');
    $stmt->execute([$id]);
    $enderecos = $stmt->fetchAll();
}
$enderecos = $enderecos ?? [];

// Ver é ver_alunos; mexer nos dados é editar_aluno; ativar/desativar tem
// permissão própria (quem só ativa salva só esse campo).
// Ficha nova: cadastrar_aluno; ficha existente: editar_aluno.
$podeEditarAluno = $id ? usuarioPodeEditarAluno() : usuarioPodeCadastrarAluno();
$podeAtivarAluno = usuarioPodeAtivarAluno();
if (!$id && !$podeEditarAluno) {
    flash('danger', 'Seu perfil não pode cadastrar alunos.');
    redirect('/alunos/listar.php');
}

$erros = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$podeEditarAluno) {
    if (!$podeAtivarAluno) {
        flash('danger', 'Seu perfil não pode editar alunos.');
        redirect('/alunos/form.php?id=' . $id);
    }
    $ativo = isset($_POST['ativo']) ? 1 : 0;
    if ($ativo !== (int) $aluno['ativo']) {
        $pdo->prepare('UPDATE alunos SET ativo = ? WHERE id = ?')->execute([$ativo, $id]);
        registrarAtividade('alunos', 'edicao', 'Aluno ' . ($ativo ? 'ativado' : 'desativado') . ': ' . $aluno['nome']);
        registrarHistoricoAluno($pdo, $id, 'situacao', $ativo ? 'Aluno ativado' : 'Aluno desativado');
    }
    flash('success', $ativo ? 'Aluno ativo.' : 'Aluno desativado.');
    redirect('/alunos/form.php?id=' . $id);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome = formatarNomePessoa(input('nome'));
    $dataNascimento = input('data_nascimento');
    // CPF e telefones: só os números no banco (a máscara é da tela).
    $cpf = apenasDigitos(input('cpf'));
    $rg = input('rg');
    $telefone = apenasDigitos(input('telefone'));
    $email = input('email');
    $observacao = input('observacao');
    // Mãe, pai e graduação: na secretaria não são obrigatórios.
    [$certificacao, $errosCertificacao] = lerDadosCertificacaoAluno($pdo, false, false, (int) ($aluno['graduacao_id'] ?? 0) ?: null);
    // Sem a permissão de ativar, o campo fica como estava (aluno novo: ativo).
    $ativo = $podeAtivarAluno ? (isset($_POST['ativo']) ? 1 : 0) : (int) ($aluno['ativo'] ?? 1);
    $telefonesExtrasPost = array_filter(array_map(fn ($t) => apenasDigitos((string) $t), $_POST['telefones_extra'] ?? []));

    $enderecosPost = [];
    $enderecoPrincipalIndice = (int) ($_POST['endereco_principal'] ?? 0);
    foreach ($_POST['endereco_logradouro'] ?? [] as $i => $logradouroEndereco) {
        $temAlgo = trim($logradouroEndereco) !== '' || trim($_POST['endereco_cidade'][$i] ?? '') !== '';
        if (!$temAlgo) {
            continue;
        }
        $enderecosPost[] = [
            'apelido' => trim($_POST['endereco_apelido'][$i] ?? ''),
            'cep' => trim($_POST['endereco_cep'][$i] ?? ''),
            'logradouro' => formatarNomePessoa($logradouroEndereco),
            'numero' => trim($_POST['endereco_numero'][$i] ?? ''),
            'complemento' => formatarNomePessoa($_POST['endereco_complemento'][$i] ?? ''),
            'bairro' => formatarNomePessoa($_POST['endereco_bairro'][$i] ?? ''),
            'cidade' => formatarNomePessoa($_POST['endereco_cidade'][$i] ?? ''),
            'uf' => strtoupper(trim($_POST['endereco_uf'][$i] ?? '')),
            'principal' => $i === $enderecoPrincipalIndice ? 1 : 0,
        ];
    }
    if ($enderecosPost && !array_sum(array_column($enderecosPost, 'principal'))) {
        $enderecosPost[0]['principal'] = 1;
    }

    if ($nome === '') {
        $erros[] = 'O nome é obrigatório.';
    }
    $erros = array_merge($erros, $errosCertificacao);
    // RA: escolhido só no cadastro (vem sugerido); depois não muda mais.
    $ra = $id ? (string) $aluno['ra'] : strtoupper(trim(input('ra')));
    if (!$id) {
        if ($ra === '') {
            $ra = proximoRaAluno($pdo);
        } elseif (!raValido($ra)) {
            $erros[] = 'O RA deve ter o formato RA0000000 (RA e 7 números).';
        } else {
            $stmt = $pdo->prepare('SELECT nome FROM alunos WHERE ra = ?');
            $stmt->execute([$ra]);
            if ($outro = $stmt->fetchColumn()) {
                $erros[] = 'O RA ' . $ra . ' já é de ' . $outro . '.';
            }
        }
    }
    if ($cpf !== '' && !validarCpf($cpf)) {
        $erros[] = 'O CPF informado é inválido.';
    }
    if ($cpf !== '') {
        // Compara só os dígitos: o mesmo CPF pode ter sido digitado com ou
        // sem pontuação em cadastros diferentes.
        $stmt = $pdo->prepare("SELECT nome FROM alunos WHERE REGEXP_REPLACE(cpf, '[^0-9]', '') = ? AND id <> ?");
        $stmt->execute([apenasDigitos($cpf), $id ?? 0]);
        $duplicado = $stmt->fetchColumn();
        if ($duplicado) {
            $erros[] = 'Já existe um aluno cadastrado com este CPF: ' . $duplicado . '.';
        }
    }

    if (!$erros) {
        $operacaoAuditoria = $id ? 'edicao' : 'criacao';
        $pdo->beginTransaction();

        $campos = [
            $nome, $dataNascimento ?: null, $cpf ?: null, $rg ?: null,
            $telefone ?: null, $email ?: null, $observacao ?: null, $ativo,
            $certificacao['nome_mae'] ?: null, $certificacao['nome_pai'] ?: null, $certificacao['possui_graduacao'], $certificacao['graduacao_id'],
        ];

        if ($id) {
            $stmt = $pdo->prepare(
                'UPDATE alunos SET nome=?, data_nascimento=?, cpf=?, rg=?, telefone=?, email=?, observacao=?, ativo=?, nome_mae=?, nome_pai=?, possui_graduacao=?, graduacao_id=? WHERE id=?'
            );
            $stmt->execute([...$campos, $id]);
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO alunos (nome, data_nascimento, cpf, rg, telefone, email, observacao, ativo, nome_mae, nome_pai, possui_graduacao, graduacao_id, ra)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $stmt->execute([...$campos, $ra]);
            $id = (int) $pdo->lastInsertId();
        }

        $stmt = $pdo->prepare('DELETE FROM aluno_telefones WHERE aluno_id = ?');
        $stmt->execute([$id]);
        if ($telefonesExtrasPost) {
            $stmtTel = $pdo->prepare('INSERT INTO aluno_telefones (aluno_id, telefone) VALUES (?, ?)');
            foreach ($telefonesExtrasPost as $tel) {
                $stmtTel->execute([$id, $tel]);
            }
        }

        $stmt = $pdo->prepare('DELETE FROM aluno_enderecos WHERE aluno_id = ?');
        $stmt->execute([$id]);
        if ($enderecosPost) {
            $stmtEnd = $pdo->prepare(
                'INSERT INTO aluno_enderecos (aluno_id, apelido, cep, logradouro, numero, complemento, bairro, cidade, uf, principal)
                 VALUES (?,?,?,?,?,?,?,?,?,?)'
            );
            foreach ($enderecosPost as $end) {
                $stmtEnd->execute([
                    $id, $end['apelido'] ?: null, $end['cep'] ?: null, $end['logradouro'] ?: null, $end['numero'] ?: null,
                    $end['complemento'] ?: null, $end['bairro'] ?: null, $end['cidade'] ?: null, $end['uf'] ?: null, $end['principal'],
                ]);
            }
        }

        $pdo->commit();

        registrarAtividade('alunos', $operacaoAuditoria, ($operacaoAuditoria === 'criacao' ? 'Aluno cadastrado: ' : 'Aluno editado: ') . $nome);
        registrarHistoricoAluno($pdo, $id, $operacaoAuditoria === 'criacao' ? 'cadastro' : 'edicao', $operacaoAuditoria === 'criacao' ? 'Aluno cadastrado no sistema' : 'Dados do cadastro alterados');

        flash('success', 'Aluno salvo com sucesso.');
        redirect('/alunos/form.php?id=' . $id);
    }

    $aluno = [
        'nome' => $nome, 'data_nascimento' => $dataNascimento, 'cpf' => $cpf, 'rg' => $rg,
        'telefone' => $telefone, 'email' => $email, 'observacao' => $observacao, 'ativo' => $ativo,
    ];
    $telefonesExtras = $telefonesExtrasPost;
    $enderecos = $enderecosPost;
}

$podeVerFinanceiro = usuarioPodeVerInadimplencia();

$matriculas = [];
$parcelas = [];
$resumo = null;
if ($id) {
    $stmt = $pdo->prepare(
        "SELECT m.*, t.nome AS turma_nome, t.data_inicio, t.data_termino, t.minimo_alunos, t.duracao_unidade, c.nome AS curso_nome,
                u.nome AS matriculado_por
         FROM matriculas m
         JOIN turmas t ON t.id = m.turma_id
         JOIN cursos c ON c.id = t.curso_id
         LEFT JOIN usuarios u ON u.id = m.usuario_id
         WHERE m.aluno_id = ?
         ORDER BY m.status = 'ativa' DESC, t.data_inicio DESC"
    );
    $stmt->execute([$id]);
    $matriculas = $stmt->fetchAll();

    if ($podeVerFinanceiro) {
        $resumo = resumoFinanceiroAluno($pdo, $id);

        $stmt = $pdo->prepare(
            "SELECT cr.*, fp.nome AS forma_pagamento_nome, " . SQL_BAIXA_PELO_BANCO . " AS pago_pelo_banco, " . SQL_BOLETO_NO_BANCO . " AS boleto_no_banco
             FROM contas_receber cr
             LEFT JOIN formas_pagamento fp ON fp.id = cr.forma_pagamento_id
             WHERE cr.aluno_id = ? AND cr.excluido_em IS NULL
             ORDER BY cr.data_vencimento, cr.id"
        );
        $stmt->execute([$id]);
        $parcelas = $stmt->fetchAll();
    }
}

// Documentos dos cursos em que o aluno está (só cursos que pedem documento).
$documentosPorCurso = [];
$documentosAguardando = 0;
if ($id) {
    $stmt = $pdo->prepare(
        "SELECT DISTINCT c.id, c.nome FROM matriculas m JOIN turmas t ON t.id = m.turma_id JOIN cursos c ON c.id = t.curso_id
         WHERE m.aluno_id = ? AND m.status IN ('ativa', 'pendente', 'concluida')
           AND EXISTS (SELECT 1 FROM curso_documentos cd WHERE cd.curso_id = c.id AND cd.ativo = 1)
         ORDER BY c.nome"
    );
    $stmt->execute([$id]);
    foreach ($stmt->fetchAll() as $cursoDoc) {
        $documentosPorCurso[] = ['curso' => $cursoDoc, 'documentos' => documentosAlunoCurso($pdo, (int) $id, (int) $cursoDoc['id'])];
    }
    foreach ($documentosPorCurso as $grupoDoc) {
        $documentosAguardando += count(array_filter($grupoDoc['documentos'], fn ($d) => $d['status'] === 'enviado'));
    }
}

$pageTitle = $id ? e($aluno['nome']) : 'Novo aluno';
$pageBreadcrumb = 'Acadêmico · Alunos';
$activeMenu = 'alunos';
$pageAction = ($id ? '<a href="/alunos/ficha.php?id=' . (int) $id . '" target="_blank" class="btn btn-outline-secondary"><i class="bi bi-printer"></i> Ficha</a>' : '')
    . '<a href="/alunos/listar.php" class="btn btn-outline-secondary">' . ($podeEditarAluno || $podeAtivarAluno ? 'Cancelar' : 'Voltar') . '</a>'
    . ($podeEditarAluno || $podeAtivarAluno ? '<button type="submit" form="form-aluno" class="btn btn-brand"><i class="bi bi-check-lg"></i> Salvar</button>' : '');
require __DIR__ . '/../../includes/layout_header.php';
?>

<?php if ($erros): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($erros as $erro): ?><li><?= e($erro) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<ul class="nav nav-tabs mb-3">
    <li class="nav-item"><a class="nav-link <?= $tabAtiva === 'dados' ? 'active' : '' ?>" href="#" data-tab="dados">Dados gerais</a></li>
    <?php if ($id): ?>
    <li class="nav-item"><a class="nav-link <?= $tabAtiva === 'matriculas' ? 'active' : '' ?>" href="#" data-tab="matriculas">Matrículas <span class="badge text-bg-light"><?= count($matriculas) ?></span></a></li>
    <?php if ($podeVerFinanceiro): ?>
    <li class="nav-item">
        <a class="nav-link <?= $tabAtiva === 'financeiro' ? 'active' : '' ?>" href="#" data-tab="financeiro">
            Financeiro
            <?php if ($resumo['parcelas_vencidas'] > 0): ?><span class="badge text-bg-danger"><?= $resumo['parcelas_vencidas'] ?> em atraso</span><?php endif; ?>
        </a>
    </li>
    <?php endif; ?>
    <li class="nav-item"><a class="nav-link <?= $tabAtiva === 'documentos' ? 'active' : '' ?>" href="#" data-tab="documentos">Documentos
        <?php if ($documentosAguardando): ?><span class="badge text-bg-warning"><?= $documentosAguardando ?> para analisar</span><?php endif; ?></a></li>
    <?php if (usuarioPodeVerProtocolos()): $protocolosAberto = protocolosAguardandoSecretaria($pdo, (int) $id); ?>
    <li class="nav-item"><a class="nav-link <?= $tabAtiva === 'protocolos' ? 'active' : '' ?>" href="#" data-tab="protocolos">Protocolos
        <?php if ($protocolosAberto): ?><span class="badge text-bg-warning"><?= $protocolosAberto ?> para responder</span><?php endif; ?></a></li>
    <?php endif; ?>
    <li class="nav-item"><a class="nav-link <?= $tabAtiva === 'historico' ? 'active' : '' ?>" href="#" data-tab="historico">Histórico</a></li>
    <?php endif; ?>
</ul>

<div class="tab-pane" id="pane-dados" style="<?= $tabAtiva === 'dados' ? '' : 'display:none;' ?>">
        <form method="post" id="form-aluno">
            <div class="secao">
            <div class="secao-titulo"><i class="bi bi-person"></i> Dados pessoais</div>
            <div class="row g-3">
                <div class="col-md-2">
                    <label class="form-label">RA</label>
                    <?php if ($id): ?>
                        <input type="text" class="form-control fw-semibold" value="<?= e($aluno['ra'] ?: '-') ?>" readonly title="O RA não muda depois do cadastro">
                    <?php else: ?>
                        <input type="text" name="ra" class="form-control text-uppercase" maxlength="9" pattern="[Rr][Aa][0-9]{7}" value="<?= e($_POST['ra'] ?? proximoRaAluno($pdo)) ?>" title="Formato RA0000000 — sugerido o próximo número; pode trocar agora (depois de salvo não muda)">
                    <?php endif; ?>
                </div>
                <div class="col-md-5">
                    <label class="form-label">Nome *</label>
                    <input type="text" name="nome" class="form-control" required value="<?= e($aluno['nome'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Data de nascimento</label>
                    <input type="date" name="data_nascimento" class="form-control" value="<?= e($aluno['data_nascimento'] ?? '') ?>">
                </div>
                <div class="col-md-2"></div>

                <div class="col-md-3">
                    <label class="form-label">CPF</label>
                    <input type="text" name="cpf" class="form-control" inputmode="numeric" data-mascara="cpf" value="<?= e(cpfFormatado($aluno['cpf'] ?? '')) ?>"
                           onblur="validarCampoDocumento(this)" oninput="this.classList.remove('is-invalid')">
                    <div class="invalid-feedback">CPF inválido.</div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">RG</label>
                    <input type="text" name="rg" class="form-control" value="<?= e($aluno['rg'] ?? '') ?>">
                </div>
                <div class="col-12">
                    <?php
                    $dc = $aluno ?? [];
                    $tiposGraduacaoCampo = tiposGraduacao($pdo, false, (int) ($dc['graduacao_id'] ?? 0) ?: null);
                    $obrigatorioCertificacao = false;
                    require __DIR__ . '/../../includes/campos_certificacao_aluno.php';
                    ?>
                    <?php if (!empty($aluno['termo_certificacao_em'])): ?>
                        <div class="small text-muted mt-1"><i class="bi bi-shield-check"></i> Concordou com o uso das informações para fins de certificação em <?= formatDateTime($aluno['termo_certificacao_em']) ?> (cadastro pelo portal).</div>
                    <?php endif; ?>
                </div>
            </div>
            </div>

            <div class="secao">
            <div class="secao-titulo"><i class="bi bi-telephone"></i> Contato</div>
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">E-mail</label>
                    <input type="email" name="email" class="form-control" value="<?= e($aluno['email'] ?? '') ?>">
                </div>

                <div class="col-md-3">
                    <label class="form-label">Telefone principal</label>
                    <input type="tel" name="telefone" class="form-control" data-mascara="telefone" value="<?= e(telefoneFormatado($aluno['telefone'] ?? '')) ?>">
                </div>
                <div class="col-md-9">
                    <label class="form-label">Outros telefones</label>
                    <div id="telefones-extras">
                        <?php foreach ($telefonesExtras as $tel): ?>
                            <div class="input-group mb-1">
                                <input type="tel" name="telefones_extra[]" class="form-control" data-mascara="telefone" value="<?= e(telefoneFormatado($tel)) ?>">
                                <button type="button" class="btn btn-outline-danger" onclick="this.closest('.input-group').remove()"><i class="bi bi-trash"></i></button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="adicionarTelefone()"><i class="bi bi-plus-lg"></i> Adicionar telefone</button>
                </div>
            </div>
            </div>

            <div class="secao">
            <div class="secao-titulo">
                <i class="bi bi-geo-alt"></i> Endereços
                <button type="button" class="btn btn-sm btn-outline-secondary ms-auto" onclick="adicionarEndereco()"><i class="bi bi-plus-lg"></i> Adicionar endereço</button>
            </div>
            <div id="enderecos-lista">
                <?php foreach ($enderecos as $i => $end): ?>
                    <?php require __DIR__ . '/_endereco_card.php'; ?>
                <?php endforeach; ?>
            </div>
            <template id="template-endereco">
                <?php $i = '__INDICE__'; $end = []; ?>
                <?php require __DIR__ . '/_endereco_card.php'; ?>
            </template>
            </div>

            <div class="secao">
            <div class="secao-titulo"><i class="bi bi-card-text"></i> Observações e situação</div>
            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label">Observação</label>
                    <input type="text" name="observacao" class="form-control" maxlength="255" value="<?= e($aluno['observacao'] ?? '') ?>">
                </div>
                <div class="col-12 form-check ps-4">
                    <input type="checkbox" name="ativo" class="form-check-input" id="ativo" <?= ($aluno['ativo'] ?? 1) ? 'checked' : '' ?> <?= $podeAtivarAluno ? '' : 'disabled title="Seu perfil não pode ativar/desativar alunos"' ?>>
                    <label class="form-check-label" for="ativo">Aluno ativo</label>
                </div>
            </div>
            </div>
        </form>

        <?php if ($id): ?>
        <div class="secao">
            <div class="secao-titulo"><i class="bi bi-globe2"></i> Acesso ao portal do aluno</div>
            <?php $senhaCriada = $_SESSION['senha_provisoria_criada'] ?? null; unset($_SESSION['senha_provisoria_criada']); ?>
            <?php if ($senhaCriada && (int) $senhaCriada['aluno_id'] === (int) $id): ?>
                <div class="alert alert-success">
                    <div class="fw-semibold mb-1"><i class="bi bi-check-circle"></i> Acesso criado! Passe estes dados para o aluno (a senha só aparece agora):</div>
                    <div>Endereço: <strong><?= e(urlAbsoluta('/portal/entrar.php')) ?></strong></div>
                    <div>CPF: <strong><?= e($senhaCriada['cpf']) ?></strong> · Senha provisória: <strong class="font-monospace fs-5" id="senha-criada"><?= e($senhaCriada['senha']) ?></strong>
                        <button type="button" class="btn btn-sm btn-outline-success ms-1" onclick="navigator.clipboard.writeText(<?= e(json_encode('Portal do aluno: ' . urlAbsoluta('/portal/entrar.php') . ' — CPF: ' . $senhaCriada['cpf'] . ' — senha provisória: ' . $senhaCriada['senha'] . ' (no primeiro acesso você cria a sua)')) ?>); this.textContent = 'Copiado!';">Copiar mensagem</button>
                    </div>
                    <div class="small mt-1">No primeiro acesso o portal pede para ele criar a própria senha.</div>
                </div>
            <?php endif; ?>
            <div class="d-flex flex-wrap align-items-center gap-3">
                <div>
                    <?php if ($aluno['senha_hash'] && $aluno['senha_provisoria']): ?>
                        <span class="badge text-bg-warning">Senha provisória</span>
                        <span class="small text-muted">o aluno vai criar a dele no primeiro acesso<?= $aluno['portal_ultimo_acesso'] ? ' · último acesso em ' . formatDateTime($aluno['portal_ultimo_acesso']) : '' ?></span>
                    <?php elseif ($aluno['senha_hash']): ?>
                        <span class="badge text-bg-success">Acesso criado</span>
                        <span class="small text-muted"><?= $aluno['portal_ultimo_acesso'] ? 'último acesso em ' . formatDateTime($aluno['portal_ultimo_acesso']) : 'ainda não entrou' ?></span>
                    <?php else: ?>
                        <span class="badge text-bg-secondary">Sem senha</span>
                        <span class="small text-muted">O aluno entra com o CPF e uma senha: crie uma provisória aqui ou mande o convite por e-mail.</span>
                    <?php endif; ?>
                </div>
                <?php if ($podeEditarAluno): ?>
                <button type="button" class="btn btn-sm btn-outline-secondary ms-auto" data-bs-toggle="modal" data-bs-target="#modal-senha-portal" <?= !validarCpf((string) $aluno['cpf']) ? 'disabled title="Precisa de CPF válido no cadastro"' : '' ?>>
                    <i class="bi bi-key"></i> <?= $aluno['senha_hash'] ? 'Nova senha provisória' : 'Criar acesso com senha provisória' ?>
                </button>
                <form method="post" action="/alunos/convite_portal.php" onsubmit="return confirm('Enviar para <?= e($aluno['email'] ?: '') ?> o link para <?= $aluno['senha_hash'] ? 'trocar' : 'criar' ?> a senha do portal?');">
                    <input type="hidden" name="id" value="<?= (int) $id ?>">
                    <button type="submit" class="btn btn-sm btn-outline-secondary" <?= !$aluno['email'] || !validarCpf((string) $aluno['cpf']) ? 'disabled title="Precisa de CPF válido e e-mail no cadastro"' : '' ?>>
                        <i class="bi bi-envelope"></i> <?= $aluno['senha_hash'] ? 'Enviar link para trocar a senha' : 'Enviar convite por e-mail' ?>
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>

        <?php endif; ?>
</div>

<?php if ($id && $podeEditarAluno): ?>
<div class="modal fade" id="modal-senha-portal" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" method="post" action="/alunos/senha_portal.php">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-key"></i> Senha provisória do portal</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="id" value="<?= (int) $id ?>">
                <p class="small text-muted">O aluno entra no portal com o CPF e esta senha. No primeiro acesso, o portal pede para ele criar a própria senha.<?= $aluno['senha_hash'] ? ' A senha atual dele deixa de valer.' : '' ?></p>
                <label class="form-label">Senha provisória</label>
                <input type="text" name="senha" class="form-control font-monospace" minlength="6" maxlength="40" autocomplete="off" placeholder="Em branco: o sistema cria uma (ex.: kmrta482)">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-brand"><i class="bi bi-check-lg"></i> Criar senha</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($id): ?>
<div class="tab-pane" id="pane-matriculas" style="<?= $tabAtiva === 'matriculas' ? '' : 'display:none;' ?>">
    <div class="secao">
        <div class="secao-titulo">
            <i class="bi bi-mortarboard"></i> Matrículas
            <?php if (usuarioPodeGerenciarMatriculas() && $aluno['ativo']): ?>
                <a href="/matriculas/form.php?aluno_id=<?= (int) $id ?>" class="btn btn-sm btn-brand ms-auto"><i class="bi bi-plus-lg"></i> Matricular em turma</a>
            <?php endif; ?>
        </div>
    </div>
    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead><tr><th>Turma</th><th>Curso</th><th>Período</th><th class="text-end">Mensalidade</th><th>Matrícula</th><th>Situação</th><th>Documentos</th></tr></thead>
                <tbody>
                <?php if (!$matriculas): ?>
                    <tr><td colspan="7" class="text-center text-muted py-3">Este aluno ainda não está matriculado em nenhuma turma.</td></tr>
                <?php endif; ?>
                <?php foreach ($matriculas as $m): ?>
                    <tr class="cursor-pointer" onclick="window.location='/matriculas/visualizar.php?id=<?= (int) $m['id'] ?>'">
                        <td><?= e($m['turma_nome']) ?></td>
                        <td><?= e($m['curso_nome']) ?></td>
                        <td><?= e(periodoTurma($m)) ?></td>
                        <td class="text-end">
                            <?= formatMoney(valorMensalidadeComDesconto((float) $m['valor_mensalidade'], (float) $m['desconto_percentual'])) ?>
                            <?php if ((float) $m['desconto_percentual'] > 0): ?><div class="small text-muted"><?= formatPercent($m['desconto_percentual']) ?> de desconto</div><?php endif; ?>
                        </td>
                        <td>
                            <?= formatDate($m['data_matricula']) ?>
                            <div class="small text-muted text-nowrap" title="Quando foi lançada no sistema">
                                <?= formatDateTime($m['criado_em']) ?> · <?= $m['origem'] === 'portal' ? '<i class="bi bi-globe2"></i> pelo portal' : 'por ' . e($m['matriculado_por'] ?: '-') ?>
                            </div>
                        </td>
                        <td><span class="badge <?= matriculaStatusBadgeClass($m['status']) ?>"><?= matriculaStatusLabel($m['status']) ?></span></td>
                        <td onclick="event.stopPropagation();">
                            <?php $docsMatricula = in_array($m['status'], ['ativa', 'concluida'], true) ? documentosMatricula($pdo, (int) $m['id']) : []; ?>
                            <?php if ($docsMatricula): ?>
                                <a href="/matriculas/visualizar.php?id=<?= (int) $m['id'] ?>#documentos" class="small text-decoration-none" title="<?= e(implode("\n", array_map(fn ($d) => $d['nome'] . ': ' . $d['texto'], $docsMatricula))) ?>">
                                    <i class="bi bi-award"></i> <?= count(array_filter($docsMatricula, fn ($d) => $d['situacao'] === 'liberado')) ?>/<?= count($docsMatricula) ?> liberado(s)
                                </a>
                                <?php if ($pendentes = array_filter($docsMatricula, fn ($d) => $d['situacao'] === 'pendente')): ?><div class="small text-danger"><?= e(reset($pendentes)['texto']) ?></div><?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if ($id): ?>
<div class="tab-pane" id="pane-documentos" style="<?= $tabAtiva === 'documentos' ? '' : 'display:none;' ?>">
    <?php $statusDocumento = statusDocumentoAluno(); $podeAvaliarDocumento = usuarioTemPermissao('avaliar_documentos'); ?>
    <?php if (!$documentosPorCurso): ?>
        <p class="text-muted small">Nenhum curso deste aluno pede documentos (isso se define no cadastro do curso › Documentos obrigatórios).</p>
    <?php endif; ?>
    <?php foreach ($documentosPorCurso as $grupoDoc): ?>
        <div class="secao">
            <div class="secao-titulo"><i class="bi bi-folder2-open"></i> <?= e($grupoDoc['curso']['nome']) ?></div>
            <div class="card">
                <div class="table-responsive">
                    <table class="table table-sm mb-0 align-middle">
                        <thead><tr><th>Documento</th><th>Situação</th><th>Arquivo</th><th class="text-end">Ações</th></tr></thead>
                        <tbody>
                        <?php foreach ($grupoDoc['documentos'] as $d): [$corDoc, $rotuloDoc] = $statusDocumento[$d['status']]; ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?= e($d['nome']) ?></div>
                                    <div class="small text-muted"><?= e(textoTiposDocumento($d)) ?><?= $d['trava_certificado'] ? ' · <i class="bi bi-lock"></i> trava o certificado' : '' ?></div>
                                </td>
                                <td>
                                    <span class="badge text-bg-<?= $corDoc ?>"><?= e($rotuloDoc) ?></span>
                                    <?php if ($d['motivo']): ?><div class="small text-muted"><?= e($d['motivo']) ?></div><?php endif; ?>
                                    <?php if ($d['avaliado_em']): ?><div class="small text-muted"><?= e($d['avaliado_por']) ?> · <?= formatDateTime($d['avaliado_em']) ?></div><?php endif; ?>
                                </td>
                                <td class="small">
                                    <?php if ($d['arquivo']): ?>
                                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="verDocumento(<?= (int) $d['envio_id'] ?>, <?= $d['mime'] === 'application/pdf' ? 'true' : 'false' ?>, <?= e(json_encode($d['nome'])) ?>)"><i class="bi bi-eye"></i> Ver</button>
                                        <div class="text-muted mt-1"><?= $d['mime'] === 'application/pdf' ? 'PDF' : 'Imagem' ?> · <?= formatarTamanhoArquivo((int) $d['tamanho']) ?>
                                            <?php if ($d['tamanho_original'] > $d['tamanho']): ?><span title="Tamanho enviado: <?= formatarTamanhoArquivo((int) $d['tamanho_original']) ?>">(comprimido)</span><?php endif; ?>
                                            · <?= e($d['enviado_por']) ?> em <?= formatDateTime($d['enviado_em']) ?></div>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end text-nowrap">
                                    <?php $formAcao = fn (string $acao, string $html, string $extra = '') => '<form method="post" action="/alunos/documento_acao.php" class="d-inline"' . $extra . '><input type="hidden" name="aluno_id" value="' . (int) $id . '"><input type="hidden" name="curso_documento_id" value="' . (int) $d['id'] . '"><input type="hidden" name="acao" value="' . $acao . '">' . $html . '</form>'; ?>
                                    <?php if ($podeAvaliarDocumento): ?>
                                        <?php if ($d['arquivo'] && $d['status'] !== 'aprovado'): ?><?= $formAcao('aprovar', '<button class="btn btn-sm btn-outline-success" title="Aprovar"><i class="bi bi-check-lg"></i></button>') ?><?php endif; ?>
                                        <?php if ($d['arquivo'] && $d['status'] !== 'recusado'): ?><?= $formAcao('recusar', '<input type="hidden" name="motivo"><button class="btn btn-sm btn-outline-danger" title="Recusar"><i class="bi bi-x-lg"></i></button>', ' onsubmit="const m = prompt(\'Motivo da recusa (o aluno vê no portal):\'); if (!m) return false; this.motivo.value = m;"') ?><?php endif; ?>
                                        <?php if (!in_array($d['status'], ['dispensado', 'aprovado'], true)): ?><?= $formAcao('dispensar', '<input type="hidden" name="motivo"><button class="btn btn-sm btn-outline-info" title="Dispensar (não precisa enviar)"><i class="bi bi-slash-circle"></i></button>', ' onsubmit="const m = prompt(\'Dispensar este documento? Motivo (opcional):\', \'\'); if (m === null) return false; this.motivo.value = m;"') ?><?php endif; ?>
                                        <?php if (in_array($d['status'], ['aprovado', 'recusado', 'dispensado'], true)): ?><?= $formAcao('reabrir', '<button class="btn btn-sm btn-outline-secondary" title="Desfazer a avaliação"><i class="bi bi-arrow-counterclockwise"></i></button>', ' onsubmit="return confirm(\'Desfazer a avaliação deste documento?\');"') ?><?php endif; ?>
                                    <?php endif; ?>
                                    <?php if ($podeEditarAluno): ?>
                                        <?= $formAcao('enviar', '<label class="btn btn-sm btn-outline-secondary mb-0" title="Enviar o arquivo pelo aluno"><i class="bi bi-upload"></i><input type="file" name="arquivo" accept="' . e(acceptDocumento($d)) . '" class="d-none" onchange="this.form.submit()"></label>', ' enctype="multipart/form-data"') ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
    <?php $anexos = anexosAluno($pdo, (int) $id); ?>
    <div class="secao">
        <div class="secao-titulo"><i class="bi bi-paperclip"></i> Anexos da ficha</div>
        <?php if ($podeEditarAluno): ?>
            <form method="post" action="/alunos/anexo_acao.php" enctype="multipart/form-data" class="row g-2 align-items-end mb-3" onsubmit="this.querySelector('button').disabled = true;">
                <input type="hidden" name="aluno_id" value="<?= (int) $id ?>"><input type="hidden" name="acao" value="enviar">
                <div class="col-md-4"><label class="form-label small mb-1">Nome</label><input type="text" name="nome" class="form-control form-control-sm" maxlength="150" required placeholder="Ex.: Contrato assinado"></div>
                <div class="col-md-4"><label class="form-label small mb-1">Arquivo (PDF, JPG ou PNG)</label><input type="file" name="arquivo" class="form-control form-control-sm" required accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"></div>
                <div class="col-md-2 form-check form-switch ms-2 mb-1"><input type="checkbox" class="form-check-input" role="switch" name="visivel_aluno" id="anexo-visivel"><label class="form-check-label small" for="anexo-visivel">Liberar ao aluno</label></div>
                <div class="col-md-auto"><button type="submit" class="btn btn-sm btn-brand"><i class="bi bi-upload"></i> Anexar</button></div>
            </form>
        <?php endif; ?>
        <?php if (!$anexos): ?>
            <p class="text-muted small mb-0">Nenhum anexo. Guarde aqui contrato, declarações, comprovantes — e escolha se o aluno pode ver no portal.</p>
        <?php else: ?>
            <div class="card"><div class="table-responsive">
                <table class="table table-sm mb-0 align-middle">
                    <thead><tr><th>Anexo</th><th>Aluno vê no portal?</th><th>Guardado</th><th class="text-end">Ações</th></tr></thead>
                    <tbody>
                    <?php foreach ($anexos as $ax): ?>
                        <tr>
                            <td>
                                <button type="button" class="btn btn-link p-0 text-start" onclick="verDocumento('anexo=<?= (int) $ax['id'] ?>', <?= $ax['mime'] === 'application/pdf' ? 'true' : 'false' ?>, <?= e(json_encode($ax['nome'])) ?>)"><i class="bi bi-eye"></i> <?= e($ax['nome']) ?></button>
                                <div class="small text-muted"><?= $ax['mime'] === 'application/pdf' ? 'PDF' : 'Imagem' ?> · <?= formatarTamanhoArquivo((int) $ax['tamanho']) ?></div>
                            </td>
                            <td>
                                <?php if ($podeEditarAluno): ?>
                                    <form method="post" action="/alunos/anexo_acao.php" class="d-inline">
                                        <input type="hidden" name="aluno_id" value="<?= (int) $id ?>"><input type="hidden" name="acao" value="visivel"><input type="hidden" name="anexo_id" value="<?= (int) $ax['id'] ?>">
                                        <div class="form-check form-switch"><input type="checkbox" class="form-check-input" role="switch" <?= $ax['visivel_aluno'] ? 'checked' : '' ?> onchange="this.form.submit()"><span class="small"><?= $ax['visivel_aluno'] ? 'Liberado' : 'Só a secretaria' ?></span></div>
                                    </form>
                                <?php else: ?>
                                    <span class="small"><?= $ax['visivel_aluno'] ? 'Liberado' : 'Só a secretaria' ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="small text-muted"><?= e($ax['enviado_por']) ?> · <?= formatDateTime($ax['criado_em']) ?></td>
                            <td class="text-end">
                                <?php if ($podeEditarAluno): ?>
                                    <form method="post" action="/alunos/anexo_acao.php" class="d-inline" onsubmit="return confirm(<?= e(json_encode('Excluir o anexo "' . $ax['nome'] . '"? O arquivo é apagado.')) ?>);">
                                        <input type="hidden" name="aluno_id" value="<?= (int) $id ?>"><input type="hidden" name="acao" value="excluir"><input type="hidden" name="anexo_id" value="<?= (int) $ax['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Excluir"><i class="bi bi-trash"></i></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div></div>
        <?php endif; ?>
    </div>
</div>
<?php require __DIR__ . '/../../includes/modal_documento.php'; ?>

<?php if (usuarioPodeVerProtocolos()): ?>
<div class="tab-pane" id="pane-protocolos" style="<?= $tabAtiva === 'protocolos' ? '' : 'display:none;' ?>">
    <?php
    $stmt = $pdo->prepare(
        "SELECT p.id, p.numero, p.criado_em, p.atualizado_em, p.aguardando, p.data_referencia, pa.nome AS assunto_nome, ps.nome AS status_nome, ps.cor AS status_cor, ps.encerra,
                c.nome AS curso_nome, t.nome AS turma_nome, cm.nome AS modulo_nome
         FROM protocolos p JOIN protocolo_assuntos pa ON pa.id = p.assunto_id JOIN protocolo_status ps ON ps.id = p.status_id
         LEFT JOIN matriculas m ON m.id = p.matricula_id LEFT JOIN turmas t ON t.id = m.turma_id LEFT JOIN cursos c ON c.id = t.curso_id
         LEFT JOIN curso_modulos cm ON cm.id = p.curso_modulo_id
         WHERE p.aluno_id = ? ORDER BY ps.encerra, p.atualizado_em DESC"
    );
    $stmt->execute([(int) $id]);
    $protocolosAluno = $stmt->fetchAll();
    ?>
    <div class="secao">
        <div class="secao-titulo"><i class="bi bi-chat-left-text"></i> Protocolos do aluno
            <?php if (usuarioPodeAbrirProtocolo()): ?><span class="ms-auto"><a href="/protocolos/novo.php?aluno=<?= (int) $id ?>&de=aluno" class="btn btn-sm btn-brand"><i class="bi bi-plus-lg"></i> Abrir protocolo</a></span><?php endif; ?></div>
        <?php if (!$protocolosAluno): ?>
            <p class="text-muted mb-0">Nenhum protocolo. O aluno abre pelo portal (Meus protocolos), por exemplo para justificar uma falta ou pedir reposição — ou a secretaria abre por ele aqui.</p>
        <?php else: ?>
            <div class="card"><div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead><tr><th>Nº</th><th>Assunto</th><th>Turma / módulo</th><th>Aberto em</th><th>Atualizado</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($protocolosAluno as $pr): ?>
                        <tr class="cursor-pointer" onclick="location.href='/protocolos/ver.php?id=<?= (int) $pr['id'] ?>'">
                            <td class="fw-semibold"><?= numeroProtocolo($pr) ?></td>
                            <td><?= e($pr['assunto_nome']) ?><?php if ($pr['data_referencia']): ?><div class="small text-muted">data: <?= formatDate($pr['data_referencia']) ?></div><?php endif; ?></td>
                            <td class="small"><?= $pr['turma_nome'] ? e($pr['curso_nome'] . ' · ' . $pr['turma_nome']) . ($pr['modulo_nome'] ? '<div class="text-muted">' . e($pr['modulo_nome']) . '</div>' : '') : '<span class="text-muted">—</span>' ?></td>
                            <td class="small text-nowrap"><?= formatDateTime($pr['criado_em']) ?></td>
                            <td class="small text-nowrap"><?= formatDateTime($pr['atualizado_em']) ?></td>
                            <td><?= badgeStatusProtocolo($pr['status_nome'], $pr['status_cor']) ?><?php if (!$pr['encerra'] && $pr['aguardando'] === 'secretaria'): ?> <span class="badge text-bg-warning">Responder</span><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div></div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<div class="tab-pane" id="pane-historico" style="<?= $tabAtiva === 'historico' ? '' : 'display:none;' ?>">
        <?php $historicoAluno = historicoAluno($pdo, (int) $id, 200); ?>
        <div class="secao">
            <div class="secao-titulo"><i class="bi bi-clock-history"></i> Histórico do aluno</div>
            <?php if (!$historicoAluno): ?>
                <p class="text-muted mb-0">Nada registrado ainda.</p>
            <?php else: ?>
                <ul class="list-unstyled mb-0">
                    <?php foreach ($historicoAluno as $i => $h): ?>
                        <li class="d-flex gap-2 py-2 border-bottom <?= $i >= 10 ? 'd-none historico-aluno-mais' : '' ?>">
                            <i class="bi <?= e($h['icone']) ?> text-<?= e($h['cor']) ?>"></i>
                            <div class="flex-grow-1 lh-sm">
                                <div class="d-flex justify-content-between gap-2">
                                    <strong><?= e($h['titulo']) ?></strong>
                                    <span class="small text-muted text-nowrap"><?= formatDateTime($h['quando']) ?></span>
                                </div>
                                <div class="small text-muted"><?= $h['detalhe'] !== '' ? e($h['detalhe']) . ' · ' : '' ?>por <?= e($h['quem']) ?></div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php if (count($historicoAluno) > 10): ?>
                    <button type="button" class="btn btn-sm btn-outline-secondary mt-2" onclick="document.querySelectorAll('.historico-aluno-mais').forEach((li) => li.classList.remove('d-none')); this.remove();">
                        Ver tudo (<?= count($historicoAluno) ?>)
                    </button>
                <?php endif; ?>
            <?php endif; ?>
        </div>
</div>
<?php endif; ?>

<?php if ($podeVerFinanceiro): ?>
<div class="tab-pane" id="pane-financeiro" style="<?= $tabAtiva === 'financeiro' ? '' : 'display:none;' ?>">
    <?php if (usuarioPodeLancarReceber()): ?>
        <div class="d-flex justify-content-end mb-2">
            <a href="/financeiro/contas_receber/form.php?aluno_id=<?= (int) $id ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-plus-lg"></i> Lançar cobrança avulsa</a>
        </div>
    <?php endif; ?>
    <div class="row g-3 mb-3">
        <div class="col-6 col-md-4">
            <div class="card h-100"><div class="card-body">
                <div class="kpi-rotulo">Já pago</div>
                <div class="kpi-valor text-success"><?= formatMoney($resumo['pago']) ?></div>
            </div></div>
        </div>
        <div class="col-6 col-md-4">
            <div class="card h-100"><div class="card-body">
                <div class="kpi-rotulo">Em aberto</div>
                <div class="kpi-valor"><?= formatMoney($resumo['em_aberto']) ?></div>
            </div></div>
        </div>
        <div class="col-12 col-md-4">
            <div class="card h-100"><div class="card-body">
                <div class="kpi-rotulo">Em atraso</div>
                <div class="kpi-valor <?= $resumo['vencido'] > 0 ? 'estoque-baixo' : '' ?>">
                    <?= formatMoney($resumo['vencido']) ?>
                    <?php if ($resumo['parcelas_vencidas'] > 0): ?><span class="small">(<?= $resumo['parcelas_vencidas'] ?> parcela<?= $resumo['parcelas_vencidas'] > 1 ? 's' : '' ?>)</span><?php endif; ?>
                </div>
            </div></div>
        </div>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead><tr><th>Descrição</th><th>Vencimento</th><th class="text-end">Valor</th><th>Pago em</th><th class="text-end">Valor pago</th><th>Status</th><th class="text-end">Ações</th></tr></thead>
                <tbody>
                <?php if (!$parcelas): ?>
                    <tr><td colspan="7" class="text-center text-muted py-3">Nenhum lançamento financeiro para este aluno.</td></tr>
                <?php endif; ?>
                <?php foreach ($parcelas as $p): ?>
                    <?php $vencida = $p['status'] === 'pendente' && $p['data_vencimento'] < date('Y-m-d'); ?>
                    <tr class="cursor-pointer" onclick="abrirParcela(<?= (int) $p['id'] ?>)" title="Clique para ver o boleto, alterar e ver o histórico">
                        <td><?= e($p['descricao']) ?><?php if ($seloBanco = seloBancoParcela($pdo, (int) $p['id'])): ?><div class="mt-1"><?= $seloBanco ?></div><?php endif; ?></td>
                        <td class="<?= $vencida ? 'estoque-baixo' : '' ?>"><?= formatDate($p['data_vencimento']) ?></td>
                        <td class="text-end"><?= formatMoney($p['valor']) ?></td>
                        <td>
                            <?= $p['status'] === 'pago' ? formatDate($p['data_recebimento']) . ($p['forma_pagamento_nome'] ? ' · ' . e($p['forma_pagamento_nome']) : '') : '-' ?>
                            <?php if ($p['status'] === 'pendente' && $p['desconto_valor'] !== null): ?>
                        <div class="small <?= (float) $p['desconto_valor'] > 0 ? 'text-success' : 'text-muted' ?>"><?= (float) $p['desconto_valor'] > 0 ? '− ' . formatMoney($p['desconto_valor']) . ' até ' . formatDate($p['desconto_ate'] ?: $p['data_vencimento']) : 'sem desconto' ?></div>
                    <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <?= $p['status'] === 'pago' ? '<span class="text-success fw-semibold">' . formatMoney($p['valor_recebido'] ?? $p['valor']) . '</span>' : '-' ?>
                        </td>
                        <td>
                            <?php if ($vencida): ?>
                                <span class="badge text-bg-danger">Em atraso</span>
                            <?php else: ?>
                                <span class="badge <?= contaStatusBadgeClass($p['status']) ?>"><?= contaStatusLabel($p['status']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end text-nowrap" onclick="event.stopPropagation();">
                            <?php if ($p['status'] === 'pendente'): ?>
                                <?= botoesCobrancaParcela($pdo, $p) ?>
                            <?php endif; ?>
                            <?php if ($p['status'] === 'pendente' && usuarioPodeBaixarReceber()): ?>
                                <a href="/financeiro/contas_receber/baixar.php?id=<?= (int) $p['id'] ?>&voltar=aluno" class="btn btn-sm btn-outline-success" title="Dar baixa (receber)"><i class="bi bi-check-lg"></i></a>
                            <?php endif; ?>
                            <?php if ($p['status'] === 'pago'): ?><a href="/financeiro/contas_receber/recibo.php?id=<?= (int) $p['id'] ?>&voltar=aluno" target="_blank" class="btn btn-sm btn-outline-secondary" title="Imprimir recibo"><i class="bi bi-receipt"></i></a><?php endif; ?>
                            <?php if ($p['status'] === 'pago' && !$p['pago_pelo_banco'] && usuarioPodeBaixarReceber()): ?>
                                <form action="/financeiro/contas_receber/estornar.php" method="post" class="d-inline" onsubmit="return confirm(<?= e(json_encode('Estornar a baixa de ' . $p['descricao'] . '? A parcela volta a ficar em aberto, como se não tivesse sido paga.')) ?>);">
                                    <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                                    <input type="hidden" name="voltar" value="aluno">
                                    <button type="submit" class="btn btn-sm btn-outline-warning" title="Estornar baixa (lançada errado)"><i class="bi bi-arrow-counterclockwise"></i></button>
                                </form>
                            <?php elseif ($p['status'] === 'pago' && $p['pago_pelo_banco']): ?>
                                <span class="btn btn-sm acao-inativa" title="Pago por boleto/Pix pelo banco — não pode ser estornado"><i class="bi bi-bank"></i></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<?php if ($podeEditarAluno === false): ?>
<script>
// Sem permissão de editar: a ficha fica só para leitura (o servidor também
// recusa). Quem pode ativar/desativar continua com essa caixa liberada.
document.querySelectorAll('#form-aluno input, #form-aluno select, #form-aluno textarea, #form-aluno button').forEach((el) => {
    if (el.id !== 'ativo') el.disabled = true;
});
</script>
<?php endif; ?>
<script>
let proximoIndiceEndereco = <?= count($enderecos) ?>;

function adicionarEndereco() {
    const template = document.getElementById('template-endereco');
    const html = template.innerHTML.replace(/__INDICE__/g, proximoIndiceEndereco);
    const div = document.createElement('div');
    div.innerHTML = html;
    document.getElementById('enderecos-lista').appendChild(div.firstElementChild);
    proximoIndiceEndereco++;
}

// CEP: com 8 dígitos, busca o endereço (ViaCEP) e preenche rua, bairro,
// cidade e UF do mesmo cartão; o número fica para digitar. Vale também para
// os endereços adicionados depois (o evento é escutado na lista toda).
document.getElementById('enderecos-lista')?.addEventListener('input', (ev) => {
    const campo = ev.target.closest('.campo-cep');
    if (!campo) return;
    const digitos = campo.value.replace(/\D/g, '');
    if (digitos.length === 8 && !campo.value.includes('-')) {
        campo.value = digitos.slice(0, 5) + '-' + digitos.slice(5);
    }
    const cartao = campo.closest('.endereco-card');
    const status = cartao.querySelector('.cep-status');
    if (digitos.length !== 8 || campo.dataset.buscado === digitos) return;
    campo.dataset.buscado = digitos;
    status.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
    fetch('https://viacep.com.br/ws/' + digitos + '/json/')
        .then((r) => r.json())
        .then((d) => {
            if (d.erro) {
                status.innerHTML = '<i class="bi bi-x-circle text-danger" title="CEP não encontrado"></i>';
                return;
            }
            const preencher = (nome, valor) => {
                const el = cartao.querySelector('[name^="endereco_' + nome + '["]');
                if (el && valor) el.value = valor;
            };
            preencher('logradouro', d.logradouro);
            preencher('bairro', d.bairro);
            preencher('cidade', d.localidade);
            preencher('uf', d.uf);
            const complemento = cartao.querySelector('[name^="endereco_complemento["]');
            if (complemento && !complemento.value && d.complemento) complemento.value = d.complemento;
            status.innerHTML = '<i class="bi bi-check-circle text-success" title="Endereço encontrado"></i>';
            cartao.querySelector('[name^="endereco_numero["]')?.focus();
        })
        .catch(() => { status.innerHTML = '<i class="bi bi-wifi-off text-muted" title="Sem conexão para buscar o CEP — preencha à mão"></i>'; });
});

function adicionarTelefone() {
    const container = document.getElementById('telefones-extras');
    const div = document.createElement('div');
    div.className = 'input-group mb-1';
    div.innerHTML = `
        <input type="tel" name="telefones_extra[]" class="form-control" data-mascara="telefone">
        <button type="button" class="btn btn-outline-danger" onclick="this.closest('.input-group').remove()"><i class="bi bi-trash"></i></button>
    `;
    container.appendChild(div);
}

document.querySelectorAll('[data-tab]').forEach(function (link) {
    link.addEventListener('click', function (ev) {
        ev.preventDefault();
        document.querySelectorAll('[data-tab]').forEach(l => l.classList.remove('active'));
        document.querySelectorAll('.tab-pane').forEach(p => p.style.display = 'none');
        this.classList.add('active');
        document.getElementById('pane-' + this.dataset.tab).style.display = '';
        history.replaceState(null, '', '?id=<?= (int) ($id ?? 0) ?>&tab=' + this.dataset.tab);
    });
});
</script>

<?php require __DIR__ . '/../../includes/modal_pix.php'; ?>
<?php $voltarParcela = 'aluno'; require __DIR__ . '/../../includes/modal_parcela.php'; ?>
<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
