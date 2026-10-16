<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    responderJson(['sucesso' => false, 'mensagem' => 'Método inválido.']);
}

$ids = array_values(array_filter(array_map('intval', $_POST['ids'] ?? [])));
if (!$ids) {
    responderJson(['sucesso' => false, 'mensagem' => 'Nenhuma ordem recebida.']);
}

$pdo = getDB();
$pdo->beginTransaction();
$stmt = $pdo->prepare('UPDATE situacoes_turma SET ordem = ? WHERE id = ?');
foreach ($ids as $posicao => $id) {
    $stmt->execute([$posicao + 1, $id]);
}
$pdo->commit();

responderJson(['sucesso' => true]);
