<?php
// Abas de uma conta bancária: dados, cobrança (boleto), API do banco, webhook
// de pagamento, auditoria do que foi trocado com o banco, conferência e
// tarifas. Quem inclui define $contaAbaId e $contaAba ('dados', 'cobranca',
// 'api', 'webhook', 'auditoria', 'conferir' ou 'tarifas').
?>
<ul class="nav nav-pills mb-3">
    <li class="nav-item"><a class="nav-link <?= $contaAba === 'dados' ? 'active' : '' ?>" href="/contas_bancarias/form.php?id=<?= (int) $contaAbaId ?>"><i class="bi bi-bank"></i> Dados da conta</a></li>
    <li class="nav-item"><a class="nav-link <?= $contaAba === 'cobranca' ? 'active' : '' ?>" href="/contas_bancarias/form.php?id=<?= (int) $contaAbaId ?>&aba=cobranca"><i class="bi bi-upc-scan"></i> Cobrança</a></li>
    <li class="nav-item"><a class="nav-link <?= $contaAba === 'api' ? 'active' : '' ?>" href="/contas_bancarias/form.php?id=<?= (int) $contaAbaId ?>&aba=api"><i class="bi bi-shield-lock"></i> API do banco</a></li>
    <li class="nav-item"><a class="nav-link <?= $contaAba === 'webhook' ? 'active' : '' ?>" href="/contas_bancarias/webhook.php?id=<?= (int) $contaAbaId ?>"><i class="bi bi-broadcast"></i> Webhook de pagamento</a></li>
    <li class="nav-item"><a class="nav-link <?= $contaAba === 'auditoria' ? 'active' : '' ?>" href="/contas_bancarias/auditoria.php?id=<?= (int) $contaAbaId ?>"><i class="bi bi-journal-text"></i> Auditoria do banco</a></li>
    <li class="nav-item"><a class="nav-link <?= $contaAba === 'conferir' ? 'active' : '' ?>" href="/contas_bancarias/conferir_boletos.php?id=<?= (int) $contaAbaId ?>"><i class="bi bi-arrow-repeat"></i> Conferir boletos</a></li>
    <li class="nav-item"><a class="nav-link <?= $contaAba === 'tarifas' ? 'active' : '' ?>" href="/contas_bancarias/tarifas.php?id=<?= (int) $contaAbaId ?>"><i class="bi bi-receipt"></i> Tarifas</a></li>
</ul>
