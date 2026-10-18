<?php

// Categorias que o próprio sistema usa ao gerar lançamentos — buscadas pela
// chave (fixa), nunca pelo nome, que o usuário pode renomear.
function categoriaSistemaId(PDO $pdo, string $chave): ?int
{
    static $cache = [];
    if (!array_key_exists($chave, $cache)) {
        $stmt = $pdo->prepare('SELECT id FROM categorias_financeiras WHERE chave = ?');
        $stmt->execute([$chave]);
        $cache[$chave] = ((int) $stmt->fetchColumn()) ?: null;
    }
    return $cache[$chave];
}

function valorMensalidadeComDesconto(float $valorMensalidade, float $descontoPercentual): float
{
    return round($valorMensalidade * (1 - $descontoPercentual / 100), 2);
}

/**
 * Divide um valor em N parcelas sem perder centavo: todas iguais e a última
 * com a diferença (R$ 1.000 em 3x = 333,33 + 333,33 + 333,34).
 */
function dividirValor(float $total, int $parcelas): array
{
    $parcelas = max(1, $parcelas);
    // Em centavos inteiros, para não errar arredondamento (0,29 * 100 = 28,999...).
    $centavos = (int) round($total * 100);
    $parte = intdiv($centavos, $parcelas);
    $lista = array_fill(0, $parcelas, $parte / 100);
    $lista[$parcelas - 1] = ($centavos - $parte * ($parcelas - 1)) / 100;
    return $lista;
}

// Mensalidade de referência (a que aparece nas telas): total ÷ parcelas.
function mensalidadeDoTotal(float $total, int $parcelas): float
{
    return dividirValor($total, $parcelas)[0];
}

/**
 * Forma de pagamento que não gera boleto/Pix (cartão, dinheiro...): no
 * portal a parcela aparece como "pague na secretaria".
 */
function formaPagaNaSecretaria(PDO $pdo, ?int $formaId): ?string
{
    static $cache = [];
    if (!$formaId) {
        return null;
    }
    if (!array_key_exists($formaId, $cache)) {
        $stmt = $pdo->prepare('SELECT nome, instrumento FROM formas_pagamento WHERE id = ?');
        $stmt->execute([$formaId]);
        $forma = $stmt->fetch();
        $cache[$formaId] = $forma && $forma['instrumento'] === 'manual' ? $forma['nome'] : null;
    }
    return $cache[$formaId];
}

/**
 * O que a forma de pagamento da parcela deixa gerar: Pix (QR Code) e/ou
 * boleto. Pix -> só Pix; Boleto -> só boleto; Boleto + Pix -> os dois;
 * cartão/dinheiro (manual) -> nenhum. Parcela sem forma: os dois (antigas).
 * Retorna ['pix' => bool, 'boleto' => bool, 'nome' => string].
 */
function meiosCobrancaForma(PDO $pdo, ?int $formaId): array
{
    static $cache = [];
    if (!$formaId) {
        return ['pix' => true, 'boleto' => true, 'nome' => ''];
    }
    if (!isset($cache[$formaId])) {
        $stmt = $pdo->prepare('SELECT nome, instrumento FROM formas_pagamento WHERE id = ?');
        $stmt->execute([$formaId]);
        $f = $stmt->fetch() ?: ['nome' => '', 'instrumento' => 'boleto_pix'];
        $cache[$formaId] = [
            'pix' => in_array($f['instrumento'], ['pix', 'boleto_pix'], true),
            'boleto' => in_array($f['instrumento'], ['boleto', 'boleto_pix'], true),
            'nome' => $f['nome'],
        ];
    }
    return $cache[$formaId];
}

// Botões de Pix e boleto da parcela (listas do financeiro, aluno, matrícula):
// apagados quando a forma de pagamento não usa aquele meio.
function botoesCobrancaParcela(PDO $pdo, array $parcela): string
{
    $meios = meiosCobrancaForma($pdo, $parcela['forma_pagamento_id'] ? (int) $parcela['forma_pagamento_id'] : null);
    $id = (int) $parcela['id'];
    $motivo = fn (string $meio) => e('A forma de pagamento desta parcela é ' . $meios['nome'] . ': não gera ' . $meio . '. Para mudar, use o lápis (forma de pagamento).');
    return ($meios['pix']
            ? '<button type="button" class="btn btn-sm btn-outline-secondary" onclick="abrirPix(' . $id . ')" title="Gerar / ver Pix (QR Code e copia e cola)"><i class="bi bi-qr-code"></i></button>'
            : '<span class="btn btn-sm acao-inativa" title="' . $motivo('Pix') . '"><i class="bi bi-qr-code"></i></span>')
        . ' ' . ($meios['boleto']
            ? '<button type="button" class="btn btn-sm btn-outline-secondary" onclick="abrirBoleto(' . $id . ')" title="Gerar / abrir boleto"><i class="bi bi-upc-scan"></i></button>'
            : '<span class="btn btn-sm acao-inativa" title="' . $motivo('boleto') . '"><i class="bi bi-upc-scan"></i></span>');
}

// Formas que o portal consegue cobrar sozinho (boleto e/ou Pix no banco).
function formaCobraOnline(PDO $pdo, ?int $formaId): bool
{
    if (!$formaId) {
        return false;
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM formas_pagamento WHERE id = ? AND instrumento IN ('boleto', 'boleto_pix', 'pix')");
    $stmt->execute([$formaId]);
    return (int) $stmt->fetchColumn() > 0;
}

/**
 * Gera as cobranças de uma matrícula em contas a receber: a taxa de
 * matrícula (se houver, vencendo na data da matrícula, na forma da
 * matrícula) e as mensalidades mês a mês a partir do primeiro vencimento,
 * na forma das mensalidades. Com valor_total, o total (menos a bolsa) é
 * dividido pelas parcelas sem perder centavo. Tudo nasce emitido na data da
 * matrícula, mas o DRE conta cada mensalidade no mês do seu vencimento
 * (ver calcularDRE).
 */
function gerarCobrancasMatricula(PDO $pdo, array $matricula, string $turmaNome, ?int $usuarioId): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO contas_receber
            (aluno_id, matricula_id, categoria_id, descricao, valor, data_emissao, data_vencimento, forma_pagamento_id, status, usuario_id)
         VALUES (?,?,?,?,?,?,?,?,'pendente',?)"
    );

    if ((float) $matricula['taxa_matricula'] > 0) {
        $stmt->execute([
            $matricula['aluno_id'], $matricula['id'], categoriaSistemaId($pdo, 'taxa_matricula'),
            "Taxa de matrícula - {$turmaNome}", $matricula['taxa_matricula'],
            $matricula['data_matricula'], $matricula['data_matricula'],
            $matricula['forma_pagamento_id'] ?: null, $usuarioId,
        ]);
    }

    $numParcelas = max(1, (int) $matricula['num_parcelas']);
    if (isset($matricula['valor_total']) && $matricula['valor_total'] !== null) {
        $totalComBolsa = round((float) $matricula['valor_total'] * (1 - (float) $matricula['desconto_percentual'] / 100), 2);
        $valores = dividirValor($totalComBolsa, $numParcelas);
    } else {
        $valores = array_fill(0, $numParcelas, valorMensalidadeComDesconto((float) $matricula['valor_mensalidade'], (float) $matricula['desconto_percentual']));
    }
    if ($valores[0] <= 0) {
        return;
    }

    $formaMensalidade = ($matricula['forma_mensalidade_id'] ?? null) ?: ($matricula['forma_pagamento_id'] ?: null);
    foreach ($valores as $i => $valor) {
        $stmt->execute([
            $matricula['aluno_id'], $matricula['id'], categoriaSistemaId($pdo, 'mensalidade'),
            "Mensalidade " . ($i + 1) . "/{$numParcelas} - {$turmaNome}", $valor,
            $matricula['data_matricula'], somarMeses($matricula['primeiro_vencimento'], $i),
            $formaMensalidade, $usuarioId,
        ]);
    }
}

