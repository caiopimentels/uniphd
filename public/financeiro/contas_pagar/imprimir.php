<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
exigirVerFinanceiro();

$pdo = getDB();

$status = implode(',', listaStatusPagar($_GET['status'] ?? 'pendente,vencido'));
$dataInicio = $_GET['data_inicio'] ?? '';
$dataFim = $_GET['data_fim'] ?? '';

$where = '1=1';
$params = [];
$where .= condicaoStatusPagar(listaStatusPagar($status));
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
$contas = ordenarLinhas($contas, estadoListagem('contas_pagar', colunasListaPagar())); // mesma ordem da tela
$contas = filtrarSelecionadas($contas); // marcadas na tela: só elas
// Mesmas colunas da tela; o total fica embaixo da coluna Valor.
$colunas = colunasVisiveis($pdo, 'contas_pagar', definicaoColunasTela($pdo, 'contas_pagar'));
$posicaoValor = array_search('valor', array_keys($colunas), true);
$posicaoValor = $posicaoValor === false || $posicaoValor === 0 ? null : $posicaoValor;

$totalExibido = array_sum(array_column($contas, 'valor'));
$labelStatus = listaStatusPagar($status) ? implode(' + ', array_map(fn ($s) => opcoesStatusPagar()[$s], listaStatusPagar($status))) : 'Todas';
$labelTotal = count(listaStatusPagar($status)) > 1 ? 'Total da seleção' : match ($status) {
    'pendente' => 'Total pendente',
    'vencido' => 'Total vencido',
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
        <?= !empty($_GET['ids']) ? 'Somente as selecionadas &middot; ' : '' ?>Situação: <?= e($labelStatus) ?>
        <?php if ($dataInicio || $dataFim): ?>
            &middot; Vencimento: <?= $dataInicio ? formatDate($dataInicio) : '...' ?> a <?= $dataFim ? formatDate($dataFim) : '...' ?>
        <?php endif; ?>
        &middot; Emitido em <?= formatDateTime(date('Y-m-d H:i:s')) ?>
    </p>

    <table>
        <thead><tr><?= cabecalhoColunasImpressao($colunas) ?></tr></thead>
        <tbody>
        <?php if (!$contas): ?>
            <tr><td colspan="<?= count($colunas) ?>">Nenhuma conta encontrada.</td></tr>
        <?php endif; ?>
        <?php foreach ($contas as $c): ?>
            <tr><?= celulasColunasImpressao($colunas, $c) ?></tr>
        <?php endforeach; ?>
        </tbody>
        <?php if ($contas): ?>
        <tfoot>
            <tr>
                <?php if ($posicaoValor !== null): ?>
                <th colspan="<?= $posicaoValor ?>" class="text-end"><?= e($labelTotal) ?></th>
                <th class="text-end"><?= formatMoney($totalExibido) ?></th>
                <?php if (count($colunas) - $posicaoValor - 1 > 0): ?><th colspan="<?= count($colunas) - $posicaoValor - 1 ?>"></th><?php endif; ?>
                <?php else: ?>
                <th colspan="<?= count($colunas) ?>" class="text-end"><?= e($labelTotal) ?>: <?= formatMoney($totalExibido) ?></th>
                <?php endif; ?>
            </tr>
        </tfoot>
        <?php endif; ?>
    </table>
</main>

</body>
</html>
