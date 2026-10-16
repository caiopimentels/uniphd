<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();
exigirFornecedores();

$pdo = getDB();
$busca = trim($_GET['busca'] ?? '');

$where = '1=1';
$params = [];
if ($busca !== '') {
    $where .= ' AND (nome LIKE ? OR documento LIKE ?)';
    $params = ["%{$busca}%", "%{$busca}%"];
}

$stmt = $pdo->prepare("SELECT * FROM fornecedores WHERE {$where} ORDER BY nome ASC");
$stmt->execute($params);
$fornecedores = $stmt->fetchAll();

$pageTitle = 'Fornecedores';
$pageBreadcrumb = 'Financeiro';
$activeMenu = 'fornecedores';
$pageAction = '<a href="/fornecedores/form.php" class="btn btn-brand"><i class="bi bi-plus-lg"></i> Incluir fornecedor</a>';
require __DIR__ . '/../../includes/layout_header.php';
?>

<div class="filtros">
    <form method="get">
        <input type="text" name="busca" class="form-control form-control-sm" placeholder="Buscar por nome ou documento"
               value="<?= e($busca) ?>" style="width: 300px;">
        <button class="btn btn-sm btn-outline-secondary" type="submit"><i class="bi bi-search"></i> Buscar</button>
    </form>
    <?php if ($busca !== ''): ?>
        <span class="filtro-chip">Busca: <?= e($busca) ?> <a href="/fornecedores/listar.php">×</a></span>
    <?php endif; ?>
    <span class="ms-auto text-muted small"><?= count($fornecedores) ?> registro(s)</span>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead><tr><th>Nome</th><th>Documento</th><th>Telefone</th><th>E-mail</th><th>Situação</th><th class="text-end">Ações</th></tr></thead>
            <tbody>
            <?php if (!$fornecedores): ?>
                <tr><td colspan="6" class="text-center text-muted py-4">Nenhum fornecedor cadastrado.</td></tr>
            <?php endif; ?>
            <?php foreach ($fornecedores as $c): ?>
                <tr class="cursor-pointer" onclick="window.location='/fornecedores/form.php?id=<?= (int) $c['id'] ?>'">
                    <td><?= e($c['nome']) ?></td>
                    <td><?= e($c['documento']) ?: '-' ?></td>
                    <td><?= e($c['telefone']) ?: '-' ?></td>
                    <td><?= e($c['email']) ?: '-' ?></td>
                    <td><?= $c['ativo'] ? '<span class="badge text-bg-success">Ativo</span>' : '<span class="badge text-bg-secondary">Inativo</span>' ?></td>
                    <td class="text-end" onclick="event.stopPropagation();">
                        <form action="/fornecedores/excluir.php" method="post" class="d-inline" onsubmit="return confirm('Alterar situação deste fornecedor?');">
                            <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="bi <?= $c['ativo'] ? 'bi-toggle-on' : 'bi-toggle-off' ?>"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
