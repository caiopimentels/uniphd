<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirPermissao('usar_email', 'Seu perfil não usa o e-mail pelo sistema.');

// Corpo (HTML) de um e-mail recebido, para o iframe da leitura. Isolado:
// sem script (CSP + sandbox do iframe), links abrem em outra aba, imagens
// de fora só com ?imagens=1 (evita o rastreio de "e-mail aberto").
$pdo = getDB();
$pasta = (string) ($_GET['pasta'] ?? 'INBOX');
$uid = (int) ($_GET['uid'] ?? 0);
$imagens = !empty($_GET['imagens']);
try {
    if (!in_array($pasta, array_column(pastasCaixa($pdo), 'nome'), true)) {
        throw new RuntimeException('Pasta não encontrada.');
    }
    $bruto = mensagemBrutaCaixa($pdo, $pasta, $uid);
    if ($bruto === null) {
        throw new RuntimeException('E-mail não encontrado.');
    }
    $mensagem = EmailMime::analisar($bruto);
    $html = htmlMensagemRecebida($mensagem, '/email/anexo.php?' . http_build_query(['pasta' => $pasta, 'uid' => $uid, 'ver' => 1]) . '&parte=');
} catch (RuntimeException $e) {
    $html = '<p style="color:#b91c1c">' . e($e->getMessage()) . '</p>';
}
header('Content-Type: text/html; charset=utf-8');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline' *; font-src *; img-src 'self' data:" . ($imagens ? ' https: http:' : '') . "; base-uri 'none'; form-action 'none'");
header('X-Frame-Options: SAMEORIGIN');
?>
<!doctype html>
<html><head><meta charset="utf-8"><base target="_blank">
<style>body { margin: 0; font-family: Arial, Helvetica, sans-serif; font-size: 14px; color: #202124; word-wrap: break-word; } img { max-width: 100%; height: auto; } blockquote { margin: 0 0 0 .8ex; border-left: 1px solid #ccc; padding-left: 1ex; } pre { white-space: pre-wrap; }</style>
</head><body><?= $html ?></body></html>
