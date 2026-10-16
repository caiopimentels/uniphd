<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
exigirPermissaoAcao('excluir_pagar', 'Seu perfil não pode excluir contas a pagar.');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/financeiro/contas_pagar/listar.php');
}

$id = (int) ($_POST['id'] ?? 0);
$pdo = getDB();

$stmt = $pdo->prepare('SELECT * FROM contas_pagar WHERE id = ?');
$stmt->execute([$id]);
$conta = $stmt->fetch();

if (!$conta) {
    flash('danger', 'Lançamento não encontrado.');
    redirect('/financeiro/contas_pagar/listar.php');
}

$stmt = $pdo->prepare('DELETE FROM contas_pagar WHERE id = ?');
$stmt->execute([$id]);

registrarAtividade('contas_pagar', 'exclusao', 'Conta a pagar excluída: ' . $conta['descricao'] . ' - ' . formatMoney($conta['valor']));

flash('success', 'Lançamento excluído.');
redirect('/financeiro/contas_pagar/listar.php');
