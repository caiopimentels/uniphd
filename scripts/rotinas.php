<?php

// Rotinas automáticas, chamadas pelo cron do servidor (fora do site):
//
//   php scripts/rotinas.php certificados   → 1x por dia: vencimento e
//                                            renovação do certificado do Itaú
//   php scripts/rotinas.php documentos     → de hora em hora: libera os
//                                            documentos de quem ficou em dia
//
// Rodam como o usuário do Apache (www-data), para gravar em storage/ com
// as mesmas permissões do site.

require __DIR__ . '/../includes/bootstrap_cli.php';

$pdo = getDB();
$rotina = $argv[1] ?? '';

switch ($rotina) {
    case 'certificados':
        verificarCertificadosItau($pdo);
        echo date('Y-m-d H:i:s') . " certificados verificados\n";
        break;

    case 'documentos':
        $liberados = sincronizarDocumentosTodos($pdo);
        // Só entra na auditoria quando liberou algo (roda de hora em hora).
        if ($liberados > 0) {
            registrarAtividadeSistema('rotinas', 'verificacao', 'Liberação automática de documentos: ' . $liberados . ' documento(s) liberado(s).');
        }
        echo date('Y-m-d H:i:s') . " documentos: $liberados liberado(s)\n";
        break;

    default:
        fwrite(STDERR, "Uso: php scripts/rotinas.php certificados|documentos\n");
        exit(1);
}
