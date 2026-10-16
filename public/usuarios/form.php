<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();

$pdo = getDB();
$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
$usuario = null;
// Novo: cadastrar_usuario; existente: editar_usuario. Em ambos, só com os
// perfis que o perfil de quem está logado pode dar (perfisGerenciaveisUsuario).
$permissaoNecessaria = $id ? 'editar_usuario' : 'cadastrar_usuario';
if (!usuarioEhAdmin() && !usuarioTemPermissao($permissaoNecessaria)) {
    flash('danger', $id ? 'Seu perfil não pode editar usuários.' : 'Seu perfil não pode cadastrar usuários.');
    redirect(usuarioPodeAcessarUsuarios() ? '/usuarios/listar.php' : '/sem_acesso.php');
}
$perfisGerenciaveis = perfisGerenciaveisUsuario($pdo);
$perfis = array_map(fn ($pid, $nome) => ['id' => $pid, 'nome' => $nome], array_keys($perfisGerenciaveis), $perfisGerenciaveis);
if (!$perfis) {
    flash('danger', 'Seu perfil não tem nenhum perfil liberado para dar a usuários. Peça ao administrador.');
    redirect('/usuarios/listar.php');
}

if ($id) {
    $stmt = $pdo->prepare('SELECT * FROM usuarios WHERE id = ?');
    $stmt->execute([$id]);
    $usuario = $stmt->fetch();
    if (!$usuario || (!usuarioEhAdmin() && ((int) $usuario['id'] === usuarioAtualId() || !isset($perfisGerenciaveis[(int) $usuario['perfil_id']])))) {
        flash('danger', $usuario ? 'Seu perfil não pode alterar usuários deste perfil.' : 'Usuário não encontrado.');
        redirect('/usuarios/listar.php');
    }
}

$erros = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome = input('nome');
    $email = input('email');
    $senha = input('senha');
    $perfilId = (int) ($_POST['perfil_id'] ?? 0);
    $ativo = isset($_POST['ativo']) ? 1 : 0;

    if ($nome === '') {
        $erros[] = 'O nome é obrigatório.';
    }
    if ($email === '') {
        $erros[] = 'O e-mail é obrigatório.';
    }
    if (!$perfilId) {
        $erros[] = 'Selecione um perfil.';
    } elseif (!isset($perfisGerenciaveis[$perfilId])) {
        $erros[] = 'Seu perfil não pode dar este perfil a um usuário.';
    }
    if (!$id && $senha === '') {
        $erros[] = 'Informe uma senha para o novo usuário.';
    }

    if (!$erros) {
        $stmt = $pdo->prepare('SELECT id FROM usuarios WHERE email = ? AND id <> ?');
        $stmt->execute([$email, $id ?? 0]);
        if ($stmt->fetch()) {
            $erros[] = 'Já existe um usuário com este e-mail.';
        }
    }

    if (!$erros) {
        if ($id) {
            if ($senha !== '') {
                $stmt = $pdo->prepare('UPDATE usuarios SET nome=?, email=?, senha=?, perfil_id=?, ativo=? WHERE id=?');
                $stmt->execute([$nome, $email, password_hash($senha, PASSWORD_DEFAULT), $perfilId, $ativo, $id]);
                registrarAtividade('usuarios', 'edicao', 'Usuário editado (com troca de senha): ' . $nome . ' (' . $email . ')');
            } else {
                $stmt = $pdo->prepare('UPDATE usuarios SET nome=?, email=?, perfil_id=?, ativo=? WHERE id=?');
                $stmt->execute([$nome, $email, $perfilId, $ativo, $id]);
                registrarAtividade('usuarios', 'edicao', 'Usuário editado: ' . $nome . ' (' . $email . ')');
            }
        } else {
            $stmt = $pdo->prepare('INSERT INTO usuarios (nome, email, senha, perfil_id, ativo) VALUES (?,?,?,?,?)');
            $stmt->execute([$nome, $email, password_hash($senha, PASSWORD_DEFAULT), $perfilId, $ativo]);
            registrarAtividade('usuarios', 'criacao', 'Usuário criado: ' . $nome . ' (' . $email . ')');
        }
        flash('success', 'Usuário salvo com sucesso.');
        redirect('/usuarios/listar.php');
    }

    $usuario = ['nome' => $nome, 'email' => $email, 'perfil_id' => $perfilId, 'ativo' => $ativo];
}

$pageTitle = $id ? 'Editar usuário' : 'Novo usuário';
$pageBreadcrumb = 'Administração · Usuários';
$pageAction = '<a href="/usuarios/listar.php" class="btn btn-outline-secondary">Cancelar</a>'
    . '<button type="submit" form="form-principal" class="btn btn-brand"><i class="bi bi-check-lg"></i> Salvar</button>';
$activeMenu = 'usuarios';
require __DIR__ . '/../../includes/layout_header.php';
?>

<?php if ($erros): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($erros as $erro): ?><li><?= e($erro) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="secao" style="max-width: 560px;">
    <div class="secao-titulo"><i class="bi bi-person-badge"></i> Dados do usuário</div>
        <form method="post" id="form-principal">
            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label">Nome *</label>
                    <input type="text" name="nome" class="form-control" required value="<?= e($usuario['nome'] ?? '') ?>">
                </div>
                <div class="col-12">
                    <label class="form-label">E-mail *</label>
                    <input type="email" name="email" class="form-control" required value="<?= e($usuario['email'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Senha <?= $id ? '(deixe em branco para manter)' : '*' ?></label>
                    <input type="password" name="senha" class="form-control" autocomplete="new-password" <?= $id ? '' : 'required' ?>>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Perfil *</label>
                    <select name="perfil_id" class="form-select" required>
                        <option value="">Selecione...</option>
                        <?php foreach ($perfis as $p): ?>
                            <option value="<?= (int) $p['id'] ?>" <?= (int) ($usuario['perfil_id'] ?? 0) === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 form-check ps-4">
                    <input type="checkbox" name="ativo" class="form-check-input" id="ativo" <?= ($usuario['ativo'] ?? 1) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="ativo">Usuário ativo</label>
                </div>
            </div>
        </form>
</div>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
