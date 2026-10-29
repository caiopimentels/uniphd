<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirPermissaoAcao('gerenciar_materiais', 'Seu perfil não envia materiais.');

// Exclui um material e apaga o arquivo (servidor ou FTP). JSON.
$pdo = getDB();
$stmt = $pdo->prepare('SELECT * FROM materiais WHERE id = ?');
$stmt->execute([(int) ($_POST['id'] ?? 0)]);
$material = $stmt->fetch();
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$material || !podeEditarMaterial($pdo, $material)) {
    responderJson(['sucesso' => false, 'mensagem' => 'Você não pode excluir este material.']);
}
$pdo->prepare('DELETE FROM materiais WHERE id = ?')->execute([$material['id']]);
apagarArquivoMaterial($pdo, $material);
registrarAtividade('materiais', 'exclusao', 'Material "' . $material['titulo'] . '" excluído');
responderJson(['sucesso' => true]);
