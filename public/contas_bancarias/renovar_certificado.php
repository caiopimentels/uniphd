<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirAdmin();

// "Gerar certificado novo no Itaú" da conta bancária: com o token temporário
// do Itaú (caminho do manual) ou tentando com a credencial atual.
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

$comToken = input('modo') === 'token';
$resultado = solicitarCertificadoItau($pdo, $conta, $comToken ? (string) ($_POST['token_temporario'] ?? '') : null);
if (!$resultado['ok']) {
    $pdo->prepare('UPDATE contas_bancarias SET api_certificado_renovacao_em = NOW(), api_certificado_renovacao_erro = ? WHERE id = ?')
        ->execute([mb_strimwidth($resultado['mensagem'], 0, 255, '…', 'UTF-8'), $id]);
}
registrarAtividade('configuracoes', 'edicao', 'Certificado do Itaú (' . $conta['apelido'] . ') — ' . ($comToken ? 'com token temporário' : 'com o access token') . ': ' . ($resultado['ok'] ? 'gerado' : 'falhou'));
flash($resultado['ok'] ? 'success' : 'danger', $resultado['mensagem']);
redirect('/contas_bancarias/form.php?id=' . $id . '#certificado');
