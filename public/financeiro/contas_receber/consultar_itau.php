<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
exigirInadimplencia();

// "Consultar no Itaú" do histórico da parcela: mostra o que o banco tem
// registrado de cada boleto desta parcela (HTML pronto para o modal).
$pdo = getDB();
$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare(
    "SELECT cb.*, cbk.* , cb.id AS cobranca_id, cb.status AS status_cobranca
     FROM cobrancas_bancarias cb JOIN contas_bancarias cbk ON cbk.id = cb.conta_bancaria_id
     WHERE cb.conta_receber_id = ? AND cb.status IN ('registrada', 'paga', 'baixada') ORDER BY cb.id DESC"
);
$stmt->execute([$id]);
$cobrancas = $stmt->fetchAll();
if (!$cobrancas) {
    echo '<div class="alert alert-secondary mb-0">Esta parcela não tem boleto registrado no Itaú.</div>';
    exit;
}
$campo = fn (?array $b, string $chave) => $b['dado_boleto']['dados_individuais_boleto'][0][$chave] ?? null;
$situacaoSistema = ['registrada' => 'No banco', 'paga' => 'Pago', 'baixada' => 'Baixado'];
foreach ($cobrancas as $cb):
    $conta = $cb;
    $conta['id'] = $cb['conta_bancaria_id'];
    $r = consultarBoletoItau($pdo, $conta, (int) $cb['nosso_numero'], $id);
    $b = $r['boleto'];
    $valorItau = $campo($b, 'valor_titulo');
    $vencItau = $campo($b, 'data_vencimento');
    $difere = $b && ((float) $valorItau !== (float) $cb['valor'] || ($vencItau && $vencItau !== $cb['data_vencimento']));
    ?>
    <div class="border rounded p-3 mb-3">
        <div class="fw-semibold mb-2">Nosso nº <?= e(formatarNossoNumeroItau((int) $cb['nosso_numero'])) ?></div>
        <?php if (!$r['ok'] || !$b): ?>
            <div class="alert alert-<?= $r['ok'] ? 'secondary' : 'danger' ?> py-2 mb-0"><?= e($r['mensagem']) ?></div>
        <?php else: ?>
            <table class="table table-sm mb-2">
                <thead><tr><th></th><th>No Itaú</th><th>No sistema</th></tr></thead>
                <tbody>
                    <tr><td>Situação</td><td><strong><?= e((string) ($campo($b, 'situacao_geral_boleto') ?? '-')) ?></strong> <span class="text-muted small"><?= e((string) ($campo($b, 'status_vencimento') ?? '')) ?></span></td><td><?= e($situacaoSistema[$cb['status_cobranca']] ?? $cb['status_cobranca']) ?></td></tr>
                    <tr><td>Valor</td><td><?= $valorItau !== null ? formatMoney((float) $valorItau) : '-' ?></td><td><?= formatMoney($cb['valor']) ?></td></tr>
                    <tr><td>Vencimento</td><td><?= $vencItau ? formatDate($vencItau) : '-' ?></td><td><?= formatDate($cb['data_vencimento']) ?></td></tr>
                    <?php if (!empty($b['dado_boleto']['baixa']['data_inclusao_alteracao_baixa'])): ?>
                        <tr><td>Baixa no Itaú</td><td colspan="2"><?= formatDate($b['dado_boleto']['baixa']['data_inclusao_alteracao_baixa']) ?></td></tr>
                    <?php endif; ?>
                    <?php foreach ($b['dado_boleto']['pagamentos_cobranca'] ?? [] as $pg): ?>
                        <tr><td>Pagamento</td><td colspan="2"><?= isset($pg['valor_pago_total_cobranca']) ? formatMoney((float) $pg['valor_pago_total_cobranca']) : '' ?> <?= !empty($pg['data_inclusao_pagamento']) ? 'em ' . formatDate($pg['data_inclusao_pagamento']) : '' ?> <span class="text-muted small"><?= e(trim(($pg['descricao_meio_pagamento'] ?? '') . ' ' . ($pg['descricao_canal_pagamento'] ?? ''))) ?></span></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php if ($difere): ?>
                <div class="alert alert-warning py-2 small">O Itaú ainda mostra valor ou vencimento diferente do sistema — a alteração aceita pela API ainda não aparece no boleto.</div>
            <?php endif; ?>
            <?php if (!empty($b['dado_boleto']['historico'])): ?>
                <div class="small fw-semibold">Histórico no Itaú</div>
                <ul class="small mb-0">
                    <?php foreach ($b['dado_boleto']['historico'] as $h): ?>
                        <li><?= !empty($h['data']) ? formatDate(substr((string) $h['data'], 0, 10)) . ' — ' : '' ?><?= e((string) ($h['operacao'] ?? '')) ?><?= !empty($h['comandado_por']) ? ' <span class="text-muted">(por ' . e((string) $h['comandado_por']) . ')</span>' : '' ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        <?php endif; ?>
    </div>
<?php endforeach; ?>
<div class="small text-muted">Consulta feita agora na API do Itaú; a resposta completa fica na Auditoria do banco.</div>
