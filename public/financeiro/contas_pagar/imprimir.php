<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
exigirVerFinanceiro();

$pdo = getDB();

$status = $_GET['status'] ?? 'pendente';
$dataInicio = $_GET['data_inicio'] ?? '';
$dataFim = $_GET['data_fim'] ?? '';

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
    "SELECT cp.*, f.nome AS fornecedor_nome, c.nome AS categoria_nome
     FROM contas_pagar cp
     LEFT JOIN fornecedores f ON f.id = cp.fornecedor_id
     LEFT JOIN categorias_financeiras c ON c.id = cp.categoria_id
     WHERE {$where}
     ORDER BY cp.data_vencimento ASC"
);
$stmt->execute($params);
$contas = $stmt->fetchAll();
$contas = ordenarLinhas($contas, estadoListagem('contas_pagar', colunasListaPagar())); // mesma ordem da tela

$totalExibido = array_sum(array_column($contas, 'valor'));
$labelStatus = match ($status) {
    'pendente' => 'Pendentes',
    'pago' => 'Pagas',
    'cancelado' => 'Canceladas',
    default => 'Todas',
};
$labelTotal = match ($status) {
    'pendente' => 'Total pendente',
    'pago' => 'Total pago',
    'cancelado' => 'Total cancelado',
    default => 'Total geral',
};
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Contas a pagar</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="<?= e(assetUrl('/assets/css/relatorio.css')) ?>">
</head>
<body>

<div class="toolbar">
    <button type="button" onclick="window.location.href='/financeiro/contas_pagar/listar.php'">
        <i class="bi bi-arrow-left"></i>
        Voltar
    </button>

    <button type="button" onclick="window.print()" class="btn-imprimir">
        <i class="bi bi-printer"></i>
        Imprimir relatório
    </button>
</div>

<main class="relatorio">
    <h1>Contas a pagar</h1>
    <p class="relatorio-meta">
        Situação: <?= e($labelStatus) ?>
        <?php if ($dataInicio || $dataFim): ?>
            &middot; Vencimento: <?= $dataInicio ? formatDate($dataInicio) : '...' ?> a <?= $dataFim ? formatDate($dataFim) : '...' ?>
        <?php endif; ?>
        &middot; Emitido em <?= formatDateTime(date('Y-m-d H:i:s')) ?>
    </p>

    <table>
        <thead>
            <tr>
                <th>Descrição</th>
                <th>Fornecedor/Categoria</th>
                <th>Vencimento</th>
                <th class="text-end">Valor</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$contas): ?>
            <tr><td colspan="5">Nenhuma conta encontrada.</td></tr>
        <?php endif; ?>
        <?php foreach ($contas as $c): ?>
            <tr>
                <td><?= e($c['descricao']) ?></td>
                <td><?= e($c['fornecedor_nome'] ?: $c['categoria_nome'] ?: '-') ?></td>
                <td><?= formatDate($c['data_vencimento']) ?></td>
                <td class="text-end"><?= formatMoney($c['valor']) ?></td>
                <td><?= e(contaStatusLabel($c['status'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <?php if ($contas): ?>
        <tfoot>
            <tr>
                <th colspan="3" class="text-end"><?= e($labelTotal) ?></th>
                <th class="text-end"><?= formatMoney($totalExibido) ?></th>
                <th></th>
            </tr>
        </tfoot>
        <?php endif; ?>
    </table>
</main>

</body>
</html>
