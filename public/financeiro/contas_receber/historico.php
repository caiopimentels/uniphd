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
        <li class="d-flex gap-3 pb-3">
            <div class="text-<?= e($h['cor']) ?> fs-5"><i class="bi <?= e($h['icone']) ?>"></i></div>
            <div class="flex-grow-1">
                <div class="d-flex flex-wrap justify-content-between gap-2">
                    <strong><?= e($h['titulo']) ?></strong>
                    <span class="small text-muted text-nowrap"><?= formatDateTime($h['quando']) ?></span>
                </div>
                <?php if ($h['detalhe'] !== ''): ?><div class="small"><?= e($h['detalhe']) ?></div><?php endif; ?>
                <div class="small text-muted">por <?= e($h['quem']) ?></div>
            </div>
        </li>
    <?php endforeach; ?>
</ul>
<?php if ($parcela['aluno_id']): ?>
    <a href="/alunos/form.php?id=<?= (int) $parcela['aluno_id'] ?>&tab=financeiro" class="btn btn-sm btn-outline-secondary mt-2"><i class="bi bi-person"></i> Abrir o financeiro do aluno</a>
<?php endif; ?>
<a href="/contas_bancarias/auditoria.php?parcela=<?= (int) $id ?>" class="btn btn-sm btn-outline-secondary mt-2"><i class="bi bi-journal-code"></i> Detalhes técnicos (auditoria do banco)</a>
<?php
$stmtBoletos = $pdo->prepare("SELECT COUNT(*) FROM cobrancas_bancarias WHERE conta_receber_id = ? AND status IN ('registrada', 'paga', 'baixada')");
$stmtBoletos->execute([$id]);
if ((int) $stmtBoletos->fetchColumn() > 0): ?>
    <button type="button" class="btn btn-sm btn-outline-primary mt-2" onclick="consultarBoletoNoItau(<?= (int) $id ?>, this)"><i class="bi bi-search"></i> Consultar no Itaú</button>
    <div id="consulta-itau-<?= (int) $id ?>" class="mt-3"></div>
<?php endif; ?>
