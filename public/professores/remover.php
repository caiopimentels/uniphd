<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();
exigirPermissaoAcao('excluir_professor', 'Seu perfil não pode excluir professores.');

// Exclui de vez — só se nenhuma turma usa este professor; com turma, o caminho
// é desativar (o histórico das turmas continua apontando para ele).
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/professores/listar.php');
}

$pdo = getDB();
$id = (int) ($_POST['id'] ?? 0);
$stmt = $pdo->prepare('SELECT x.nome, (SELECT COUNT(*) FROM turmas WHERE professor_id = x.id) AS turmas FROM professores x WHERE x.id = ?');
$stmt->execute([$id]);
$registro = $stmt->fetch();
if (!$registro) {
    flash('danger', 'Professor não encontrado.');
    redirect('/professores/listar.php');
}
if ($registro['turmas']) {
    flash('danger', $registro['nome'] . ' está ligado a ' . (int) $registro['turmas'] . ' turma(s) e não pode ser excluído — desative em vez disso.');
    redirect('/professores/listar.php');
}

$pdo->prepare('DELETE FROM professores WHERE id = ?')->execute([$id]);
registrarAtividade('professores', 'exclusao', 'Professor excluído: ' . $registro['nome']);
flash('success', $registro['nome'] . ' excluído.');
redirect('/professores/listar.php');
