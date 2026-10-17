<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirInadimplencia();

// Consulta manual de pagamento: pergunta ao banco se o boleto da parcela já
// foi pago (para quando o aviso do webhook demora) e, se foi, baixa a parcela.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responderJson(['ok' => false, 'pago' => false, 'mensagem' => 'Use POST.']);
}

responderJson(consultarPagamentoItau(getDB(), (int) ($_POST['id'] ?? 0)));
