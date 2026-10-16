<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirAluno();

$pdo = getDB();
$parcela = parcelaDoAluno($pdo, alunoAtualId(), (int) ($_GET['id'] ?? 0));
$dados = $parcela ? dadosBoletoParcela($pdo, (int) $parcela['id']) : null;
// O aluno só vê boleto registrado no banco — prévia não serve para pagar.
if (!$dados || !$dados['registrada']) {
    flash('warning', 'O boleto desta parcela ainda não foi gerado.');
    redirect('/portal/financeiro.php');
}
require __DIR__ . '/../../includes/boleto_render.php';
