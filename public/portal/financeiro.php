<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirTelaPortal('financeiro');
exigirAluno();

$pdo = getDB();
$tituloPagina = 'Pagamentos';
$telaPortal = 'financeiro';
require __DIR__ . '/../../includes/portal_header.php';

// Tudo o que foi lançado para o aluno, numa lista só: primeiro o que está
// em aberto, por ordem de vencimento; depois o que já foi pago (mais
// recentes primeiro). Cada linha diz de qual curso/turma é.
$stmt = $pdo->prepare(
    "SELECT cr.*, t.nome AS turma_nome, c.nome AS curso_nome,
            (SELECT COUNT(*) FROM cobrancas_bancarias cb WHERE cb.conta_receber_id = cr.id AND cb.status = 'registrada') AS tem_boleto
     FROM contas_receber cr
     LEFT JOIN matriculas m ON m.id = cr.matricula_id
     LEFT JOIN turmas t ON t.id = m.turma_id
     LEFT JOIN cursos c ON c.id = t.curso_id
     WHERE cr.aluno_id = ? AND cr.status IN ('pendente', 'pago') AND cr.excluido_em IS NULL
     ORDER BY cr.data_vencimento, cr.id"
);
$stmt->execute([alunoAtualId()]);
$hoje = date('Y-m-d');
$abertas = [];
$pagas = [];
foreach ($stmt->fetchAll() as $p) {
    $p['vencida'] = $p['status'] === 'pendente' && $p['data_vencimento'] < $hoje;
    if ($p['status'] === 'pago') {
        $pagas[] = $p;
    } else {
        $abertas[] = $p;
    }
}
usort($pagas, fn ($x, $y) => strcmp((string) $y['data_recebimento'], (string) $x['data_recebimento']));
$totalAberto = array_sum(array_column($abertas, 'valor'));
$totalVencido = array_sum(array_map(fn ($p) => $p['vencida'] ? (float) $p['valor'] : 0, $abertas));
$origem = fn (array $p) => $p['matricula_id'] ? $p['curso_nome'] . ' · ' . $p['turma_nome'] : 'Lançamento avulso';
?>
<h1 class="portal-titulo">Pagamentos</h1>
<p class="portal-sub">Suas parcelas em ordem de vencimento.</p>

<?php if (!$abertas && !$pagas): ?>
    <div class="portal-card portal-vazio"><i class="bi bi-emoji-smile"></i>Nenhum lançamento por aqui.</div>
<?php endif; ?>

<?php if ($abertas): ?>
    <div class="d-flex flex-wrap gap-2 mb-2">
        <span class="etiqueta aberta">Em aberto: <?= formatMoney($totalAberto) ?></span>
        <?php if ($totalVencido > 0): ?><span class="etiqueta vencida">Vencido: <?= formatMoney($totalVencido) ?></span><?php endif; ?>
    </div>
    <div class="portal-card">
        <?php foreach ($abertas as $p): $desconto = descontoPontualidadeParcela($pdo, $p); $descontoValendo = $desconto && $desconto['data_limite'] >= $hoje; ?>
            <div class="lista-linha">
                <div class="principal">
                    <div class="t"><?= e($p['descricao']) ?></div>
                    <div class="s"><i class="bi <?= $p['matricula_id'] ? 'bi-mortarboard' : 'bi-receipt' ?>"></i> <?= e($origem($p)) ?></div>
                    <div class="s">
                        Vence <?= formatDate($p['data_vencimento']) ?>
                        <span class="etiqueta <?= $p['vencida'] ? 'vencida' : 'aberta' ?> ms-1"><?= $p['vencida'] ? 'Vencida' : 'Em aberto' ?></span>
                    </div>
                    <?php if ($descontoValendo): ?><div class="s text-success"><?= e($desconto['texto']) ?></div><?php endif; ?>
                </div>
                <div class="text-end">
                    <?php if ($descontoValendo): ?>
                        <div class="valor-riscado"><?= formatMoney($p['valor']) ?></div>
                        <div class="valor text-success"><?= formatMoney($desconto['valor_com_desconto']) ?></div>
                    <?php else: ?>
                        <div class="valor"><?= formatMoney($p['valor']) ?></div>
                        <?php if ($p['vencida']): ?><div class="small text-danger">+ encargos do atraso</div><?php endif; ?>
                    <?php endif; ?>
                    <?php if (!$p['tem_boleto'] && ($formaSecretaria = formaPagaNaSecretaria($pdo, $p['forma_pagamento_id'] ? (int) $p['forma_pagamento_id'] : null))): ?>
                        <button type="button" class="btn btn-sm btn-brand mt-1" onclick="pagarNaSecretaria(<?= e(json_encode($formaSecretaria)) ?>)">Pagar</button>
                    <?php else: ?>
                        <a href="/portal/pagar.php?id=<?= (int) $p['id'] ?>" class="btn btn-sm btn-brand mt-1">Pagar</a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php elseif ($pagas): ?>
    <div class="portal-card portal-vazio"><i class="bi bi-emoji-smile"></i>Tudo em dia — nenhuma parcela em aberto.</div>
<?php endif; ?>

<?php if ($pagas): ?>
    <h2 class="h6 mt-4 mb-2 text-muted text-uppercase">Pagos</h2>
    <div class="portal-card">
        <?php foreach ($pagas as $p): ?>
            <div class="lista-linha">
                <div class="principal">
                    <div class="t"><?= e($p['descricao']) ?></div>
                    <div class="s"><i class="bi <?= $p['matricula_id'] ? 'bi-mortarboard' : 'bi-receipt' ?>"></i> <?= e($origem($p)) ?></div>
                    <div class="s">Pago em <?= formatDate($p['data_recebimento']) ?> <span class="etiqueta paga ms-1">Pago</span></div>
                </div>
                <div class="text-end"><div class="valor"><?= formatMoney($p['valor_recebido'] ?? $p['valor']) ?></div></div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<div class="modal fade" id="modal-secretaria" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-body text-center p-4">
                <i class="bi bi-credit-card fs-1" style="color: var(--accent);"></i>
                <div class="fw-semibold mt-2">Pagamento na secretaria</div>
                <div class="mt-2">Esta parcela é paga com <strong id="forma-secretaria"></strong> direto na secretaria da escola.</div>
            </div>
            <div class="modal-footer justify-content-center"><button type="button" class="btn btn-brand" data-bs-dismiss="modal">Entendi</button></div>
        </div>
    </div>
</div>
<script>
// Parcela no cartão/dinheiro: o botão Pagar explica onde pagar.
function pagarNaSecretaria(forma) {
    document.getElementById('forma-secretaria').textContent = forma.toLocaleLowerCase('pt-BR');
    bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-secretaria')).show();
}
</script>
<?php require __DIR__ . '/../../includes/portal_footer.php'; ?>
