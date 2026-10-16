<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

$voltar = destinoSeguroPortal($_GET['voltar'] ?? $_POST['voltar'] ?? null);
if (alunoLogado()) {
    redirect($voltar);
}

$pdo = getDB();
$erro = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $resultado = tentarLoginAluno($pdo, input('cpf'), (string) ($_POST['senha'] ?? ''));
    if ($resultado['ok']) {
        redirect($voltar);
    }
    $erro = $resultado['mensagem'];
}

$tituloPagina = 'Entrar';
require __DIR__ . '/../../includes/portal_header.php';
?>
<div class="portal-acesso">
    <h1 class="portal-titulo text-center">Área do aluno</h1>
    <p class="portal-sub text-center">Entre com seu CPF e senha.</p>
    <div class="portal-card">
        <?php if ($erro): ?><div class="alert alert-danger py-2"><?= e($erro) ?></div><?php endif; ?>
        <form method="post">
            <input type="hidden" name="voltar" value="<?= e($voltar) ?>">
            <div class="mb-3">
                <label class="form-label">CPF</label>
                <input type="text" name="cpf" class="form-control form-control-lg" inputmode="numeric" data-mascara="cpf" required autofocus value="<?= e($_POST['cpf'] ?? '') ?>">
            </div>
            <div class="mb-2">
                <label class="form-label">Senha</label>
                <input type="password" name="senha" class="form-control form-control-lg" required autocomplete="current-password">
            </div>
            <div class="text-end mb-3"><a href="/portal/esqueci_senha.php" class="small">Primeiro acesso / esqueci a senha</a></div>
            <button type="submit" class="btn btn-brand btn-lg w-100">Entrar</button>
        </form>
    </div>
    <?php if ($configPortal['portal_cadastro_aberto']): ?>
        <p class="text-center mt-3">Ainda não é aluno? <a href="/portal/cadastro.php<?= $voltar !== '/portal/index.php' ? '?voltar=' . urlencode($voltar) : '' ?>">Crie sua conta</a></p>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../../includes/portal_footer.php'; ?>
