<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();

// Testa uma caixa (entrada e saída) sem salvar: com o e-mail/senha
// digitados ou, sem senha, com a senha guardada. Sem usuario_id = a própria
// caixa; de outro usuário, só quem pode editar usuários.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responderJson(['sucesso' => false]);
}
$pdo = getDB();
$usuarioId = (int) ($_POST['usuario_id'] ?? 0) ?: (int) usuarioAtualId();
if ($usuarioId !== (int) usuarioAtualId() && !usuarioEhAdmin() && !usuarioTemPermissao('editar_usuario')) {
    responderJson(['sucesso' => false, 'mensagem' => 'Sem permissão para testar a caixa de outro usuário.']);
}
if (limiteDeTentativasExcedido($pdo, 'teste_email', (string) usuarioAtualId(), 10, 10, 10)) {
    responderJson(['sucesso' => false, 'mensagem' => 'Muitos testes seguidos. Espere alguns minutos.']);
}
registrarTentativaAcesso($pdo, 'teste_email', (string) usuarioAtualId());
$caixa = caixaUsuario($pdo, $usuarioId);
$email = strtolower(trim((string) ($_POST['email'] ?? ''))) ?: $caixa['email'];
$senha = (string) ($_POST['senha'] ?? '') !== '' ? (string) $_POST['senha'] : (string) $caixa['senha'];
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $senha === '') {
    responderJson(['sucesso' => false, 'mensagem' => 'Informe o e-mail e a senha da caixa.']);
}
if (!emailConfig($pdo)['imap_host']) {
    responderJson(['sucesso' => false, 'mensagem' => 'O servidor de e-mail ainda não foi configurado (Configurações › E-mail).']);
}
responderJson(['sucesso' => true, 'resultado' => testarCaixaEmail($pdo, $email, $senha)]);
