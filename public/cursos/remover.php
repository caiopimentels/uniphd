<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();
exigirPermissaoAcao('excluir_curso', 'Seu perfil não pode excluir cursos.');

// Exclui de vez — só se nenhuma turma usa este curso; com turma, o caminho
// é desativar (o histórico das turmas continua apontando para ele).
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/cursos/listar.php');
}

$pdo = getDB();
$id = (int) ($_POST['id'] ?? 0);
$stmt = $pdo->prepare('SELECT x.nome, (SELECT COUNT(*) FROM turmas WHERE curso_id = x.id) AS turmas FROM cursos x WHERE x.id = ?');
$stmt->execute([$id]);
$registro = $stmt->fetch();
if (!$registro) {
    flash('danger', 'Curso não encontrado.');
    redirect('/cursos/listar.php');
}
if ($registro['turmas']) {
    flash('danger', $registro['nome'] . ' está ligado a ' . (int) $registro['turmas'] . ' turma(s) e não pode ser excluído — desative em vez disso.');
    redirect('/cursos/listar.php');
}

$pdo->prepare('DELETE FROM cursos WHERE id = ?')->execute([$id]);
registrarAtividade('cursos', 'exclusao', 'Curso excluído: ' . $registro['nome']);
flash('success', $registro['nome'] . ' excluído.');
redirect('/cursos/listar.php');
