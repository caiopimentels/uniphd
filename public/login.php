<?php
require_once __DIR__ . '/../includes/bootstrap.php';

if (usuarioLogado()) {
    redirect('/index.php');
}

$pdo = getDB();
$config = buscarConfiguracoes($pdo);
$logoSistema = $config['logo_sistema'] ?? null;
$nomeSistema = nomeSistema();
$logoPosX = (int) ($config['logo_pos_x'] ?? 50);
$logoPosY = (int) ($config['logo_pos_y'] ?? 50);
$logoZoom = (float) ($config['logo_zoom'] ?? 1);
$logoDeslocamentoX = ($logoPosX - 50) * 0.9;
$logoDeslocamentoY = ($logoPosY - 50) * 0.9;

$erro = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = input('email');
    $senha = input('senha');

    if ($email === '' || $senha === '') {
        $erro = 'Informe e-mail e senha.';
    } elseif (limiteDeTentativasExcedido($pdo, 'login', $email, 5, 20, 15)) {
        $erro = 'Muitas tentativas de login. Aguarde alguns minutos e tente novamente.';
    } elseif (tentarLogin($pdo, $email, $senha)) {
        limparTentativasAcesso($pdo, 'login', $email);
        redirect('/index.php');
    } else {
        registrarTentativaAcesso($pdo, 'login', $email);
        registrarTentativaLoginFalha($email);
        $erro = 'E-mail ou senha inválidos.';
    }
}
?>
<!doctype html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(csrfToken()) ?>">
    <?php if ($iconeAba = urlIconeAba()): ?><link rel="icon" type="image/png" href="<?= e($iconeAba) ?>"><?php endif; ?>
    <title>Entrar - <?= e($nomeSistema) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;450;500;550;600&display=swap" rel="stylesheet">
    <link href="/assets/css/app.css" rel="stylesheet">
</head>
<body class="login-page">
<div class="login-card">
    <div class="mb-4 text-center">
        <span class="login-logo">
            <?php if (!empty($logoSistema)): ?>
                <img
                    src="<?= e($logoSistema) ?>"
                    alt="<?= e($nomeSistema) ?>"
                    class="login-logo-img"
                    style="
                        left: <?= $logoDeslocamentoX ?>%;
                        top: <?= $logoDeslocamentoY ?>%;
                        transform: scale(<?= $logoZoom ?>);
                    "
                >
            <?php else: ?>
                <span class="login-logo-letra">
                    <?= e(mb_strtoupper(mb_substr($nomeSistema, 0, 1))) ?>
                </span>
            <?php endif; ?>
        </span>
        <div class="login-marca"><?= e($nomeSistema) ?></div>
        <div class="login-sub">Entre com suas credenciais</div>
    </div>

    <?php if ($erro): ?>
        <div class="alert alert-danger py-2 text-center"><?= e($erro) ?></div>
    <?php endif; ?>

    <form method="post">
        <div class="mb-3">
            <label class="form-label">E-mail</label>
            <input type="email" name="email" class="form-control" required autofocus value="<?= e($_POST['email'] ?? '') ?>">
        </div>
        <div class="mb-2">
            <label class="form-label">Senha</label>
            <div class="login-senha-wrap">
                <input type="password" name="senha" id="campo-senha" class="form-control" required>
                <button type="button" class="login-senha-toggle" id="btn-mostrar-senha" aria-label="Mostrar senha" aria-pressed="false" tabindex="-1">
                    <i class="bi bi-eye"></i>
                </button>
            </div>
        </div>
        <div class="text-end mb-4">
            <a href="/recuperar_senha.php" class="login-esqueci">Esqueci minha senha</a>
        </div>
        <button type="submit" class="btn btn-brand w-100 py-2">Entrar</button>
    </form>
</div>
<script src="/assets/js/csrf.js"></script>
<script>
(function () {
    var campo = document.getElementById('campo-senha');
    var botao = document.getElementById('btn-mostrar-senha');
    if (!campo || !botao) return;
    botao.addEventListener('click', function () {
        var visivel = campo.type === 'text';
        campo.type = visivel ? 'password' : 'text';
        botao.setAttribute('aria-pressed', String(!visivel));
        botao.setAttribute('aria-label', visivel ? 'Mostrar senha' : 'Ocultar senha');
        botao.querySelector('i').className = visivel ? 'bi bi-eye' : 'bi bi-eye-slash';
    });
})();
</script>
</body>
</html>
