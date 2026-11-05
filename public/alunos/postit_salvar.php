<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirPermissaoAcao('usar_postits', 'Seu perfil não pode criar post-its.');

// Cria ou altera um post-it do aluno (formulário do quadro de post-its, na
// ficha do aluno ou na matrícula) e volta para a tela de onde veio.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/alunos/listar.php');
}

$pdo = getDB();
$alunoId = (int) ($_POST['aluno_id'] ?? 0);
$matriculaId = (int) ($_POST['matricula_id'] ?? 0) ?: null;
$id = (int) ($_POST['id'] ?? 0) ?: null;

// Só volta para endereço do próprio sistema.
$voltar = (string) ($_POST['voltar'] ?? '');
if (!preg_match('#^/[^/\\\\]#', $voltar)) {
    $voltar = '/alunos/form.php?id=' . $alunoId;
}

if (!usuarioPodeVerAlunos() && !usuarioPodeVerCursos()) {
    flash('danger', 'Seu perfil não tem acesso aos alunos.');
    redirect('/sem_acesso.php');
}

$stmt = $pdo->prepare('SELECT nome FROM alunos WHERE id = ?');
$stmt->execute([$alunoId]);
$alunoNome = $stmt->fetchColumn();
if (!$alunoNome) {
    flash('danger', 'Aluno não encontrado.');
    redirect('/alunos/listar.php');
}
if ($matriculaId) {
    $stmt = $pdo->prepare('SELECT 1 FROM matriculas WHERE id = ? AND aluno_id = ?');
    $stmt->execute([$matriculaId, $alunoId]);
    if (!$stmt->fetchColumn()) {
        $matriculaId = null;
    }
}

if ($id) {
    $stmt = $pdo->prepare('SELECT * FROM aluno_postits WHERE id = ? AND aluno_id = ?');
    $stmt->execute([$id, $alunoId]);
    $postit = $stmt->fetch();
    if (!$postit) {
        flash('danger', 'Post-it não encontrado.');
        redirect($voltar);
    }
    if (!usuarioPodeAlterarPostit($postit)) {
        flash('danger', 'Só quem escreveu o post-it (ou o administrador) pode alterá-lo.');
        redirect($voltar);
    }
}

try {
    $id = salvarPostit($pdo, $alunoId, $matriculaId, $_POST, $id);
} catch (InvalidArgumentException $e) {
    flash('danger', $e->getMessage());
    redirect($voltar);
}

$resumo = resumoHistoricoPostit((string) $_POST['texto'], (string) ($_POST['visibilidade'] ?? 'todos'));
registrarAtividade('alunos', isset($postit) ? 'edicao' : 'cadastro', 'Post-it ' . (isset($postit) ? 'alterado' : 'criado') . ' em ' . $alunoNome);
registrarHistoricoAluno($pdo, $alunoId, 'postit', (isset($postit) ? 'Alterado: ' : 'Criado: ') . $resumo, $matriculaId);
flash('success', isset($postit) ? 'Post-it alterado.' : 'Post-it criado.');
redirect($voltar);
