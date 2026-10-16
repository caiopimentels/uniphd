<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirVerFinanceiro();

$pdo = getDB();
$id = (int) ($_GET['id'] ?? 0);
$turma = resultadoTurmas($pdo, ['turma_id' => $id])[0] ?? null;
if (!$turma) {
    flash('danger', 'Turma não encontrada.');
    redirect('/financeiro/turmas_resultado.php');
}

// Receitas por tipo: taxa de matrícula, mensalidades e o resto (lançado
// com outra categoria na matrícula).
$stmt = $pdo->prepare(
    "SELECT tipo,
            SUM(CASE WHEN status = 'pago' THEN COALESCE(valor_recebido, valor) ELSE 0 END) AS recebido,
            SUM(CASE WHEN status = 'pendente' THEN valor ELSE 0 END) AS a_receber,
            SUM(CASE WHEN status = 'pendente' AND data_vencimento < CURDATE() THEN valor ELSE 0 END) AS em_atraso
     FROM (
         SELECT CASE cf.chave WHEN 'taxa_matricula' THEN 'Taxas de matrícula' WHEN 'mensalidade' THEN 'Mensalidades' ELSE 'Outras' END AS tipo,
                cr.status, cr.valor, cr.valor_recebido, cr.data_vencimento
         FROM contas_receber cr
         JOIN matriculas m ON m.id = cr.matricula_id
         LEFT JOIN categorias_financeiras cf ON cf.id = cr.categoria_id
         WHERE m.turma_id = ? AND cr.status IN ('pendente', 'pago')
     ) x
     GROUP BY tipo ORDER BY FIELD(tipo, 'Taxas de matrícula', 'Mensalidades', 'Outras')"
);
$stmt->execute([$id]);
$receitas = $stmt->fetchAll();

$stmt = $pdo->prepare(
    "SELECT cp.*, cf.nome AS categoria_nome, f.nome AS fornecedor_nome
     FROM contas_pagar cp
     LEFT JOIN categorias_financeiras cf ON cf.id = cp.categoria_id
     LEFT JOIN fornecedores f ON f.id = cp.fornecedor_id
     WHERE cp.turma_id = ? AND cp.status IN ('pendente', 'pago')
     ORDER BY cp.data_vencimento, cp.id"
);
$stmt->execute([$id]);
$despesas = $stmt->fetchAll();

$porCategoria = [];
foreach ($despesas as $d) {
    $nome = $d['categoria_nome'] ?: 'Sem categoria';
    $porCategoria[$nome] ??= ['pago' => 0.0, 'a_pagar' => 0.0];
    $porCategoria[$nome][$d['status'] === 'pago' ? 'pago' : 'a_pagar'] += (float) $d['valor'];
}
uasort($porCategoria, fn ($a, $b) => ($b['pago'] + $b['a_pagar']) <=> ($a['pago'] + $a['a_pagar']));

$corResultado = fn (float $v) => $v < 0 ? 'text-danger' : ($v > 0 ? 'text-success' : '');
$hoje = date('Y-m-d');

$pageTitle = 'Resultado · ' . $turma['curso_nome'] . ' · ' . $turma['nome'];
$pageBreadcrumb = 'Relatórios · Resultado por turma';
$activeMenu = 'resultado-turmas';
$pageAction = '<a href="/financeiro/turmas_resultado.php" class="btn btn-outline-secondary">Voltar</a> '
    . '<a href="/turmas/visualizar.php?id=' . $id . '" class="btn btn-outline-secondary"><i class="bi bi-people"></i> Ver turma</a> '
    . '<button type="button" class="btn btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer"></i> Imprimir</button> '
    . (usuarioTemAcessoFinanceiro() ? '<a href="/financeiro/contas_pagar/form.php?turma_id=' . $id . '&voltar=resultado" class="btn btn-brand"><i class="bi bi-plus-lg"></i> Lançar despesa</a>' : '');
require __DIR__ . '/../../includes/layout_header.php';
?>

<p class="text-muted">
    <?= badgeSituacaoTurma($turma['situacao_nome'], $turma['situacao_cor']) ?>
    <?= e(periodoTurma($turma)) ?> · <?= e(textoDuracaoTurma($turma)) ?> · <?= (int) $turma['alunos'] ?> aluno(s)
    <?php if ($turma['data_conclusao']): ?> · concluída em <?= formatDate($turma['data_conclusao']) ?><?php endif; ?>
