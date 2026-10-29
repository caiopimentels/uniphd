<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirPermissao('usar_email', 'Seu perfil não usa o e-mail pelo sistema.');

// O próprio usuário liga a caixa dele: e-mail, senha, nome e assinatura
// (o mesmo bloco do cadastro de usuário).
$pdo = getDB();
$id = (int) usuarioAtualId();
$erros = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $erros = salvarCaixaEmailUsuario($pdo, $id, $_POST);
    if (!$erros) {
        registrarAtividade('usuarios', 'edicao', 'Caixa de e-mail atualizada por ' . usuarioAtualNome());
        flash('success', 'Caixa de e-mail salva.');
        redirect(caixaUsuario($pdo, $id)['configurada'] ? '/email/index.php' : '/email/minha_caixa.php');
    }
}
$stmt = $pdo->prepare('SELECT * FROM usuarios WHERE id = ?');
$stmt->execute([$id]);
$caixaUsuario = $stmt->fetch();
$caixaUsuarioId = $id;
$semServidor = !emailConfig($pdo)['imap_host'];

$pageTitle = 'Minha caixa de e-mail';
$pageBreadcrumb = 'E-mail';
$activeMenu = 'email';
$pageAction = '<a href="/email/index.php" class="btn btn-outline-secondary">Voltar</a><button type="submit" form="form-caixa" class="btn btn-brand"><i class="bi bi-check-lg"></i> Salvar</button>';
require __DIR__ . '/../../includes/layout_header.php';
?>
<?php if ($erros): ?><div class="alert alert-danger"><?= e(implode(' ', $erros)) ?></div><?php endif; ?>
<?php if ($semServidor): ?>
    <div class="alert alert-warning" style="max-width: 760px;"><i class="bi bi-exclamation-triangle"></i> O servidor de e-mail ainda não foi configurado. <?= usuarioEhAdmin() ? '<a href="/configuracoes/email.php">Configurar agora</a>.' : 'Peça ao administrador (Configurações › E-mail).' ?></div>
<?php endif; ?>
<form method="post" id="form-caixa">
    <?php require __DIR__ . '/../../includes/form_caixa_email.php'; ?>
</form>
<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
