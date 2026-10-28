<?php
// Mensagens do WhatsApp, enviadas pela Evolution API (evento MESSAGES_UPSERT).
// A URL leva o token da configuração (?token=...). Responde 200 na hora e
// só depois, com a conexão já fechada, a IA pensa e responde ao cliente —
// assim a Evolution API não espera nem reenvia.
require_once __DIR__ . '/../../includes/funcoes_sistema.php';

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('{"erro":"use_post"}');
}

$pdo = getDB();
$cfg = whatsappConfig($pdo);
$token = (string) ($_GET['token'] ?? '');
if (empty($cfg['webhook_token']) || !hash_equals($cfg['webhook_token'], $token)) {
    http_response_code(401);
    exit('{"erro":"token_invalido"}');
}

$corpo = json_decode((string) file_get_contents('php://input'), true);
$evento = strtolower(str_replace('_', '.', (string) ($corpo['event'] ?? '')));
$itens = $corpo['data'] ?? [];
$itens = is_array($itens) && array_is_list($itens) ? $itens : [$itens];

$paraResponder = [];
if ($evento === 'messages.upsert') {
    foreach ($itens as $msg) {
        $jid = (string) ($msg['key']['remoteJid'] ?? '');
        // Só conversas individuais (grupos, status e canais ficam de fora).
        if (!str_ends_with($jid, '@s.whatsapp.net')) {
            continue;
        }
        $numero = numeroDoJidWhatsapp($jid);
        $idExterno = (string) ($msg['key']['id'] ?? '') ?: null;
        $conteudo = $msg['message'] ?? [];
        $texto = $conteudo['conversation'] ?? $conteudo['extendedTextMessage']['text'] ?? null;
        $tipo = 'texto';
        if ($texto === null) {
            $tipo = match (true) {
                isset($conteudo['audioMessage']) => 'audio',
                isset($conteudo['imageMessage']) => 'imagem',
                isset($conteudo['documentMessage']) => 'documento',
                isset($conteudo['videoMessage']) => 'video',
                isset($conteudo['stickerMessage']) => 'figurinha',
                default => 'outro',
            };
            $texto = $conteudo['imageMessage']['caption'] ?? $conteudo['documentMessage']['caption'] ?? $conteudo['videoMessage']['caption'] ?? null;
            // Áudio: a Groq transcreve (grátis) e a IA responde ao texto.
            if ($tipo === 'audio') {
                $transcricao = transcreverAudioWhatsapp($cfg, (string) ($conteudo['base64'] ?? $msg['base64'] ?? ''), (string) ($conteudo['audioMessage']['mimetype'] ?? 'audio/ogg'));
                $texto = $transcricao !== null ? '🎤 ' . $transcricao : null;
            }
        }

        $conversa = conversaWhatsapp($pdo, $numero, ($msg['key']['fromMe'] ?? false) ? null : ($msg['pushName'] ?? null));
        if (!empty($msg['key']['fromMe'])) {
            // Enviada pelo próprio número: se não foi o sistema (já gravada com
            // este id), alguém respondeu pelo celular — a IA sai da conversa.
            if (gravarMensagemWhatsapp($pdo, (int) $conversa['id'], 'atendente', $texto ?? '[' . $tipo . ']', $tipo, $idExterno)) {
                $pdo->prepare("UPDATE whatsapp_conversas SET modo = 'humano', aguardando = 0 WHERE id = ?")->execute([$conversa['id']]);
            }
            continue;
        }
        $mensagemId = gravarMensagemWhatsapp($pdo, (int) $conversa['id'], 'cliente', $texto ?? '[' . $tipo . ']', $tipo, $idExterno);
        if (!$mensagemId) {
            continue; // repetida
        }
        // Imagem, documento etc. sem texto: a IA não lê — passa para a equipe.
        $paraResponder[] = [(int) $conversa['id'], $mensagemId, $texto === null];
    }
}

// Responde já e fecha a conexão; o resto roda depois. Sem compactação
// (gzip do Apache seguraria a resposta até o fim do processamento).
if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', '1');
}
@ini_set('zlib.output_compression', '0');
ignore_user_abort(true);
$saida = '{"ok":true}';
header('Content-Length: ' . strlen($saida));
header('Connection: close');
echo $saida;
while (ob_get_level() > 0) {
    ob_end_flush();
}
flush();
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

set_time_limit(180);
foreach ($paraResponder as [$conversaId, $mensagemId, $semTexto]) {
    try {
        if ($semTexto) {
            $stmt = $pdo->prepare("SELECT * FROM whatsapp_conversas WHERE id = ? AND modo = 'ia'");
            $stmt->execute([$conversaId]);
            if (($conversa = $stmt->fetch()) && $cfg['ia_ativa'] && numeroLiberadoWhatsapp($cfg, $conversa['numero']) && !ehNumeroAvisoWhatsapp($cfg, $conversa['numero'])) {
                transferirConversaWhatsapp($pdo, $cfg, $conversa, 'o cliente mandou arquivo, foto ou áudio que a IA não lê');
            }
            continue;
        }
        responderConversaWhatsapp($pdo, $conversaId, $mensagemId);
    } catch (Throwable $e) {
        error_log('[WhatsApp] ' . $e->getMessage());
    }
}
