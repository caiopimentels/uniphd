<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirDRE();

$pdo = getDB();

// Lembra o último período usado nesta tela entre uma visita e outra, como
// em contas a pagar/receber.
$_SESSION['filtro_fluxo_caixa'] = array_merge(['data_inicio' => date('Y-m-01'), 'data_fim' => date('Y-m-t')], $_SESSION['filtro_fluxo_caixa'] ?? []);
if (isset($_GET['data_inicio']) || isset($_GET['data_fim'])) {
    $_SESSION['filtro_fluxo_caixa'] = [
        'data_inicio' => $_GET['data_inicio'] ?? '',
        'data_fim' => $_GET['data_fim'] ?? '',
    ];
}
$dataInicio = $_SESSION['filtro_fluxo_caixa']['data_inicio'];
$dataFim = $_SESSION['filtro_fluxo_caixa']['data_fim'];

$fluxo = calcularFluxoCaixa($pdo, $dataInicio, $dataFim);

$pageTitle = 'Fluxo de caixa';
$pageBreadcrumb = 'Relatórios';
$activeMenu = 'fluxo-caixa';
require __DIR__ . '/../../includes/layout_header.php';
?>

<div class="filtros">
    <form method="get">
        <?php require __DIR__ . '/../../includes/filtro_periodo.php'; ?>
    </form>
    <span class="ms-auto filtro-chip">Saldo final: <?= formatMoney($fluxo['saldo_final']) ?></span>
</div>

<div class="secao">
    <div class="secao-titulo"><i class="bi bi-bank"></i> Extrato de caixa · regime de caixa</div>

    <div class="row g-3 mb-3">
        <div class="col-6 col-md-3">
            <div class="card h-100"><div class="card-body">
                <div class="kpi-rotulo">Saldo inicial</div>
                <div class="kpi-valor"><?= formatMoney($fluxo['saldo_inicial']) ?></div>
            </div></div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card h-100"><div class="card-body">
                <div class="kpi-rotulo">Entradas</div>
                <div class="kpi-valor text-success"><?= formatMoney($fluxo['total_entradas']) ?></div>
            </div></div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card h-100"><div class="card-body">
                <div class="kpi-rotulo">Saídas</div>
                <div class="kpi-valor text-danger"><?= formatMoney($fluxo['total_saidas']) ?></div>
            </div></div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card h-100"><div class="card-body">
                <div class="kpi-rotulo">Saldo final</div>
                <div class="kpi-valor <?= $fluxo['saldo_final'] >= 0 ? '' : 'estoque-baixo' ?>"><?= formatMoney($fluxo['saldo_final']) ?></div>
            </div></div>
        </div>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead><tr><th>Data</th><th>Descrição</th><th>Aluno/Fornecedor</th><th class="text-end">Entrada</th><th class="text-end">Saída</th><th class="text-end">Saldo</th></tr></thead>
                <tbody>
                <tr class="table-light">
                    <td colspan="5" class="fw-semibold">Saldo inicial<?= $dataInicio ? ' em ' . formatDate($dataInicio) : '' ?></td>
                    <td class="text-end fw-semibold"><?= formatMoney($fluxo['saldo_inicial']) ?></td>
                </tr>
                <?php if (!$fluxo['movimentos']): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">Nenhuma movimentação de caixa no período.</td></tr>
                <?php endif; ?>
                <?php foreach ($fluxo['movimentos'] as $mov): ?>
                    <tr>
                        <td><?= formatDate($mov['data']) ?></td>
                        <td><?= e($mov['descricao']) ?></td>
                        <td><?= e($mov['origem']) ?></td>
                        <td class="text-end text-success"><?= $mov['tipo'] === 'entrada' ? formatMoney($mov['valor']) : '' ?></td>
                        <td class="text-end text-danger"><?= $mov['tipo'] === 'saida' ? formatMoney($mov['valor']) : '' ?></td>
                        <td class="text-end"><?= formatMoney($mov['saldo']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <?php if ($fluxo['movimentos']): ?>
                <tfoot>
                    <tr class="table-<?= $fluxo['saldo_final'] >= 0 ? 'success' : 'danger' ?>">
                        <th colspan="3" class="text-end fs-5">Saldo final</th>
                        <th class="text-end"><?= formatMoney($fluxo['total_entradas']) ?></th>
                        <th class="text-end"><?= formatMoney($fluxo['total_saidas']) ?></th>
                        <th class="text-end fs-5"><?= formatMoney($fluxo['saldo_final']) ?></th>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
