<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirAdmin();

$pdo = getDB();
$perfis = $pdo->query('SELECT * FROM perfis ORDER BY nome')->fetchAll();

$stmt = $pdo->query(
    'SELECT pp.perfil_id, perm.chave
     FROM perfil_permissoes pp JOIN permissoes perm ON perm.id = pp.permissao_id'
);
$permissoesPorPerfil = [];
foreach ($stmt->fetchAll() as $row) {
    $permissoesPorPerfil[$row['perfil_id']][] = $row['chave'];
}

$pageTitle = 'Perfis de acesso';
$pageBreadcrumb = 'Administração';
$pageAction = '<a href="/perfis/form.php" class="btn btn-brand"><i class="bi bi-plus-lg"></i> Incluir perfil</a>';
$activeMenu = 'perfis';
require __DIR__ . '/../../includes/layout_header.php';
?>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead><tr><th>Nome</th><th>Administrador</th><th>Permissões</th><th class="text-end">Desconto máx.</th></tr></thead>
            <tbody>
            <?php foreach ($perfis as $p): ?>
                <?php $chaves = $permissoesPorPerfil[$p['id']] ?? []; ?>
                <tr class="cursor-pointer" onclick="window.location='/perfis/form.php?id=<?= (int) $p['id'] ?>'">
                    <td><?= e($p['nome']) ?></td>
                    <td><?= $p['administrador'] ? '<span class="badge text-bg-success">Sim</span>' : '<span class="badge text-bg-secondary">Não</span>' ?></td>
                    <td>
                        <?php if ($p['administrador']): ?>
                            <span class="text-muted">Todas (administrador)</span>
                        <?php else: ?>
                            <?php foreach ($chaves as $chave): ?>
                                <span class="badge text-bg-light border me-1"><?= e($chave) ?></span>
                            <?php endforeach; ?>
                            <?php if (!$chaves): ?><span class="text-muted">Nenhuma</span><?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td class="text-end"><?= formatPercent($p['desconto_max']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
