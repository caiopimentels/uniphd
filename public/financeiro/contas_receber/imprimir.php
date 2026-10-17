<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
exigirVerFinanceiro();

$pdo = getDB();

$filtros = [
    'status' => $_GET['status'] ?? 'pendente',
    'data_inicio' => $_GET['data_inicio'] ?? '',
    'data_fim' => $_GET['data_fim'] ?? '',
    'busca' => trim((string) ($_GET['busca'] ?? '')),
];
$status = $filtros['status'];
$dataInicio = $filtros['data_inicio'];
$dataFim = $filtros['data_fim'];
$agrupar = (string) ($_GET['agrupar'] ?? '');
$agrupar = array_key_exists($agrupar, opcoesAgruparReceber()) ? $agrupar : '';
// Grupos abertos na tela: só eles saem com as parcelas; os fechados saem
// só com o título e o subtotal (igual ao que se via ao imprimir).
$abertos = array_map('strval', (array) ($_GET['abertos'] ?? []));

$contas = buscarContasReceber($pdo, $filtros);
$grupos = agruparContasReceber($contas, $agrupar);
$totalExibido = array_sum(array_map('valorExibidoReceber', $contas));
$labelStatus = match ($status) {
    'pendente' => 'Pendentes',
    'pago' => 'Recebidas',
    'cancelado' => 'Canceladas',
    default => 'Todas',
};
$labelTotal = match ($status) {
    'pendente' => 'Total pendente',
    'pago' => 'Total recebido',
    'cancelado' => 'Total cancelado',
    default => 'Total geral',
};
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Contas a receber</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="<?= e(assetUrl('/assets/css/relatorio.css')) ?>">
<style>
.linha-grupo th { background: #f3f4f6; text-align: left; padding: 2mm 3mm; border-bottom: 1px solid #d1d5db; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
.linha-grupo th.text-end { text-align: right; }
.grupo-qtd { font-weight: 400; color: #6b7280; font-size: .9em; }
</style>
</head>
<body>

<div class="toolbar">
    <button type="button" onclick="window.location.href='/financeiro/contas_receber/listar.php'">
        <i class="bi bi-arrow-left"></i>
        Voltar
    </button>

    <button type="button" onclick="window.print()" class="btn-imprimir">
        <i class="bi bi-printer"></i>
        Imprimir relatório
    </button>
</div>

<main class="relatorio">
    <h1>Contas a receber</h1>
    <p class="relatorio-meta">
        Situação: <?= e($labelStatus) ?>
        <?php if ($filtros['busca'] !== ''): ?>&middot; Aluno: <?= e($filtros['busca']) ?><?php endif; ?>
        <?php if ($agrupar): ?>&middot; Agrupado <?= e(mb_strtolower(opcoesAgruparReceber()[$agrupar])) ?><?php endif; ?>
        <?php if ($dataInicio || $dataFim): ?>
            &middot; Vencimento: <?= $dataInicio ? formatDate($dataInicio) : '...' ?> a <?= $dataFim ? formatDate($dataFim) : '...' ?>
        <?php endif; ?>
        &middot; Emitido em <?= formatDateTime(date('Y-m-d H:i:s')) ?>
    </p>

    <table>
        <thead>
            <tr>
                <th>Descrição</th>
                <th>Aluno/Categoria</th>
                <th>Vencimento</th>
                <th class="text-end">Valor</th>
                <th>Pago em</th>
                <th class="text-end">Valor pago</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$contas): ?>
            <tr><td colspan="7">Nenhuma conta encontrada.</td></tr>
        <?php endif; ?>
        <?php foreach ($grupos as $grupo): $grupoAberto = !$agrupar || in_array($grupo['id'], $abertos, true); ?>
            <?php if ($agrupar): ?>
                <tr class="linha-grupo">
                    <th colspan="3"><?= e($grupo['nome']) ?> <span class="grupo-qtd">· <?= count($grupo['contas']) ?> lançamento(s)</span></th>
                    <th class="text-end"><?= formatMoney($grupo['total']) ?></th>
                    <th colspan="3"></th>
                </tr>
            <?php endif; ?>
            <?php if ($grupoAberto): foreach ($grupo['contas'] as $c): ?>
            <tr>
                <td><?= e($c['descricao']) ?></td>
                <td><?= e($c['aluno_nome'] ?: $c['categoria_nome'] ?: '-') ?></td>
                <td><?= formatDate($c['data_vencimento']) ?></td>
                <td class="text-end">
                    <?= formatMoney($c['valor']) ?>
                </td>
                <td><?= $c['status'] === 'pago' && $c['data_recebimento'] ? formatDate($c['data_recebimento']) : '-' ?></td>
                <td class="text-end"><?= $c['status'] === 'pago' ? formatMoney($c['valor_recebido'] ?? $c['valor']) : '-' ?></td>
                <td><?= e(contaStatusLabel($c['status'])) ?></td>
            </tr>
            <?php endforeach; endif; ?>
        <?php endforeach; ?>
        </tbody>
        <?php if ($contas): ?>
        <tfoot>
            <tr>
                <th colspan="3" class="text-end"><?= e($labelTotal) ?></th>
                <th class="text-end"><?= formatMoney($totalExibido) ?></th>
                <th colspan="3"></th>
            </tr>
        </tfoot>
        <?php endif; ?>
    </table>
</main>

</body>
</html>
