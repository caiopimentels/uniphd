<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirInadimplencia();

// Dados do Pix e do boleto de uma parcela, para o modal "Pagar com Pix".
// Com POST gerar=1, registra antes a cobrança no Itaú (boleto + Pix) se a
// parcela ainda não tem uma — é o que o modal faz ao abrir. Depois de um
// erro recente, só tenta de novo com forcar=1 (cada tentativa que chega ao
// banco consome um nosso número).
$pdo = getDB();
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$erroGeracao = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['gerar'])) {
    $stmt = $pdo->prepare('SELECT * FROM contas_receber WHERE id = ?');
    $stmt->execute([$id]);
    $parcela = $stmt->fetch();
    $stmt = $pdo->prepare("SELECT status, mensagem_erro, criado_em FROM cobrancas_bancarias WHERE conta_receber_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$id]);
    $ultima = $stmt->fetch();
    $jaTem = $ultima && in_array($ultima['status'], ['pendente_envio', 'registrada', 'paga'], true);
    $conta = $parcela ? contaBancariaParaCobranca($pdo, $parcela['forma_pagamento_id'] ? (int) $parcela['forma_pagamento_id'] : null) : null;

    if ($parcela && !$jaTem && $parcela['status'] === 'pendente' && $conta && $conta['integracao'] === 'itau') {
        $erroRecente = $ultima && $ultima['status'] === 'erro' && strtotime($ultima['criado_em']) > time() - 600;
        if ($erroRecente && empty($_POST['forcar'])) {
            $erroGeracao = 'A última tentativa falhou: ' . ($ultima['mensagem_erro'] ?: 'erro do banco') . ' Corrija e clique em "Tentar de novo".';
        } else {
            $resultado = registrarCobrancaItau($pdo, $id, usuarioAtualId());
            if ($resultado['ok']) {
                registrarAtividade('contas_receber', 'edicao', 'Boleto + Pix registrado no Itaú para a parcela ' . ($parcela['descricao'] ?? $id));
            } else {
                $erroGeracao = $resultado['mensagem'];
            }
        }
    }
}

$dados = dadosBoletoParcela($pdo, $id);
if (!$dados) {
    responderJson(['sucesso' => false, 'mensagem' => 'Parcela não encontrada.']);
}

$parcela = $dados['parcela'];
$aluno = $dados['aluno'];
$registrado = $dados['registrada'] && (bool) $dados['pix_copia_cola'];

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
    'registrado' => $registrado,
    'boleto_registrado' => (bool) $dados['registrada'],
    'nosso_numero' => $dados['cobranca'] ? formatarNossoNumeroItau((int) $dados['cobranca']['nosso_numero']) : null,
    'copia_cola' => $dados['pix_copia_cola'],
    'linha_digitavel' => $dados['registrada'] ? $dados['linha_digitavel'] : null,
    'erro_geracao' => $erroGeracao,
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
