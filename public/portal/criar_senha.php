<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

$pdo = getDB();
$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
$registro = buscarTokenSenhaAluno($pdo, $token);
$erro = null;

if ($registro && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $senha = (string) ($_POST['senha'] ?? '');
    $erro = validarSenhaNovaAluno($senha, (string) ($_POST['senha_confirmacao'] ?? ''));
    if (!$erro) {
        $pdo->beginTransaction();
        salvarSenhaAluno($pdo, (int) $registro['id'], $senha);
        $pdo->prepare('UPDATE aluno_tokens_senha SET usado_em = NOW() WHERE aluno_id = ? AND usado_em IS NULL')->execute([$registro['id']]);
        $pdo->commit();
        registrarAtividadeUsuario(null, 'Portal: ' . $registro['nome'], 'alunos', 'edicao', 'Aluno ' . ($registro['tipo'] === 'convite' ? 'criou' : 'trocou') . ' a senha do portal');
        entrarComoAluno($pdo, $registro);
        flash('success', 'Senha salva! Você já está dentro do portal.');
        redirect('/portal/index.php');
    }
}

$tituloPagina = 'Criar senha';
require __DIR__ . '/../../includes/portal_header.php';
?>
<div class="portal-acesso">
    <h1 class="portal-titulo text-center"><?= $registro && $registro['tipo'] === 'redefinir' ? 'Nova senha' : 'Crie sua senha' ?></h1>
    <div class="portal-card mt-3">
        <?php if (!$registro): ?>
            <div class="text-center">
                <i class="bi bi-link-45deg fs-1 text-danger"></i>
                <p class="mt-2">Este link expirou ou já foi usado.</p>
                <a href="/portal/esqueci_senha.php" class="btn btn-brand">Pedir um novo link</a>
            </div>
        <?php else: ?>
            <p>Olá, <strong><?= e(explode(' ', trim($registro['nome']))[0]) ?></strong>! Para entrar, você vai usar seu CPF e esta senha.</p>
            <?php if ($erro): ?><div class="alert alert-danger py-2"><?= e($erro) ?></div><?php endif; ?>
            <form method="post">
                <input type="hidden" name="token" value="<?= e($token) ?>">
                <label class="form-label">Senha <span class="text-muted small">(mínimo 8 caracteres)</span></label>
                <input type="password" name="senha" class="form-control form-control-lg mb-3" required minlength="8" autocomplete="new-password" autofocus>
                <label class="form-label">Repita a senha</label>
                <input type="password" name="senha_confirmacao" class="form-control form-control-lg mb-3" required minlength="8" autocomplete="new-password">
                <button type="submit" class="btn btn-brand btn-lg w-100">Salvar senha e entrar</button>
            </form>
        <?php endif; ?>
    </div>
</div>
<?php require __DIR__ . '/../../includes/portal_footer.php'; ?>
