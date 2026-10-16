<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirDRE();

$pdo = getDB();

// Lembra o último período usado, como nas demais telas do financeiro.
$_SESSION['filtro_dre'] = array_merge(['data_inicio' => date('Y-m-01'), 'data_fim' => date('Y-m-t')], $_SESSION['filtro_dre'] ?? []);
if (isset($_GET['data_inicio']) || isset($_GET['data_fim'])) {
    $_SESSION['filtro_dre'] = [
        'data_inicio' => $_GET['data_inicio'] ?? '',
        'data_fim' => $_GET['data_fim'] ?? '',
    ];
}
$dataInicio = $_SESSION['filtro_dre']['data_inicio'];
$dataFim = $_SESSION['filtro_dre']['data_fim'];

// O DRE compara com BETWEEN, então "sem limite" vira uma borda bem larga.
$dre = calcularDRE($pdo, $dataInicio ?: '1000-01-01', $dataFim ?: '9999-12-31');

$rotuloPeriodo = ($dataInicio || $dataFim)
    ? ($dataInicio ? formatDate($dataInicio) : '...') . ' até ' . ($dataFim ? formatDate($dataFim) : '...')
    : 'Todo o período';

$pageTitle = 'DRE';
$pageBreadcrumb = 'Relatórios';
$activeMenu = 'dre';
require __DIR__ . '/../../includes/layout_header.php';
?>

<div class="filtros">
    <form method="get">
        <?php require __DIR__ . '/../../includes/filtro_periodo.php'; ?>
    </form>
    <span class="ms-auto filtro-chip">Resultado: <?= formatMoney($dre['resultado_liquido']) ?></span>
</div>

<div class="row g-4">
<div class="col-lg-7">
<div class="secao">
    <div class="secao-titulo"><i class="bi bi-file-earmark-bar-graph"></i> <?= e($rotuloPeriodo) ?> · regime de competência</div>
    <div class="card">
    <div class="table-responsive">
        <table class="table mb-0 align-middle">
            <tbody>
                <tr><td colspan="2" class="fw-semibold">Receitas</td></tr>
                <?php if (!$dre['receitas_por_categoria']): ?>
                    <tr><td class="text-muted ps-4">Nenhuma no período</td><td class="text-end">-</td></tr>
                <?php endif; ?>
                <?php foreach ($dre['receitas_por_categoria'] as $r): ?>
                    <tr>
                        <td class="ps-4"><?= e($r['categoria'] ?: 'Sem categoria') ?></td>
                        <td class="text-end text-success">+ <?= formatMoney($r['total']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr class="table-light">
                    <th>(=) Receita bruta</th>
                    <th class="text-end"><?= formatMoney($dre['total_receitas']) ?></th>
                </tr>

                <tr><td colspan="2" class="pt-4 fw-semibold">Despesas</td></tr>
                <?php if (!$dre['despesas_por_categoria']): ?>
                    <tr><td class="text-muted ps-4">Nenhuma no período</td><td class="text-end">-</td></tr>
                <?php endif; ?>
                <?php foreach ($dre['despesas_por_categoria'] as $d): ?>
                    <tr>
                        <td class="ps-4"><?= e($d['categoria'] ?: 'Sem categoria') ?></td>
                        <td class="text-end text-danger">- <?= formatMoney($d['total']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr>
                    <td class="fw-semibold">(-) Total despesas</td>
                    <td class="text-end fw-semibold text-danger">- <?= formatMoney($dre['total_despesas']) ?></td>
                </tr>

                <tr class="table-<?= $dre['resultado_liquido'] >= 0 ? 'success' : 'danger' ?>">
                    <th class="fs-5">(=) Resultado do período</th>
                    <th class="text-end fs-5"><?= formatMoney($dre['resultado_liquido']) ?></th>
                </tr>
            </tbody>
        </table>
    </div>
    </div>
    <div class="form-text mt-2">Receitas contam no mês do vencimento de cada parcela; despesas, no mês da emissão. Lançamentos cancelados não entram.</div>
</div>
</div>

<div class="col-lg-5">
<div class="secao">
    <div class="secao-titulo"><i class="bi bi-mortarboard"></i> Receita de matrículas por curso</div>
    <div class="card">
    <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle">
            <thead><tr><th>Curso</th><th class="text-end">Receita</th></tr></thead>
            <tbody>
            <?php if (!$dre['receitas_por_curso']): ?>
                <tr><td colspan="2" class="text-center text-muted py-3">Nenhuma receita de matrícula no período.</td></tr>
            <?php endif; ?>
            <?php foreach ($dre['receitas_por_curso'] as $c): ?>
                <tr>
                    <td><?= e($c['curso']) ?></td>
                    <td class="text-end"><?= formatMoney($c['total']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    </div>
</div>
</div>
</div>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
