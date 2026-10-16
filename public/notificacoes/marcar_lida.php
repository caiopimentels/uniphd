<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Método inválido.']);
    exit;
}

$pdo = getDB();
$usuarioId = (int) usuarioAtualId();
$id = (int) ($_POST['id'] ?? 0);
$todas = isset($_POST['todas']);

try {
    if ($todas) {
        marcarTodasNotificacoesLidas($pdo, $usuarioId);
    } elseif ($id) {
        marcarNotificacaoLida($pdo, $usuarioId, $id);
    } else {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Nada a marcar.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        'sucesso' => true,
        'nao_lidas' => contarNotificacoesNaoLidas($pdo, $usuarioId),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('Erro ao marcar notificação como lida: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Não foi possível marcar como lida.'], JSON_UNESCAPED_UNICODE);
}