// Texto curto da regra de pontualidade de uma matrícula, para telas e
// mensagens ("R$ 50,00 por mensalidade, pagando até o vencimento").
function textoDescontoPontualidade(array $matricula): string
{
    if (($matricula['desconto_pontualidade_tipo'] ?? 'nenhum') === 'nenhum' || (float) $matricula['desconto_pontualidade_valor'] <= 0) {
        return 'Sem desconto por pontualidade';
    }
    $quanto = $matricula['desconto_pontualidade_tipo'] === 'valor'
        ? formatMoney($matricula['desconto_pontualidade_valor']) . ' por mensalidade'
        : formatPercent($matricula['desconto_pontualidade_valor']) . ' da mensalidade';
    $dias = (int) $matricula['desconto_pontualidade_dias'];
    $ate = $dias > 0 ? "até {$dias} dia(s) antes do vencimento" : 'até o vencimento';
    return "{$quanto}, pagando {$ate}";
}

/**
 * Desconto de pontualidade que vale para UMA parcela: quanto desconta, até
 * quando e quanto fica. Null quando não há desconto — a parcela não é
 * mensalidade (taxa de matrícula, lançamento avulso) ou a matrícula não
 * tem a regra.
 */
function descontoPontualidadeParcela(PDO $pdo, array $parcela): ?array
{
    // Desconto combinado só para esta parcela (modal de edição) vale por
    // cima da regra da matrícula; 0 = esta parcela não tem desconto.
    if (isset($parcela['desconto_valor']) && $parcela['desconto_valor'] !== null) {
        $valor = (float) $parcela['valor'];
        $desconto = min(round((float) $parcela['desconto_valor'], 2), $valor);
        if ($desconto <= 0) {
            return null;
        }
        $dataLimite = $parcela['desconto_ate'] ?: $parcela['data_vencimento'];
        return [
            'tipo' => 'valor',
            'percentual' => null,
            'desconto' => $desconto,
            'valor_com_desconto' => round($valor - $desconto, 2),
            'data_limite' => $dataLimite,
            'texto' => 'Pagando até ' . formatDate($dataLimite) . ', desconto de ' . formatMoney($desconto)
                . ' — pague ' . formatMoney($valor - $desconto) . '.',
        ];
    }

    if (!$parcela['matricula_id'] || (int) $parcela['categoria_id'] !== (int) categoriaSistemaId($pdo, 'mensalidade')) {
        return null;
    }

    static $matriculas = [];
    $matriculaId = (int) $parcela['matricula_id'];
    if (!array_key_exists($matriculaId, $matriculas)) {
        $stmt = $pdo->prepare('SELECT desconto_pontualidade_tipo, desconto_pontualidade_valor, desconto_pontualidade_dias FROM matriculas WHERE id = ?');
        $stmt->execute([$matriculaId]);
        $matriculas[$matriculaId] = $stmt->fetch() ?: null;
    }
    $regra = $matriculas[$matriculaId];
    if (!$regra || $regra['desconto_pontualidade_tipo'] === 'nenhum' || (float) $regra['desconto_pontualidade_valor'] <= 0) {
        return null;
    }

    $valor = (float) $parcela['valor'];
    $desconto = $regra['desconto_pontualidade_tipo'] === 'valor'
        ? min((float) $regra['desconto_pontualidade_valor'], $valor)
        : round($valor * (float) $regra['desconto_pontualidade_valor'] / 100, 2);
    if ($desconto <= 0) {
        return null;
    }

    $dataLimite = date('Y-m-d', strtotime($parcela['data_vencimento'] . ' -' . (int) $regra['desconto_pontualidade_dias'] . ' days'));

    return [
        'tipo' => $regra['desconto_pontualidade_tipo'],
        'percentual' => $regra['desconto_pontualidade_tipo'] === 'percentual' ? (float) $regra['desconto_pontualidade_valor'] : null,
        'desconto' => $desconto,
        'valor_com_desconto' => round($valor - $desconto, 2),
        'data_limite' => $dataLimite,
        'texto' => 'Pagando até ' . formatDate($dataLimite) . ', desconto de ' . formatMoney($desconto)
            . ' — pague ' . formatMoney($valor - $desconto) . '.',
    ];
}

/**
 * Ao cancelar uma matrícula, só deixam de ser cobradas as parcelas que
 * vencem a partir da data do cancelamento. O que já venceu e não foi pago
 * continua sendo dívida do aluno — cancelar não perdoa atraso.
 * Retorna quantas parcelas foram canceladas.
 */
// Parcela paga pelo banco (alias "cr"): boleto/Pix confirmado pelo Itaú.
// Essa baixa não se desfaz — o dinheiro entrou de verdade na conta.
// Parcela com boleto/Pix já enviado ao banco (alias "cr"): valor, vencimento
// e desconto estão gravados no Itaú e não mudam só aqui.
const SQL_BOLETO_NO_BANCO = "EXISTS (SELECT 1 FROM cobrancas_bancarias cbr WHERE cbr.conta_receber_id = cr.id AND cbr.status IN ('registrada', 'pendente_envio'))";

const SQL_BAIXA_PELO_BANCO = "(cr.baixa_origem = 'banco' OR EXISTS (SELECT 1 FROM cobrancas_bancarias cbp WHERE cbp.conta_receber_id = cr.id AND cbp.status = 'paga'))";

/**
 * Dados da parcela para o modal de edição (botão de lápis): os valores
 * atuais, o desconto da regra da matrícula (para mostrar como opção) e se
 * está travada por ter boleto no banco.
 */
function dadosEdicaoParcela(PDO $pdo, array $parcela): array
{
    $regra = descontoPontualidadeParcela($pdo, ['desconto_valor' => null] + $parcela);
    return [
        'id' => (int) $parcela['id'],
        'descricao' => $parcela['descricao'],
        'valor' => (float) $parcela['valor'],
        'vencimento' => $parcela['data_vencimento'],
        'desconto_valor' => $parcela['desconto_valor'] === null ? null : (float) $parcela['desconto_valor'],
        'desconto_ate' => $parcela['desconto_ate'],
        'observacao' => (string) $parcela['observacao'],
        'forma' => $parcela['forma_pagamento_id'] ? (int) $parcela['forma_pagamento_id'] : null,
        'regra' => $regra ? $regra['texto'] : null,
        'no_banco' => !empty($parcela['boleto_no_banco']),
    ];
}

// Filtro de contas a receber por aluno (alias "a" = alunos): pelo nome,
// sem diferenciar maiúsculas, ou pelos dígitos do CPF.
function filtroBuscaAlunoReceber(string $busca): array
{
    $digitos = preg_replace('/\D/', '', $busca);
    $sql = ' AND (a.nome LIKE ?';
    $params = ['%' . $busca . '%'];
    if (strlen($digitos) >= 3) {
        $sql .= " OR REPLACE(REPLACE(REPLACE(a.cpf, '.', ''), '-', ''), ' ', '') LIKE ?";
        $params[] = '%' . $digitos . '%';
    }
    return [$sql . ')', $params];
}

