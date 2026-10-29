<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
exigirVerFinanceiro();

$pdo = getDB();

// Lembra o último filtro usado nesta tela entre uma visita e outra — sem
// isso, todo acesso pelo menu resetava pra "Pendentes" e perdia o período
// escolhido, obrigando a escolher tudo de novo só pra ajustar a data.
$_SESSION['filtro_contas_pagar'] = array_merge(['status' => 'pendente,vencido', 'data_inicio' => '', 'data_fim' => ''], $_SESSION['filtro_contas_pagar'] ?? []);
if (isset($_GET['status']) || isset($_GET['data_inicio']) || isset($_GET['data_fim'])) {
    $_SESSION['filtro_contas_pagar'] = [
        'status' => implode(',', listaStatusPagar($_GET['status'] ?? [])),
        'data_inicio' => $_GET['data_inicio'] ?? '',
        'data_fim' => $_GET['data_fim'] ?? '',
    ];
}
$status = $_SESSION['filtro_contas_pagar']['status'];
$dataInicio = $_SESSION['filtro_contas_pagar']['data_inicio'];
$dataFim = $_SESSION['filtro_contas_pagar']['data_fim'];

$where = '1=1';
$params = [];
$statusMarcados = listaStatusPagar($status);
$where .= condicaoStatusPagar($statusMarcados);
if ($dataInicio !== '') {
    $where .= ' AND cp.data_vencimento >= ?';
    $params[] = $dataInicio;
}
if ($dataFim !== '') {
    $where .= ' AND cp.data_vencimento <= ?';
    $params[] = $dataFim;
}

$stmt = $pdo->prepare(
    "SELECT cp.*, f.nome AS fornecedor_nome, c.nome AS categoria_nome, t.nome AS turma_nome, cu.nome AS curso_nome
     FROM contas_pagar cp
     LEFT JOIN turmas t ON t.id = cp.turma_id
     LEFT JOIN cursos cu ON cu.id = t.curso_id
     LEFT JOIN fornecedores f ON f.id = cp.fornecedor_id
     LEFT JOIN categorias_financeiras c ON c.id = cp.categoria_id
     WHERE {$where}
     ORDER BY cp.data_vencimento ASC"
);
$stmt->execute($params);
$contas = $stmt->fetchAll();
$lista = estadoListagem('contas_pagar', colunasListaPagar());
$contas = ordenarLinhas($contas, $lista);
$contasPagina = paginarLinhas($contas, $lista);
$telaColunas = 'contas_pagar';
$definicaoColunas = definicaoColunasTela($pdo, $telaColunas);
$colunas = colunasVisiveis($pdo, $telaColunas, $definicaoColunas);
$posicaoValor = array_search('valor', array_keys($colunas), true);
$posicaoValor = $posicaoValor === false || $posicaoValor === 0 ? null : $posicaoValor;

$totalExibido = array_sum(array_column($contas, 'valor'));
$labelTotal = count($statusMarcados) > 1 ? 'Total da seleção' : match ($status) {
    'pendente' => 'Total pendente',
    'vencido' => 'Total vencido',
    'pago' => 'Total pago',
    'cancelado' => 'Total cancelado',
    default => 'Total geral',
};

$queryImpressao = http_build_query([
    'status' => $status,
    'data_inicio' => $dataInicio,
    'data_fim' => $dataFim,
]);

$pageTitle = 'Contas a pagar';
$pageBreadcrumb = 'Financeiro';
$activeMenu = 'contas-pagar';
$pageAction = '<a href="/financeiro/contas_pagar/imprimir.php?' . e($queryImpressao) . '" target="_blank" data-imprimir=".selecao-pagar" class="btn btn-outline-secondary" title="Com contas marcadas, imprime só elas"><i class="bi bi-printer"></i> Imprimir</a> '
    . (usuarioTemAcessoFinanceiro() ? '<a href="/financeiro/contas_pagar/form.php" class="btn btn-brand"><i class="bi bi-plus-lg"></i> Incluir lançamento</a>' : '');
require __DIR__ . '/../../../includes/layout_header.php';
?>

<div class="filtros">
    <form method="get">
        <?php $opcoesStatus = opcoesStatusPagar(); require __DIR__ . '/../../../includes/filtro_status.php'; ?>
        <?php require __DIR__ . '/../../../includes/filtro_periodo.php'; ?>
    </form>
    <div class="ms-auto d-flex align-items-center gap-2">
        <?php if ($contas): ?><span class="filtro-chip"><?= e($labelTotal) ?>: <?= formatMoney($totalExibido) ?></span><?php endif; ?>
        <?php require __DIR__ . '/../../../includes/painel_colunas.php'; ?>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead><tr><th style="width: 36px;"><input type="checkbox" class="form-check-input" id="pagar-todos" title="Marcar todas (para imprimir só elas)"></th><?= cabecalhoColunas($colunas, $lista) ?><th class="text-end">Ações</th></tr></thead>
            <tbody>
            <?php if (!$contas): ?>
                <tr><td colspan="<?= count($colunas) + 2 ?>" class="text-center text-muted py-4">Nenhuma conta encontrada.</td></tr>
            <?php endif; ?>
            <?php foreach ($contasPagina as $c): ?>
                <tr>
                    <td><input type="checkbox" class="form-check-input selecao-pagar" value="<?= (int) $c['id'] ?>"></td>
                    <?= celulasColunas($colunas, $c) ?>
                    <td class="text-end" onclick="event.stopPropagation();">
                        <?php if ($c['status'] === 'pendente' && usuarioPodePagarConta()): ?>
                            <a href="/financeiro/contas_pagar/baixar.php?id=<?= (int) $c['id'] ?>" class="btn btn-sm btn-outline-success" title="Dar baixa (pagar)"><i class="bi bi-check-lg"></i></a>
                        <?php endif; ?>
                        <?php
                            $avisoExclusao = 'Excluir este lançamento?';
                        ?>
                        <?php if (usuarioPodeExcluirPagar()): ?>
                        <form action="/financeiro/contas_pagar/excluir.php" method="post" class="d-inline" onsubmit="return confirm('<?= e($avisoExclusao) ?>');">
                            <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Excluir lançamento"><i class="bi bi-trash"></i></button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <?php if ($contas): ?>
            <tfoot><tr>
                <?php if ($posicaoValor !== null): ?>
                <th colspan="<?= $posicaoValor + 1 ?>" class="text-end"><?= e($labelTotal) ?></th><th class="text-end"><?= formatMoney($totalExibido) ?></th><th colspan="<?= count($colunas) - $posicaoValor ?>"></th>
                <?php else: ?>
                <th colspan="<?= count($colunas) + 2 ?>" class="text-end"><?= e($labelTotal) ?>: <?= formatMoney($totalExibido) ?></th>
                <?php endif; ?>
            </tr></tfoot>
            <?php endif; ?>
        </table>
    </div>
    <?= rodapePaginacao($lista) ?>
</div>

<script>
// Marcar todas: só para imprimir as marcadas.
document.getElementById('pagar-todos')?.addEventListener('change', (ev) => {
    document.querySelectorAll('.selecao-pagar').forEach((c) => { c.checked = ev.target.checked; });
});
</script>
<?php require __DIR__ . '/../../../includes/layout_footer.php'; ?>
