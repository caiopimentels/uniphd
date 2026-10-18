<?php

// Bootstrap das URLs chamadas por sistemas de fora (webhook do banco): sem
// sessão, sem CSRF e sem tela — só JSON. Quem chama se autentica pelo
// token OAuth emitido em /webhook/itau/token.php.
//
// Toda chamada fica registrada (webhook_requisicoes + arquivo em
// storage/logs): a linha entra no banco assim que a requisição chega e é
// completada com a resposta na saída — inclusive em erro fatal.

set_exception_handler(function (Throwable $e) {
    error_log('[Webhook] ' . $e->getMessage() . ' em ' . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString());
    if (function_exists('notificarErroSistema')) {
        notificarErroSistema($e->getMessage() . "\n\n" . $e->getTraceAsString(), ($_SERVER['REQUEST_METHOD'] ?? '') . ' ' . ($_SERVER['REQUEST_URI'] ?? ''));
    }
    // 500 faz o banco reenviar o aviso mais tarde — o que queremos quando a
    // falha é nossa (banco de dados fora, por exemplo).
    responderJsonWebhook(500, ['erro' => 'erro_interno'], 'exceção: ' . $e->getMessage());
});

register_shutdown_function(function () {
    $erro = error_get_last();
    $fatal = $erro && in_array($erro['type'], [E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR], true);
    finalizarRegistroWebhook(http_response_code() ?: 500, $fatal ? '[erro fatal] ' . $erro['message'] : '[sem resposta registrada]');
});

header('X-Content-Type-Options: nosniff');

// Bytes guardados do corpo; acima disso, corta (ninguém legítimo manda isso).
const WEBHOOK_LOG_MAX_CORPO = 1048576;

// Lido antes de qualquer require: se o banco de dados estiver fora, o
// config.php já quebra, e mesmo assim o corpo vai para o arquivo de log.
$GLOBALS['webhook_registro'] = [
    'inicio' => $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true),
    'endpoint' => 'itau/' . basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''), '.php'),
    'metodo' => (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
    'ip' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
    'corpo' => (string) file_get_contents('php://input'),
    'id' => null,
    'conta_bancaria_id' => null,
    'finalizado' => false,
];

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/notificacoes.php';
require_once __DIR__ . '/cripto.php';
require_once __DIR__ . '/boleto.php';
require_once __DIR__ . '/cobranca_itau.php';
require_once __DIR__ . '/certificado_itau.php';
require_once __DIR__ . '/documentos_aluno.php';
require_once __DIR__ . '/documentos_emitidos.php';
require_once __DIR__ . '/webhook_itau.php';
require_once __DIR__ . '/turmas.php';
require_once __DIR__ . '/financeiro.php';
require_once __DIR__ . '/portal.php';
require_once __DIR__ . '/rate_limit.php';
require_once __DIR__ . '/smtp_mailer.php';
require_once __DIR__ . '/auditoria.php';

iniciarRegistroWebhook();

/**
 * Cabeçalhos recebidos, com o Authorization mascarado: Basic mostra só o
 * client_id, Bearer só o fim do token — dá para saber quem chamou sem
 * guardar nada que sirva para chamar de novo.
 */
function cabecalhosMascaradosWebhook(): array
{
    $cabecalhos = function_exists('getallheaders') ? getallheaders() : [];
    if (!$cabecalhos) {
        foreach ($_SERVER as $chave => $valor) {
            if (str_starts_with($chave, 'HTTP_')) {
                $cabecalhos[str_replace('_', '-', ucwords(strtolower(substr($chave, 5)), '_'))] = $valor;
            }
        }
    }
    foreach ($cabecalhos as $nome => $valor) {
        if (strtolower($nome) === 'authorization') {
            if (stripos($valor, 'Basic ') === 0) {
                $clientId = explode(':', (string) base64_decode(substr($valor, 6)), 2)[0];
                $cabecalhos[$nome] = 'Basic [client_id=' . $clientId . ', secret oculto]';
            } elseif (stripos($valor, 'Bearer ') === 0) {
                $cabecalhos[$nome] = 'Bearer …' . substr(trim($valor), -6);
            } else {
                $cabecalhos[$nome] = '[oculto]';
            }
        }
    }
    return $cabecalhos;
}

