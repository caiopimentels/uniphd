<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();
exigirCupons();

// Só exclui cupom que nunca foi usado; usado, desative (o histórico fica).
$pdo = getDB();
$id = (int) ($_POST['id'] ?? 0);
$stmt = $pdo->prepare('SELECT codigo FROM cupons WHERE id = ?');
$stmt->execute([$id]);
$codigo = $stmt->fetchColumn();
$stmt = $pdo->prepare('SELECT COUNT(*) FROM matriculas WHERE cupom_id = ?');
$stmt->execute([$id]);
if ($codigo === false) {
    flash('danger', 'Cupom não encontrado.');
} elseif ((int) $stmt->fetchColumn() > 0) {
    flash('warning', 'O cupom ' . $codigo . ' já foi usado em matrícula — desative em vez de excluir.');
} else {
    $pdo->prepare('DELETE FROM cupons WHERE id = ?')->execute([$id]);
    registrarAtividade('cupons', 'exclusao', 'Cupom excluído: ' . $codigo);
    flash('success', 'Cupom ' . $codigo . ' excluído.');
}
redirect('/cupons/listar.php');