function cancelarCobrancasFuturasMatricula(PDO $pdo, int $matriculaId, string $dataCorte): int
{
    $stmt = $pdo->prepare("SELECT id FROM contas_receber WHERE matricula_id = ? AND status = 'pendente' AND data_vencimento >= ?");
    $stmt->execute([$matriculaId, $dataCorte]);
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (!$ids) {
        return 0;
    }
    $pdo->prepare("UPDATE contas_receber SET status = 'cancelado' WHERE id IN (" . implode(',', array_map('intval', $ids)) . ") AND status = 'pendente'")->execute();
    foreach ($ids as $parcelaId) {
        registrarHistoricoParcela($pdo, (int) $parcelaId, 'cancelamento', 'Cancelada junto com a matrícula');
    }
    agendarBaixaBoletosCancelados();
    return count($ids);
}

/**
 * Resumo financeiro de um aluno: o que já pagou, o que está em aberto e,
 * dentro do aberto, o que já venceu (atraso).
 */
function resumoFinanceiroAluno(PDO $pdo, int $alunoId): array
{
    $stmt = $pdo->prepare(
        "SELECT
            COALESCE(SUM(CASE WHEN status = 'pago' THEN COALESCE(valor_recebido, valor) END), 0) AS pago,
            COALESCE(SUM(CASE WHEN status = 'pendente' THEN valor END), 0) AS em_aberto,
            COALESCE(SUM(CASE WHEN status = 'pendente' AND data_vencimento < CURDATE() THEN valor END), 0) AS vencido,
            COUNT(CASE WHEN status = 'pendente' AND data_vencimento < CURDATE() THEN 1 END) AS parcelas_vencidas
         FROM contas_receber WHERE aluno_id = ?"
    );
    $stmt->execute([$alunoId]);
    $r = $stmt->fetch();

    return [
        'pago' => (float) $r['pago'],
        'em_aberto' => (float) $r['em_aberto'],
        'vencido' => (float) $r['vencido'],
        'parcelas_vencidas' => (int) $r['parcelas_vencidas'],
    ];
}

/**
 * Alunos com parcelas vencidas e não pagas, do maior atraso para o menor.
 * $filtros aceita turma_id e curso_id (0 = todos); quando filtrado, só
 * entram as parcelas daquela turma/curso.
 */
function listarInadimplentes(PDO $pdo, array $filtros = []): array
{
    $where = "cr.status = 'pendente' AND cr.data_vencimento < CURDATE() AND cr.aluno_id IS NOT NULL";
    $params = [];

    if (!empty($filtros['turma_id'])) {
        $where .= ' AND m.turma_id = ?';
        $params[] = (int) $filtros['turma_id'];
    }
    if (!empty($filtros['curso_id'])) {
        $where .= ' AND t.curso_id = ?';
        $params[] = (int) $filtros['curso_id'];
    }

    $stmt = $pdo->prepare(
        "SELECT a.id AS aluno_id, a.nome, a.telefone, a.email,
                COUNT(*) AS parcelas,
                SUM(cr.valor) AS valor_vencido,
                MIN(cr.data_vencimento) AS vencimento_mais_antigo,
                DATEDIFF(CURDATE(), MIN(cr.data_vencimento)) AS dias_atraso,
                GROUP_CONCAT(DISTINCT t.nome ORDER BY t.nome SEPARATOR ', ') AS turmas
         FROM contas_receber cr
         JOIN alunos a ON a.id = cr.aluno_id
         LEFT JOIN matriculas m ON m.id = cr.matricula_id
         LEFT JOIN turmas t ON t.id = m.turma_id
         WHERE {$where}
         GROUP BY a.id, a.nome, a.telefone, a.email
         ORDER BY dias_atraso DESC, valor_vencido DESC"
    );
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * DRE (regime de competência) do período, agrupado por categoria.
 *
 * Receitas contam pela data de VENCIMENTO: as mensalidades de uma
 * matrícula são todas emitidas no dia em que o aluno se matricula, então
 * contar pela emissão jogaria o curso inteiro no mês da matrícula. Parcela
 * já paga entra pelo valor efetivamente recebido (pode ter havido desconto
 * de pontualidade). Despesas contam pela
 * data de emissão (o mês a que a despesa se refere). Lançamentos cancelados
 * não entram.
 */
function calcularDRE(PDO $pdo, string $dataInicio, string $dataFim): array
{
    $stmt = $pdo->prepare(
        "SELECT c.nome AS categoria, COALESCE(SUM(IF(cr.status = 'pago', COALESCE(cr.valor_recebido, cr.valor), cr.valor)), 0) AS total
         FROM contas_receber cr
         LEFT JOIN categorias_financeiras c ON c.id = cr.categoria_id
         WHERE cr.status <> 'cancelado' AND cr.data_vencimento BETWEEN ? AND ?
         GROUP BY c.nome
         ORDER BY total DESC"
    );
    $stmt->execute([$dataInicio, $dataFim]);
    $receitasPorCategoria = $stmt->fetchAll();

    $stmt = $pdo->prepare(
        "SELECT c.nome AS categoria, COALESCE(SUM(cp.valor), 0) AS total
         FROM contas_pagar cp
         LEFT JOIN categorias_financeiras c ON c.id = cp.categoria_id
         WHERE cp.status <> 'cancelado' AND cp.data_emissao BETWEEN ? AND ?
         GROUP BY c.nome
         ORDER BY total DESC"
    );
    $stmt->execute([$dataInicio, $dataFim]);
    $despesasPorCategoria = $stmt->fetchAll();

    // Quebra das receitas de matrícula por curso — responde "qual curso
    // está trazendo dinheiro", sem alterar o total do DRE.
    $stmt = $pdo->prepare(
        "SELECT cu.nome AS curso, COALESCE(SUM(IF(cr.status = 'pago', COALESCE(cr.valor_recebido, cr.valor), cr.valor)), 0) AS total
         FROM contas_receber cr
         JOIN matriculas m ON m.id = cr.matricula_id
         JOIN turmas t ON t.id = m.turma_id
         JOIN cursos cu ON cu.id = t.curso_id
         WHERE cr.status <> 'cancelado' AND cr.data_vencimento BETWEEN ? AND ?
         GROUP BY cu.nome
         ORDER BY total DESC"
    );
    $stmt->execute([$dataInicio, $dataFim]);
    $receitasPorCurso = $stmt->fetchAll();

    $totalReceitas = array_sum(array_column($receitasPorCategoria, 'total'));
    $totalDespesas = array_sum(array_column($despesasPorCategoria, 'total'));

    return [
        'receitas_por_categoria' => $receitasPorCategoria,
        'total_receitas' => $totalReceitas,
        'despesas_por_categoria' => $despesasPorCategoria,
        'total_despesas' => $totalDespesas,
        'receitas_por_curso' => $receitasPorCurso,
        'resultado_liquido' => $totalReceitas - $totalDespesas,
    ];
}

/**
 * Fluxo de caixa (regime de caixa): só o que realmente entrou/saiu do caixa
 * (contas a receber/pagar já baixadas, pela data de recebimento/pagamento),
 * em ordem cronológica e com saldo acumulado — como um extrato de conta
 * corrente, para acompanhar a evolução real do caixa. Diferente do DRE
 * (regime de competência), que conta pelo mês a que o lançamento se refere,
 * independente de já ter sido recebido ou pago.
 *
 * $dataInicio/$dataFim vazios = sem limite naquele lado (todo o período).
 */
function calcularFluxoCaixa(PDO $pdo, string $dataInicio, string $dataFim): array
{
    // Saldo inicial = tudo que já tinha entrado/saído do caixa antes do
    // início do período — sem isso, o saldo acumulado dentro do período
    // não representaria o caixa real da empresa, só o líquido daqueles dias.
    $saldoInicial = 0.0;
    if ($dataInicio !== '') {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(COALESCE(valor_recebido, valor)), 0) FROM contas_receber WHERE status = 'pago' AND data_recebimento < ?");
        $stmt->execute([$dataInicio]);
        $saldoInicial += (float) $stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COALESCE(SUM(valor), 0) FROM contas_pagar WHERE status = 'pago' AND data_pagamento < ?");
        $stmt->execute([$dataInicio]);
        $saldoInicial -= (float) $stmt->fetchColumn();
    }

    $condEntrada = "cr.status = 'pago' AND cr.data_recebimento IS NOT NULL";
    $condSaida = "cp.status = 'pago' AND cp.data_pagamento IS NOT NULL";
    $paramsEntrada = [];
    $paramsSaida = [];

    if ($dataInicio !== '') {
        $condEntrada .= ' AND cr.data_recebimento >= ?';
        $condSaida .= ' AND cp.data_pagamento >= ?';
        $paramsEntrada[] = $dataInicio;
        $paramsSaida[] = $dataInicio;
    }
    if ($dataFim !== '') {
        $condEntrada .= ' AND cr.data_recebimento <= ?';
        $condSaida .= ' AND cp.data_pagamento <= ?';
        $paramsEntrada[] = $dataFim;
        $paramsSaida[] = $dataFim;
    }

    $stmt = $pdo->prepare(
        "SELECT cr.data_recebimento AS data, cr.descricao, COALESCE(cr.valor_recebido, cr.valor) AS valor, cr.criado_em,
                COALESCE(a.nome, c.nome, '-') AS origem
         FROM contas_receber cr
         LEFT JOIN alunos a ON a.id = cr.aluno_id
         LEFT JOIN categorias_financeiras c ON c.id = cr.categoria_id
         WHERE {$condEntrada}"
    );
    $stmt->execute($paramsEntrada);
    $entradas = $stmt->fetchAll();

    $stmt = $pdo->prepare(
        "SELECT cp.data_pagamento AS data, cp.descricao, cp.valor, cp.criado_em,
                COALESCE(f.nome, c.nome, '-') AS origem
         FROM contas_pagar cp
         LEFT JOIN fornecedores f ON f.id = cp.fornecedor_id
         LEFT JOIN categorias_financeiras c ON c.id = cp.categoria_id
         WHERE {$condSaida}"
    );
    $stmt->execute($paramsSaida);
    $saidas = $stmt->fetchAll();

    $movimentos = [];
    foreach ($entradas as $mov) {
        $movimentos[] = [
            'data' => $mov['data'], 'tipo' => 'entrada', 'descricao' => $mov['descricao'],
            'origem' => $mov['origem'], 'valor' => (float) $mov['valor'], 'criado_em' => $mov['criado_em'],
        ];
    }
    foreach ($saidas as $mov) {
        $movimentos[] = [
            'data' => $mov['data'], 'tipo' => 'saida', 'descricao' => $mov['descricao'],
            'origem' => $mov['origem'], 'valor' => (float) $mov['valor'], 'criado_em' => $mov['criado_em'],
        ];
    }

    usort($movimentos, fn ($a, $b) => [$a['data'], $a['criado_em']] <=> [$b['data'], $b['criado_em']]);

    $saldo = $saldoInicial;
    foreach ($movimentos as &$mov) {
        $saldo += $mov['tipo'] === 'entrada' ? $mov['valor'] : -$mov['valor'];
        $mov['saldo'] = $saldo;
    }
    unset($mov);

    return [
        'saldo_inicial' => $saldoInicial,
        'movimentos' => $movimentos,
        'total_entradas' => array_sum(array_column($entradas, 'valor')),
        'total_saidas' => array_sum(array_column($saidas, 'valor')),
        'saldo_final' => $saldo,
    ];
}

