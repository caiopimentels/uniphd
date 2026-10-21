<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
exigirVerFinanceiro();

$pdo = getDB();

// Lembra o último filtro usado nesta tela entre uma visita e outra — sem
// isso, todo acesso pelo menu resetava pra "Pendentes" e perdia o período
// escolhido, obrigando a escolher tudo de novo só pra ajustar a data.
$_SESSION['filtro_contas_pagar'] = array_merge(['status' => 'pendente', 'data_inicio' => '', 'data_fim' => ''], $_SESSION['filtro_contas_pagar'] ?? []);
if (isset($_GET['status']) || isset($_GET['data_inicio']) || isset($_GET['data_fim'])) {
    $_SESSION['filtro_contas_pagar'] = [
        'status' => $_GET['status'] ?? '',
        'data_inicio' => $_GET['data_inicio'] ?? '',
        'data_fim' => $_GET['data_fim'] ?? '',
    ];
}
$status = $_SESSION['filtro_contas_pagar']['status'];
$dataInicio = $_SESSION['filtro_contas_pagar']['data_inicio'];
$dataFim = $_SESSION['filtro_contas_pagar']['data_fim'];

$where = '1=1';
$params = [];
if (in_array($status, ['pendente', 'pago', 'cancelado'], true)) {
    $where .= ' AND cp.status = ?';
    $params[] = $status;
}
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

$totalExibido = array_sum(array_column($contas, 'valor'));
$labelTotal = match ($status) {
    'pendente' => 'Total pendente',
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
$pageAction = '<a href="/financeiro/contas_pagar/imprimir.php?' . e($queryImpressao) . '" target="_blank" class="btn btn-outline-secondary"><i class="bi bi-printer"></i> Imprimir</a> '
    . (usuarioTemAcessoFinanceiro() ? '<a href="/financeiro/contas_pagar/form.php" class="btn btn-brand"><i class="bi bi-plus-lg"></i> Incluir lançamento</a>' : '');
require __DIR__ . '/../../../includes/layout_header.php';
?>

<div class="filtros">
    <form method="get">
        <select name="status" class="form-select filtro-controle" style="width:170px" onchange="this.form.submit()">
            <option value="pendente" <?= $status === 'pendente' ? 'selected' : '' ?>>Pendentes</option>
            <option value="pago" <?= $status === 'pago' ? 'selected' : '' ?>>Pagas</option>
            <option value="cancelado" <?= $status === 'cancelado' ? 'selected' : '' ?>>Canceladas</option>
            <option value="">Todas</option>
        </select>
        <?php require __DIR__ . '/../../../includes/filtro_periodo.php'; ?>
    </form>
    <?php if ($contas): ?>
        <span class="ms-auto filtro-chip"><?= e($labelTotal) ?>: <?= formatMoney($totalExibido) ?></span>
    <?php endif; ?>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead><tr><?= thOrdenavel('Descrição', 'descricao', $lista) ?><?= thOrdenavel('Fornecedor/Categoria', 'fornecedor', $lista) ?><?= thOrdenavel('Vencimento', 'vencimento', $lista) ?><?= thOrdenavel('Valor', 'valor', $lista, 'text-end') ?><?= thOrdenavel('Status', 'status', $lista) ?><th class="text-end">Ações</th></tr></thead>
            <tbody>
            <?php if (!$contas): ?>
                <tr><td colspan="6" class="text-center text-muted py-4">Nenhuma conta encontrada.</td></tr>
            <?php endif; ?>
            <?php foreach ($contasPagina as $c): ?>
                <?php $vencida = $c['status'] === 'pendente' && $c['data_vencimento'] < date('Y-m-d'); ?>
                <tr>
                    <td>
                        <?= e($c['descricao']) ?>
                        <?php if ($c['turma_id']): ?><div class="small text-muted"><i class="bi bi-people"></i> <a href="/turmas/visualizar.php?id=<?= (int) $c['turma_id'] ?>"><?= e($c['curso_nome'] . ' · ' . $c['turma_nome']) ?></a></div><?php endif; ?>
                    </td>
                    <td><?= e($c['fornecedor_nome'] ?: $c['categoria_nome'] ?: '-') ?></td>
                    <td class="<?= $vencida ? 'estoque-baixo' : '' ?>"><?= formatDate($c['data_vencimento']) ?></td>
                    <td class="text-end"><?= formatMoney($c['valor']) ?></td>
                    <td><span class="badge <?= contaStatusBadgeClass($c['status']) ?>"><?= contaStatusLabel($c['status']) ?></span></td>
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
            <tfoot><tr><th colspan="3" class="text-end"><?= e($labelTotal) ?></th><th class="text-end"><?= formatMoney($totalExibido) ?></th><th colspan="2"></th></tr></tfoot>
            <?php endif; ?>
        </table>
    </div>
    <?= rodapePaginacao($lista) ?>
</div>

<?php require __DIR__ . '/../../../includes/layout_footer.php'; ?>
