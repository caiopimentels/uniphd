<?php

// Regras de turma compartilhadas pela tela da turma, pela listagem (menu de
// cada linha e ações em massa) e pelo formulário — para mudar a situação
// ou excluir valer a mesma coisa venha de onde vier.

// Comportamentos que ainda aceitam aluno novo.
const COMPORTAMENTOS_TURMA_ABERTA = ['aberta', 'em_andamento'];

// Matrícula que ocupa vaga (alias "m"): ativa, ou inscrição do portal
// aguardando pagamento que ainda segura a vaga (modo "reserva", no prazo).
const SQL_MATRICULA_OCUPA_VAGA = "(m.status = 'ativa' OR (m.status = 'pendente' AND m.segura_vaga = 1 AND m.pagamento_ate >= NOW()))";

// Vagas guardadas pela secretaria para alguém (alias da turma: "t"). Contam
// como ocupadas em todo lugar: na matrícula pela secretaria e no site.
const SQL_RESERVAS_TURMA = '(SELECT COUNT(*) FROM turma_reservas r WHERE r.turma_id = t.id)';

// Matrículas que ocupam vaga + reservas. Ao matricular a pessoa de uma
// reserva, a própria reserva sai da conta ($ignorarReservaId).
function vagasOcupadasTurma(PDO $pdo, int $turmaId, int $ignorarMatriculaId = 0, int $ignorarReservaId = 0): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM matriculas m WHERE m.turma_id = ? AND m.id <> ? AND ' . SQL_MATRICULA_OCUPA_VAGA);
    $stmt->execute([$turmaId, $ignorarMatriculaId]);
    $matriculas = (int) $stmt->fetchColumn();
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM turma_reservas WHERE turma_id = ? AND id <> ?');
    $stmt->execute([$turmaId, $ignorarReservaId]);
    return $matriculas + (int) $stmt->fetchColumn();
}

// "6 meses", "1 mês", "2 dias".
function textoDuracaoTurma(array $turma): string
{
    $n = (int) $turma['duracao_meses'];
    if (($turma['duracao_unidade'] ?? 'mes') === 'dia') {
        return $n . ($n === 1 ? ' dia' : ' dias');
    }
    return $n . ($n === 1 ? ' mês' : ' meses');
}

// Quantas mensalidades a turma tem por padrão: uma por mês de duração; curso
// em dias (fim de semana, 7 dias) é cobrado em uma parcela.
function parcelasPadraoTurma(array $turma): int
{
    return ($turma['duracao_unidade'] ?? 'mes') === 'dia' ? 1 : max(1, (int) $turma['duracao_meses']);
}

// "02/03/2026 a 30/11/2026", ou, na turma sem data, "A definir (mínimo de
// 10 alunos)". $ultimaAula: termina no dia anterior ao data_termino.
function periodoTurma(array $turma, bool $ultimaAula = false): string
{
    if (empty($turma['data_inicio'])) {
        return 'A definir' . (!empty($turma['minimo_alunos']) ? ' (começa com ' . (int) $turma['minimo_alunos'] . ' alunos)' : '');
    }
    // Curso em dias: mostra sempre o último dia (fim de semana = sáb a dom).
    $ultimaAula = $ultimaAula || ($turma['duracao_unidade'] ?? 'mes') === 'dia';
    $fim = $ultimaAula ? date('Y-m-d', strtotime($turma['data_termino'] . ' -1 day')) : $turma['data_termino'];
    return formatDate($turma['data_inicio']) . ' a ' . formatDate($fim);
}

/**
 * Turma sem data que acabou de juntar o mínimo de alunos ativos: avisa no
 * sininho (uma vez só) para a secretaria definir a data de início.
 */
