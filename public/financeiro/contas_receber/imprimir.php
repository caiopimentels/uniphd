<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
exigirVerFinanceiro();

$pdo = getDB();

$filtros = [
    'status' => implode(',', listaStatusReceber($_GET['status'] ?? 'pendente')),
    'data_tipo' => ($_GET['data_tipo'] ?? '') === 'pagamento' ? 'pagamento' : 'vencimento',
    'data_inicio' => $_GET['data_inicio'] ?? '',
    'data_fim' => $_GET['data_fim'] ?? '',
    'busca' => trim((string) ($_GET['busca'] ?? '')),
    'boleto' => (string) ($_GET['boleto'] ?? ''),
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
$contas = ordenarLinhas($contas, estadoListagem('contas_receber', colunasListaReceber())); // mesma ordem da tela
// Parcelas marcadas na tela: só elas, com os grupos abertos.
$soSelecionadas = !empty($_GET['ids']);
$contas = filtrarSelecionadas($contas);
$grupos = agruparContasReceber($contas, $agrupar);
if ($soSelecionadas) {
    $abertos = array_column($grupos, 'id');
}
// Mesmas colunas da tela; os totais ficam embaixo da coluna Valor.
$colunas = colunasVisiveis($pdo, 'contas_receber', definicaoColunasTela($pdo, 'contas_receber'));
$posicaoValor = array_search('valor', array_keys($colunas), true);
$posicaoValor = $posicaoValor === false || $posicaoValor === 0 ? null : $posicaoValor;
$totalExibido = array_sum(array_map('valorExibidoReceber', $contas));
$labelStatus = rotuloStatusReceber(listaStatusReceber($status));
$labelTotal = count(listaStatusReceber($status)) > 1 ? 'Total da seleção' : match ($status) {
    'pendente' => 'Total pendente',
    'vencido' => 'Total vencido',
    'pago' => 'Total recebido',
    'cancelado' => 'Total cancelado',
    'suspenso' => 'Total suspenso',
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
        <?= $soSelecionadas ? 'Somente as selecionadas &middot; ' : '' ?>Situação: <?= e($labelStatus) ?>
        <?php if ($filtros['busca'] !== ''): ?>&middot; Aluno: <?= e($filtros['busca']) ?><?php endif; ?>
        <?php if ($agrupar): ?>&middot; Agrupado <?= e(mb_strtolower(opcoesAgruparReceber()[$agrupar])) ?><?php endif; ?>
        <?php if ($dataInicio || $dataFim): ?>
            &middot; <?= $filtros['data_tipo'] === 'pagamento' ? 'Pagamento' : 'Vencimento' ?>: <?= $dataInicio ? formatDate($dataInicio) : '...' ?> a <?= $dataFim ? formatDate($dataFim) : '...' ?>
        <?php endif; ?>
        &middot; Emitido em <?= formatDateTime(date('Y-m-d H:i:s')) ?>
    </p>

    <table>
        <thead><tr><?= cabecalhoColunasImpressao($colunas) ?></tr></thead>
        <tbody>
        <?php if (!$contas): ?>
            <tr><td colspan="<?= count($colunas) ?>">Nenhuma conta encontrada.</td></tr>
        <?php endif; ?>
        <?php foreach ($grupos as $grupo): $grupoAberto = !$agrupar || in_array($grupo['id'], $abertos, true); ?>
            <?php if ($agrupar): ?>
                <tr class="linha-grupo">
                    <th colspan="<?= $posicaoValor ?? count($colunas) ?>"><?= e($grupo['nome']) ?> <span class="grupo-qtd">· <?= count($grupo['contas']) ?> lançamento(s)<?= $posicaoValor === null ? ' · ' . formatMoney($grupo['total']) : '' ?></span></th>
                    <?php if ($posicaoValor !== null): ?>
                    <th class="text-end"><?= formatMoney($grupo['total']) ?></th>
                    <?php if (count($colunas) - $posicaoValor - 1 > 0): ?><th colspan="<?= count($colunas) - $posicaoValor - 1 ?>"></th><?php endif; ?>
                    <?php endif; ?>
                </tr>
            <?php endif; ?>
            <?php if ($grupoAberto): foreach ($grupo['contas'] as $c): ?>
            <tr><?= celulasColunasImpressao($colunas, $c) ?></tr>
            <?php endforeach; endif; ?>
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
