<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirInadimplencia();

$pdo = getDB();
$id = (int) ($_GET['id'] ?? 0);
$dados = dadosBoletoParcela($pdo, $id);
if (!$dados) {
    flash('danger', 'Parcela não encontrada.');
    redirect('/financeiro/contas_receber/listar.php');
}

require __DIR__ . '/../../includes/boleto_render.php';
