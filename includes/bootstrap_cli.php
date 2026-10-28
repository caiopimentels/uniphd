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

require_once __DIR__ . '/funcoes_sistema.php';
