<?php

/**
 * Notificações do sistema (sino do topo). A leitura é registrada por
 * usuário — cada um tem o seu contador de não lidas.
 */

function criarNotificacao(PDO $pdo, string $tipo, string $titulo, ?string $descricao = null, ?string $link = null, ?int $referenciaId = null): void
{
    // Notificação nunca pode derrubar a operação que a gerou, então falha
    // em silêncio e só registra no log.
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO notificacoes (tipo, titulo, descricao, link, referencia_id) VALUES (?,?,?,?,?)'
        );
        $stmt->execute([$tipo, $titulo, $descricao, $link, $referenciaId]);
    } catch (Throwable $e) {
        error_log('Erro ao criar notificação: ' . $e->getMessage());
    }
}

function contarNotificacoesNaoLidas(PDO $pdo, int $usuarioId): int
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
           FROM notificacoes n
          WHERE NOT EXISTS (
                SELECT 1 FROM notificacoes_lidas nl
                 WHERE nl.notificacao_id = n.id AND nl.usuario_id = ?
          )'
    );
    $stmt->execute([$usuarioId]);

    return (int) $stmt->fetchColumn();
}

function buscarNotificacoes(PDO $pdo, int $usuarioId, int $limite = 15): array
{
    $stmt = $pdo->prepare(
        'SELECT n.*,
                (nl.notificacao_id IS NOT NULL) AS lida
           FROM notificacoes n
           LEFT JOIN notificacoes_lidas nl
                  ON nl.notificacao_id = n.id AND nl.usuario_id = ?
          ORDER BY n.criado_em DESC, n.id DESC
          LIMIT ' . (int) $limite
    );
    $stmt->execute([$usuarioId]);

    return $stmt->fetchAll();
}

function marcarNotificacaoLida(PDO $pdo, int $usuarioId, int $notificacaoId): void
{
    $stmt = $pdo->prepare(
        'INSERT IGNORE INTO notificacoes_lidas (notificacao_id, usuario_id) VALUES (?, ?)'
    );
    $stmt->execute([$notificacaoId, $usuarioId]);
}

function marcarTodasNotificacoesLidas(PDO $pdo, int $usuarioId): void
{
    $stmt = $pdo->prepare(
        'INSERT IGNORE INTO notificacoes_lidas (notificacao_id, usuario_id)
         SELECT n.id, ? FROM notificacoes n'
    );
    $stmt->execute([$usuarioId]);
}

/**
 * Texto relativo curto ("há 5 min"), no estilo de feed de notificação.
 */
function tempoRelativo(string $dataHora): string
{
    $segundos = time() - strtotime($dataHora);

    if ($segundos < 60) {
        return 'agora';
    }
    if ($segundos < 3600) {
        return 'há ' . floor($segundos / 60) . ' min';
    }
    if ($segundos < 86400) {
        $horas = floor($segundos / 3600);
        return 'há ' . $horas . ($horas === 1 ? ' hora' : ' horas');
    }
    if ($segundos < 604800) {
        $dias = floor($segundos / 86400);
        return 'há ' . $dias . ($dias === 1 ? ' dia' : ' dias');
    }

    return formatDate(date('Y-m-d', strtotime($dataHora)));
}
