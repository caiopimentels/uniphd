<?php
require_once __DIR__ . '/../includes/bootstrap.php';
exigirLogin();

$pdo = getDB();
$stmt = $pdo->prepare('SELECT * FROM usuarios WHERE id = ?');
$stmt->execute([usuarioAtualId()]);
$usuario = $stmt->fetch();

$erros = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome = input('nome');
    $senhaAtual = input('senha_atual');
    $novaSenha = input('nova_senha');
    $confirmarSenha = input('confirmar_senha');

    if ($nome === '') {
        $erros[] = 'O nome é obrigatório.';
    }

    if ($novaSenha !== '') {
        if ($senhaAtual === '' || !password_verify($senhaAtual, $usuario['senha'])) {
            $erros[] = 'Senha atual incorreta.';
        }
        if ($novaSenha !== $confirmarSenha) {
            $erros[] = 'A confirmação de senha não confere.';
        }
        if (strlen($novaSenha) < 4) {
            $erros[] = 'A nova senha deve ter ao menos 4 caracteres.';
        }
    }

    if (!$erros) {
        if ($novaSenha !== '') {
            $stmt = $pdo->prepare('UPDATE usuarios SET nome = ?, senha = ? WHERE id = ?');
            $stmt->execute([$nome, password_hash($novaSenha, PASSWORD_DEFAULT), usuarioAtualId()]);
        } else {
            $stmt = $pdo->prepare('UPDATE usuarios SET nome = ? WHERE id = ?');
            $stmt->execute([$nome, usuarioAtualId()]);
        }

        $_SESSION['user_nome'] = $nome;
        flash('success', 'Dados atualizados com sucesso.');
        redirect('/minha_conta.php');
    }

    $usuario['nome'] = $nome;
}

$pageTitle = 'Minha conta';
$pageBreadcrumb = 'Minha conta';
$pageAction = '<button type="submit" form="form-principal" class="btn btn-brand"><i class="bi bi-check-lg"></i> Salvar</button>';
$activeMenu = '';
require __DIR__ . '/../includes/layout_header.php';
?>

<?php if ($erros): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($erros as $erro): ?><li><?= e($erro) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="secao" style="max-width: 480px;">
    <div class="secao-titulo"><i class="bi bi-person-circle"></i> Dados de acesso</div>
        <form method="post" id="form-principal">
            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label">Nome *</label>
                    <input type="text" name="nome" class="form-control" required value="<?= e($usuario['nome']) ?>">
                </div>
                <div class="col-12">
                    <label class="form-label">E-mail</label>
                    <input type="email" class="form-control" value="<?= e($usuario['email']) ?>" disabled>
                    <div class="form-text">O e-mail não pode ser alterado por aqui.</div>
                </div>
                <div class="col-12"><hr></div>
                <div class="col-12">
                    <label class="form-label">Senha atual</label>
                    <input type="password" name="senha_atual" class="form-control" autocomplete="current-password">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Nova senha</label>
                    <input type="password" name="nova_senha" class="form-control" autocomplete="new-password">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Confirmar nova senha</label>
                    <input type="password" name="confirmar_senha" class="form-control" autocomplete="new-password">
                </div>
                <div class="col-12 text-muted small">Deixe os campos de senha em branco para manter a senha atual.</div>
            </div>
        </form>
</div>

<?php require __DIR__ . '/../includes/layout_footer.php'; ?>
