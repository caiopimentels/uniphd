<?php
// Trancamento de matrícula. As regras vêm de Configurações › Matrículas e
// ficam gravadas na matrícula no dia em que trancou:
//   mensalidades futuras → continuar | suspender (voltam ao destrancar,
//                           com vencimentos novos) | cancelar
//   vaga                 → liberar | manter
//   retorno              → escolher (mesma turma ou outra do curso) | mesma

/** Situações da matrícula que veem materiais e videoaulas no portal (SQL). */
function statusAcessoConteudoPortal(PDO $pdo): string
{
    return "'ativa', 'pendente', 'concluida'" . (!empty(buscarConfiguracoes($pdo)['trancamento_portal']) ? ", 'trancada'" : '');
}

function opcoesTrancamento(): array
{
    return [
        'parcelas' => [
            'continuar' => ['Continuam normais', 'O aluno segue pagando enquanto estiver trancado.'],
            'suspender' => ['Ficam suspensas', 'Saem da cobrança e voltam ao destrancar, com os vencimentos empurrados para a volta.'],
            'cancelar' => ['São canceladas', 'Caem como no cancelamento; ao voltar, a secretaria lança as parcelas novas.'],
        ],
        'vaga' => [
            'liberar' => ['Libera a vaga', 'Outro aluno pode ocupar. Ao destrancar, só volta se houver vaga.'],
            'manter' => ['Mantém a vaga', 'A vaga fica guardada para ele até voltar.'],
        ],
        'retorno' => [
            'escolher' => ['Escolher na hora', 'Volta para a mesma turma ou para outra turma do mesmo curso (ex.: a próxima).'],
            'mesma' => ['Sempre a mesma turma', 'Volta para a turma em que estava.'],
        ],
    ];
}

/**
 * Tranca uma matrícula ativa. Retorna ['ok' => bool, 'mensagem' => string].
 */
