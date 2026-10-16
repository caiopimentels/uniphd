<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirAluno();

// Primeiro acesso com a senha provisória que a secretaria criou: o aluno
// escolhe a dele antes de usar o portal (exigirAluno() traz para cá).
$pdo = getDB();
if (empty($_SESSION['portal_trocar_senha'])) {
    redirect('/portal/index.php');
}
$erro = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $senha = (string) ($_POST['senha'] ?? '');
    $erro = validarSenhaNovaAluno($senha, (string) ($_POST['senha_confirmacao'] ?? ''));
    $stmt = $pdo->prepare('SELECT senha_hash FROM alunos WHERE id = ?');
    $stmt->execute([alunoAtualId()]);
    if (!$erro && password_verify($senha, (string) $stmt->fetchColumn())) {
        $erro = 'Escolha uma senha diferente da provisória.';
    }
    if (!$erro) {
        salvarSenhaAluno($pdo, alunoAtualId(), $senha);
        registrarAtividadeUsuario(null, 'Portal: ' . alunoAtualNome(), 'alunos', 'edicao', 'Aluno trocou a senha provisória do portal');
        flash('success', 'Senha trocada! Agora é só usar o portal.');
        redirect('/portal/index.php');
    }
}

$tituloPagina = 'Crie sua senha';
require __DIR__ . '/../../includes/portal_header.php';
?>
<div class="portal-acesso">
    <h1 class="portal-titulo text-center">Crie sua senha</h1>
    <div class="portal-card mt-3">
        <p>Olá, <strong><?= e(explode(' ', trim(alunoAtualNome()))[0]) ?></strong>! Você entrou com uma senha provisória.
            Para continuar, escolha a sua senha — você vai usar ela com o seu CPF daqui para frente.</p>
        <?php if ($erro): ?><div class="alert alert-danger py-2"><?= e($erro) ?></div><?php endif; ?>
        <form method="post">
            <label class="form-label">Nova senha <span class="text-muted small">(mínimo 8 caracteres)</span></label>
            <input type="password" name="senha" class="form-control form-control-lg mb-3" required minlength="8" autocomplete="new-password" autofocus>
            <label class="form-label">Repita a senha</label>
            <input type="password" name="senha_confirmacao" class="form-control form-control-lg mb-3" required minlength="8" autocomplete="new-password">
            <button type="submit" class="btn btn-brand btn-lg w-100">Salvar e continuar</button>
        </form>
        <div class="text-center mt-3"><a href="/portal/sair.php" class="small">Sair</a></div>
    </div>
</div>
<?php require __DIR__ . '/../../includes/portal_footer.php'; ?>
