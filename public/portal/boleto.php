<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirAluno();

$pdo = getDB();
$parcela = parcelaDoAluno($pdo, alunoAtualId(), (int) ($_GET['id'] ?? 0));
// Parcela paga só por Pix (ou cartão): não tem boleto.
if ($parcela && !meiosCobrancaForma($pdo, $parcela['forma_pagamento_id'] ? (int) $parcela['forma_pagamento_id'] : null)['boleto']) {
    flash('info', 'Esta parcela não é paga por boleto.');
    redirect('/portal/pagar.php?id=' . (int) $parcela['id']);
}
// Sem boleto ainda: gera no banco agora (mesmo caminho do "Pagar").
$garantia = $parcela && $parcela['status'] === 'pendente' ? garantirCobrancaParcelaPortal($pdo, $parcela) : ['ok' => (bool) $parcela, 'mensagem' => ''];
$dados = $parcela ? dadosBoletoParcela($pdo, (int) $parcela['id']) : null;
// O aluno só vê boleto registrado no banco — prévia não serve para pagar.
if (!$dados || !$dados['registrada']) {
    flash('warning', $garantia['mensagem'] ?: 'O boleto desta parcela ainda não foi gerado.');
    redirect($parcela ? '/portal/pagar.php?id=' . (int) $parcela['id'] : '/portal/financeiro.php');
}
require __DIR__ . '/../../includes/boleto_render.php';
