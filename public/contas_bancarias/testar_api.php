<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/contas_bancarias/listar.php');
}

$pdo = getDB();
$id = (int) ($_POST['id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM contas_bancarias WHERE id = ?');
$stmt->execute([$id]);
$conta = $stmt->fetch();
if (!$conta) {
    flash('danger', 'Conta bancária não encontrada.');
    redirect('/contas_bancarias/listar.php');
}

// Sempre pede um token novo: o objetivo é provar que as credenciais e o
// certificado funcionam agora, não que existe um token antigo no cache.
$resultado = obterTokenItau($pdo, $conta, true);

$stmt = $pdo->prepare('UPDATE contas_bancarias SET api_ultimo_teste_em = NOW(), api_ultimo_teste_ok = ?, api_ultimo_teste_mensagem = ? WHERE id = ?');
$stmt->execute([$resultado['ok'] ? 1 : 0, mb_strimwidth($resultado['mensagem'], 0, 255, '…', 'UTF-8'), $id]);

registrarAtividade('configuracoes', 'edicao', 'Teste da API do banco (' . $conta['apelido'] . '): ' . ($resultado['ok'] ? 'sucesso' : 'falha'));

flash($resultado['ok'] ? 'success' : 'danger', ($resultado['ok'] ? 'Conexão OK! ' : 'A conexão falhou. ') . $resultado['mensagem']);
redirect('/contas_bancarias/form.php?id=' . $id);
