<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirAluno();

$pdo = getDB();
$parcela = parcelaDoAluno($pdo, alunoAtualId(), (int) ($_GET['id'] ?? 0));
if (!$parcela) {
    flash('danger', 'Parcela não encontrada.');
    redirect('/portal/financeiro.php');
}
if ($parcela['status'] === 'pago') {
    flash('success', 'Esta parcela já está paga.');
    redirect('/portal/financeiro.php');
}
// Parcela no cartão/dinheiro (sem boleto já gerado): paga na secretaria.
$stmt = $pdo->prepare("SELECT COUNT(*) FROM cobrancas_bancarias WHERE conta_receber_id = ? AND status IN ('pendente_envio', 'registrada')");
$stmt->execute([$parcela['id']]);
if (!(int) $stmt->fetchColumn() && ($formaSecretaria = formaPagaNaSecretaria($pdo, $parcela['forma_pagamento_id'] ? (int) $parcela['forma_pagamento_id'] : null))) {
    flash('info', 'Esta parcela é paga com ' . mb_strtolower($formaSecretaria) . ' na secretaria.');
    redirect('/portal/financeiro.php');
}

// Registra o boleto + Pix no banco na primeira vez que o aluno abre.
$garantia = garantirCobrancaParcelaPortal($pdo, $parcela);
$dados = $garantia['ok'] ? dadosBoletoParcela($pdo, (int) $parcela['id']) : null;
if ($dados && !$dados['registrada']) {
    $dados = null;
}

// É o pagamento que confirma uma inscrição? Mostra as etapas e o prazo.
$stmt = $pdo->prepare(
    "SELECT m.pagamento_ate, m.segura_vaga, t.nome AS turma_nome, c.nome AS curso_nome
     FROM matriculas m JOIN turmas t ON t.id = m.turma_id JOIN cursos c ON c.id = t.curso_id
     WHERE m.conta_receber_confirmacao_id = ? AND m.status = 'pendente' AND m.aluno_id = ?"
);
$stmt->execute([$parcela['id'], alunoAtualId()]);
$inscricaoPendente = $stmt->fetch() ?: null;

$tituloPagina = 'Pagamento';
require __DIR__ . '/../../includes/portal_header.php';
?>
<a href="/portal/financeiro.php" class="small"><i class="bi bi-arrow-left"></i> Pagamentos</a>