/**
 * Relatório de recebimentos: parcelas baixadas (status pago) por data em
 * que o dinheiro entrou, com o valor efetivamente recebido. Filtros:
 * data_inicio/data_fim (vazios = sem limite), forma_pagamento_id (número,
 * 'sem' = baixas sem forma informada, vazio = todas) e busca (descrição ou
 * nome do aluno). $pagina null = sem paginação (impressão).
 */
function buscarRecebimentos(PDO $pdo, array $filtros, ?int $pagina = 1, int $porPagina = 50): array
{
    $condicoes = ["cr.status = 'pago'", 'cr.data_recebimento IS NOT NULL'];
    $parametros = [];
    if (!empty($filtros['data_inicio'])) {
        $condicoes[] = 'cr.data_recebimento >= ?';
        $parametros[] = $filtros['data_inicio'];
    }
    if (!empty($filtros['data_fim'])) {
        $condicoes[] = 'cr.data_recebimento <= ?';
        $parametros[] = $filtros['data_fim'];
    }
    if (($filtros['forma_pagamento_id'] ?? '') === 'sem') {
        $condicoes[] = 'cr.forma_pagamento_id IS NULL';
    } elseif ((int) ($filtros['forma_pagamento_id'] ?? 0) > 0) {
        $condicoes[] = 'cr.forma_pagamento_id = ?';
        $parametros[] = (int) $filtros['forma_pagamento_id'];
    }
    if (!empty($filtros['busca'])) {
        $condicoes[] = '(cr.descricao LIKE ? OR a.nome LIKE ?)';
        $parametros[] = '%' . $filtros['busca'] . '%';
        $parametros[] = '%' . $filtros['busca'] . '%';
    }
    $from = 'FROM contas_receber cr
             LEFT JOIN alunos a ON a.id = cr.aluno_id
             LEFT JOIN formas_pagamento fp ON fp.id = cr.forma_pagamento_id
             WHERE ' . implode(' AND ', $condicoes);

    // Totais sempre do filtro inteiro, não só da página.
    $stmt = $pdo->prepare("SELECT COALESCE(fp.nome, 'Não informada') AS forma, COUNT(*) AS quantidade, SUM(COALESCE(cr.valor_recebido, cr.valor)) AS total
                           {$from} GROUP BY fp.id, fp.nome ORDER BY total DESC");
    $stmt->execute($parametros);
    $porForma = $stmt->fetchAll();
    $quantidade = (int) array_sum(array_column($porForma, 'quantidade'));
    $total = (float) array_sum(array_column($porForma, 'total'));

    $limite = '';
    if ($pagina !== null) {
        $totalPaginas = max(1, (int) ceil($quantidade / $porPagina));
        $pagina = min(max(1, $pagina), $totalPaginas);
        $limite = 'LIMIT ' . $porPagina . ' OFFSET ' . (($pagina - 1) * $porPagina);
    }

    $stmt = $pdo->prepare(
        "SELECT cr.id, cr.descricao, cr.aluno_id, cr.data_recebimento, cr.valor, COALESCE(cr.valor_recebido, cr.valor) AS valor_recebido,
                a.nome AS aluno_nome, COALESCE(fp.nome, 'Não informada') AS forma_nome
         {$from} ORDER BY cr.data_recebimento, cr.id {$limite}"
    );
    $stmt->execute($parametros);

    return [
        'itens' => $stmt->fetchAll(),
        'quantidade' => $quantidade,
        'total' => $total,
        'por_forma' => $porForma,
        'pagina' => $pagina,
        'total_paginas' => $pagina !== null ? $totalPaginas : 1,
    ];
}

/**
 * Resultado financeiro por turma: o que entrou e vai entrar (parcelas das
 * matrículas da turma) contra o que ela custou (contas a pagar ligadas a
 * ela). Canceladas ficam de fora. Parcela paga conta pelo valor recebido.
 * $filtros: situacao ('aberta' = aceita matrícula, 'concluida', 'cancelada'
 * ou vazio), curso_id, busca (nome da turma/curso), turma_id.
 */
function resultadoTurmas(PDO $pdo, array $filtros = []): array
{
    $where = '1=1';
    $params = [];
    if (($filtros['situacao'] ?? '') === 'aberta') {
        $where .= " AND s.comportamento IN ('aberta', 'em_andamento')";
    } elseif (in_array($filtros['situacao'] ?? '', ['concluida', 'cancelada'], true)) {
        $where .= ' AND s.comportamento = ?';
        $params[] = $filtros['situacao'];
    }
    if (!empty($filtros['curso_id'])) {
        $where .= ' AND t.curso_id = ?';
        $params[] = (int) $filtros['curso_id'];
    }
    if (($filtros['busca'] ?? '') !== '') {
        $where .= ' AND (t.nome LIKE ? OR c.nome LIKE ?)';
        array_push($params, '%' . $filtros['busca'] . '%', '%' . $filtros['busca'] . '%');
    }
    if (!empty($filtros['turma_id'])) {
        $where .= ' AND t.id = ?';
        $params[] = (int) $filtros['turma_id'];
    }

    $stmt = $pdo->prepare(
        "SELECT t.*, c.nome AS curso_nome, s.nome AS situacao_nome, s.cor AS situacao_cor, s.comportamento,
                (SELECT COUNT(*) FROM matriculas m WHERE m.turma_id = t.id AND m.status IN ('ativa', 'concluida')) AS alunos,
                COALESCE(r.recebido, 0) AS recebido, COALESCE(r.a_receber, 0) AS a_receber, COALESCE(r.em_atraso, 0) AS em_atraso,
                COALESCE(d.pago, 0) AS despesa_paga, COALESCE(d.a_pagar, 0) AS despesa_a_pagar
         FROM turmas t
         JOIN cursos c ON c.id = t.curso_id
         JOIN situacoes_turma s ON s.id = t.situacao_id
         LEFT JOIN (
             SELECT m.turma_id,
                    SUM(CASE WHEN cr.status = 'pago' THEN COALESCE(cr.valor_recebido, cr.valor) ELSE 0 END) AS recebido,
                    SUM(CASE WHEN cr.status = 'pendente' THEN cr.valor ELSE 0 END) AS a_receber,
                    SUM(CASE WHEN cr.status = 'pendente' AND cr.data_vencimento < CURDATE() THEN cr.valor ELSE 0 END) AS em_atraso
             FROM contas_receber cr JOIN matriculas m ON m.id = cr.matricula_id
             GROUP BY m.turma_id
         ) r ON r.turma_id = t.id
         LEFT JOIN (
             SELECT turma_id,
                    SUM(CASE WHEN status = 'pago' THEN valor ELSE 0 END) AS pago,
                    SUM(CASE WHEN status = 'pendente' THEN valor ELSE 0 END) AS a_pagar
             FROM contas_pagar WHERE turma_id IS NOT NULL
             GROUP BY turma_id
         ) d ON d.turma_id = t.id
         WHERE {$where}
         ORDER BY t.data_inicio IS NULL, t.data_inicio DESC, c.nome, t.nome"
    );
    $stmt->execute($params);
    $turmas = $stmt->fetchAll();
    foreach ($turmas as &$t) {
        $t['receita_total'] = (float) $t['recebido'] + (float) $t['a_receber'];
        $t['despesa_total'] = (float) $t['despesa_paga'] + (float) $t['despesa_a_pagar'];
        // Previsto: tudo o que a turma deve render menos tudo o que custa.
        // Realizado: o que já entrou menos o que já saiu do caixa.
        $t['resultado_previsto'] = $t['receita_total'] - $t['despesa_total'];
        $t['resultado_realizado'] = (float) $t['recebido'] - (float) $t['despesa_paga'];
        $t['custo_por_aluno'] = (int) $t['alunos'] > 0 ? $t['despesa_total'] / (int) $t['alunos'] : null;
    }
    unset($t);
    return $turmas;
}

/**
 * Lançamentos de contas a receber pelos filtros da tela (status,
 * data_inicio/data_fim do vencimento, busca por aluno). Usada pela lista e
 * pela impressão, para as duas mostrarem a mesma coisa.
 */
function buscarContasReceber(PDO $pdo, array $filtros): array
{
    $params = [];
    // Excluídas (já tinham passado pelo banco) só aparecem no filtro delas.
    if (($filtros['status'] ?? '') === 'excluido') {
        $where = 'cr.excluido_em IS NOT NULL';
    } else {
        $where = 'cr.excluido_em IS NULL';
        if (in_array($filtros['status'] ?? '', ['pendente', 'pago', 'cancelado'], true)) {
            $where .= ' AND cr.status = ?';
            $params[] = $filtros['status'];
        }
    }
    if (($filtros['data_inicio'] ?? '') !== '') {
        $where .= ' AND cr.data_vencimento >= ?';
        $params[] = $filtros['data_inicio'];
    }
    if (($filtros['data_fim'] ?? '') !== '') {
        $where .= ' AND cr.data_vencimento <= ?';
        $params[] = $filtros['data_fim'];
    }
    if (($filtros['busca'] ?? '') !== '') {
        [$sqlBusca, $paramsBusca] = filtroBuscaAlunoReceber($filtros['busca']);
        $where .= $sqlBusca;
        array_push($params, ...$paramsBusca);
    }
    $stmt = $pdo->prepare(
        "SELECT cr.*, a.nome AS aluno_nome, c.nome AS categoria_nome, c.chave AS categoria_chave,
                t.nome AS turma_nome, cu.nome AS curso_nome, " . SQL_BAIXA_PELO_BANCO . " AS pago_pelo_banco, " . SQL_BOLETO_NO_BANCO . " AS boleto_no_banco,
                EXISTS (SELECT 1 FROM cobrancas_bancarias cbx WHERE cbx.conta_receber_id = cr.id) AS passou_pelo_banco
         FROM contas_receber cr
         LEFT JOIN alunos a ON a.id = cr.aluno_id
         LEFT JOIN categorias_financeiras c ON c.id = cr.categoria_id
         LEFT JOIN matriculas m ON m.id = cr.matricula_id
         LEFT JOIN turmas t ON t.id = m.turma_id
         LEFT JOIN cursos cu ON cu.id = t.curso_id
         WHERE {$where}
         ORDER BY cr.data_vencimento ASC"
    );
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function opcoesAgruparReceber(): array
{
    return ['' => 'Sem agrupar (por data)', 'turma' => 'Por turma', 'curso' => 'Por curso', 'aluno' => 'Por aluno', 'tipo' => 'Por tipo'];
}

// Parcela paga conta pelo que entrou de fato (pode ter tido desconto de
// pontualidade); as demais, pelo valor da parcela.
function valorExibidoReceber(array $c): float
{
    return $c['status'] === 'pago' && $c['valor_recebido'] !== null ? (float) $c['valor_recebido'] : (float) $c['valor'];
}

/**
 * Agrupa os lançamentos (turma, curso, aluno ou tipo). O que não tem
 * turma/aluno vai para um grupo "sem ..." no fim; dentro do grupo segue a
 * ordem da data. Cada grupo tem uma chave curta e estável ('id') para a
 * tela lembrar quais estão abertos e a impressão repetir isso.
 * Sem agrupamento, devolve um grupo só com tudo.
 */
function agruparContasReceber(array $contas, string $agrupar): array
{
    $grupos = [];
    foreach ($contas as $c) {
        [$chave, $nome] = match ($agrupar) {
            'turma' => $c['turma_nome'] ? ['t' . $c['curso_nome'] . ' · ' . $c['turma_nome'], $c['curso_nome'] . ' · ' . $c['turma_nome']] : ['~', 'Sem turma (lançamentos avulsos)'],
            'curso' => $c['curso_nome'] ? ['c' . $c['curso_nome'], $c['curso_nome']] : ['~', 'Sem curso (lançamentos avulsos)'],
            'aluno' => $c['aluno_id'] ? ['a' . $c['aluno_nome'] . $c['aluno_id'], $c['aluno_nome']] : ['~', 'Sem aluno'],
            'tipo' => match (true) {
                $c['matricula_id'] && $c['categoria_chave'] === 'taxa_matricula' => ['1', 'Taxa de matrícula'],
                $c['matricula_id'] && $c['categoria_chave'] === 'mensalidade' => ['2', 'Mensalidade'],
                default => ['3', 'Avulso'],
            },
            default => ['', ''],
        };
        $grupos[$chave] ??= ['id' => substr(md5($agrupar . '|' . $chave), 0, 10), 'nome' => $nome, 'contas' => [], 'total' => 0.0,
            'aluno_id' => $agrupar === 'aluno' ? $c['aluno_id'] : null];
        $grupos[$chave]['contas'][] = $c;
        $grupos[$chave]['total'] += valorExibidoReceber($c);
    }
    ksort($grupos, SORT_NATURAL | SORT_FLAG_CASE);
    return array_values($grupos);
}

/* ---------------- Matrícula de aluno inadimplente ---------------- */

// Quanto o aluno tem vencido e não pago (todas as turmas e avulsos).
function valorAtrasoAluno(PDO $pdo, int $alunoId): float
{
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(valor), 0) FROM contas_receber WHERE aluno_id = ? AND status = 'pendente' AND data_vencimento < CURDATE()");
    $stmt->execute([$alunoId]);
    return (float) $stmt->fetchColumn();
}

// 'permitir', 'bloquear' ou 'autorizar' (Configurações › Matrículas).
function regraMatriculaInadimplente(PDO $pdo): string
{
    $regra = buscarConfiguracoes($pdo)['matricula_inadimplente'] ?? 'permitir';
    return in_array($regra, ['bloquear', 'autorizar'], true) ? $regra : 'permitir';
}

// Liberação aprovada por um administrador e ainda não usada (vale 2 dias).
function liberacaoAprovadaAluno(PDO $pdo, int $alunoId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT l.*, u.nome AS decidido_por_nome FROM liberacoes_inadimplencia l LEFT JOIN usuarios u ON u.id = l.decidido_por
         WHERE l.aluno_id = ? AND l.status = 'aprovada' AND l.decidido_em >= NOW() - INTERVAL 2 DAY
         ORDER BY l.decidido_em DESC LIMIT 1"
    );
    $stmt->execute([$alunoId]);
    return $stmt->fetch() ?: null;
}

