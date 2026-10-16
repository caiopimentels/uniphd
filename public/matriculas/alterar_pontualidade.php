<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();
exigirPermissaoAcao('editar_financeiro_aluno', 'Seu perfil não pode alterar o desconto por pontualidade.');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/turmas/listar.php');
}

$pdo = getDB();
$id = (int) ($_POST['id'] ?? 0);

$stmt = $pdo->prepare(
    'SELECT m.*, a.nome AS aluno_nome FROM matriculas m JOIN alunos a ON a.id = m.aluno_id WHERE m.id = ?'
);
$stmt->execute([$id]);
$matricula = $stmt->fetch();
if (!$matricula || $matricula['status'] !== 'ativa') {
    flash('danger', 'Só matrículas ativas podem ter o desconto alterado.');
    redirect($matricula ? '/matriculas/visualizar.php?id=' . $id : '/turmas/listar.php');
}

$tipo = in_array(input('desconto_pontualidade_tipo'), ['valor', 'percentual'], true) ? input('desconto_pontualidade_tipo') : 'nenhum';
$valor = $tipo === 'nenhum' ? 0 : decimalInput('desconto_pontualidade_valor', 0);
$dias = $tipo === 'nenhum' ? 0 : (int) ($_POST['desconto_pontualidade_dias'] ?? 0);

// Mesmas regras (e mesma alçada do perfil) do formulário de matrícula.
$erros = [];
$mensalidadeFinal = valorMensalidadeComDesconto((float) $matricula['valor_mensalidade'], (float) $matricula['desconto_percentual']);
if ($tipo !== 'nenhum') {
    if ($valor <= 0) {
        $erros[] = 'Informe o valor do desconto (ou escolha "Sem desconto").';
    } elseif ($tipo === 'percentual' && $valor > 100) {
        $erros[] = 'O desconto não pode passar de 100%.';
    } elseif ($tipo === 'valor' && $valor > $mensalidadeFinal) {
        $erros[] = 'O desconto (' . formatMoney($valor) . ') é maior que a mensalidade (' . formatMoney($mensalidadeFinal) . ').';
    }
    if ($dias < 0 || $dias > 31) {
        $erros[] = 'O prazo vai de 0 (até o vencimento) a 31 dias antes.';
    }
    $percentual = $tipo === 'percentual' ? $valor : ($mensalidadeFinal > 0 ? $valor / $mensalidadeFinal * 100 : 100);
    if (!$erros && $percentual > usuarioDescontoMax()) {
        $erros[] = 'Este desconto equivale a ' . formatPercent($percentual) . ' da mensalidade; seu perfil pode conceder até ' . formatPercent(usuarioDescontoMax()) . '.';
    }
}

if ($erros) {
    foreach ($erros as $erro) {
        flash('danger', $erro);
    }
    redirect('/matriculas/visualizar.php?id=' . $id);
}

$stmt = $pdo->prepare('UPDATE matriculas SET desconto_pontualidade_tipo = ?, desconto_pontualidade_valor = ?, desconto_pontualidade_dias = ? WHERE id = ?');
$stmt->execute([$tipo, $valor, $dias, $id]);

$novo = ['desconto_pontualidade_tipo' => $tipo, 'desconto_pontualidade_valor' => $valor, 'desconto_pontualidade_dias' => $dias];
registrarAtividade('matriculas', 'edicao', 'Desconto por pontualidade de ' . $matricula['aluno_nome'] . ': ' . textoDescontoPontualidade($novo));

flash('success', 'Desconto por pontualidade atualizado: ' . textoDescontoPontualidade($novo) . '.');
redirect('/matriculas/visualizar.php?id=' . $id);
