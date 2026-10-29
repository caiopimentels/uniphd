<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/documento.php';
exigirDRE();

// DRE para imprimir, no período escolhido na tela do DRE. ?detalhe=1: com
// os lançamentos de cada linha. ?detalhar=tipo:id: só o detalhamento de
// uma linha.
$pdo = getDB();
$filtro = $_SESSION['filtro_dre'] ?? ['data_inicio' => date('Y-m-01'), 'data_fim' => date('Y-m-t')];
$dataInicio = $filtro['data_inicio'] ?: '1000-01-01';
$dataFim = $filtro['data_fim'] ?: '9999-12-31';
$rotuloPeriodo = ($filtro['data_inicio'] || $filtro['data_fim'])
    ? ($filtro['data_inicio'] ? formatDate($filtro['data_inicio']) : '...') . ' até ' . ($filtro['data_fim'] ? formatDate($filtro['data_fim']) : '...')
    : 'Todo o período';
$dre = calcularDRE($pdo, $dataInicio, $dataFim);
$comDetalhe = !empty($_GET['detalhe']);
$soUma = null;
if (preg_match('/^(receita|despesa):(\d+)$/', (string) ($_GET['detalhar'] ?? ''), $m)) {
    $soUma = [$m[1], (int) $m[2]];
}
$link = fn (array $mudar) => '?' . http_build_query(array_merge(['detalhe' => (int) $comDetalhe], $mudar));

// Tabela de lançamentos de uma linha.
$detalhe = function (string $tipo, int $id) use ($pdo, $dataInicio, $dataFim) {
    $linhas = detalheDRE($pdo, $tipo, $id, $dataInicio, $dataFim);
    $ehDespesa = $tipo === 'despesa';
    ?>
    <table class="tabela-detalhe">
        <thead><tr><th style="width: 22mm">Competência</th><th>Descrição</th><th><?= $ehDespesa ? 'Fornecedor' : 'Cliente' ?></th><th style="width: 36mm">Situação</th><th class="text-end" style="width: 26mm">Valor</th></tr></thead>
        <tbody>
        <?php foreach ($linhas as $l): ?>
            <tr><td><?= e(formatDate($l['data'])) ?></td><td><?= e($l['descricao']) ?></td><td><?= e($l['quem'] ?: '—') ?></td><td class="text-nowrap"><?= e($l['situacao']) ?></td><td class="text-end text-nowrap"><?= formatMoney($l['valor']) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$linhas): ?><tr><td colspan="5">Nenhum lançamento.</td></tr><?php endif; ?>
        </tbody>
    </table>
    <?php
};
$nomeDe = function (string $tipo, int $id) use ($dre): string {
    foreach ($dre[$tipo === 'receita' ? 'receitas_por_categoria' : 'despesas_por_categoria'] as $l) {
        if ((int) $l['categoria_id'] === $id) {
            return (string) ($l['categoria'] ?: 'Sem categoria');
        }
    }
    return '';
};

