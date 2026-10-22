<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();
exigirCupons();

// Ativa/desativa o cupom (desativado não vale mais em novas matrículas).
$pdo = getDB();
$stmt = $pdo->prepare('SELECT codigo, ativo FROM cupons WHERE id = ?');
$stmt->execute([(int) ($_POST['id'] ?? 0)]);
$cupom = $stmt->fetch();
if ($cupom) {
    $pdo->prepare('UPDATE cupons SET ativo = 1 - ativo WHERE id = ?')->execute([(int) $_POST['id']]);
    registrarAtividade('cupons', 'edicao', 'Cupom ' . $cupom['codigo'] . ($cupom['ativo'] ? ' desativado' : ' ativado'));
    flash('success', 'Cupom ' . $cupom['codigo'] . ($cupom['ativo'] ? ' desativado.' : ' ativado.'));
}
redirect(($_POST['voltar'] ?? '') === 'form' ? '/cupons/form.php?id=' . (int) ($_POST['id'] ?? 0) : '/cupons/listar.php');
