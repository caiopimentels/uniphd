<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirAdmin();

// Tarifas que o banco cobrou (emissão, liquidação, baixa, alteração), anotadas
// pelo sistema quando cada operação aconteceu, com o valor configurado na
// conta. Serve para conferir o extrato e lançar como despesa.
$pdo = getDB();
$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM contas_bancarias WHERE id = ?');
$stmt->execute([$id]);
$conta = $stmt->fetch();
if (!$conta) {
    flash('danger', 'Conta bancária não encontrada.');
    redirect('/contas_bancarias/listar.php');
}

$inicio = $_GET['data_inicio'] ?? date('Y-m-01');
$fim = $_GET['data_fim'] ?? date('Y-m-t');
if (!strtotime($inicio) || !strtotime($fim)) {
    [$inicio, $fim] = [date('Y-m-01'), date('Y-m-t')];
}

$stmt = $pdo->prepare(
    'SELECT bt.*, cr.descricao AS parcela_descricao, a.nome AS aluno_nome, cb.nosso_numero
     FROM banco_tarifas bt
     LEFT JOIN contas_receber cr ON cr.id = bt.conta_receber_id
     LEFT JOIN alunos a ON a.id = cr.aluno_id
     LEFT JOIN cobrancas_bancarias cb ON cb.id = bt.cobranca_id
     WHERE bt.conta_bancaria_id = ? AND bt.data BETWEEN ? AND ?
     ORDER BY bt.data DESC, bt.id DESC'
);
$stmt->execute([$id, $inicio, $fim]);
$tarifas = $stmt->fetchAll();
$tipos = tiposTarifaBanco();
$resumo = array_fill_keys(array_keys($tipos), ['quantidade' => 0, 'total' => 0.0]);
foreach ($tarifas as $t) {
    $resumo[$t['tipo']]['quantidade']++;
    $resumo[$t['tipo']]['total'] += (float) $t['valor'];
}
$totalGeral = array_sum(array_column($resumo, 'total'));
$semTarifa = (float) $conta['tarifa_emissao'] + (float) $conta['tarifa_liquidacao'] + (float) $conta['tarifa_baixa'] + (float) $conta['tarifa_alteracao'] <= 0;

$pageTitle = 'Configurações';
$pageBreadcrumb = 'Administração · Contas bancárias · Tarifas';
$activeMenu = 'configuracoes';
$pageAction = '<a href="/contas_bancarias/listar.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Contas bancárias</a>';
require __DIR__ . '/../../includes/layout_header.php';
$abaAtiva = 'contas';
require __DIR__ . '/../../includes/abas_configuracoes.php';
$contaAbaId = $id;
$contaAba = 'tarifas';
require __DIR__ . '/../../includes/abas_conta_bancaria.php';
?>

<?php if ($semTarifa): ?>
    <div class="alert alert-warning" style="max-width: 900px;">
        Nenhuma tarifa configurada nesta conta. Informe os valores em <a href="/contas_bancarias/form.php?id=<?= $id ?>">Dados da conta › Tarifas do banco</a>;
        daí em diante cada operação passa a ser anotada aqui.
    </div>
<?php endif; ?>

<div class="filtros">
    <form method="get" class="d-flex flex-wrap gap-2 align-items-end">
        <input type="hidden" name="id" value="<?= $id ?>">
        <div><label class="form-label small mb-1">De</label><input type="date" name="data_inicio" class="form-control form-control-sm" value="<?= e($inicio) ?>"></div>
        <div><label class="form-label small mb-1">Até</label><input type="date" name="data_fim" class="form-control form-control-sm" value="<?= e($fim) ?>"></div>
        <button class="btn btn-sm btn-outline-secondary" type="submit"><i class="bi bi-search"></i> Filtrar</button>
    </form>
    <span class="ms-auto fw-semibold">Total no período: <?= formatMoney($totalGeral) ?></span>
</div>

<div class="row g-3 mb-3">
    <?php foreach ($tipos as $tipo => $rotulo): ?>
        <div class="col-6 col-lg-3">
            <div class="card h-100"><div class="card-body">
                <div class="small text-muted"><?= e($rotulo) ?></div>
                <div class="fs-5 fw-bold"><?= formatMoney($resumo[$tipo]['total']) ?></div>
                <div class="small text-muted"><?= $resumo[$tipo]['quantidade'] ?> × <?= formatMoney($conta['tarifa_' . $tipo]) ?> (valor atual)</div>
            </div></div>
        </div>
    <?php endforeach; ?>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle">
            <thead><tr><th>Data</th><th>Tarifa</th><th>Aluno · parcela</th><th>Nosso nº</th><th class="text-end">Valor</th></tr></thead>
            <tbody>
            <?php if (!$tarifas): ?>
                <tr><td colspan="5" class="text-center text-muted py-4">Nenhuma tarifa no período.</td></tr>
            <?php endif; ?>
            <?php foreach ($tarifas as $t): ?>
                <tr>
                    <td><?= formatDate($t['data']) ?></td>
                    <td><?= e($tipos[$t['tipo']] ?? $t['tipo']) ?></td>
                    <td><?= e($t['aluno_nome'] ?: '-') ?><?= $t['parcela_descricao'] ? ' · <span class="text-muted">' . e($t['parcela_descricao']) . '</span>' : '' ?></td>
                    <td><?= $t['nosso_numero'] ? e(formatarNossoNumeroItau((int) $t['nosso_numero'])) : '-' ?></td>
                    <td class="text-end"><?= formatMoney($t['valor']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <?php if ($tarifas): ?>
                <tfoot><tr><th colspan="4" class="text-end">Total</th><th class="text-end"><?= formatMoney($totalGeral) ?></th></tr></tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
