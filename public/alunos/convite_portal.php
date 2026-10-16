<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();
exigirPermissaoAcao('editar_aluno', 'Seu perfil não pode enviar o convite do portal.');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/alunos/listar.php');
}

$pdo = getDB();
$id = (int) ($_POST['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM alunos WHERE id = ?');
$stmt->execute([$id]);
$aluno = $stmt->fetch();

if (!$aluno || !$aluno['ativo']) {
    flash('danger', 'Aluno não encontrado ou inativo.');
} elseif (!validarCpf((string) $aluno['cpf']) || !$aluno['email']) {
    flash('danger', 'Para o acesso ao portal o aluno precisa de CPF válido e e-mail no cadastro.');
} else {
    $resultado = enviarLinkSenhaAluno($pdo, $aluno, $aluno['senha_hash'] ? 'redefinir' : 'convite');
    if ($resultado['sucesso']) {
        registrarAtividade('alunos', 'edicao', 'Convite do portal enviado para ' . $aluno['nome'] . ' (' . $aluno['email'] . ')');
        flash('success', 'Link enviado para ' . $aluno['email'] . '.');
    } else {
        flash('danger', 'Não foi possível enviar o e-mail: ' . $resultado['mensagem']);
    }
}
redirect('/alunos/form.php?id=' . $id);
