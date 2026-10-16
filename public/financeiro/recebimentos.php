<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirVerFinanceiro();

$pdo = getDB();

// Lembra o último filtro usado nesta tela, como no fluxo de caixa.
$_SESSION['filtro_recebimentos'] = array_merge(
    ['data_inicio' => date('Y-m-01'), 'data_fim' => date('Y-m-t'), 'forma_pagamento_id' => '', 'busca' => ''],
    $_SESSION['filtro_recebimentos'] ?? []
);
if (isset($_GET['data_inicio']) || isset($_GET['data_fim']) || isset($_GET['forma_pagamento_id']) || isset($_GET['busca'])) {
    $_SESSION['filtro_recebimentos'] = [
        'data_inicio' => $_GET['data_inicio'] ?? '',
        'data_fim' => $_GET['data_fim'] ?? '',
        'forma_pagamento_id' => (string) ($_GET['forma_pagamento_id'] ?? ''),
        'busca' => trim($_GET['busca'] ?? ''),
    ];
}
$filtros = $_SESSION['filtro_recebimentos'];
$dataInicio = $filtros['data_inicio'];
$dataFim = $filtros['data_fim'];

$relatorio = buscarRecebimentos($pdo, $filtros, (int) ($_GET['pagina'] ?? 1), 50);
$formasPagamento = $pdo->query('SELECT id, nome, ativo FROM formas_pagamento ORDER BY ativo DESC, nome')->fetchAll();

$urlPagina = fn (int $p) => '?' . http_build_query(array_merge($filtros, ['pagina' => $p]));

$pageTitle = 'Recebimentos';
$pageBreadcrumb = 'Relatórios';
$activeMenu = 'recebimentos';
$pageAction = '<a href="/financeiro/recebimentos_imprimir.php?' . e(http_build_query($filtros)) . '" target="_blank" class="btn btn-outline-secondary"><i class="bi bi-printer"></i> Imprimir</a>';
require __DIR__ . '/../../includes/layout_header.php';
?>

<div class="filtros">
    <form method="get" class="d-flex flex-wrap gap-2 align-items-center">
        <?php require __DIR__ . '/../../includes/filtro_periodo.php'; ?>
        <select name="forma_pagamento_id" class="form-select filtro-controle" style="width:210px" onchange="this.form.submit()">
            <option value="">Todos os tipos de recebimento</option>
            <?php foreach ($formasPagamento as $f): ?>
                <option value="<?= (int) $f['id'] ?>" <?= $filtros['forma_pagamento_id'] === (string) $f['id'] ? 'selected' : '' ?>><?= e($f['nome']) ?><?= $f['ativo'] ? '' : ' (inativa)' ?></option>
            <?php endforeach; ?>
            <option value="sem" <?= $filtros['forma_pagamento_id'] === 'sem' ? 'selected' : '' ?>>Não informada</option>
        </select>
        <input type="search" name="busca" class="form-control filtro-controle" style="width:220px" placeholder="Descrição ou aluno" value="<?= e($filtros['busca']) ?>">
        <button type="submit" class="btn btn-outline-secondary filtro-controle"><i class="bi bi-search"></i></button>
    </form>
    <span class="ms-auto filtro-chip">Total recebido: <?= formatMoney($relatorio['total']) ?></span>
</div>

<?php if ($relatorio['por_forma']): ?>
<div class="row g-3 mb-3">
    <?php foreach ($relatorio['por_forma'] as $grupo): ?>
        <div class="col-6 col-md-3">
            <div class="card h-100"><div class="card-body">
                <div class="kpi-rotulo"><?= e($grupo['forma']) ?></div>
                <div class="kpi-valor"><?= formatMoney($grupo['total']) ?></div>
                <div class="small text-muted"><?= (int) $grupo['quantidade'] ?> recebimento(s)</div>
            </div></div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead><tr><th>Data</th><th>Descrição</th><th>Tipo de recebimento</th><th class="text-end">Valor</th></tr></thead>
            <tbody>
            <?php if (!$relatorio['itens']): ?>
                <tr><td colspan="4" class="text-center text-muted py-4">Nenhum recebimento no filtro escolhido.</td></tr>
            <?php endif; ?>
            <?php foreach ($relatorio['itens'] as $r): ?>
                <tr <?= $r['aluno_id'] ? 'class="cursor-pointer" onclick="window.location=\'/alunos/form.php?id=' . (int) $r['aluno_id'] . '&tab=financeiro\'"' : '' ?>>
                    <td class="text-nowrap"><?= formatDate($r['data_recebimento']) ?></td>
                    <td>
                        <?= e($r['descricao']) ?>
                        <?php if ($r['aluno_nome']): ?><div class="small text-muted"><?= e($r['aluno_nome']) ?></div><?php endif; ?>
                    </td>
                    <td><?= e($r['forma_nome']) ?></td>
                    <td class="text-end text-nowrap">
                        <?= formatMoney($r['valor_recebido']) ?>
                        <?php if ((float) $r['valor_recebido'] !== (float) $r['valor']): ?>
                            <div class="small text-muted">parcela <?= formatMoney($r['valor']) ?></div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <?php if ($relatorio['itens']): ?>
            <tfoot>
                <tr class="table-light">
                    <th colspan="3" class="text-end"><?= (int) $relatorio['quantidade'] ?> recebimento(s) no filtro · Total</th>
                    <th class="text-end"><?= formatMoney($relatorio['total']) ?></th>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
    <?php if ($relatorio['total_paginas'] > 1): ?>
    <div class="card-footer bg-transparent d-flex flex-wrap align-items-center gap-2">
        <nav>
            <ul class="pagination pagination-sm mb-0">
                <li class="page-item <?= $relatorio['pagina'] <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= e($urlPagina($relatorio['pagina'] - 1)) ?>">‹</a></li>
                <?php
                // Janela de páginas ao redor da atual, mais a primeira e a última.
                $paginas = array_unique(array_filter(
                    [1, $relatorio['pagina'] - 2, $relatorio['pagina'] - 1, $relatorio['pagina'], $relatorio['pagina'] + 1, $relatorio['pagina'] + 2, $relatorio['total_paginas']],
                    fn ($p) => $p >= 1 && $p <= $relatorio['total_paginas']
                ));
                sort($paginas);
                $anterior = 0;
                ?>
                <?php foreach ($paginas as $p): ?>
                    <?php if ($p - $anterior > 1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
                    <li class="page-item <?= $p === $relatorio['pagina'] ? 'active' : '' ?>"><a class="page-link" href="<?= e($urlPagina($p)) ?>"><?= $p ?></a></li>
                    <?php $anterior = $p; ?>
                <?php endforeach; ?>
                <li class="page-item <?= $relatorio['pagina'] >= $relatorio['total_paginas'] ? 'disabled' : '' ?>"><a class="page-link" href="<?= e($urlPagina($relatorio['pagina'] + 1)) ?>">›</a></li>
            </ul>
        </nav>
        <span class="small text-muted ms-auto">Página <?= $relatorio['pagina'] ?> de <?= $relatorio['total_paginas'] ?> · 50 por página</span>
    </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