$doc = documentoInicio($pdo, 'DRE · ' . $rotuloPeriodo, '/financeiro/dre.php');
?>
<style>
    .dre { width: 100%; border-collapse: collapse; font-size: 12.5px; }
    .dre td, .dre th { padding: 1.6mm 2mm; border-bottom: 1px solid #e5e7eb; }
    .dre .grupo td { font-weight: 700; padding-top: 4mm; border-bottom: 1px solid #999; }
    .dre .item td:first-child { padding-left: 6mm; }
    .dre .total td { font-weight: 700; background: #f4f6f9; }
    .dre .nota td { font-size: 10.5px; color: #6b7280; padding-top: .8mm; border-bottom: 0; }
    .dre .resultado td { font-weight: 800; font-size: 14px; border-top: 2px solid #333; }
    .dre .positivo { color: #15803d; } .dre .negativo { color: #b91c1c; }
    .text-end { text-align: right; }
    .tabela-detalhe { width: 100%; border-collapse: collapse; font-size: 10.5px; margin: 1mm 0 4mm; }
    .tabela-detalhe th { text-align: left; background: #f4f6f9; padding: 1.2mm 2mm; font-weight: 600; }
    .tabela-detalhe td { padding: 1mm 2mm; border-bottom: 1px solid #eee; }
    .detalhe-titulo { font-size: 12px; font-weight: 700; margin: 5mm 0 1mm; }
    @media print { .detalhe-bloco { break-inside: auto; } .detalhe-titulo { break-after: avoid; } }
</style>

<?php if ($soUma): [$tipo, $id] = $soUma; ?>
    <?php documentoCabecalho($doc, 'Detalhamento do DRE', $rotuloPeriodo); ?>
    <div class="detalhe-titulo"><?= ['receita' => 'Receitas', 'despesa' => 'Despesas'][$tipo] ?> · <?= e($nomeDe($tipo, $id)) ?></div>
    <?php $detalhe($tipo, $id); ?>
<?php else: ?>
    <div class="opcoes-impressao">
        <label><input type="checkbox" <?= $comDetalhe ? 'checked' : '' ?> onchange="location.href='<?= e($link(['detalhe' => $comDetalhe ? 0 : 1])) ?>'"> Com o detalhamento de cada linha</label>
    </div>
    <?php documentoCabecalho($doc, 'DRE — Demonstração do Resultado', $rotuloPeriodo . ' · regime de competência'); ?>

    <table class="dre">
        <tr class="grupo"><td>Receitas</td><td class="text-end"></td></tr>
        <?php foreach ($dre['receitas_por_categoria'] as $r): ?>
            <tr class="item"><td><?= e($r['categoria'] ?: 'Sem categoria') ?> (<?= (int) $r['quantidade'] ?>)</td><td class="text-end positivo"><?= formatMoney($r['total']) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$dre['receitas_por_categoria']): ?><tr class="item"><td>Nenhuma no período</td><td class="text-end">—</td></tr><?php endif; ?>
        <tr class="total"><td>(=) Receita bruta</td><td class="text-end"><?= formatMoney($dre['total_receitas']) ?></td></tr>

        <tr class="grupo"><td>Despesas</td><td></td></tr>
        <?php foreach ($dre['despesas_por_categoria'] as $d): ?>
            <tr class="item"><td><?= e($d['categoria'] ?: 'Sem categoria') ?> (<?= (int) $d['quantidade'] ?>)</td><td class="text-end negativo">- <?= formatMoney($d['total']) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$dre['despesas_por_categoria']): ?><tr class="item"><td>Nenhuma no período</td><td class="text-end">—</td></tr><?php endif; ?>
        <tr class="total"><td>(-) Total despesas</td><td class="text-end negativo">- <?= formatMoney($dre['total_despesas']) ?></td></tr>

        <tr class="resultado"><td>(=) Resultado do período</td><td class="text-end <?= $dre['resultado_liquido'] >= 0 ? 'positivo' : 'negativo' ?>"><?= formatMoney($dre['resultado_liquido']) ?></td></tr>
    </table>


    <?php if ($comDetalhe): ?>
        <div class="doc-secao" style="margin-top: 8mm;">
            <h2>Detalhamento</h2>
            <?php foreach ($dre['receitas_por_categoria'] as $r): ?>
                <div class="detalhe-bloco"><div class="detalhe-titulo">Receitas · <?= e($r['categoria'] ?: 'Sem categoria') ?> — <?= formatMoney($r['total']) ?></div><?php $detalhe('receita', (int) $r['categoria_id']); ?></div>
            <?php endforeach; ?>
            <?php foreach ($dre['despesas_por_categoria'] as $d): ?>
                <div class="detalhe-bloco"><div class="detalhe-titulo">Despesas · <?= e($d['categoria'] ?: 'Sem categoria') ?> — <?= formatMoney($d['total']) ?></div><?php $detalhe('despesa', (int) $d['categoria_id']); ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>
<?php documentoFim(); ?>
