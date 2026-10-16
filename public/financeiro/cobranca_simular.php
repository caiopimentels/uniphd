<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirVerFinanceiro();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/financeiro/contas_receber/listar.php');
}

$pdo = getDB();
$id = (int) ($_POST['id'] ?? 0);

if (($_POST['modo'] ?? '') === 'exemplo') {
    $previa = previaCobrancaItau($pdo, $id);
    $resultado = $previa['conta']
        ? testarExemploSandboxItau($pdo, $previa['conta'])
        : ['ok' => false, 'status' => 0, 'mensagem' => 'Nenhuma conta bancária com integração.', 'requisicao' => null, 'resposta' => null];
    $descricao = 'Boleto de exemplo no sandbox do Itaú';
} else {
    $resultado = simularCobrancaItau($pdo, $id);
    $descricao = 'Simulação de cobrança no Itaú (parcela #' . $id . ')';
}

registrarAtividade('contas_receber', 'edicao', $descricao . ': ' . ($resultado['ok'] ? 'aceita' : 'recusada'));

responderJson($resultado);
