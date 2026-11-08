<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();

// A equipe/professor baixa o álbum inteiro (?id=turma) ou as fotos
// marcadas (&fotos=1,2,3) num .zip.
$pdo = getDB();
$turmaId = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT nome FROM turmas WHERE id = ?');
$stmt->execute([$turmaId]);
$nome = $stmt->fetchColumn();
if (!$nome || !podeAlbumTurma($pdo, $turmaId)) {
    http_response_code(404);
    exit('Turma não encontrada.');
}
$fotos = fotosDaTurma($pdo, $turmaId);
$marcadas = array_filter(array_map('intval', explode(',', (string) ($_GET['fotos'] ?? ''))));
if ($marcadas) {
    $fotos = array_filter($fotos, fn ($f) => in_array((int) $f['id'], $marcadas, true));
}
enviarZipAlbum($pdo, $fotos, 'Álbum ' . $nome);
