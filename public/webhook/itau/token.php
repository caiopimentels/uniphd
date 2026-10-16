<?php
// Endpoint OAuth 2.0 (client_credentials) que o Itaú chama antes de cada
// aviso de pagamento. Aceita as credenciais no corpo (form ou JSON) ou em
// HTTP Basic, que são as duas formas previstas no padrão.
require_once __DIR__ . '/../../../includes/bootstrap_webhook.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    responderJsonWebhook(405, ['error' => 'invalid_request', 'error_description' => 'Use POST.']);
}

$pdo = getDB();

if (limiteDeTentativasExcedido($pdo, 'webhook_token', enderecoIpAtual(), 20, 20, 15)) {
    responderJsonWebhook(429, ['error' => 'slow_down']);
}

$corpo = $_POST;
if (!$corpo) {
    $json = json_decode((string) file_get_contents('php://input'), true);
    $corpo = is_array($json) ? $json : [];
}

$clientId = (string) ($corpo['client_id'] ?? '');
$clientSecret = (string) ($corpo['client_secret'] ?? '');
$authorization = cabecalhoAuthorization();
if ($clientId === '' && stripos($authorization, 'Basic ') === 0) {
    [$clientId, $clientSecret] = array_pad(explode(':', (string) base64_decode(substr($authorization, 6)), 2), 2, '');
    $clientId = urldecode($clientId);
    $clientSecret = urldecode($clientSecret);
}

if (($corpo['grant_type'] ?? 'client_credentials') !== 'client_credentials') {
    responderJsonWebhook(400, ['error' => 'unsupported_grant_type']);
}

$token = emitirTokenWebhook($pdo, $clientId, $clientSecret);
if (!$token) {
    registrarTentativaAcesso($pdo, 'webhook_token', enderecoIpAtual());
    header('WWW-Authenticate: Basic realm="webhook"');
    responderJsonWebhook(401, ['error' => 'invalid_client']);
}

definirContaRequisicaoWebhook($token['conta_bancaria_id']);
responderJsonWebhook(200, $token['resposta']);