/**
 * Confere usuário (e-mail) e senha de um administrador ativo, para liberar
 * na hora uma matrícula de inadimplente. Com limite de tentativas.
 * Retorna o administrador ou uma mensagem de erro.
 */
function validarAdministradorPresente(PDO $pdo, string $email, string $senha): array
{
    $email = mb_strtolower(trim($email));
    if ($email === '' || $senha === '') {
        return ['ok' => false, 'mensagem' => 'Informe o e-mail e a senha do administrador.'];
    }
    if (limiteDeTentativasExcedido($pdo, 'liberacao_admin', $email, 5, 20, 15)) {
        return ['ok' => false, 'mensagem' => 'Muitas tentativas. Aguarde alguns minutos.'];
    }
    $stmt = $pdo->prepare(
        'SELECT u.id, u.nome, u.senha FROM usuarios u JOIN perfis p ON p.id = u.perfil_id
         WHERE LOWER(u.email) = ? AND u.ativo = 1 AND p.administrador = 1'
    );
    $stmt->execute([$email]);
    $admin = $stmt->fetch();
    if (!$admin || !password_verify($senha, $admin['senha'])) {
        registrarTentativaAcesso($pdo, 'liberacao_admin', $email);
        return ['ok' => false, 'mensagem' => 'Usuário ou senha de administrador inválidos.'];
    }
    limparTentativasAcesso($pdo, 'liberacao_admin', $email);
    return ['ok' => true, 'id' => (int) $admin['id'], 'nome' => $admin['nome']];
}