function trancarMatricula(PDO $pdo, int $matriculaId, string $data, string $motivo, ?string $previsao): array
{
    $config = buscarConfiguracoes($pdo);
    $regraParcelas = $config['trancamento_parcelas'] ?? 'continuar';
    $seguraVaga = ($config['trancamento_vaga'] ?? 'liberar') === 'manter';

    $pdo->beginTransaction();
    $stmt = $pdo->prepare(
        'SELECT m.*, a.nome AS aluno_nome, t.nome AS turma_nome FROM matriculas m
         JOIN alunos a ON a.id = m.aluno_id JOIN turmas t ON t.id = m.turma_id WHERE m.id = ? FOR UPDATE'
    );
    $stmt->execute([$matriculaId]);
    $matricula = $stmt->fetch();
    if (!$matricula || $matricula['status'] !== 'ativa') {
        $pdo->rollBack();
        return ['ok' => false, 'mensagem' => 'Só matrículas ativas podem ser trancadas.'];
    }

    $pdo->prepare("UPDATE matriculas SET status = 'trancada', data_trancamento = ?, trancamento_motivo = ?, trancamento_previsao = ?,
                          trancamento_vaga = ?, trancamento_parcelas = ? WHERE id = ?")
        ->execute([$data, mb_substr($motivo, 0, 255) ?: null, $previsao ?: null, $seguraVaga ? 1 : 0, $regraParcelas, $matriculaId]);

    $qtd = 0;
    if ($regraParcelas === 'cancelar') {
        $qtd = cancelarCobrancasFuturasMatricula($pdo, $matriculaId, $data);
    } elseif ($regraParcelas === 'suspender') {
        $stmt = $pdo->prepare("SELECT id FROM contas_receber WHERE matricula_id = ? AND status = 'pendente' AND excluido_em IS NULL AND data_vencimento >= ?");
        $stmt->execute([$matriculaId, $data]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $parcelaId) {
            $pdo->prepare("UPDATE contas_receber SET status = 'cancelado', suspensa_trancamento = 1 WHERE id = ? AND status = 'pendente'")->execute([$parcelaId]);
            registrarHistoricoParcela($pdo, (int) $parcelaId, 'cancelamento', 'Suspensa pelo trancamento da matrícula');
            $qtd++;
        }
        if ($qtd) {
            agendarBaixaBoletosCancelados();
        }
    }
    $pdo->commit();

    $parcelasTexto = match ($regraParcelas) {
        'suspender' => "{$qtd} mensalidade(s) suspensa(s)",
        'cancelar' => "{$qtd} mensalidade(s) cancelada(s)",
        default => 'mensalidades continuam',
    };
    $detalhe = $matricula['turma_nome'] . ' — ' . $parcelasTexto . ($seguraVaga ? ', vaga mantida' : ', vaga liberada')
        . ($motivo !== '' ? '. Motivo: ' . $motivo : '');
    registrarHistoricoAluno($pdo, (int) $matricula['aluno_id'], 'trancamento', $detalhe, $matriculaId);
    registrarAtividade('matriculas', 'trancamento', 'Matrícula trancada: ' . $matricula['aluno_nome'] . ' — ' . $detalhe);
    sincronizarDocumentosMatricula($pdo, $matriculaId);

    return ['ok' => true, 'mensagem' => $matricula['aluno_nome'] . ': matrícula trancada (' . $parcelasTexto . ($seguraVaga ? ', vaga mantida' : ', vaga liberada') . ').'];
}

/**
 * Turmas para onde a matrícula trancada pode voltar: a dela e, se a regra
 * permitir, as outras abertas do mesmo curso. Cada uma com as vagas livres.
 */
function turmasRetornoTrancamento(PDO $pdo, array $matricula): array
{
    $soMesma = (buscarConfiguracoes($pdo)['trancamento_retorno'] ?? 'escolher') === 'mesma';
    $stmt = $pdo->prepare(
        'SELECT t.id, t.nome, t.data_inicio, t.data_termino, t.vagas, s.comportamento
         FROM turmas t JOIN situacoes_turma s ON s.id = t.situacao_id
         WHERE t.curso_id = (SELECT curso_id FROM turmas WHERE id = ?) AND (t.id = ? OR (? = 0 AND s.comportamento IN (\'aberta\', \'em_andamento\')))
           AND (t.id = ? OR NOT EXISTS (SELECT 1 FROM matriculas x WHERE x.turma_id = t.id AND x.aluno_id = ?))
         ORDER BY t.id = ? DESC, t.data_inicio'
    );
    $turmaId = (int) $matricula['turma_id'];
    $stmt->execute([$turmaId, $turmaId, $soMesma ? 1 : 0, $turmaId, $matricula['aluno_id'], $turmaId]);
    $turmas = $stmt->fetchAll();
    foreach ($turmas as &$t) {
        $t['livres'] = max(0, (int) $t['vagas'] - vagasOcupadasTurma($pdo, (int) $t['id'], (int) $matricula['id']));
        $t['aberta'] = in_array($t['comportamento'], COMPORTAMENTOS_TURMA_ABERTA, true);
    }
    return $turmas;
}

/**
 * Destranca: a matrícula volta a ficar ativa na turma escolhida. As
 * mensalidades suspensas voltam com os vencimentos empurrados até a volta
 * (mesmo dia do mês, mesma distância entre elas).
 */
function destrancarMatricula(PDO $pdo, int $matriculaId, string $dataRetorno, int $turmaDestinoId): array
{
    $pdo->beginTransaction();
    $stmt = $pdo->prepare(
        'SELECT m.*, a.nome AS aluno_nome, t.nome AS turma_nome FROM matriculas m
         JOIN alunos a ON a.id = m.aluno_id JOIN turmas t ON t.id = m.turma_id WHERE m.id = ? FOR UPDATE'
    );
    $stmt->execute([$matriculaId]);
    $matricula = $stmt->fetch();
    if (!$matricula || $matricula['status'] !== 'trancada') {
        $pdo->rollBack();
        return ['ok' => false, 'mensagem' => 'Esta matrícula não está trancada.'];
    }
    $destino = null;
    foreach (turmasRetornoTrancamento($pdo, $matricula) as $t) {
        if ((int) $t['id'] === $turmaDestinoId) {
            $destino = $t;
        }
    }
    if (!$destino) {
        $pdo->rollBack();
        return ['ok' => false, 'mensagem' => 'Escolha uma turma válida para o retorno.'];
    }
    if (!$destino['aberta']) {
        $pdo->rollBack();
        return ['ok' => false, 'mensagem' => 'A turma ' . $destino['nome'] . ' está encerrada. Mude a situação dela para "Em andamento" ou escolha outra turma.'];
    }
    $pdo->prepare('SELECT id FROM turmas WHERE id = ? FOR UPDATE')->execute([$turmaDestinoId]);
    $mesmaVagaGuardada = $turmaDestinoId === (int) $matricula['turma_id'] && $matricula['trancamento_vaga'];
    if (!$mesmaVagaGuardada && vagasOcupadasTurma($pdo, $turmaDestinoId, $matriculaId) >= (int) $destino['vagas']) {
        $pdo->rollBack();
        return ['ok' => false, 'mensagem' => 'A turma ' . $destino['nome'] . ' está cheia. Aumente o número de alunos dela ou escolha outra turma.'];
    }

    $pdo->prepare("UPDATE matriculas SET status = 'ativa', turma_id = ?, trancamento_vaga = 0 WHERE id = ?")->execute([$turmaDestinoId, $matriculaId]);

    // Mensalidades suspensas: a primeira passa a vencer na volta (ou depois),
    // as outras acompanham.
    $stmt = $pdo->prepare("SELECT id, data_vencimento FROM contas_receber WHERE matricula_id = ? AND suspensa_trancamento = 1 AND status = 'cancelado' AND excluido_em IS NULL ORDER BY data_vencimento, id");
    $stmt->execute([$matriculaId]);
    $suspensas = $stmt->fetchAll();
    $meses = 0;
    if ($suspensas) {
        while (somarMeses($suspensas[0]['data_vencimento'], $meses) < $dataRetorno && $meses < 240) {
            $meses++;
        }
    }
    foreach ($suspensas as $p) {
        $novo = somarMeses($p['data_vencimento'], $meses);
        $pdo->prepare("UPDATE contas_receber SET status = 'pendente', suspensa_trancamento = 0, data_vencimento = ? WHERE id = ?")->execute([$novo, $p['id']]);
        registrarHistoricoParcela($pdo, (int) $p['id'], 'alteracao', 'Voltou com o destrancamento da matrícula'
            . ($novo !== $p['data_vencimento'] ? ': vencimento ' . formatDate($p['data_vencimento']) . ' → ' . formatDate($novo) : ''));
    }
    $pdo->commit();

    sincronizarDocumentosMatricula($pdo, $matriculaId);
    avisarMinimoTurma($pdo, $turmaDestinoId);

    $transferiu = $turmaDestinoId !== (int) $matricula['turma_id'];
    $detalhe = ($transferiu ? $matricula['turma_nome'] . ' → ' . $destino['nome'] : $matricula['turma_nome'])
        . ($suspensas ? ' — ' . count($suspensas) . ' mensalidade(s) voltaram' . ($meses ? ' (vencimentos ' . $meses . ' mês(es) depois)' : '') : '');
    registrarHistoricoAluno($pdo, (int) $matricula['aluno_id'], 'destrancamento', $detalhe, $matriculaId);
    registrarAtividade('matriculas', 'destrancamento', 'Matrícula destrancada: ' . $matricula['aluno_nome'] . ' — ' . $detalhe);

    $mensagem = $matricula['aluno_nome'] . ' voltou a ficar ativo' . ($transferiu ? ' na turma ' . $destino['nome'] : '') . '.';
    if ($suspensas) {
        $mensagem .= ' ' . count($suspensas) . ' mensalidade(s) voltaram' . ($meses ? ' com vencimento ' . $meses . ' mês(es) depois' : '') . ' — gere os boletos novos se precisar.';
    } elseif ($matricula['trancamento_parcelas'] === 'cancelar') {
        $mensagem .= ' As mensalidades foram canceladas no trancamento: lance as parcelas novas da volta.';
    }
    return ['ok' => true, 'mensagem' => $mensagem];
}
