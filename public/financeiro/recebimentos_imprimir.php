<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirVerFinanceiro();

$pdo = getDB();

$filtros = [
    'data_inicio' => $_GET['data_inicio'] ?? '',
    'data_fim' => $_GET['data_fim'] ?? '',
    'forma_pagamento_id' => (string) ($_GET['forma_pagamento_id'] ?? ''),
    'busca' => trim($_GET['busca'] ?? ''),
];
// Impressão leva tudo o que bate no filtro, não só a página da tela.
$relatorio = buscarRecebimentos($pdo, $filtros, null);

$labelForma = 'Todos';
if ($filtros['forma_pagamento_id'] === 'sem') {
    $labelForma = 'Não informada';
} elseif ((int) $filtros['forma_pagamento_id'] > 0) {
    $stmt = $pdo->prepare('SELECT nome FROM formas_pagamento WHERE id = ?');
    $stmt->execute([(int) $filtros['forma_pagamento_id']]);
    $labelForma = $stmt->fetchColumn() ?: 'Todos';
}
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Recebimentos</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="<?= e(assetUrl('/assets/css/relatorio.css')) ?>">
</head>
<body>

<div class="toolbar">
    <button type="button" onclick="window.location.href='/financeiro/recebimentos.php'">
        <i class="bi bi-arrow-left"></i>
        Voltar
    </button>

    <button type="button" onclick="window.print()" class="btn-imprimir">
        <i class="bi bi-printer"></i>
        Imprimir relatório
    </button>
</div>

<main class="relatorio">
    <h1>Recebimentos</h1>
    <p class="relatorio-meta">
        Período: <?= ($filtros['data_inicio'] || $filtros['data_fim'])
            ? ($filtros['data_inicio'] ? formatDate($filtros['data_inicio']) : '...') . ' a ' . ($filtros['data_fim'] ? formatDate($filtros['data_fim']) : '...')
            : 'todo o período' ?>
        &middot; Tipo de recebimento: <?= e($labelForma) ?>
        <?php if ($filtros['busca'] !== ''): ?>&middot; Busca: "<?= e($filtros['busca']) ?>"<?php endif; ?>
        &middot; Emitido em <?= formatDateTime(date('Y-m-d H:i:s')) ?>
    </p>

    <table>
        <thead>
            <tr>
                <th>Data</th>
                <th>Descrição</th>
                <th>Tipo de recebimento</th>
                <th class="text-end">Valor</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$relatorio['itens']): ?>
            <tr><td colspan="4">Nenhum recebimento no filtro escolhido.</td></tr>
        <?php endif; ?>
        <?php foreach ($relatorio['itens'] as $r): ?>
            <tr>
                <td><?= formatDate($r['data_recebimento']) ?></td>
                <td><?= e($r['descricao']) ?><?= $r['aluno_nome'] ? ' · ' . e($r['aluno_nome']) : '' ?></td>
                <td><?= e($r['forma_nome']) ?></td>
                <td class="text-end"><?= formatMoney($r['valor_recebido']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <?php if ($relatorio['itens']): ?>
        <tfoot>
            <?php if (count($relatorio['por_forma']) > 1): ?>
                <?php foreach ($relatorio['por_forma'] as $grupo): ?>
                    <tr>
                        <td colspan="3" class="text-end">Subtotal <?= e($grupo['forma']) ?> (<?= (int) $grupo['quantidade'] ?>)</td>
                        <td class="text-end"><?= formatMoney($grupo['total']) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            <tr>
                <td colspan="3" class="text-end">Total recebido (<?= (int) $relatorio['quantidade'] ?> recebimento(s))</td>
                <td class="text-end"><?= formatMoney($relatorio['total']) ?></td>
            </tr>
        </tfoot>
        <?php endif; ?>
    </table>
</main>

</body>
</html>
