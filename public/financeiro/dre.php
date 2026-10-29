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
    <a class="btn btn-outline-secondary" href="/financeiro/dre_imprimir.php" target="_blank"><i class="bi bi-printer"></i> Imprimir</a>
</div>

<div style="max-width: 860px;">
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
                    <tr class="linha-dre" onclick="detalhar('receita', <?= (int) $r['categoria_id'] ?>, this)" title="Ver os lançamentos">
                        <td class="ps-4"><?= e($r['categoria'] ?: 'Sem categoria') ?> <span class="small text-muted">(<?= (int) $r['quantidade'] ?>)</span> <i class="bi bi-chevron-right small text-muted seta"></i>
                            </td>
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
                    <tr class="linha-dre" onclick="detalhar('despesa', <?= (int) $d['categoria_id'] ?>, this)" title="Ver os lançamentos">
                        <td class="ps-4"><?= e($d['categoria'] ?: 'Sem categoria') ?> <span class="small text-muted">(<?= (int) $d['quantidade'] ?>)</span> <i class="bi bi-chevron-right small text-muted seta"></i>
                            </td>
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
    <div class="form-text mt-2"><i class="bi bi-info-circle"></i> Clique numa linha para ver os lançamentos.</div>
</div>
</div>

<style>
    .linha-dre { cursor: pointer; }
    .linha-dre:hover td { background: var(--accent-soft); }
    .linha-dre .seta { opacity: 0; transition: opacity .15s; }
    .linha-dre:hover .seta { opacity: 1; }
</style>
<div class="modal fade" id="modal-dre" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div><h5 class="modal-title mb-0" id="dre-titulo"></h5><div class="small text-muted"><?= e($rotuloPeriodo) ?></div></div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0" id="dre-corpo"></div>
            <div class="modal-footer"><span class="me-auto fw-semibold" id="dre-total"></span><a class="btn btn-outline-secondary" id="dre-imprimir" target="_blank"><i class="bi bi-printer"></i> Imprimir este detalhamento</a></div>
        </div>
    </div>
</div>
<script>
const PERIODO_DRE = <?= json_encode(['data_inicio' => $dataInicio, 'data_fim' => $dataFim]) ?>;
async function detalhar(tipo, id, linha) {
    const nome = linha.querySelector('td').childNodes[0].textContent.trim();
    document.getElementById('dre-titulo').textContent = ({ receita: 'Receitas', despesa: 'Despesas' })[tipo] + ' · ' + nome;
    const corpo = document.getElementById('dre-corpo');
    corpo.innerHTML = '<div class="p-4 text-muted small">Carregando...</div>';
    document.getElementById('dre-total').textContent = '';
    const q = new URLSearchParams({ tipo, id, ...PERIODO_DRE });
    document.getElementById('dre-imprimir').href = '/financeiro/dre_imprimir.php?detalhar=' + tipo + ':' + id;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-dre')).show();
    const r = await (await fetch('/financeiro/dre_detalhe.php?' + q)).json();
    const ehDespesa = tipo === 'despesa';
    const corSituacao = (l) => (l.pago ? 'text-success' : (/^Vencido/.test(l.situacao) ? 'text-danger' : 'text-warning-emphasis'));
    corpo.innerHTML = '<table class="table table-sm table-hover mb-0 align-middle"><thead class="table-light"><tr>'
        + `<th class="ps-3">Competência</th><th>Descrição</th><th>${ehDespesa ? 'Fornecedor' : 'Cliente'}</th><th>Situação</th><th class="text-end pe-3">Valor</th></tr></thead><tbody>`
        + (r.linhas.length ? r.linhas.map((l) => `<tr><td class="ps-3 text-nowrap">${escapeHtml(l.data_br)}</td><td>${escapeHtml(l.descricao)}</td><td>${escapeHtml(l.quem || '—')}</td>`
            + `<td class="small text-nowrap"><span class="${corSituacao(l)}">${escapeHtml(l.situacao)}</span></td>`
            + `<td class="text-end pe-3 text-nowrap ${ehDespesa ? 'text-danger' : 'text-success'}">${escapeHtml(l.valor_br)}</td></tr>`).join('')
            : '<tr><td colspan="5" class="text-center text-muted py-4">Nenhum lançamento.</td></tr>')
        + '</tbody></table>';
    document.getElementById('dre-total').textContent = r.linhas.length + ' lançamento(s) · total ' + r.total;
}
</script>
<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