// ---------------------------------------------------------------------
// Histórico da parcela
// ---------------------------------------------------------------------

/**
 * Grava um acontecimento no histórico da parcela (edição, baixa manual,
 * estorno, cancelamento...). Quem fez sai da sessão: usuário do sistema,
 * aluno no portal ou "Sistema". Nunca derruba a operação principal.
 */
function registrarHistoricoParcela(PDO $pdo, int $contaReceberId, string $tipo, string $descricao): void
{
    try {
        $usuarioId = function_exists('usuarioLogado') && usuarioLogado() ? usuarioAtualId() : null;
        $nome = $usuarioId ? usuarioAtualNome() : (function_exists('alunoLogado') && alunoLogado() ? 'Aluno pelo portal' : 'Sistema');
        $pdo->prepare('INSERT INTO parcela_historico (conta_receber_id, usuario_id, usuario_nome, tipo, descricao) VALUES (?, ?, ?, ?, ?)')
            ->execute([$contaReceberId, $usuarioId, $nome, $tipo, mb_strimwidth($descricao, 0, 255, '…', 'UTF-8')]);
    } catch (Throwable $e) {
        error_log('[Histórico parcela] ' . $e->getMessage());
    }
}

/**
 * Linha do tempo da parcela, do lançamento ao pagamento: junta o histórico
 * gravado, o que foi trocado com o banco (auditoria) e os avisos de
 * pagamento recebidos. Cada item: ['quando', 'quem', 'titulo', 'detalhe',
 * 'icone', 'cor'], em ordem cronológica.
 */
