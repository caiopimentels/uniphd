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
$erros = errosDescontoPontualidade($pdo, $matricula, $tipo, $valor, $dias);

if ($erros) {
    foreach ($erros as $erro) {
        flash('danger', $erro);
    }
    redirect('/matriculas/visualizar.php?id=' . $id);
}

// Boletos já no banco que seguem a regra: lidos antes de gravar a nova.
$boletos = boletosSeguemPontualidade($pdo, $id);

$stmt = $pdo->prepare('UPDATE matriculas SET desconto_pontualidade_tipo = ?, desconto_pontualidade_valor = ?, desconto_pontualidade_dias = ? WHERE id = ?');
$stmt->execute([$tipo, $valor, $dias, $id]);

$novo = ['desconto_pontualidade_tipo' => $tipo, 'desconto_pontualidade_valor' => $valor, 'desconto_pontualidade_dias' => $dias];
registrarAtividade('matriculas', 'edicao', 'Desconto por pontualidade de ' . $matricula['aluno_nome'] . ': ' . textoDescontoPontualidade($novo));

// O desconto também muda nos boletos já registrados (baixa + boleto novo).
$banco = ['reemitidos' => 0, 'falhas' => [], 'aguardando' => false];
if ($boletos) {
    set_time_limit(300);
    $banco = reemitirBoletosPontualidade($pdo, $id, $boletos, $matricula, $novo, usuarioAtualId());
    if ($banco['reemitidos'] || $banco['falhas']) {
        registrarAtividade('matriculas', 'edicao', 'Boletos reemitidos pelo novo desconto de ' . $matricula['aluno_nome'] . ': ' . $banco['reemitidos']
            . ($banco['falhas'] ? ' (falhas: ' . implode('; ', $banco['falhas']) . ')' : ''));
    }
}

$mensagem = 'Desconto por pontualidade atualizado: ' . textoDescontoPontualidade($novo) . '.';
if ($banco['reemitidos']) {
    $mensagem .= ' ' . $banco['reemitidos'] . ' boleto(s) do Itaú foram baixados e emitidos de novo com o desconto novo — os boletos e Pix enviados antes ao aluno não valem mais; mande os novos.';
}
if ($banco['aguardando']) {
    $mensagem .= ' ' . AVISO_APROVACAO_ITAU;
}
if ($banco['falhas']) {
    flash('warning', $mensagem . ' Não deu certo no banco: ' . implode('; ', $banco['falhas']) . '.');
} else {
    flash($banco['aguardando'] ? 'warning' : 'success', $mensagem);
}
redirect('/matriculas/visualizar.php?id=' . $id);
