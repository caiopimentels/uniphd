<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();

// Recebe UMA foto do álbum (a tela manda uma por vez, com a barra de
// progresso, para não estourar o limite de envio do servidor). JSON.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responderJson(['sucesso' => false]);
}
$pdo = getDB();
$turmaId = (int) ($_POST['turma_id'] ?? 0);
if (!$turmaId || !podeAlbumTurma($pdo, $turmaId)) {
    responderJson(['sucesso' => false, 'mensagem' => 'Você não pode mexer no álbum desta turma.']);
}
$ftp = ftpMateriais($pdo);
$arquivo = $_FILES['foto'] ?? null;
$nome = trim(preg_replace('/[\x00-\x1f\/\\\\:*?"<>|]+/', '_', basename((string) ($arquivo['name'] ?? '')))) ?: 'foto.jpg';
$erro = $arquivo['error'] ?? UPLOAD_ERR_NO_FILE;
if ($erro !== UPLOAD_ERR_OK) {
    responderJson(['sucesso' => false, 'mensagem' => '"' . $nome . '" não chegou ' . (in_array($erro, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? '(grande demais para o servidor).' : '(erro no envio).')]);
}
if ($arquivo['size'] > $ftp['max_mb'] * 1048576) {
    responderJson(['sucesso' => false, 'mensagem' => '"' . $nome . '" passa do limite de ' . $ftp['max_mb'] . ' MB.']);
}
$checagem = validarFotoAlbum($arquivo['tmp_name'], $nome);
if (!$checagem['ok']) {
    registrarAtividade('materiais', 'bloqueio', 'Foto recusada no álbum da turma #' . $turmaId . ': ' . $checagem['mensagem']);
    responderJson(['sucesso' => false, 'mensagem' => $checagem['mensagem']]);
}
$cota = cotaMateriais($pdo);
if ($arquivo['size'] > $cota['livre']) {
    responderJson(['sucesso' => false, 'mensagem' => 'Sem espaço: materiais e fotos já usam ' . tamanhoLegivel($cota['usado']) . ' de ' . tamanhoLegivel($cota['limite']) . '. Exclua arquivos antigos ou peça para aumentar o limite.']);
}
$r = guardarFotoAlbum($pdo, $turmaId, $arquivo['tmp_name'], $nome, (int) $arquivo['size'], (string) $checagem['tipo'], ($_POST['visivel'] ?? '1') === '1');
if (!$r['ok']) {
    responderJson(['sucesso' => false, 'mensagem' => $r['mensagem']]);
}
responderJson(['sucesso' => true, 'id' => $r['id']]);
