<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
exigirPermissaoAcao('gerenciar_categorias', 'Seu perfil não pode cadastrar categorias financeiras.');

$pdo = getDB();
$categorias = $pdo->query('SELECT * FROM categorias_financeiras ORDER BY tipo, nome')->fetchAll();

$pageTitle = 'Categorias financeiras';
$pageBreadcrumb = 'Financeiro';
$pageAction = '<a href="/financeiro/categorias/form.php" class="btn btn-brand"><i class="bi bi-plus-lg"></i> Incluir categoria</a>';
$activeMenu = 'categorias-financeiras';
require __DIR__ . '/../../../includes/layout_header.php';
?>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead><tr><th>Nome</th><th>Tipo</th><th>Situação</th></tr></thead>
            <tbody>
            <?php foreach ($categorias as $c): ?>
                <tr class="cursor-pointer" onclick="window.location='/financeiro/categorias/form.php?id=<?= (int) $c['id'] ?>'">
                    <td><?= e($c['nome']) ?></td>
                    <td><?= $c['tipo'] === 'despesa' ? '<span class="badge text-bg-danger">Despesa</span>' : '<span class="badge text-bg-success">Receita</span>' ?></td>
                    <td><?= $c['ativo'] ? '<span class="badge text-bg-success">Ativa</span>' : '<span class="badge text-bg-secondary">Inativa</span>' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/../../../includes/layout_footer.php'; ?>
