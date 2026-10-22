<?php

// Cupons de desconto da matrícula (portal e secretaria).
//   'valor'          → R$ a menos no valor do curso (mensalidades)
//   'percentual'     → % a menos no valor do curso
//   'matricula_zero' → taxa de matrícula zerada
// Uso = matrícula não cancelada com o cupom: inscrição do portal que vence
// sem pagar é cancelada e devolve o cupom.

function tiposCupom(): array
{
    return ['valor' => 'Valor em R$ (no curso)', 'percentual' => 'Percentual (no curso)', 'matricula_zero' => 'Matrícula zero'];
}

function normalizarCodigoCupom(string $codigo): string
{
    return mb_strtoupper(preg_replace('/\s+/', '', $codigo), 'UTF-8');
}

// "R$ 100,00 de desconto no curso", "10% de desconto no curso", "Matrícula grátis".
function textoDescontoCupom(array $cupom): string
{
    return match ($cupom['tipo']) {
        'valor' => formatMoney($cupom['valor']) . ' de desconto no curso',
        'percentual' => formatPercent($cupom['valor']) . ' de desconto no curso',
        default => 'Matrícula grátis',
    };
}

// Matrículas não canceladas com o cupom (usadas + reservadas). $travado:
// dentro da transação da matrícula, lê o que já foi gravado de fato (a
// leitura comum enxergaria uma "foto" do início da transação e deixaria
// passar quem se inscreveu ao mesmo tempo).
function usosCupom(PDO $pdo, int $cupomId, bool $travado = false): int
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM matriculas WHERE cupom_id = ? AND status <> 'cancelada'" . ($travado ? ' FOR SHARE' : ''));
    $stmt->execute([$cupomId]);
    return (int) $stmt->fetchColumn();
}

function cursosCupom(PDO $pdo, int $cupomId): array
{
    $stmt = $pdo->prepare('SELECT curso_id FROM cupom_cursos WHERE cupom_id = ?');
    $stmt->execute([$cupomId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Confere se o cupom vale para este curso (e aluno, quando já se sabe).
 * $travar = true dentro da transação da matrícula: segura o cupom até o
 * commit, para duas matrículas não usarem o último ao mesmo tempo.
 * Retorna ['ok' => bool, 'mensagem' => string, 'cupom' => ?array].
 */
function validarCupom(PDO $pdo, string $codigo, int $cursoId, ?int $alunoId = null, bool $travar = false): array
{
    $falha = fn (string $m) => ['ok' => false, 'mensagem' => $m, 'cupom' => null];
    $codigo = normalizarCodigoCupom($codigo);
    if ($codigo === '') {
        return $falha('Digite o código do cupom.');
    }
    $stmt = $pdo->prepare('SELECT * FROM cupons WHERE codigo = ?' . ($travar ? ' FOR UPDATE' : ''));
    $stmt->execute([$codigo]);
    $cupom = $stmt->fetch();
    $hoje = date('Y-m-d');
    if (!$cupom || !$cupom['ativo']) {
        return $falha('Cupom "' . $codigo . '" não encontrado.');
    }
    if ($cupom['validade_inicio'] && $hoje < $cupom['validade_inicio']) {
        return $falha('Este cupom só vale a partir de ' . formatDate($cupom['validade_inicio']) . '.');
    }
    if ($cupom['validade_fim'] && $hoje > $cupom['validade_fim']) {
        return $falha('Este cupom venceu em ' . formatDate($cupom['validade_fim']) . '.');
    }
    $cursos = cursosCupom($pdo, (int) $cupom['id']);
    if ($cursos && !in_array($cursoId, $cursos, true)) {
        return $falha('Este cupom não vale para este curso.');
    }
    if ($cupom['quantidade'] !== null && usosCupom($pdo, (int) $cupom['id'], $travar) >= (int) $cupom['quantidade']) {
        return $falha('Este cupom esgotou.');
    }
    if ($alunoId && $cupom['uma_vez_por_aluno']) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM matriculas WHERE cupom_id = ? AND aluno_id = ? AND status <> 'cancelada'" . ($travar ? ' FOR SHARE' : ''));
        $stmt->execute([$cupom['id'], $alunoId]);
        if ((int) $stmt->fetchColumn() > 0) {
            return $falha('Este cupom já foi usado por este aluno.');
        }
    }
    return ['ok' => true, 'mensagem' => textoDescontoCupom($cupom), 'cupom' => $cupom];
}

/**
 * Aplica o cupom aos valores da matrícula. Retorna o total do curso e a
 * taxa já com o cupom, e quanto ele descontou — ou erro se não há o que
 * descontar (ex.: matrícula zero numa turma sem taxa).
 * ['ok' => bool, 'mensagem' => string, 'valor_total' => float, 'taxa' => float, 'desconto' => float]
 */
function aplicarCupom(array $cupom, float $valorTotal, float $taxa): array
{
    $resultado = ['ok' => true, 'mensagem' => '', 'valor_total' => $valorTotal, 'taxa' => $taxa, 'desconto' => 0.0];
    if ($cupom['tipo'] === 'matricula_zero') {
        if ($taxa <= 0) {
            return ['ok' => false, 'mensagem' => 'Esta turma não tem taxa de matrícula para o cupom zerar.'] + $resultado;
        }
        return ['taxa' => 0.0, 'desconto' => round($taxa, 2)] + $resultado;
    }
    if ($valorTotal <= 0) {
        return ['ok' => false, 'mensagem' => 'Este curso não tem valor para o cupom descontar.'] + $resultado;
    }
    $desconto = $cupom['tipo'] === 'percentual'
        ? round($valorTotal * min(100, (float) $cupom['valor']) / 100, 2)
        : min(round((float) $cupom['valor'], 2), $valorTotal);
    return ['valor_total' => round($valorTotal - $desconto, 2), 'desconto' => $desconto] + $resultado;
}

// Situação do uso do cupom numa matrícula:
// [chave, rótulo, classe do selo, detalhe].
function situacaoUsoCupom(array $matricula): array
{
    return match ($matricula['status']) {
        'pendente' => ['reservado', 'Reservado', 'text-bg-warning',
            'Aguardando pagamento' . ($matricula['pagamento_ate'] ? ' até ' . formatDateTime($matricula['pagamento_ate']) : '')],
        'cancelada' => ['liberado', 'Liberado', 'text-bg-secondary', 'Inscrição cancelada — o cupom voltou'],
        'concluida' => ['usado', 'Usado', 'text-bg-success', 'Curso concluído'],
        default => ['usado', 'Usado', 'text-bg-success', 'Matrícula confirmada'],
    };
}

// Texto para mostrar o que o cupom fez numa matrícula.
function resumoCupomMatricula(PDO $pdo, array $matricula): string
{
    if (empty($matricula['cupom_id'])) {
        return '';
    }
    $stmt = $pdo->prepare('SELECT codigo, tipo FROM cupons WHERE id = ?');
    $stmt->execute([$matricula['cupom_id']]);
    $cupom = $stmt->fetch();
    if (!$cupom) {
        return '';
    }
    return 'Cupom ' . $cupom['codigo'] . ': ' . ($cupom['tipo'] === 'matricula_zero'
        ? 'matrícula grátis (' . formatMoney($matricula['cupom_desconto']) . ')'
        : formatMoney($matricula['cupom_desconto']) . ' de desconto no curso');
}