function historicoParcela(PDO $pdo, int $contaReceberId): array
{
    $stmt = $pdo->prepare(
        'SELECT cr.*, u.nome AS lancado_por, m.id AS matricula FROM contas_receber cr
         LEFT JOIN usuarios u ON u.id = cr.usuario_id LEFT JOIN matriculas m ON m.id = cr.matricula_id WHERE cr.id = ?'
    );
    $stmt->execute([$contaReceberId]);
    $parcela = $stmt->fetch();
    if (!$parcela) {
        return [];
    }
    $itens = [];
    // $ordem desempata o que aconteceu no mesmo segundo: lançamento, depois
    // o banco (a instrução vai antes de salvar), depois o histórico, depois avisos.
    $item = fn ($quando, $quem, $titulo, $detalhe, $icone, $cor, $ordem = 2) => compact('quando', 'quem', 'titulo', 'detalhe', 'icone', 'cor', 'ordem');

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM parcela_historico WHERE conta_receber_id = ? AND tipo = 'edicao'");
    $stmt->execute([$contaReceberId]);
    $foiAlterada = (int) $stmt->fetchColumn() > 0;
    $itens[] = $item($parcela['criado_em'], $parcela['lancado_por'] ?: ($parcela['matricula'] ? 'Sistema' : '-'),
        'Lançamento', trim(($parcela['matricula'] ? 'Gerada pela matrícula' : '')
            . ($foiAlterada ? '' : ($parcela['matricula'] ? ' · ' : '') . formatMoney($parcela['valor']) . ' com vencimento em ' . formatDate($parcela['data_vencimento']))),
        'bi-plus-circle', 'secondary', 0);

    $estilos = [
        'edicao' => ['Alteração', 'bi-pencil', 'warning'],
        'baixa' => ['Baixa manual', 'bi-check-circle', 'success'],
        'estorno' => ['Estorno da baixa', 'bi-arrow-counterclockwise', 'danger'],
        'cancelamento' => ['Cancelamento', 'bi-x-circle', 'danger'],
    ];
    $stmt = $pdo->prepare('SELECT * FROM parcela_historico WHERE conta_receber_id = ? ORDER BY id');
    $stmt->execute([$contaReceberId]);
    $temBaixaManual = false;
    foreach ($stmt->fetchAll() as $h) {
        [$titulo, $icone, $cor] = $estilos[$h['tipo']] ?? [ucfirst($h['tipo']), 'bi-dot', 'secondary'];
        $temBaixaManual = $temBaixaManual || $h['tipo'] === 'baixa';
        $itens[] = $item($h['criado_em'], $h['usuario_nome'] ?: 'Sistema', $titulo, $h['descricao'], $icone, $cor);
    }

    // O que foi enviado ao banco (sem pedidos de token e consultas).
    $rotulos = [
        'registro_boleto' => ['Boleto + Pix gerado no Itaú', 'Itaú recusou gerar o boleto', 'bi-bank'],
        'baixa_boleto' => ['Boleto baixado (cancelado) no Itaú', 'Itaú recusou a baixa do boleto', 'bi-bank'],
        'alteracao_valor' => ['Valor do boleto alterado no Itaú', 'Itaú recusou mudar o valor', 'bi-bank'],
        'alteracao_vencimento' => ['Vencimento do boleto alterado no Itaú', 'Itaú recusou mudar o vencimento', 'bi-bank'],
        'alteracao_desconto' => ['Desconto do boleto alterado no Itaú', 'Itaú recusou mudar o desconto', 'bi-bank'],
    ];
    $stmt = $pdo->prepare(
        "SELECT bc.*, u.nome AS usuario_nome, a.nome AS aluno_nome FROM banco_chamadas bc
         LEFT JOIN usuarios u ON u.id = bc.usuario_id LEFT JOIN alunos a ON a.id = bc.aluno_id
         WHERE bc.conta_receber_id = ? AND bc.operacao IN ('" . implode("','", array_keys($rotulos)) . "') ORDER BY bc.id"
    );
    $stmt->execute([$contaReceberId]);
    foreach ($stmt->fetchAll() as $c) {
        [$ok, $falha, $icone] = $rotulos[$c['operacao']];
        $quem = $c['origem'] === 'portal' ? ($c['aluno_nome'] ?: 'Aluno') . ' (portal)' : ($c['origem'] === 'automatico' ? 'Sistema' : ($c['usuario_nome'] ?: '-'));
        $numero = nossoNumeroDaChamadaBanco($c);
        $corpo = json_decode((string) $c['requisicao'], true) ?: [];
        $detalhe = $numero ? 'Nosso nº ' . formatarNossoNumeroItau($numero) : '';
        if ($c['operacao'] === 'alteracao_valor' && isset($corpo['valor_titulo'])) {
            $detalhe .= ' · novo valor ' . formatMoney((float) $corpo['valor_titulo']);
        } elseif ($c['operacao'] === 'alteracao_vencimento' && isset($corpo['data_vencimento'])) {
            $detalhe .= ' · novo vencimento ' . formatDate($corpo['data_vencimento']);
        } elseif ($c['operacao'] === 'registro_boleto' && $c['sucesso']) {
            $individual = ($corpo['data'] ?? $corpo)['dado_boleto']['dados_individuais_boleto'][0] ?? [];
            $valorBoleto = $individual['valor_titulo'] ?? null;
            if ($valorBoleto !== null) {
                // Cobrança v2 manda o valor em 17 dígitos com 2 casas.
                $valorBoleto = ctype_digit((string) $valorBoleto) ? (int) $valorBoleto / 100 : (float) $valorBoleto;
                $detalhe .= ' · ' . formatMoney($valorBoleto) . (isset($individual['data_vencimento']) ? ' para ' . formatDate($individual['data_vencimento']) : '');
            }
        }
        if (!$c['sucesso']) {
            $detalhe .= ($detalhe ? ' · ' : '') . ($c['mensagem'] ?: 'HTTP ' . $c['status_http']);
        } elseif (str_contains((string) $c['resposta'], 'Aguardando aprova')) {
            $detalhe .= ' · aguardando aprovação no Itaú';
        }
        $itens[] = $item($c['criado_em'], $quem, $c['sucesso'] ? $ok : $falha, $detalhe, $icone, $c['sucesso'] ? 'primary' : 'danger', 1);
    }

    // Avisos de pagamento que o banco mandou.
    $stmt = $pdo->prepare('SELECT * FROM webhook_eventos WHERE conta_receber_id = ? ORDER BY id');
    $stmt->execute([$contaReceberId]);
    foreach ($stmt->fetchAll() as $ev) {
        $tipo = $ev['tipo_notificacao'] === 'PIX' ? 'Pix' : 'boleto' . ($ev['tipo_notificacao'] ? ' (' . strtolower(str_replace('_', ' ', $ev['tipo_notificacao'])) . ')' : '');
        $pago = $ev['status'] === 'processado';
        $itens[] = $item($ev['criado_em'], 'Itaú',
            $pago ? 'Pago — aviso de ' . $tipo : 'Aviso de ' . $tipo . ' recebido',
            trim(($ev['valor_pago'] !== null ? formatMoney($ev['valor_pago']) : '') . ($ev['data_pagamento'] ? ' em ' . formatDate($ev['data_pagamento']) : '') . ' · ' . $ev['mensagem'], ' ·'),
            $pago ? 'bi-cash-coin' : 'bi-broadcast', $pago ? 'success' : 'secondary', 3);
    }

    // Baixa manual de antes do histórico existir.
    if ($parcela['status'] === 'pago' && $parcela['baixa_origem'] === 'manual' && !$temBaixaManual) {
        $itens[] = $item($parcela['data_recebimento'] . ' 12:00:00', '-', 'Baixa manual',
            formatMoney($parcela['valor_recebido'] ?? $parcela['valor']) . ' recebido em ' . formatDate($parcela['data_recebimento']), 'bi-check-circle', 'success');
    }

    usort($itens, fn ($a, $b) => [(string) $a['quando'], $a['ordem']] <=> [(string) $b['quando'], $b['ordem']]);
    return $itens;
}

