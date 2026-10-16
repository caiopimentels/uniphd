<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();
exigirCursos();

$pdo = getDB();
$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
$curso = null;

if ($id) {
    $stmt = $pdo->prepare('SELECT * FROM cursos WHERE id = ?');
    $stmt->execute([$id]);
    $curso = $stmt->fetch();
    if (!$curso) {
        flash('danger', 'Curso não encontrado.');
        redirect('/cursos/listar.php');
    }
}

$erros = [];

// Módulos (conteúdo programático), na ordem em que aparecem.
$modulos = [];
if ($id) {
    $stmt = $pdo->prepare('SELECT nome, carga_horaria FROM curso_modulos WHERE curso_id = ? ORDER BY ordem, id');
    $stmt->execute([$id]);
    $modulos = $stmt->fetchAll();
}

// Sem a permissão, a ficha abre só para consulta.
$podeEditar = $id ? usuarioPodeEditarCurso() : usuarioPodeCriarCurso();
if (!$podeEditar && (!$id || $_SERVER['REQUEST_METHOD'] === 'POST')) {
    flash('danger', 'Seu perfil não pode cadastrar ou editar cursos.');
    redirect($id ? '/cursos/form.php?id=' . $id : '/cursos/listar.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $modulos = [];
    foreach ((array) ($_POST['modulo_nome'] ?? []) as $i => $nomeModulo) {
        $nomeModulo = mb_substr(trim((string) $nomeModulo), 0, 150);
        $cargaModulo = (int) ($_POST['modulo_carga'][$i] ?? 0);
        if ($nomeModulo !== '') {
            $modulos[] = ['nome' => $nomeModulo, 'carga_horaria' => $cargaModulo > 0 ? $cargaModulo : null];
        }
    }
    $nome = input('nome');
    $cargaHoraria = (int) ($_POST['carga_horaria'] ?? 0);
    $descricao = input('descricao');
    $ativo = isset($_POST['ativo']) ? 1 : 0;
    $modeloId = (int) ($_POST['certificado_modelo_id'] ?? 0) ?: null;
    if ($modeloId && !buscarModeloCertificado($pdo, $modeloId)) {
        $modeloId = null;
    }

    if ($nome === '') {
        $erros[] = 'O nome do curso é obrigatório.';
    }
    if ($cargaHoraria < 0) {
        $erros[] = 'A carga horária não pode ser negativa.';
    }

    if (!$erros) {
        $pdo->beginTransaction();
        if ($id) {
            $stmt = $pdo->prepare('UPDATE cursos SET nome=?, carga_horaria=?, descricao=?, certificado_modelo_id=?, ativo=? WHERE id=?');
            $stmt->execute([$nome, $cargaHoraria ?: null, $descricao ?: null, $modeloId, $ativo, $id]);
            registrarAtividade('cursos', 'edicao', 'Curso editado: ' . $nome);
        } else {
            $stmt = $pdo->prepare('INSERT INTO cursos (nome, carga_horaria, descricao, certificado_modelo_id, ativo) VALUES (?,?,?,?,?)');
            $stmt->execute([$nome, $cargaHoraria ?: null, $descricao ?: null, $modeloId, $ativo]);
            $id = (int) $pdo->lastInsertId();
            registrarAtividade('cursos', 'criacao', 'Curso criado: ' . $nome);
        }
        $pdo->prepare('DELETE FROM curso_modulos WHERE curso_id = ?')->execute([$id]);
        $insere = $pdo->prepare('INSERT INTO curso_modulos (curso_id, nome, carga_horaria, ordem) VALUES (?, ?, ?, ?)');
        foreach ($modulos as $ordem => $modulo) {
            $insere->execute([$id, $modulo['nome'], $modulo['carga_horaria'], ($ordem + 1) * 10]);
        }
        $pdo->commit();
        flash('success', 'Curso salvo com sucesso.');
        redirect('/cursos/form.php?id=' . $id);
    }

    $curso = ['nome' => $nome, 'carga_horaria' => $cargaHoraria, 'descricao' => $descricao, 'certificado_modelo_id' => $modeloId, 'ativo' => $ativo];
}

$turmas = [];
if ($id) {
    $stmt = $pdo->prepare(
        "SELECT t.*, p.nome AS professor_nome, s.nome AS situacao_nome, s.cor AS situacao_cor,
                (SELECT COUNT(*) FROM matriculas m WHERE m.turma_id = t.id AND m.status = 'ativa') AS alunos_ativos
         FROM turmas t
         JOIN situacoes_turma s ON s.id = t.situacao_id
         LEFT JOIN professores p ON p.id = t.professor_id
         WHERE t.curso_id = ?
         ORDER BY t.data_inicio DESC"
    );
    $stmt->execute([$id]);
    $turmas = $stmt->fetchAll();
}

$pageTitle = $id ? e($curso['nome']) : 'Novo curso';
$pageBreadcrumb = 'Acadêmico · Cursos';
$activeMenu = 'cursos';
$pageAction = $podeEditar
    ? '<a href="/cursos/listar.php" class="btn btn-outline-secondary">Cancelar</a>'
        . '<button type="submit" form="form-principal" class="btn btn-brand"><i class="bi bi-check-lg"></i> Salvar</button>'
    : '<a href="/cursos/listar.php" class="btn btn-outline-secondary">Voltar</a>';
require __DIR__ . '/../../includes/layout_header.php';
?>

<?php if ($erros): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($erros as $erro): ?><li><?= e($erro) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="secao" style="max-width: 760px;">
    <div class="secao-titulo"><i class="bi bi-book"></i> Dados do curso</div>
    <form method="post" id="form-principal">
        <div class="row g-3">
            <div class="col-md-9">
                <label class="form-label">Nome do curso *</label>
                <input type="text" name="nome" class="form-control" required maxlength="150" value="<?= e($curso['nome'] ?? '') ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Carga horária (horas)</label>
                <input type="number" name="carga_horaria" class="form-control" min="0" value="<?= e((string) ($curso['carga_horaria'] ?? '')) ?>">
            </div>
            <div class="col-12">
                <label class="form-label">Descrição</label>
                <textarea name="descricao" class="form-control" rows="5"><?= e($curso['descricao'] ?? '') ?></textarea>
            </div>
            <div class="col-md-8">
                <label class="form-label">Modelo de certificado</label>
                <select name="certificado_modelo_id" class="form-select">
                    <?php foreach (modelosCertificado($pdo) as $modelo): ?>
                        <option value="<?= $modelo['padrao'] ? '' : (int) $modelo['id'] ?>" <?= ($modelo['padrao'] ? !($curso['certificado_modelo_id'] ?? null) : (int) ($curso['certificado_modelo_id'] ?? 0) === (int) $modelo['id']) ? 'selected' : '' ?>>
                            <?= e($modelo['nome']) ?><?= $modelo['padrao'] ? ' (padrão)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">O certificado que os alunos deste curso recebem ao concluir. <?php if (usuarioPodeGerenciarPortal()): ?><a href="/configuracoes/certificado.php" target="_blank">Ver / editar modelos</a><?php endif; ?></div>
            </div>
            <div class="col-12">
                <div class="d-flex align-items-center mb-2">
                    <label class="form-label mb-0 fw-semibold">Módulos do curso</label>
                    <span class="small text-muted ms-2" id="soma-modulos"></span>
                    <button type="button" class="btn btn-sm btn-outline-secondary ms-auto" onclick="adicionarModulo()"><i class="bi bi-plus-lg"></i> Módulo</button>
                </div>
                <div id="lista-modulos"></div>
                <div class="form-text">Aparecem na página do curso no portal e podem ir no certificado (elemento "Lista de módulos"). Use as setas para mudar a ordem.</div>
                <template id="modelo-modulo">
                    <div class="input-group input-group-sm mb-1 linha-modulo">
                        <span class="input-group-text numero"></span>
                        <input type="text" name="modulo_nome[]" class="form-control" maxlength="150" placeholder="Nome do módulo">
                        <input type="number" name="modulo_carga[]" class="form-control" min="0" style="max-width: 110px;" placeholder="Horas" oninput="somarModulos()">
                        <button type="button" class="btn btn-outline-secondary" onclick="moverModulo(this, -1)" title="Subir"><i class="bi bi-arrow-up"></i></button>
                        <button type="button" class="btn btn-outline-secondary" onclick="moverModulo(this, 1)" title="Descer"><i class="bi bi-arrow-down"></i></button>
                        <button type="button" class="btn btn-outline-danger" onclick="this.closest('.linha-modulo').remove(); numerarModulos();" title="Remover"><i class="bi bi-x-lg"></i></button>
                    </div>
                </template>
            </div>
            <div class="col-12 form-check ps-4">
                <input type="checkbox" name="ativo" class="form-check-input" id="ativo" <?= ($curso['ativo'] ?? 1) ? 'checked' : '' ?>>
                <label class="form-check-label" for="ativo">Curso ativo (disponível para abrir turmas)</label>
            </div>
        </div>
    </form>
</div>

<?php if ($id): ?>
<div class="secao">
    <div class="secao-titulo">
        <i class="bi bi-people"></i> Turmas deste curso
        <?php if ($curso['ativo']): ?>
            <?php if (usuarioPodeCriarTurma()): ?><a href="/turmas/form.php?curso_id=<?= (int) $id ?>" class="btn btn-sm btn-brand ms-auto"><i class="bi bi-plus-lg"></i> Nova turma</a><?php endif; ?>
        <?php endif; ?>
    </div>
    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead><tr><th>Turma</th><th>Professor</th><th>Período</th><th class="text-center">Alunos</th><th>Situação</th></tr></thead>
                <tbody>
                <?php if (!$turmas): ?>
                    <tr><td colspan="5" class="text-center text-muted py-3">Nenhuma turma aberta para este curso.</td></tr>
                <?php endif; ?>
                <?php foreach ($turmas as $t): ?>
                    <tr class="cursor-pointer" onclick="window.location='/turmas/visualizar.php?id=<?= (int) $t['id'] ?>'">
                        <td><?= e($t['nome']) ?></td>
                        <td><?= e($t['professor_nome'] ?: '-') ?></td>
                        <td><?= e(periodoTurma($t)) ?></td>
                        <td class="text-center"><?= (int) $t['alunos_ativos'] ?>/<?= (int) $t['vagas'] ?></td>
                        <td><?= badgeSituacaoTurma($t['situacao_nome'], $t['situacao_cor']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
function adicionarModulo(nome = '', carga = '') {
    const linha = document.getElementById('modelo-modulo').content.firstElementChild.cloneNode(true);
    linha.querySelector('[name="modulo_nome[]"]').value = nome;
    linha.querySelector('[name="modulo_carga[]"]').value = carga ?? '';
    document.getElementById('lista-modulos').appendChild(linha);
    numerarModulos();
    if (!nome) linha.querySelector('[name="modulo_nome[]"]').focus();
}
function moverModulo(botao, direcao) {
    const linha = botao.closest('.linha-modulo');
    const vizinha = direcao < 0 ? linha.previousElementSibling : linha.nextElementSibling;
    if (vizinha) direcao < 0 ? vizinha.before(linha) : vizinha.after(linha);
    numerarModulos();
}
function numerarModulos() {
    document.querySelectorAll('#lista-modulos .numero').forEach((el, i) => { el.textContent = i + 1; });
    somarModulos();
}
// Mostra a soma das horas dos módulos ao lado da carga horária do curso.
function somarModulos() {
    const soma = [...document.querySelectorAll('[name="modulo_carga[]"]')].reduce((t, c) => t + (parseInt(c.value, 10) || 0), 0);
    const total = parseInt(document.querySelector('[name="carga_horaria"]').value, 10) || 0;
    document.getElementById('soma-modulos').textContent = soma ? `soma: ${soma} h` + (total && soma !== total ? ` (carga do curso: ${total} h)` : '') : '';
}
<?php foreach ($modulos as $modulo): ?>
adicionarModulo(<?= json_encode($modulo['nome']) ?>, <?= json_encode($modulo['carga_horaria']) ?>);
<?php endforeach; ?>
document.querySelector('[name="carga_horaria"]').addEventListener('input', somarModulos);
</script>

<?php if (!$podeEditar): ?>
<script>
// Só consulta: trava os campos (o servidor também recusa o envio).
document.querySelectorAll('#form-principal input, #form-principal select, #form-principal textarea, #form-principal button').forEach((el) => { el.disabled = true; });
</script>
<?php endif; ?>
<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
