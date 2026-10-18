<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
exigirPermissaoAcao('editar_financeiro_aluno', 'Seu perfil não pode alterar parcelas.');

// Muda as condições de uma parcela em aberto (modal do lápis): descrição,
// valor, vencimento, desconto até uma data e observação. Com boleto/Pix já
// registrado, valor, vencimento e desconto vão antes para o banco; o que o
// banco recusar não muda aqui (sistema e boleto ficam sempre iguais).
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/financeiro/contas_receber/listar.php');
}

$pdo = getDB();
$id = (int) ($_POST['id'] ?? 0);
$stmt = $pdo->prepare('SELECT cr.*, ' . SQL_BOLETO_NO_BANCO . ' AS boleto_no_banco FROM contas_receber cr WHERE cr.id = ?');
$stmt->execute([$id]);
$conta = $stmt->fetch();

$voltar = match (input('voltar')) {
    'aluno' => $conta && $conta['aluno_id'] ? '/alunos/form.php?id=' . (int) $conta['aluno_id'] . '&tab=financeiro' : null,
    'matricula' => $conta && $conta['matricula_id'] ? '/matriculas/visualizar.php?id=' . (int) $conta['matricula_id'] : null,
    default => null,
} ?? '/financeiro/contas_receber/listar.php';

if (!$conta || $conta['status'] !== 'pendente') {
    flash('danger', 'Só parcelas em aberto podem ser alteradas.');
    redirect($voltar);
}

$descricao = mb_substr(input('descricao'), 0, 255);
$valor = decimalInput('valor', 0);
$vencimento = input('data_vencimento');
$observacao = mb_substr(input('observacao'), 0, 255);
$modoDesconto = input('desconto_modo');
$descontoValor = match ($modoDesconto) {
    'personalizado' => decimalInput('desconto_valor', 0),
    'nenhum' => 0.0,
    default => null,
};
$descontoAte = $modoDesconto === 'personalizado' ? (input('desconto_ate') ?: null) : null;
$formaNova = (int) ($_POST['forma_pagamento_id'] ?? 0) ?: null;

$erros = [];
if ($descricao === '') {
    $erros[] = 'Informe a descrição.';
}
if ($valor <= 0) {
    $erros[] = 'O valor precisa ser maior que zero.';
}
if (!$vencimento || !strtotime($vencimento)) {
    $erros[] = 'Informe o vencimento.';
}
if ($modoDesconto === 'personalizado') {
    if ($descontoValor <= 0) {
        $erros[] = 'Informe o valor do desconto (ou escolha "Sem desconto").';
    } elseif ($descontoValor >= $valor) {
        $erros[] = 'O desconto precisa ser menor que o valor da parcela.';
    }
    if ($descontoAte && $vencimento && $descontoAte > $vencimento) {
        $erros[] = 'O desconto vale até, no máximo, o dia do vencimento.';
    }
}
if ($conta['boleto_no_banco'] && $vencimento && $vencimento < date('Y-m-d') && $vencimento !== $conta['data_vencimento']) {
    $erros[] = 'Com boleto no banco, o novo vencimento não pode ser no passado.';
}
if ($erros) {
    flash('danger', implode(' ', $erros));
    redirect($voltar);
}

// Forma de pagamento: indo para cartão/dinheiro, o boleto do banco é baixado
// antes (e aí não há mais boleto para alterar).
$avisoForma = '';
if (array_key_exists('forma_pagamento_id', $_POST) && (int) $conta['forma_pagamento_id'] !== (int) $formaNova) {
    $troca = trocarFormaParcela($pdo, $id, $formaNova);
    if (!$troca['ok']) {
        flash('danger', $troca['mensagem']);
        redirect($voltar);
    }
    $avisoForma = $troca['resumo'] ?? '';
    $stmt = $pdo->prepare('SELECT ' . SQL_BOLETO_NO_BANCO . ' FROM contas_receber cr WHERE cr.id = ?');
    $stmt->execute([$id]);
    $conta['boleto_no_banco'] = (bool) $stmt->fetchColumn();
}

$avisoBanco = '';
$reemitir = false;
if ($conta['boleto_no_banco']) {
    $depois = array_merge($conta, ['valor' => $valor, 'data_vencimento' => $vencimento, 'desconto_valor' => $descontoValor, 'desconto_ate' => $descontoAte]);
    $banco = alterarBoletoBancoItau($pdo, $conta, $depois);
    if (!$banco['ok']) {
        // O que o banco aceitou antes da recusa vale; o resto fica como era.
        $valor = $banco['aceito']['valor'] ? $valor : (float) $conta['valor'];
        $vencimento = $banco['aceito']['vencimento'] ? $vencimento : $conta['data_vencimento'];
        if (!$banco['aceito']['desconto']) {
            $descontoValor = $conta['desconto_valor'] === null ? null : (float) $conta['desconto_valor'];
            $descontoAte = $conta['desconto_ate'];
        }
        $avisoBanco = $banco['mensagem'];
    } elseif ($banco['havia_boleto']) {
        $avisoBanco = $banco['aguardando_aprovacao'] ? 'ok:' . AVISO_APROVACAO_ITAU : 'ok';
        $reemitir = $banco['reemitir'];
    }
}

