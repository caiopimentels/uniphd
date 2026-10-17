<?php

// Avisa por e-mail (configurações > E-mail) quando o sistema quebra de
// verdade — exceção que ninguém tratou, ou erro fatal do PHP — sem depender
// de alguém reparar sozinho ou de vasculhar o log do servidor. Não pega
// validação de negócio (aquelas já viram flash message pro usuário através
// de try/catch específico em cada tela).
set_exception_handler(function (Throwable $e) {
    error_log('[Erro não tratado] ' . $e->getMessage() . ' em ' . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString());

    if (function_exists('notificarErroSistema')) {
        notificarErroSistema(
            $e->getMessage() . "\n\n" . $e->getTraceAsString(),
            ($_SERVER['REQUEST_METHOD'] ?? '') . ' ' . ($_SERVER['REQUEST_URI'] ?? '')
        );
    }

    if (!headers_sent()) {
        http_response_code(500);
    }

    if (function_exists('ehRequisicaoAjax') && ehRequisicaoAjax()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['sucesso' => false, 'mensagem' => 'Erro interno no servidor.']);
    } else {
        echo '<!doctype html><html lang="pt-br"><head><meta charset="utf-8"><title>Erro</title></head>'
            . '<body style="font-family:sans-serif;text-align:center;padding:60px 20px;color:#333;">'
            . '<h1>Ocorreu um erro inesperado</h1><p>A equipe já foi avisada. Tente novamente em instantes.</p>'
            . '</body></html>';
    }
});

register_shutdown_function(function () {
    $erro = error_get_last();
    if ($erro && in_array($erro['type'], [E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR], true)) {
        error_log('[Erro fatal] ' . $erro['message'] . ' em ' . $erro['file'] . ':' . $erro['line']);
        if (function_exists('notificarErroSistema')) {
            notificarErroSistema(
                $erro['message'] . ' em ' . $erro['file'] . ':' . $erro['line'],
                ($_SERVER['REQUEST_METHOD'] ?? '') . ' ' . ($_SERVER['REQUEST_URI'] ?? '')
            );
        }
    }
});

// Cookie de sessão protegido: só via HTTP (não acessível por JavaScript),
// só em conexões HTTPS quando disponível, e não enviado em requisições
// de origem cruzada (mitiga a maior parte de CSRF via formulário/redirect).
$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
ini_set('session.use_strict_mode', '1');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $https,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

// Headers de segurança em toda resposta do sistema.
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
if ($https) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

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
require_once __DIR__ . '/webhook_itau.php';
require_once __DIR__ . '/portal.php';
require_once __DIR__ . '/portal_site.php';
require_once __DIR__ . '/calendario.php';
require_once __DIR__ . '/certificado.php';
require_once __DIR__ . '/rate_limit.php';
require_once __DIR__ . '/smtp_mailer.php';
require_once __DIR__ . '/auditoria.php';

// Garante que toda página (logada ou não — login e recuperação de senha
// também enviam formulário) já tem um token de CSRF disponível pra colocar
// no formulário/meta tag antes de renderizar qualquer coisa.
csrfToken();

// Todo POST do sistema (formulário normal ou fetch) precisa devolver esse
// token — no cabeçalho X-CSRF-Token (é o que o /assets/js/csrf.js manda
// sozinho em todo fetch) ou no campo _csrf (que o mesmo script injeta em
// todo <form>). Sem isso, um site diferente não consegue montar uma página
// que engane o navegador de alguém já logado a disparar uma ação aqui.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tokenEnviado = (string) ($_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'], $tokenEnviado)) {
        http_response_code(403);
        if (ehRequisicaoAjax()) {
            responderJson(['sucesso' => false, 'mensagem' => 'Sessão desatualizada — recarregue a página e tente de novo.']);
        }
        flash('danger', 'Sua sessão estava desatualizada. Recarregue a página e tente novamente.');
        redirect('/');
    }
}
