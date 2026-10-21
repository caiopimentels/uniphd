<?php

// Bootstrap dos scripts de linha de comando (cron do servidor): carrega as
// mesmas funções do sistema, sem sessão, cabeçalhos HTTP ou CSRF.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

set_exception_handler(function (Throwable $e) {
    error_log('[Rotina] ' . $e->getMessage() . ' em ' . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString());
    if (function_exists('notificarErroSistema')) {
        notificarErroSistema($e->getMessage() . "\n\n" . $e->getTraceAsString(), 'cron ' . implode(' ', $GLOBALS['argv'] ?? []));
    }
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
});

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/notificacoes.php';
require_once __DIR__ . '/financeiro.php';
require_once __DIR__ . '/turmas.php';
require_once __DIR__ . '/cripto.php';
require_once __DIR__ . '/pix.php';
require_once __DIR__ . '/boleto.php';
require_once __DIR__ . '/cobranca_itau.php';
require_once __DIR__ . '/certificado_itau.php';
require_once __DIR__ . '/documentos_aluno.php';
require_once __DIR__ . '/documentos_emitidos.php';
require_once __DIR__ . '/webhook_itau.php';
require_once __DIR__ . '/portal.php';
require_once __DIR__ . '/portal_site.php';
require_once __DIR__ . '/calendario.php';
require_once __DIR__ . '/certificado.php';
require_once __DIR__ . '/rate_limit.php';
require_once __DIR__ . '/smtp_mailer.php';
require_once __DIR__ . '/auditoria.php';