$stmt = $pdo->prepare(
    'UPDATE contas_receber SET descricao = ?, valor = ?, data_vencimento = ?, desconto_valor = ?, desconto_ate = ?, observacao = ? WHERE id = ?'
);
$stmt->execute([$descricao, $valor, $vencimento, $descontoValor, $descontoAte, $observacao ?: null, $id]);

// Histórico do que mudou (valores antes → depois).
$mudancas = [];
if ((float) $conta['valor'] !== $valor) {
    $mudancas[] = 'valor ' . formatMoney($conta['valor']) . ' → ' . formatMoney($valor);
}
if ($conta['data_vencimento'] !== $vencimento) {
    $mudancas[] = 'vencimento ' . formatDate($conta['data_vencimento']) . ' → ' . formatDate($vencimento);
}
$textoDesconto = fn ($v, $ate) => $v === null ? 'regra da matrícula' : ((float) $v > 0 ? formatMoney($v) . ($ate ? ' até ' . formatDate($ate) : ' até o vencimento') : 'sem desconto');
$antes = $textoDesconto($conta['desconto_valor'], $conta['desconto_ate']);
$depois = $textoDesconto($descontoValor, $descontoAte);
if ($antes !== $depois) {
    $mudancas[] = "desconto {$antes} → {$depois}";
}
if ($conta['descricao'] !== $descricao) {
    $mudancas[] = 'descrição alterada';
}
if ($avisoForma !== '') {
    $mudancas[] = 'forma de pagamento ' . $avisoForma;
}
registrarAtividade('contas_receber', 'edicao', 'Parcela alterada: ' . $descricao . ($mudancas ? ' (' . implode('; ', $mudancas) . ')' : ''));
$mudancasHistorico = array_values(array_filter($mudancas, fn ($m) => !str_starts_with($m, 'forma de pagamento')));
if ($mudancasHistorico) {
    registrarHistoricoParcela($pdo, $id, 'edicao', ucfirst(implode('; ', $mudancasHistorico)) . ($reemitir ? ' — boleto baixado e emitido de novo' : ''));
}

// Desconto mudou: o boleto antigo já foi baixado; emite o novo com a
// parcela já salva (valor, vencimento e desconto novos).
if ($reemitir) {
    $novo = registrarCobrancaItau($pdo, $id, usuarioAtualId());
    registrarAtividade('contas_receber', 'edicao', 'Boleto reemitido por mudança de desconto: ' . $descricao . ($novo['ok'] ? '' : ' (falhou: ' . $novo['mensagem'] . ')'));
    $textoAprovacao = str_starts_with($avisoBanco, 'ok:') ? ' ' . substr($avisoBanco, 3) : '';
    flash($novo['ok'] && $textoAprovacao === '' ? 'success' : 'warning', 'Parcela atualizada' . ($mudancas ? ': ' . implode('; ', $mudancas) : '') . '. '
        . ($novo['ok']
            ? 'Como o desconto mudou, o boleto antigo foi baixado e um novo foi emitido — o boleto e o Pix enviados antes ao aluno não valem mais; mande o novo.'
            : 'O boleto antigo foi baixado, mas o novo não foi emitido: ' . $novo['mensagem'] . ' Clique no ícone do boleto para tentar de novo.')
        . $textoAprovacao);
    redirect($voltar);
}
if ($avisoBanco !== '' && !str_starts_with($avisoBanco, 'ok')) {
    flash('danger', $avisoBanco . ($mudancas ? ' Foi salvo só o que o banco aceitou: ' . implode('; ', $mudancas) . '.' : ' Nada foi alterado.'));
    redirect($voltar);
}
flash(str_starts_with($avisoBanco, 'ok:') ? 'warning' : 'success', 'Parcela atualizada' . ($mudancas ? ': ' . implode('; ', $mudancas) : '') . '.'
    . (str_starts_with($avisoBanco, 'ok') && $mudancas ? ' O boleto foi alterado também no banco.' : '')
    . (str_starts_with($avisoBanco, 'ok:') ? ' ' . substr($avisoBanco, 3) : ''));
redirect($voltar);
