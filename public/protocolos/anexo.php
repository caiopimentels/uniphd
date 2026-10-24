<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

// Entrega o anexo de uma mensagem de protocolo (?m=ID). Pode ver: quem tem
// a permissão de protocolos, ou o próprio aluno (com o código do protocolo
// na URL, e nunca o anexo de uma nota interna).
$pdo = getDB();
$stmt = $pdo->prepare('SELECT pm.*, p.aluno_id, p.codigo FROM protocolo_mensagens pm JOIN protocolos p ON p.id = pm.protocolo_id WHERE pm.id = ?');
$stmt->execute([(int) ($_GET['m'] ?? 0)]);
$mensagem = $stmt->fetch();

$podeVer = $mensagem && $mensagem['anexo'] && (
    (usuarioLogado() && usuarioPodeVerProtocolos())
    || (alunoLogado() && alunoAtualId() === (int) $mensagem['aluno_id'] && !$mensagem['interna']
        && hash_equals($mensagem['codigo'], strtoupper((string) ($_GET['c'] ?? ''))))
);
$caminho = $podeVer ? caminhoStorage('documentos/' . $mensagem['anexo']) : null;
if (!$caminho || str_contains((string) $mensagem['anexo'], '..') || !is_file($caminho)) {
    http_response_code(404);
    echo 'Anexo não encontrado.';
    exit;
}

$mime = $mensagem['anexo_mime'] === 'application/pdf' ? 'application/pdf' : 'image/jpeg';
$nome = preg_replace('/[^\w.\- ]/u', '_', pathinfo((string) $mensagem['anexo_nome'], PATHINFO_FILENAME) ?: 'anexo') . ($mime === 'application/pdf' ? '.pdf' : '.jpg');
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($caminho));
header('Content-Disposition: inline; filename="' . $nome . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
header('X-Frame-Options: SAMEORIGIN');
readfile($caminho);
