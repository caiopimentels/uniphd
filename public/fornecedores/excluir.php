<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();
exigirFornecedores();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/fornecedores/listar.php');
}

$id = (int) ($_POST['id'] ?? 0);
$pdo = getDB();
$stmt = $pdo->prepare('UPDATE fornecedores SET ativo = NOT ativo WHERE id = ?');
$stmt->execute([$id]);

$stmt = $pdo->prepare('SELECT nome, ativo FROM fornecedores WHERE id = ?');
$stmt->execute([$id]);
$fornecedor = $stmt->fetch();
if ($fornecedor) {
    registrarAtividade('fornecedores', 'edicao', 'Fornecedor ' . ($fornecedor['ativo'] ? 'ativado' : 'desativado') . ': ' . $fornecedor['nome']);
}

flash('success', 'Situação do fornecedor atualizada.');
redirect('/fornecedores/listar.php');
