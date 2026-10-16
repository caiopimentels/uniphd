<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirInadimplencia();

$pdo = getDB();
$dados = dadosBoletoParcela($pdo, (int) ($_GET['id'] ?? 0));
if (!$dados) {
    responderJson(['sucesso' => false, 'mensagem' => 'Parcela não encontrada.']);
}

$parcela = $dados['parcela'];
$aluno = $dados['aluno'];

// Só monta o link de WhatsApp quando há Pix de verdade para mandar.
$whatsapp = null;
if ($dados['pix_copia_cola'] && !empty($aluno['telefone'])) {
    $digitos = apenasDigitos($aluno['telefone']);
    $whatsapp = linkWhatsapp(strlen($digitos) <= 11 ? '55' . $digitos : $digitos,
        'Olá, ' . explode(' ', trim($aluno['nome']))[0] . '! Segue o Pix da ' . mb_strtolower($parcela['descricao']) . ' (' . formatMoney($parcela['valor'])
        . ', vencimento ' . formatDate($parcela['data_vencimento']) . ')'
        . ($dados['desconto'] ? '. ' . $dados['desconto']['texto'] : '.') . " Copie o código abaixo e cole no app do seu banco:\n\n" . $dados['pix_copia_cola']);
}

responderJson([
    'sucesso' => true,
    'registrado' => $dados['registrada'] && (bool) $dados['pix_copia_cola'],
    'copia_cola' => $dados['pix_copia_cola'],
    'descricao' => $parcela['descricao'],
    'valor' => formatMoney($parcela['valor']),
    'vencimento' => formatDate($parcela['data_vencimento']),
    'pagador' => $aluno['nome'] ?? '-',
    'beneficiario' => $dados['empresa']['empresa_razao_social'] ?: ($dados['empresa']['nome_sistema'] ?? ''),
    'pendencias' => $dados['pendencias'],
    'desconto' => $dados['desconto'] ? $dados['desconto']['texto'] : null,
    'boleto_url' => '/financeiro/boleto.php?id=' . (int) $parcela['id'],
    'whatsapp' => $whatsapp,
]);
