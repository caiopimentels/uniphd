<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

$pdo = getDB();
$enviado = false;
$erro = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cpf = apenasDigitos(input('cpf'));
    if (!validarCpf($cpf)) {
        $erro = 'CPF inválido.';
    } else {
        // A resposta é sempre a mesma: não revela quem tem cadastro.
        $enviado = true;
        if (!limiteDeTentativasExcedido($pdo, 'portal_senha', $cpf, 3, 15, 60)) {
            registrarTentativaAcesso($pdo, 'portal_senha', $cpf);
            $aluno = buscarAlunoPorCpf($pdo, $cpf);
            if ($aluno && $aluno['ativo'] && $aluno['email']) {
                $resultado = enviarLinkSenhaAluno($pdo, $aluno, $aluno['senha_hash'] ? 'redefinir' : 'convite');
                if (!$resultado['sucesso']) {
                    error_log('[Portal] e-mail de senha não enviado: ' . $resultado['mensagem']);
                }
            }
        }
    }
}

$tituloPagina = 'Criar ou trocar senha';
require __DIR__ . '/../../includes/portal_header.php';
?>
<div class="portal-acesso">
    <h1 class="portal-titulo text-center">Criar ou trocar senha</h1>
    <p class="portal-sub text-center">Primeiro acesso ou esqueceu a senha? Mandamos um link para o e-mail do seu cadastro.</p>
    <div class="portal-card">
        <?php if ($enviado): ?>
            <div class="text-center">
                <i class="bi bi-envelope-check fs-1 text-success"></i>
                <p class="mt-2">Se o CPF tiver cadastro com e-mail, o link chega em instantes. Confira também a caixa de spam.</p>
                <p class="small text-muted">Não chegou ou mudou de e-mail? Fale com a secretaria.</p>
                <a href="/portal/entrar.php" class="btn btn-outline-brand">Voltar para o login</a>
            </div>
        <?php else: ?>
            <?php if ($erro): ?><div class="alert alert-danger py-2"><?= e($erro) ?></div><?php endif; ?>
            <form method="post">
                <label class="form-label">CPF</label>
                <input type="text" name="cpf" class="form-control form-control-lg mb-3" inputmode="numeric" data-mascara="cpf" required autofocus>
                <button type="submit" class="btn btn-brand btn-lg w-100">Enviar link</button>
            </form>
        <?php endif; ?>
    </div>
</div>
<?php require __DIR__ . '/../../includes/portal_footer.php'; ?>
