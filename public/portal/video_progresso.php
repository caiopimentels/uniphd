<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

// Onde o aluno parou numa videoaula (JSON). Só aula que ele pode ver.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !alunoLogado()) {
    responderJson(['sucesso' => false]);
}
$pdo = getDB();
$codigo = preg_replace('/[^a-f0-9]/', '', (string) ($_POST['c'] ?? ''));
$stmt = $pdo->prepare('SELECT * FROM videoaulas WHERE codigo = ? AND visivel = 1 AND (liberar_em IS NULL OR liberar_em <= NOW())');
$stmt->execute([$codigo]);
$video = $stmt->fetch();
if (!$video || !matriculaComAcessoMaterial($pdo, (int) alunoAtualId(), $video)) {
    responderJson(['sucesso' => false]);
}
$r = registrarProgressoVideo($pdo, $video, (int) alunoAtualId(), (int) ($_POST['posicao'] ?? 0), (int) ($_POST['duracao'] ?? 0));
responderJson(['sucesso' => true, 'concluida' => $r['concluida']]);
