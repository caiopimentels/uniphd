<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/contas_bancarias/listar.php');
}

$pdo = getDB();
$id = (int) ($_POST['id'] ?? 0);

$stmt = $pdo->prepare('SELECT apelido, ativo FROM contas_bancarias WHERE id = ?');
$stmt->execute([$id]);
$conta = $stmt->fetch();
if (!$conta) {
    flash('danger', 'Conta bancária não encontrada.');
    redirect('/contas_bancarias/listar.php');
}

// Desativar não apaga: cobranças já geradas continuam apontando para ela.
// Só tira a marca de padrão, para nenhuma cobrança nova cair numa conta
// desativada. "padrao" vem antes de "ativo" porque o MySQL avalia o SET da
// esquerda para a direita — invertido, o IF já leria o ativo novo.
$stmt = $pdo->prepare('UPDATE contas_bancarias SET padrao = IF(ativo = 1, 0, padrao), ativo = NOT ativo WHERE id = ?');
$stmt->execute([$id]);

registrarAtividade('configuracoes', 'edicao', 'Conta bancária ' . ($conta['ativo'] ? 'desativada' : 'ativada') . ': ' . $conta['apelido']);
flash('success', 'Conta ' . ($conta['ativo'] ? 'desativada' : 'ativada') . '.');
redirect('/contas_bancarias/listar.php');
