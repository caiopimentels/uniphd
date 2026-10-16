<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
exigirPermissaoAcao('editar_financeiro_aluno', 'Seu perfil não pode alterar parcelas.');

// Muda as condições de uma parcela em aberto (modal do lápis): descrição,
// valor, vencimento, desconto até uma data e observação. Parcela com
// boleto/Pix já no banco não muda aqui — o banco cobraria o valor antigo.
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
if ($conta['boleto_no_banco']) {
    flash('danger', 'Esta parcela já tem boleto/Pix registrado no banco, com o valor e o desconto antigos — por isso não pode ser alterada aqui.');
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
if ($erros) {
    flash('danger', implode(' ', $erros));
    redirect($voltar);
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
registrarAtividade('contas_receber', 'edicao', 'Parcela alterada: ' . $descricao . ($mudancas ? ' (' . implode('; ', $mudancas) . ')' : ''));

flash('success', 'Parcela atualizada' . ($mudancas ? ': ' . implode('; ', $mudancas) : '') . '.');
redirect($voltar);