// client_secret e access_token nunca vão para o registro.
function mascararSegredosWebhook(string $texto): string
{
    $json = json_decode($texto, true);
    if (is_array($json)) {
        array_walk_recursive($json, function (&$valor, $chave) {
            if (in_array($chave, ['client_secret', 'access_token', 'webhook_client_secret'], true)) {
                $valor = '[oculto]';
            }
        });
        return json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    return (string) preg_replace('/((?:^|&)(?:client_secret|access_token)=)[^&]*/', '$1[oculto]', $texto);
}

function iniciarRegistroWebhook(): void
{
    $registro = &$GLOBALS['webhook_registro'];
    $registro['cabecalhos'] = json_encode(cabecalhosMascaradosWebhook(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $registro['corpo'] = mascararSegredosWebhook(substr($registro['corpo'], 0, WEBHOOK_LOG_MAX_CORPO));

    try {
        $stmt = getDB()->prepare('INSERT INTO webhook_requisicoes (endpoint, metodo, ip, cabecalhos, corpo) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$registro['endpoint'], $registro['metodo'], $registro['ip'], $registro['cabecalhos'], $registro['corpo']]);
        $registro['id'] = (int) getDB()->lastInsertId();
    } catch (Throwable $e) {
        error_log('[Webhook] não gravou a requisição no banco: ' . $e->getMessage());
    }
}

function idRequisicaoWebhook(): ?int
{
    return $GLOBALS['webhook_registro']['id'] ?? null;
}

function definirContaRequisicaoWebhook(int $contaId): void
{
    $GLOBALS['webhook_registro']['conta_bancaria_id'] = $contaId;
}

/**
 * Completa o registro com a resposta e grava a linha no arquivo do mês.
 * Roda uma vez só (responderJsonWebhook e o shutdown chamam).
 */
function finalizarRegistroWebhook(int $status, string $resposta): void
{
    $registro = &$GLOBALS['webhook_registro'];
    if (!isset($registro) || $registro['finalizado']) {
        return;
    }
    $registro['finalizado'] = true;
    $resposta = mascararSegredosWebhook($resposta);
    // Se o registro nem chegou a iniciar (config.php quebrou), o corpo
    // ainda está cru.
    $registro['corpo'] = mascararSegredosWebhook(substr($registro['corpo'], 0, WEBHOOK_LOG_MAX_CORPO));
    $duracaoMs = (int) round((microtime(true) - $registro['inicio']) * 1000);

    $linha = json_encode([
        'quando' => date('c'),
        'requisicao_id' => $registro['id'],
        'endpoint' => $registro['endpoint'],
        'metodo' => $registro['metodo'],
        'ip' => $registro['ip'],
        'conta_bancaria_id' => $registro['conta_bancaria_id'],
        'cabecalhos' => json_decode($registro['cabecalhos'] ?? 'null', true),
        'corpo' => $registro['corpo'],
        'status_http' => $status,
        'resposta' => $resposta,
        'duracao_ms' => $duracaoMs,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    // Pasta criada aqui mesmo (sem caminhoStorage) para funcionar até
    // quando o config.php não carregou.
    $pasta = dirname(__DIR__) . '/storage/logs';
    if (!is_dir($pasta)) {
        @mkdir($pasta, 0700, true);
    }
    @file_put_contents($pasta . '/webhook-' . date('Y-m') . '.log', $linha . "\n", FILE_APPEND | LOCK_EX);

    if ($registro['id'] && function_exists('getDB')) {
        try {
            $stmt = getDB()->prepare('UPDATE webhook_requisicoes SET status_http = ?, resposta = ?, duracao_ms = ?, conta_bancaria_id = ? WHERE id = ?');
            $stmt->execute([$status, mb_substr($resposta, 0, 60000), $duracaoMs, $registro['conta_bancaria_id'], $registro['id']]);
        } catch (Throwable $e) {
            error_log('[Webhook] não completou o registro da requisição: ' . $e->getMessage());
        }
    }
}

/**
 * Responde e encerra. $observacao entra só no registro (ex.: a mensagem da
 * exceção), nunca na resposta ao banco.
 */
function responderJsonWebhook(int $status, array $dados, string $observacao = ''): void
{
    $json = json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo $json;
    finalizarRegistroWebhook($status, $json . ($observacao !== '' ? "\n" . $observacao : ''));
    exit;
}