</p>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body">
        <div class="kpi-rotulo">Receita da turma</div>
        <div class="kpi-valor"><?= formatMoney($turma['receita_total']) ?></div>
        <div class="small text-muted">recebido <?= formatMoney($turma['recebido']) ?> · a receber <?= formatMoney($turma['a_receber']) ?></div>
        <?php if ((float) $turma['em_atraso'] > 0): ?><div class="small text-danger"><?= formatMoney($turma['em_atraso']) ?> em atraso</div><?php endif; ?>
    </div></div></div>
    <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body">
        <div class="kpi-rotulo">Quanto a turma custou</div>
        <div class="kpi-valor"><?= formatMoney($turma['despesa_total']) ?></div>
        <div class="small text-muted">pago <?= formatMoney($turma['despesa_paga']) ?> · a pagar <?= formatMoney($turma['despesa_a_pagar']) ?></div>
        <?php if ($turma['custo_por_aluno'] !== null): ?><div class="small text-muted"><?= formatMoney($turma['custo_por_aluno']) ?> por aluno</div><?php endif; ?>
    </div></div></div>
    <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body">
        <div class="kpi-rotulo">Resultado previsto</div>
        <div class="kpi-valor <?= $corResultado($turma['resultado_previsto']) ?>"><?= formatMoney($turma['resultado_previsto']) ?></div>
        <div class="small text-muted">receita − custo<?= $turma['receita_total'] > 0 ? ' · margem ' . formatPercent($turma['resultado_previsto'] / $turma['receita_total'] * 100) : '' ?></div>
    </div></div></div>
    <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body">
        <div class="kpi-rotulo">Em caixa hoje</div>
        <div class="kpi-valor <?= $corResultado($turma['resultado_realizado']) ?>"><?= formatMoney($turma['resultado_realizado']) ?></div>
        <div class="small text-muted">já recebido − já pago</div>
    </div></div></div>
</div>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="secao">
            <div class="secao-titulo"><i class="bi bi-arrow-up-circle"></i> Receitas</div>
            <div class="card"><div class="table-responsive">
                <table class="table mb-0 align-middle">
                    <thead><tr><th>Tipo</th><th class="text-end">Recebido</th><th class="text-end">A receber</th><th class="text-end">Total</th></tr></thead>
                    <tbody>
                    <?php if (!$receitas): ?><tr><td colspan="4" class="text-center text-muted py-3">Nenhuma parcela lançada.</td></tr><?php endif; ?>
                    <?php foreach ($receitas as $r): ?>
                        <tr>
                            <td><?= e($r['tipo']) ?></td>
                            <td class="text-end"><?= formatMoney($r['recebido']) ?></td>
                            <td class="text-end"><?= formatMoney($r['a_receber']) ?><?php if ((float) $r['em_atraso'] > 0): ?><div class="small text-danger"><?= formatMoney($r['em_atraso']) ?> em atraso</div><?php endif; ?></td>
                            <td class="text-end fw-semibold"><?= formatMoney((float) $r['recebido'] + (float) $r['a_receber']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div></div>
        </div>

        <div class="secao">
            <div class="secao-titulo"><i class="bi bi-pie-chart"></i> Custo por categoria</div>
            <div class="card"><div class="table-responsive">
                <table class="table mb-0 align-middle">
                    <thead><tr><th>Categoria</th><th class="text-end">Total</th><th class="text-end">% do custo</th></tr></thead>
                    <tbody>
                    <?php if (!$porCategoria): ?><tr><td colspan="3" class="text-center text-muted py-3">Nenhuma despesa lançada.</td></tr><?php endif; ?>
                    <?php foreach ($porCategoria as $nome => $c): $total = $c['pago'] + $c['a_pagar']; ?>
                        <tr>
                            <td><?= e($nome) ?><?php if ($c['a_pagar'] > 0): ?><div class="small text-muted"><?= formatMoney($c['a_pagar']) ?> a pagar</div><?php endif; ?></td>
                            <td class="text-end"><?= formatMoney($total) ?></td>
                            <td class="text-end"><?= $turma['despesa_total'] > 0 ? formatPercent($total / $turma['despesa_total'] * 100) : '-' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div></div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="secao">
            <div class="secao-titulo"><i class="bi bi-arrow-down-circle"></i> Despesas da turma</div>
            <div class="card"><div class="table-responsive">
                <table class="table mb-0 align-middle">
                    <thead><tr><th>Vencimento</th><th>Descrição</th><th>Categoria</th><th class="text-end">Valor</th><th>Situação</th></tr></thead>
                    <tbody>
                    <?php if (!$despesas): ?>
                        <tr><td colspan="5" class="text-center text-muted py-4">Nenhuma despesa lançada para esta turma.
                            <span class="nao-imprimir"><a href="/financeiro/contas_pagar/form.php?turma_id=<?= $id ?>&voltar=resultado">Lançar a primeira</a></span></td></tr>
                    <?php endif; ?>
                    <?php foreach ($despesas as $d): ?>
                        <tr>
                            <td><?= formatDate($d['data_vencimento']) ?></td>
                            <td><?= e($d['descricao']) ?><?php if ($d['fornecedor_nome']): ?><div class="small text-muted"><?= e($d['fornecedor_nome']) ?></div><?php endif; ?></td>
                            <td><?= e($d['categoria_nome'] ?: '-') ?></td>
                            <td class="text-end"><?= formatMoney($d['valor']) ?></td>
                            <td>
                                <?php if ($d['status'] === 'pago'): ?>
                                    <span class="badge text-bg-success">Pago <?= formatDate($d['data_pagamento']) ?></span>
                                <?php else: ?>
                                    <span class="badge <?= $d['data_vencimento'] < $hoje ? 'text-bg-danger' : 'text-bg-warning' ?>"><?= $d['data_vencimento'] < $hoje ? 'Vencida' : 'A pagar' ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <?php if ($despesas): ?>
                        <tfoot><tr><th colspan="3" class="text-end">Total</th><th class="text-end"><?= formatMoney($turma['despesa_total']) ?></th><th></th></tr></tfoot>
                    <?php endif; ?>
                </table>
            </div></div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
