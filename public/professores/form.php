<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();
exigirCursos();

$pdo = getDB();
$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
$professor = null;

if ($id) {
    $stmt = $pdo->prepare('SELECT * FROM professores WHERE id = ?');
    $stmt->execute([$id]);
    $professor = $stmt->fetch();
    if (!$professor) {
        flash('danger', 'Professor não encontrado.');
        redirect('/professores/listar.php');
    }
}

$erros = [];

// Sem a permissão, a ficha abre só para consulta.
$podeEditar = $id ? usuarioPodeEditarProfessor() : usuarioPodeGerenciarProfessores();
if (!$podeEditar && (!$id || $_SERVER['REQUEST_METHOD'] === 'POST')) {
    flash('danger', 'Seu perfil não pode cadastrar ou editar professores.');
    redirect($id ? '/professores/form.php?id=' . $id : '/professores/listar.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome = input('nome');
    $cpf = input('cpf');
    $formacao = input('formacao');
    $telefone = input('telefone');
    $email = input('email');
    $observacao = input('observacao');
    $ativo = isset($_POST['ativo']) ? 1 : 0;

    if ($nome === '') {
        $erros[] = 'O nome é obrigatório.';
    }
    if ($cpf !== '' && !validarCpf($cpf)) {
        $erros[] = 'O CPF informado é inválido.';
    }

    if (!$erros) {
        $campos = [$nome, $cpf ?: null, $formacao ?: null, $telefone ?: null, $email ?: null, $observacao ?: null, $ativo];
        if ($id) {
            $stmt = $pdo->prepare('UPDATE professores SET nome=?, cpf=?, formacao=?, telefone=?, email=?, observacao=?, ativo=? WHERE id=?');
            $stmt->execute([...$campos, $id]);
            registrarAtividade('professores', 'edicao', 'Professor editado: ' . $nome);
        } else {
            $stmt = $pdo->prepare('INSERT INTO professores (nome, cpf, formacao, telefone, email, observacao, ativo) VALUES (?,?,?,?,?,?,?)');
            $stmt->execute($campos);
            $id = (int) $pdo->lastInsertId();
            registrarAtividade('professores', 'criacao', 'Professor cadastrado: ' . $nome);
        }
        flash('success', 'Professor salvo com sucesso.');
        redirect('/professores/form.php?id=' . $id);
    }

    $professor = [
        'nome' => $nome, 'cpf' => $cpf, 'formacao' => $formacao, 'telefone' => $telefone,
        'email' => $email, 'observacao' => $observacao, 'ativo' => $ativo,
    ];
}

$turmas = [];
if ($id) {
    $stmt = $pdo->prepare(
        "SELECT t.*, c.nome AS curso_nome, s.nome AS situacao_nome, s.cor AS situacao_cor,
                (SELECT COUNT(*) FROM matriculas m WHERE m.turma_id = t.id AND m.status = 'ativa') AS alunos_ativos
         FROM turmas t
         JOIN cursos c ON c.id = t.curso_id
         JOIN situacoes_turma s ON s.id = t.situacao_id
         WHERE t.professor_id = ?
         ORDER BY t.data_inicio DESC"
    );
    $stmt->execute([$id]);
    $turmas = $stmt->fetchAll();
}

$pageTitle = $id ? e($professor['nome']) : 'Novo professor';
$pageBreadcrumb = 'Acadêmico · Professores';
$activeMenu = 'professores';
$pageAction = $podeEditar
    ? '<a href="/professores/listar.php" class="btn btn-outline-secondary">Cancelar</a>'
        . '<button type="submit" form="form-principal" class="btn btn-brand"><i class="bi bi-check-lg"></i> Salvar</button>'
    : '<a href="/professores/listar.php" class="btn btn-outline-secondary">Voltar</a>';
require __DIR__ . '/../../includes/layout_header.php';
?>

<?php if ($erros): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($erros as $erro): ?><li><?= e($erro) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="secao" style="max-width: 760px;">
    <div class="secao-titulo"><i class="bi bi-person-badge"></i> Dados do professor</div>
    <form method="post" id="form-principal">
        <div class="row g-3">
            <div class="col-md-8">
                <label class="form-label">Nome *</label>
                <input type="text" name="nome" class="form-control" required value="<?= e($professor['nome'] ?? '') ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">CPF</label>
                <input type="text" name="cpf" class="form-control" value="<?= e($professor['cpf'] ?? '') ?>"
                       onblur="validarCampoDocumento(this)" oninput="this.classList.remove('is-invalid')">
                <div class="invalid-feedback">CPF inválido.</div>
            </div>
            <div class="col-md-12">
                <label class="form-label">Formação / titulação</label>
                <input type="text" name="formacao" class="form-control" placeholder="Ex: Mestre em Educação" value="<?= e($professor['formacao'] ?? '') ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">Telefone</label>
                <input type="text" name="telefone" class="form-control" value="<?= e($professor['telefone'] ?? '') ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">E-mail</label>
                <input type="email" name="email" class="form-control" value="<?= e($professor['email'] ?? '') ?>">
            </div>
            <div class="col-12">
                <label class="form-label">Observação</label>
                <input type="text" name="observacao" class="form-control" maxlength="255" value="<?= e($professor['observacao'] ?? '') ?>">
            </div>
            <div class="col-12 form-check ps-4">
                <input type="checkbox" name="ativo" class="form-check-input" id="ativo" <?= ($professor['ativo'] ?? 1) ? 'checked' : '' ?>>
                <label class="form-check-label" for="ativo">Professor ativo</label>
            </div>
        </div>
    </form>
</div>

<?php if ($id): ?>
<div class="secao">
    <div class="secao-titulo"><i class="bi bi-people"></i> Turmas deste professor</div>
    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead><tr><th>Turma</th><th>Curso</th><th>Período</th><th class="text-center">Alunos</th><th>Situação</th></tr></thead>
                <tbody>
                <?php if (!$turmas): ?>
                    <tr><td colspan="5" class="text-center text-muted py-3">Nenhuma turma atribuída a este professor.</td></tr>
                <?php endif; ?>
                <?php foreach ($turmas as $t): ?>
                    <tr class="cursor-pointer" onclick="window.location='/turmas/visualizar.php?id=<?= (int) $t['id'] ?>'">
                        <td><?= e($t['nome']) ?></td>
                        <td><?= e($t['curso_nome']) ?></td>
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

<?php if (!$podeEditar): ?>
<script>
// Só consulta: trava os campos (o servidor também recusa o envio).
document.querySelectorAll('#form-principal input, #form-principal select, #form-principal textarea, #form-principal button').forEach((el) => { el.disabled = true; });
</script>
<?php endif; ?>
<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
