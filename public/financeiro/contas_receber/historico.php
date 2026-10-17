<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
exigirInadimplencia();

// Linha do tempo de uma parcela (modal "Histórico"): devolve o HTML pronto.
$pdo = getDB();
$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare(
    'SELECT cr.*, a.nome AS aluno_nome, fp.nome AS forma_nome FROM contas_receber cr
     LEFT JOIN alunos a ON a.id = cr.aluno_id LEFT JOIN formas_pagamento fp ON fp.id = cr.forma_pagamento_id WHERE cr.id = ?'
);
$stmt->execute([$id]);
$parcela = $stmt->fetch();
if (!$parcela) {
    http_response_code(404);
    echo '<div class="alert alert-danger mb-0">Parcela não encontrada.</div>';
    exit;
}
$itens = historicoParcela($pdo, $id);
?>
<div class="d-flex flex-wrap justify-content-between gap-2 mb-3">
    <div>
        <div class="fw-semibold"><?= e($parcela['descricao']) ?></div>
        <div class="small text-muted"><?= e($parcela['aluno_nome'] ?: '-') ?> · vence <?= formatDate($parcela['data_vencimento']) ?> · <?= formatMoney($parcela['valor']) ?></div>
    </div>
    <div class="text-end">
        <span class="badge <?= contaStatusBadgeClass($parcela['status']) ?>"><?= contaStatusLabel($parcela['status']) ?></span>
        <?php if ($parcela['status'] === 'pago'): ?>
            <div class="small text-success fw-semibold mt-1"><?= formatMoney($parcela['valor_recebido'] ?? $parcela['valor']) ?> em <?= formatDate($parcela['data_recebimento']) ?></div>
            <?php if ($parcela['forma_nome']): ?><div class="small text-muted"><?= e($parcela['forma_nome']) ?></div><?php endif; ?>
        <?php endif; ?>
    </div>
</div>
<ul class="list-unstyled mb-0 historico-parcela">
    <?php foreach ($itens as $h): ?>
        <li class="d-flex gap-2 py-2 border-bottom">
            <i class="bi <?= e($h['icone']) ?> text-<?= e($h['cor']) ?>"></i>
            <div class="flex-grow-1 lh-sm">
                <div class="d-flex justify-content-between gap-2">
                    <strong><?= e($h['titulo']) ?></strong>
                    <span class="small text-muted text-nowrap"><?= formatDateTime($h['quando']) ?></span>
                </div>
                <div class="small text-muted"><?= $h['detalhe'] !== '' ? e($h['detalhe']) . ' · ' : '' ?>por <?= e($h['quem']) ?></div>
            </div>
        </li>
    <?php endforeach; ?>
</ul>