<div class="portal-acesso largo mt-2">
    <?php if ($inscricaoPendente): ?>
        <div class="portal-card mb-3">
            <div class="d-flex justify-content-between text-center small fw-semibold etapas-inscricao">
                <div class="text-success"><i class="bi bi-check-circle-fill fs-4 d-block"></i>Inscrição feita</div>
                <div style="color: var(--accent-dark);"><i class="bi bi-2-circle-fill fs-4 d-block"></i>Pagamento</div>
                <div class="text-muted"><i class="bi bi-3-circle fs-4 d-block"></i>Matrícula confirmada</div>
            </div>
            <div class="mt-3">
                <strong><?= e($inscricaoPendente['curso_nome']) ?></strong> · <?= e($inscricaoPendente['turma_nome']) ?><br>
                Falta só pagar: <strong><?= e($parcela['descricao']) ?></strong>, até <strong><?= formatDateTime($inscricaoPendente['pagamento_ate']) ?></strong>.
                <?= $inscricaoPendente['segura_vaga'] ? 'Sua vaga fica reservada até lá.' : 'A vaga é garantida assim que o pagamento for confirmado.' ?>
                Pelo Pix a confirmação chega em poucos minutos.
            </div>
        </div>
    <?php endif; ?>
    <div class="portal-card text-center">
        <div class="text-muted"><?= e($parcela['descricao']) ?></div>
        <?php // Com boleto gerado, valem a data e o valor dele (parcela vencida ganha vencimento novo).
        $exibida = $dados ? $dados['parcela'] : $parcela; ?>
        <div class="fs-2 fw-bold"><?= formatMoney($exibida['valor']) ?></div>
        <div class="small text-muted">Vencimento <?= formatDate($exibida['data_vencimento']) ?></div>
        <?php if ($exibida['data_vencimento'] !== $parcela['data_vencimento']): ?>
            <div class="small text-muted">
                Parcela vencida em <?= formatDate($parcela['data_vencimento']) ?> — este boleto tem novo vencimento<?php if ((float) $exibida['valor'] > (float) $parcela['valor']): ?>
                    e inclui <?= formatMoney((float) $exibida['valor'] - (float) $parcela['valor']) ?> de multa e juros pelo atraso<?php endif; ?>.
            </div>
        <?php endif; ?>
        <?php if ($dados && $dados['desconto'] && $dados['desconto']['data_limite'] >= date('Y-m-d')): ?>
            <div class="small text-success fw-semibold mt-1"><?= e($dados['desconto']['texto']) ?></div>
        <?php endif; ?>
    </div>

    <?php if (!$dados): ?>
        <div class="alert alert-warning mt-3">
            <?= e($garantia['mensagem'] ?: 'O pagamento desta parcela ainda não está disponível.') ?>
            <?php if (str_contains($garantia['mensagem'], 'Meus dados')): ?><a href="/portal/conta.php?voltar=<?= urlencode('/portal/pagar.php?id=' . (int) $parcela['id']) ?>" class="alert-link">Completar agora</a><?php endif; ?>
        </div>
    <?php else: ?>
        <?php $meiosParcela = meiosCobrancaForma($pdo, $parcela['forma_pagamento_id'] ? (int) $parcela['forma_pagamento_id'] : null); ?>
        <?php if ($dados['pix_copia_cola'] && $meiosParcela['pix']): ?>
            <div class="portal-card mt-3 text-center">
                <div class="fw-semibold mb-2"><i class="bi bi-qr-code"></i> Pague com Pix — cai na hora</div>
                <div id="qr-pix" class="d-inline-block p-2 bg-white rounded-3 border mb-3"></div>
                <div class="small text-muted mb-2">Abra o app do seu banco, escolha Pix › Ler QR Code. No celular, use o "copia e cola":</div>
                <div class="input-group">
                    <input type="text" id="pix-codigo" class="form-control" readonly value="<?= e($dados['pix_copia_cola']) ?>">
                    <button class="btn btn-brand" type="button" data-copiar="pix-codigo"><i class="bi bi-clipboard"></i> Copiar</button>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($dados['linha_digitavel'] && $meiosParcela['boleto']): ?>
            <div class="portal-card mt-3">
                <div class="fw-semibold mb-2"><i class="bi bi-upc"></i> <?= $meiosParcela['pix'] ? 'Ou pague o boleto' : 'Pague o boleto' ?></div>
                <label class="small text-muted">Linha digitável</label>
                <div class="input-group mb-3">
                    <input type="text" id="linha-digitavel" class="form-control font-monospace" readonly value="<?= e($dados['linha_digitavel']) ?>">
                    <button class="btn btn-outline-brand" type="button" data-copiar="linha-digitavel"><i class="bi bi-clipboard"></i> Copiar</button>
                </div>
                <a href="/portal/boleto.php?id=<?= (int) $parcela['id'] ?>" target="_blank" class="btn btn-outline-brand w-100"><i class="bi bi-printer"></i> Ver / imprimir boleto</a>
                <div class="small text-muted mt-2">O boleto leva até 3 dias úteis para ser compensado.</div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php if ($dados && $dados['pix_copia_cola'] && $meiosParcela['pix']): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>new QRCode(document.getElementById('qr-pix'), { text: <?= json_encode($dados['pix_copia_cola']) ?>, width: 220, height: 220, correctLevel: QRCode.CorrectLevel.M });</script>
<?php endif; ?>
<?php require __DIR__ . '/../../includes/portal_footer.php'; ?>
