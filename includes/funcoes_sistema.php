<?php

// Funções do sistema, sem sessão, cabeçalhos ou CSRF: usado pelo cron
// (bootstrap_cli.php) e pelo webhook do WhatsApp.

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
require_once __DIR__ . '/listagem.php';
require_once __DIR__ . '/listas_academico.php';
require_once __DIR__ . '/colunas_telas.php';
require_once __DIR__ . '/cupons.php';
require_once __DIR__ . '/contratos.php';
require_once __DIR__ . '/protocolos.php';
require_once __DIR__ . '/whatsapp.php';
