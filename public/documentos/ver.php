<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

// Entrega o arquivo de um documento de aluno para ver na tela (modal do
// sistema ou nova aba no portal). Pode ver: usuário do sistema com acesso a
// alunos, ou o próprio aluno logado no portal. Nada fica no site público.
$pdo = getDB();
// ?id= documento enviado para o curso; ?anexo= anexo da ficha (o aluno só
// vê o anexo que a secretaria liberou).
$ehAnexo = isset($_GET['anexo']);
$stmt = $pdo->prepare($ehAnexo ? 'SELECT * FROM aluno_anexos WHERE id = ?' : 'SELECT * FROM aluno_documentos WHERE id = ?');
$stmt->execute([(int) ($ehAnexo ? $_GET['anexo'] : ($_GET['id'] ?? 0))]);
$envio = $stmt->fetch();

$podeVer = $envio && (
    (usuarioLogado() && (usuarioEhAdmin() || usuarioTemPermissao('ver_alunos')))
    || (alunoLogado() && alunoAtualId() === (int) $envio['aluno_id'] && (!$ehAnexo || $envio['visivel_aluno']))
);
$caminho = $envio ? caminhoDocumentoAluno($envio) : null;
if (!$podeVer || !$caminho || !is_file($caminho)) {
    http_response_code(404);
    echo 'Documento não encontrado.';
    exit;
}

$mime = $envio['mime'] === 'application/pdf' ? 'application/pdf' : 'image/jpeg';
$nome = preg_replace('/[^\w.\- ]/u', '_', ($ehAnexo ? $envio['nome'] : pathinfo((string) $envio['nome_original'], PATHINFO_FILENAME)) ?: 'documento') . ($mime === 'application/pdf' ? '.pdf' : '.jpg');
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($caminho));
header('Content-Disposition: ' . (isset($_GET['baixar']) ? 'attachment' : 'inline') . '; filename="' . $nome . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
header('X-Frame-Options: SAMEORIGIN');
readfile($caminho);