/**
 * Exclui uma parcela a receber. Se ela já passou pelo banco (tem cobrança),
 * não some: o boleto registrado recebe a baixa no banco antes (sem a baixa,
 * nada muda) e a parcela fica como cancelada, guardando o histórico.
 * Parcela paga pelo banco não sai. Retorna ['ok' => bool, 'mensagem' =>
 * string, 'resultado' => 'excluida'|'cancelada'|null, 'aguardando' => bool].
 */
function excluirParcelaReceber(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare('SELECT * FROM contas_receber WHERE id = ?');
    $stmt->execute([$id]);
    $conta = $stmt->fetch();
    if (!$conta) {
        return ['ok' => false, 'mensagem' => 'Lançamento não encontrado.', 'resultado' => null, 'aguardando' => false];
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM cobrancas_bancarias WHERE conta_receber_id = ?');
    $stmt->execute([$id]);
    if ((int) $stmt->fetchColumn() === 0) {
        $pdo->prepare('DELETE FROM contas_receber WHERE id = ?')->execute([$id]);
        if ($conta['aluno_id']) {
            sincronizarDocumentosAluno($pdo, (int) $conta['aluno_id']); // pode ter saído da inadimplência
        }
        registrarAtividade('contas_receber', 'exclusao', 'Conta a receber excluída: ' . $conta['descricao'] . ' - ' . formatMoney($conta['valor']));
        return ['ok' => true, 'mensagem' => 'Lançamento excluído.', 'resultado' => 'excluida', 'aguardando' => false];
    }

    if ($conta['status'] === 'pago' && ($conta['baixa_origem'] ?? '') === 'banco') {
        return ['ok' => false, 'mensagem' => $conta['descricao'] . ': já foi paga pelo banco e não pode ser excluída.', 'resultado' => null, 'aguardando' => false];
    }
    if ($conta['excluido_em']) {
        return ['ok' => true, 'mensagem' => $conta['descricao'] . ' já estava excluída.', 'resultado' => 'cancelada', 'aguardando' => false];
    }
    $baixa = ['ok' => true, 'havia_boleto' => false, 'mensagem' => ''];
    if ($conta['status'] === 'pendente') {
        $baixa = baixarBoletoBancoItau($pdo, $id);
        if (!$baixa['ok']) {
            return ['ok' => false, 'mensagem' => $conta['descricao'] . ': ' . $baixa['mensagem'] . ' A parcela não foi excluída.', 'resultado' => null, 'aguardando' => false];
        }
    }
    // Já passou pelo banco: não apaga (o histórico do boleto fica); some das
    // telas e vai para o filtro "Excluídas".
    $pdo->prepare("UPDATE contas_receber SET status = 'cancelado', excluido_em = NOW() WHERE id = ?")->execute([$id]);
    sincronizarDocumentosPelaParcela($pdo, $id); // pode ter saído da inadimplência
    registrarHistoricoParcela($pdo, $id, 'cancelamento', 'Excluída pelo usuário' . ($baixa['havia_boleto'] ? ' — boleto baixado no banco' : '') . ' (fica no filtro "Excluídas", porque já passou pelo banco)');
    registrarAtividade('contas_receber', 'exclusao', 'Conta a receber excluída (já tinha passado pelo banco): ' . $conta['descricao'] . ' - ' . formatMoney($conta['valor']));
    $aguardando = str_contains($baixa['mensagem'], AVISO_APROVACAO_ITAU);
    return [
        'ok' => true,
        'resultado' => 'cancelada',
        'aguardando' => $aguardando,
        'mensagem' => ($baixa['havia_boleto'] ? ($aguardando ? $baixa['mensagem'] . ' ' : 'Boleto baixado no banco. ') : '')
            . 'Parcela excluída — como já passou pelo banco, ela fica guardada no filtro "Excluídas".',
    ];
}

/**
 * Muda a forma de pagamento de uma parcela em aberto. Indo para uma forma
 * que não é boleto/Pix (cartão, dinheiro...), o boleto que já estiver no
 * banco é baixado antes — o aluno não pode pagar duas vezes. Se o banco
 * recusar a baixa, nada muda.
 * Retorna ['ok' => bool, 'mensagem' => string, 'mudou' => bool].
 */
function trocarFormaParcela(PDO $pdo, int $id, ?int $formaNova): array
{
    $stmt = $pdo->prepare('SELECT cr.*, fp.nome AS forma_nome FROM contas_receber cr LEFT JOIN formas_pagamento fp ON fp.id = cr.forma_pagamento_id WHERE cr.id = ?');
    $stmt->execute([$id]);
    $parcela = $stmt->fetch();
    if (!$parcela || $parcela['status'] !== 'pendente' || $parcela['excluido_em']) {
        return ['ok' => false, 'mensagem' => 'Só parcelas em aberto mudam de forma de pagamento.', 'mudou' => false];
    }
    if ((int) $parcela['forma_pagamento_id'] === (int) $formaNova) {
        return ['ok' => true, 'mensagem' => '', 'mudou' => false];
    }
    $stmt = $pdo->prepare('SELECT nome FROM formas_pagamento WHERE id = ?');
    $stmt->execute([$formaNova]);
    $nomeNova = $formaNova ? (string) $stmt->fetchColumn() : '';
    if ($formaNova && $nomeNova === '') {
        return ['ok' => false, 'mensagem' => 'Forma de pagamento não encontrada.', 'mudou' => false];
    }

    $aviso = '';
    if (!formaCobraOnline($pdo, $formaNova)) {
        $baixa = baixarBoletoBancoItau($pdo, $id);
        if (!$baixa['ok']) {
            return ['ok' => false, 'mensagem' => $parcela['descricao'] . ': ' . $baixa['mensagem'] . ' A forma de pagamento não foi mudada.', 'mudou' => false];
        }
        $aviso = $baixa['havia_boleto'] ? ' — boleto/Pix baixado no banco' : '';
    }
    $pdo->prepare('UPDATE contas_receber SET forma_pagamento_id = ? WHERE id = ?')->execute([$formaNova, $id]);
    registrarHistoricoParcela($pdo, $id, 'edicao', 'Forma de pagamento: ' . ($parcela['forma_nome'] ?: 'nenhuma') . ' → ' . ($nomeNova ?: 'nenhuma') . $aviso);
    return ['ok' => true, 'mensagem' => $parcela['descricao'] . ': ' . ($nomeNova ?: 'sem forma') . $aviso, 'mudou' => true,
        'resumo' => ($parcela['forma_nome'] ?: 'nenhuma') . ' → ' . ($nomeNova ?: 'nenhuma') . $aviso];
}

/**
 * Nome da forma de pagamento com o tipo de cobrança, para as listas de
 * escolha: "Boleto Bancário (boleto + Pix)", "Cartão de crédito (na
 * secretaria)". Não repete o que o nome já diz ("Pix", não "Pix (Pix)").
 */
function rotuloFormaPagamento(array $forma): string
{
    $nome = (string) $forma['nome'];
    $instrumento = $forma['instrumento'] ?? null;
    if ($instrumento === null) {
        return $nome;
    }
    $minusculo = mb_strtolower($nome, 'UTF-8');
    $temBoleto = str_contains($minusculo, 'boleto');
    $temPix = str_contains($minusculo, 'pix');
    $tipo = match ($instrumento) {
        'boleto_pix' => $temBoleto && $temPix ? '' : 'boleto + Pix',
        'boleto' => $temBoleto ? '' : 'boleto',
        'pix' => $temPix ? '' : 'Pix',
        default => 'na secretaria',
    };
    return $tipo === '' ? $nome : $nome . ' (' . $tipo . ')';
}
