<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();
if (!usuarioEhAdmin() && !usuarioTemPermissao('desativar_usuario')) {
    flash('danger', 'Seu perfil não pode ativar/desativar usuários.');
    redirect('/sem_acesso.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/usuarios/listar.php');
}

$id = (int) ($_POST['id'] ?? 0);

if ($id === usuarioAtualId()) {
    flash('danger', 'Você não pode alterar seu próprio usuário por aqui.');
    redirect('/usuarios/listar.php');
}

$pdo = getDB();
$stmt = $pdo->prepare('SELECT perfil_id FROM usuarios WHERE id = ?');
$stmt->execute([$id]);
if (!isset(perfisGerenciaveisUsuario($pdo)[(int) $stmt->fetchColumn()])) {
    flash('danger', 'Seu perfil não pode alterar usuários deste perfil.');
    redirect('/usuarios/listar.php');
}
$stmt = $pdo->prepare('UPDATE usuarios SET ativo = NOT ativo WHERE id = ?');
$stmt->execute([$id]);

$stmt = $pdo->prepare('SELECT nome, email, ativo FROM usuarios WHERE id = ?');
$stmt->execute([$id]);
$usuario = $stmt->fetch();
if ($usuario) {
    registrarAtividade('usuarios', 'edicao', 'Usuário ' . ($usuario['ativo'] ? 'ativado' : 'desativado') . ': ' . $usuario['nome'] . ' (' . $usuario['email'] . ')');
}

flash('success', 'Situação do usuário atualizada.');
redirect('/usuarios/listar.php');