function avisarMinimoTurma(PDO $pdo, int $turmaId): void
{
    $stmt = $pdo->prepare(
        "SELECT t.nome, t.minimo_alunos, c.nome AS curso_nome,
                (SELECT COUNT(*) FROM matriculas m WHERE m.turma_id = t.id AND m.status = 'ativa') AS ativos
         FROM turmas t JOIN cursos c ON c.id = t.curso_id
         WHERE t.id = ? AND t.data_inicio IS NULL AND t.minimo_alunos IS NOT NULL"
    );
    $stmt->execute([$turmaId]);
    $turma = $stmt->fetch();
    if (!$turma || (int) $turma['ativos'] < (int) $turma['minimo_alunos']) {
        return;
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM notificacoes WHERE tipo = 'turma_minimo' AND referencia_id = ?");
    $stmt->execute([$turmaId]);
    if ((int) $stmt->fetchColumn() > 0) {
        return;
    }
    criarNotificacao($pdo, 'turma_minimo', 'Turma pronta para começar',
        $turma['curso_nome'] . ' · ' . $turma['nome'] . ' atingiu ' . (int) $turma['ativos'] . ' alunos (mínimo ' . (int) $turma['minimo_alunos'] . '). Defina a data de início.',
        '/turmas/form.php?id=' . $turmaId, $turmaId);
}

function buscarReservasTurma(PDO $pdo, int $turmaId): array
{
    $stmt = $pdo->prepare(
        'SELECT r.*, a.nome AS aluno_nome, u.nome AS usuario_nome
         FROM turma_reservas r
         LEFT JOIN alunos a ON a.id = r.aluno_id
         LEFT JOIN usuarios u ON u.id = r.usuario_id
         WHERE r.turma_id = ? ORDER BY r.criado_em, r.id'
    );
    $stmt->execute([$turmaId]);
    return $stmt->fetchAll();
}

function rotulosComportamentoTurma(): array
{
    return [
        'aberta' => 'Não começou (aceita matrícula)',
        'em_andamento' => 'Em andamento (aceita matrícula)',
        'concluida' => 'Concluída (encerra as matrículas ativas)',
        'cancelada' => 'Cancelada (só sem alunos ativos)',
    ];
}

function buscarSituacoesTurma(PDO $pdo, bool $apenasAtivas = false): array
{
    return $pdo->query(
        'SELECT * FROM situacoes_turma' . ($apenasAtivas ? ' WHERE ativo = 1' : '') . ' ORDER BY ordem, nome'
    )->fetchAll();
}

function situacaoPadraoTurmaId(PDO $pdo): ?int
{
    $id = $pdo->query('SELECT id FROM situacoes_turma WHERE padrao = 1 AND ativo = 1 ORDER BY ordem LIMIT 1')->fetchColumn();
    if (!$id) {
        $id = $pdo->query('SELECT id FROM situacoes_turma WHERE ativo = 1 ORDER BY ordem, id LIMIT 1')->fetchColumn();
    }
    return $id ? (int) $id : null;
}

// A cor é livre (cadastrável), então o badge sai com estilo inline em vez
// das classes fixas do Bootstrap.
function badgeSituacaoTurma(?string $nome, ?string $cor): string
{
    if (!$nome) {
        return '<span class="text-muted">—</span>';
    }
    $cor = ($cor && preg_match('/^#[0-9A-Fa-f]{6}$/', $cor)) ? $cor : '#6c757d';
    return '<span class="badge" style="background:' . $cor . '; color:#fff;">' . e($nome) . '</span>';
}

/**
 * Leva a turma para a situação informada, aplicando a regra do
 * comportamento dela. Não abre transação: quem chama controla, porque o
 * formulário grava outros campos da turma junto.
 * Concluir exige a data de conclusão ($dataConclusao, AAAA-MM-DD), que é a
 * data de término impressa no certificado; reabrir a turma apaga a data.
 * Turma ainda sem data de início recebe a dela aqui ($dataInicio) — o
 * certificado diz "realizado de ... a ...".
 * Retorna ['ok' => bool, 'mensagem' => string, 'concluidas' => int,
 *          'canceladas' => int, 'reativadas' => int].
 */
function aplicarSituacaoTurma(PDO $pdo, int $turmaId, array $situacao, ?string $dataConclusao = null, ?string $dataInicio = null): array
{
    $stmt = $pdo->prepare(
        'SELECT t.nome, t.data_inicio, s.comportamento AS comportamento_atual
         FROM turmas t JOIN situacoes_turma s ON s.id = t.situacao_id
         WHERE t.id = ?'
    );
    $stmt->execute([$turmaId]);
    $turma = $stmt->fetch();
    if (!$turma) {
        return ['ok' => false, 'mensagem' => 'Turma não encontrada.', 'concluidas' => 0, 'canceladas' => 0, 'reativadas' => 0];
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM matriculas WHERE turma_id = ? AND status = 'ativa'");
    $stmt->execute([$turmaId]);
    $ativas = (int) $stmt->fetchColumn();

    // Cancelar a turma com gente matriculada deixaria mensalidades sendo
    // cobradas de uma turma que não existe mais — cada matrícula precisa
    // ser cancelada (e suas parcelas futuras junto) de forma consciente.
    if ($situacao['comportamento'] === 'cancelada' && $ativas > 0) {
        return [
            'ok' => false,
            'mensagem' => "Turma {$turma['nome']}: tem {$ativas} matrícula(s) ativa(s). Cancele as matrículas antes de cancelar a turma.",
            'concluidas' => 0, 'canceladas' => 0, 'reativadas' => 0,
        ];
    }

    if ($situacao['comportamento'] === 'concluida') {
        if (!$turma['data_inicio']) {
            if (!$dataInicio || !strtotime($dataInicio)) {
                return ['ok' => false, 'mensagem' => "Turma {$turma['nome']}: está sem data de início — informe a data de início para concluir.", 'concluidas' => 0, 'canceladas' => 0, 'reativadas' => 0];
            }
            $pdo->prepare('UPDATE turmas SET data_inicio = ? WHERE id = ?')->execute([$dataInicio, $turmaId]);
            $turma['data_inicio'] = $dataInicio;
        }
        $erroData = validarDataConclusaoTurma($dataConclusao, $turma['data_inicio']);
        if ($erroData) {
            return ['ok' => false, 'mensagem' => "Turma {$turma['nome']}: {$erroData}", 'concluidas' => 0, 'canceladas' => 0, 'reativadas' => 0];
        }
    }

    $stmt = $pdo->prepare('UPDATE turmas SET situacao_id = ?, data_conclusao = ? WHERE id = ?');
    $stmt->execute([$situacao['id'], $situacao['comportamento'] === 'concluida' ? $dataConclusao : null, $turmaId]);

    // Turma concluída = acabou: todo mundo que estava cursando concluiu (as
    // parcelas em aberto continuam em aberto: concluir não quita dívida),
    // inscrição do site que não foi paga é cancelada e as vagas reservadas
    // somem. A turma sai do site e não aceita mais ninguém; sobra só liberar
    // os certificados.
    $concluidas = $canceladas = $reativadas = 0;
    $abertaAgora = in_array($situacao['comportamento'], COMPORTAMENTOS_TURMA_ABERTA, true);
    if ($situacao['comportamento'] === 'concluida' && $turma['comportamento_atual'] !== 'concluida') {
        $stmt = $pdo->prepare("UPDATE matriculas SET status = 'concluida' WHERE turma_id = ? AND status = 'ativa'");
        $stmt->execute([$turmaId]);
        $concluidas = $stmt->rowCount();

        $stmt = $pdo->prepare("SELECT id FROM matriculas WHERE turma_id = ? AND status = 'pendente'");
        $stmt->execute([$turmaId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $pendenteId) {
            $pdo->prepare("UPDATE matriculas SET status = 'cancelada', segura_vaga = 0, lista_espera = 0, data_cancelamento = CURDATE() WHERE id = ?")
                ->execute([$pendenteId]);
            cancelarCobrancasFuturasMatricula($pdo, (int) $pendenteId, '0000-01-01');
            $canceladas++;
        }
        $pdo->prepare('DELETE FROM turma_reservas WHERE turma_id = ?')->execute([$turmaId]);
    } elseif ($abertaAgora && $turma['comportamento_atual'] === 'concluida') {
        // Reabriu uma turma concluída (ex.: concluída por engano): quem
        // tinha concluído volta a cursar.
        $stmt = $pdo->prepare("UPDATE matriculas SET status = 'ativa' WHERE turma_id = ? AND status = 'concluida'");
        $stmt->execute([$turmaId]);
        $reativadas = $stmt->rowCount();
    }

    return ['ok' => true, 'mensagem' => '', 'concluidas' => $concluidas, 'canceladas' => $canceladas, 'reativadas' => $reativadas];
}

// Data de conclusão: obrigatória, válida, não no futuro e não antes do início.
function validarDataConclusaoTurma(?string $data, ?string $dataInicio): ?string
{
    if (!$data || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) || !strtotime($data)) {
        return 'informe a data de conclusão da turma.';
    }
    if ($data > date('Y-m-d')) {
        return 'a data de conclusão não pode ser no futuro.';
    }
    if ($dataInicio && $data < $dataInicio) {
        return 'a data de conclusão não pode ser antes do início (' . formatDate($dataInicio) . ').';
    }
    return null;
}

// Frase com o que a mudança de situação fez nas matrículas (para o aviso).
function resumoSituacaoTurma(int $concluidas, int $canceladas, int $reativadas): string
{
    $partes = [];
    if ($concluidas) {
        $partes[] = "{$concluidas} matrícula(s) marcada(s) como concluída(s)";
    }
    if ($canceladas) {
        $partes[] = "{$canceladas} inscrição(ões) não paga(s) cancelada(s)";
    }
    if ($reativadas) {
        $partes[] = "{$reativadas} aluno(s) voltaram a ficar ativos";
    }
    return $partes ? ' ' . implode('; ', $partes) . '.' : '';
}

/**
 * Exclui a turma com as matrículas e parcelas dela. Bloqueia se alguma
 * parcela já foi paga: apagar levaria junto o registro do recebimento e o
 * caixa ficaria errado. Abre a própria transação.
 * Retorna ['ok' => bool, 'mensagem' => string].
 */
function excluirTurma(PDO $pdo, int $turmaId): array
{
    $stmt = $pdo->prepare('SELECT nome FROM turmas WHERE id = ?');
    $stmt->execute([$turmaId]);
    $nome = $stmt->fetchColumn();
    if ($nome === false) {
        return ['ok' => false, 'mensagem' => 'Turma não encontrada.'];
    }

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM contas_receber cr JOIN matriculas m ON m.id = cr.matricula_id
         WHERE m.turma_id = ? AND cr.status = 'pago'"
    );
    $stmt->execute([$turmaId]);
    if ((int) $stmt->fetchColumn() > 0) {
        return ['ok' => false, 'mensagem' => "Turma {$nome}: já tem mensalidade paga e não pode ser excluída. Mude a situação para Cancelada ou Concluída."];
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('DELETE ce FROM certificados ce JOIN matriculas m ON m.id = ce.matricula_id WHERE m.turma_id = ?');
        $stmt->execute([$turmaId]);
        $stmt = $pdo->prepare('DELETE cr FROM contas_receber cr JOIN matriculas m ON m.id = cr.matricula_id WHERE m.turma_id = ?');
        $stmt->execute([$turmaId]);
        $stmt = $pdo->prepare('DELETE FROM matriculas WHERE turma_id = ?');
        $stmt->execute([$turmaId]);
        $stmt = $pdo->prepare('DELETE FROM turmas WHERE id = ?');
        $stmt->execute([$turmaId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    registrarAtividade('turmas', 'exclusao', 'Turma excluída: ' . $nome);

    return ['ok' => true, 'mensagem' => ''];
}
