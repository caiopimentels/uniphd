<?php
// Aviso de Pix na hora (webhook Pix do Itaú, padrão do Banco Central). O
// endereço é cadastrado como .../pix.php e o Itaú chama .../pix.php/pix.
// Não há token: cada Pix avisado é confirmado na API do banco antes da baixa
// (processarAvisoPixItau), então um aviso falso não baixa nada.
// Responde 200 quando o aviso foi tratado; 500 só quando a confirmação no
// banco falhou por algo passageiro, para o Itaú reenviar.
require_once __DIR__ . '/../../../includes/bootstrap_webhook.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    responderJsonWebhook(405, ['erro' => 'use_post']);
}

$corpo = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($corpo)) {
    responderJsonWebhook(400, ['erro' => 'json_invalido']);
}

// Corpo sem "pix" (o banco testando o endereço no cadastro): só confirma.
$avisos = is_array($corpo['pix'] ?? null) ? $corpo['pix'] : [];
$pdo = getDB();
$repetir = false;
foreach ($avisos as $pix) {
    if (is_array($pix)) {
        $repetir = processarAvisoPixItau($pdo, $pix)['repetir'] || $repetir;
    }
}

responderJsonWebhook($repetir ? 500 : 200, ['recebido' => true, 'avisos' => count($avisos)]);
