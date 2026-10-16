<?php
// Aviso de pagamento de boleto (baixa operacional / efetiva) vindo do Itaú.
// Responde 200 sempre que o aviso foi guardado — mesmo que não ache a
// cobrança: reenviar não mudaria nada, e o evento fica na lista da conta
// bancária para conferência. Só falha nossa (exceção) devolve 500.
require_once __DIR__ . '/../../../includes/bootstrap_webhook.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    responderJsonWebhook(405, ['erro' => 'use_post']);
}

$pdo = getDB();

$authorization = cabecalhoAuthorization();
$conta = stripos($authorization, 'Bearer ') === 0 ? contaPorTokenWebhook($pdo, trim(substr($authorization, 7))) : null;
if (!$conta) {
    header('WWW-Authenticate: Bearer error="invalid_token"');
    responderJsonWebhook(401, ['erro' => 'token_invalido']);
}
definirContaRequisicaoWebhook((int) $conta['id']);

$bruto = (string) file_get_contents('php://input');
$corpo = json_decode($bruto, true);

$stmt = $pdo->prepare('INSERT INTO webhook_eventos (conta_bancaria_id, requisicao_id, payload, ip) VALUES (?, ?, ?, ?)');
$registrar = function (string $payload) use ($stmt, $pdo, $conta): int {
    $stmt->execute([$conta['id'], idRequisicaoWebhook(), $payload, enderecoIpAtual()]);
    return (int) $pdo->lastInsertId();
};

if (!is_array($corpo)) {
    $eventoId = $registrar($bruto);
    $pdo->prepare("UPDATE webhook_eventos SET status = 'erro', mensagem = 'Corpo não é JSON.' WHERE id = ?")->execute([$eventoId]);
    responderJsonWebhook(400, ['erro' => 'json_invalido']);
}

$resultados = [];
foreach (separarNotificacoes($corpo) as $aviso) {
    $eventoId = $registrar(json_encode($aviso, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $resultados[] = is_array($aviso)
        ? processarNotificacaoBoletoItau($pdo, $conta, $aviso, $eventoId)['status']
        : 'erro';
}

responderJsonWebhook(200, ['recebido' => true, 'avisos' => count($resultados)]);
