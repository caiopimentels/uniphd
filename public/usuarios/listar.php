<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();
if (!usuarioPodeAcessarUsuarios()) {
    flash('danger', 'Seu perfil não tem acesso a Usuários.');
    redirect('/sem_acesso.php');
}

$pdo = getDB();
// Quem não é administrador só enxerga os usuários dos perfis que pode gerenciar.
$perfisGerenciaveis = perfisGerenciaveisUsuario($pdo);
$usuarios = $pdo->query('SELECT u.*, p.nome AS perfil_nome FROM usuarios u JOIN perfis p ON p.id = u.perfil_id ORDER BY u.nome')->fetchAll();
if (!usuarioEhAdmin()) {
    $usuarios = array_values(array_filter($usuarios, fn ($u) => isset($perfisGerenciaveis[(int) $u['perfil_id']]) && (int) $u['id'] !== usuarioAtualId()));
}
$podeEditarUsuario = usuarioEhAdmin() || usuarioTemPermissao('editar_usuario');

$pageTitle = 'Usuários';
$pageBreadcrumb = 'Administração';
$pageAction = (usuarioEhAdmin() || usuarioTemPermissao('cadastrar_usuario')) && $perfisGerenciaveis
    ? '<a href="/usuarios/form.php" class="btn btn-brand"><i class="bi bi-plus-lg"></i> Incluir usuário</a>' : '';
$activeMenu = 'usuarios';
require __DIR__ . '/../../includes/layout_header.php';
?>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead><tr><th>Nome</th><th>E-mail</th><th>Perfil</th><th>Situação</th><th class="text-end">Ações</th></tr></thead>
            <tbody>
            <?php foreach ($usuarios as $u): ?>
                <tr <?= $podeEditarUsuario ? 'class="cursor-pointer" onclick="window.location=\'/usuarios/form.php?id=' . (int) $u['id'] . '\'"' : '' ?>>
                    <td><?= e($u['nome']) ?></td>
                    <td><?= e($u['email']) ?></td>
                    <td><?= e($u['perfil_nome']) ?></td>
                    <td><?= $u['ativo'] ? '<span class="badge text-bg-success">Ativo</span>' : '<span class="badge text-bg-secondary">Inativo</span>' ?></td>
                    <td class="text-end" onclick="event.stopPropagation();">
                        <?php if ((int) $u['id'] !== usuarioAtualId() && (usuarioEhAdmin() || usuarioTemPermissao('desativar_usuario'))): ?>
                        <form action="/usuarios/excluir.php" method="post" class="d-inline" onsubmit="return confirm('Alterar situação deste usuário?');">
                            <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="bi <?= $u['ativo'] ? 'bi-toggle-on' : 'bi-toggle-off' ?>"></i></button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
