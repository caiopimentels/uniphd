<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirPermissao('usar_email', 'Seu perfil não usa o e-mail pelo sistema.');

// Baixa um anexo (ou mostra a imagem embutida no corpo, com ?ver=1).
$pdo = getDB();
$pasta = (string) ($_GET['pasta'] ?? 'INBOX');
$uid = (int) ($_GET['uid'] ?? 0);
$parte = preg_replace('/[^0-9.]/', '', (string) ($_GET['parte'] ?? ''));
try {
    if (!in_array($pasta, array_column(pastasCaixa($pdo), 'nome'), true)) {
        throw new RuntimeException('Pasta não encontrada.');
    }
    $bruto = mensagemBrutaCaixa($pdo, $pasta, $uid);
    $mensagem = $bruto !== null ? EmailMime::analisar($bruto) : null;
    $info = null;
    foreach ($mensagem['anexos'] ?? [] as $a) {
        if ($a['id'] === $parte) {
            $info = $a;
        }
    }
    $dados = $info ? EmailMime::parte($bruto, $parte) : null;
    if (!$dados) {
        throw new RuntimeException('Anexo não encontrado.');
    }
} catch (RuntimeException $e) {
    http_response_code(404);
    exit(e($e->getMessage()));
}
// Imagem embutida no corpo (?ver=1): só se for imagem de verdade e marcada
// como embutida. Todo o resto SÓ BAIXA (nunca abre no sistema), depois de
// passar pelas barreiras de segurança e pelo antivírus.
$tiposImagem = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];
$imagemDoCorpo = !empty($_GET['ver']) && $info['cid'] && in_array($info['tipo'], $tiposImagem, true) && @getimagesizefromstring($dados['conteudo']);
if (!$imagemDoCorpo) {
    $motivo = motivoArquivoPerigoso($info['nome'], $dados['conteudo']);
    if ($motivo) {
        registrarAtividade('email', 'bloqueio', 'Anexo bloqueado (' . $info['nome'] . '): ' . $motivo);
        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        exit('<!doctype html><meta charset="utf-8"><body style="font-family:sans-serif;padding:40px;text-align:center"><h3>Anexo bloqueado por segurança</h3><p>'
            . e($info['nome']) . ': ' . e($motivo) . '</p><p style="color:#666">Peça ao remetente para mandar o arquivo de outra forma (PDF comum, documento sem macro...).</p></body>');
    }
}
header('Content-Type: ' . ($imagemDoCorpo ? $info['tipo'] : 'application/octet-stream'));
header('Content-Length: ' . strlen($dados['conteudo']));
header('Content-Disposition: ' . ($imagemDoCorpo ? 'inline' : 'attachment') . "; filename=\"" . preg_replace('/[^\x20-\x7e]|"/', '_', $info['nome']) . "\"; filename*=UTF-8''" . rawurlencode($info['nome']));
header("Content-Security-Policy: default-src 'none'; sandbox");
header('X-Download-Options: noopen');
header('Cache-Control: private, max-age=3600');
echo $dados['conteudo'];
