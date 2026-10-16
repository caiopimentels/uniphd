<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();
exigirFormasPagamento();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/formas_pagamento/listar.php');
}

$id = (int) ($_POST['id'] ?? 0);
$pdo = getDB();
$stmt = $pdo->prepare('UPDATE formas_pagamento SET ativo = NOT ativo WHERE id = ?');
$stmt->execute([$id]);

$stmt = $pdo->prepare('SELECT nome, ativo FROM formas_pagamento WHERE id = ?');
$stmt->execute([$id]);
$forma = $stmt->fetch();
if ($forma) {
    registrarAtividade('formas_pagamento', 'edicao', 'Forma de pagamento ' . ($forma['ativo'] ? 'ativada' : 'desativada') . ': ' . $forma['nome']);
}

flash('success', 'Situação da forma de pagamento atualizada.');
redirect('/formas_pagamento/listar.php');
