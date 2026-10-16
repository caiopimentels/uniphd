<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();
exigirFormasPagamento();

$pdo = getDB();
$formas = $pdo->query(
    'SELECT fp.*, cb.apelido AS conta_apelido FROM formas_pagamento fp
     LEFT JOIN contas_bancarias cb ON cb.id = fp.conta_bancaria_id
     ORDER BY fp.nome'
)->fetchAll();
$instrumentos = instrumentosCobranca();

$pageTitle = 'Formas de pagamento';
$pageBreadcrumb = 'Financeiro';
$pageAction = '<a href="/formas_pagamento/form.php" class="btn btn-brand"><i class="bi bi-plus-lg"></i> Incluir forma de pagamento</a>';
$activeMenu = 'formas-pagamento';
require __DIR__ . '/../../includes/layout_header.php';
?>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead><tr><th>Nome</th><th>Cobrança</th><th>Conta bancária</th><th>Situação</th><th class="text-end">Ações</th></tr></thead>
            <tbody>
            <?php foreach ($formas as $f): ?>
                <tr class="cursor-pointer" onclick="window.location='/formas_pagamento/form.php?id=<?= (int) $f['id'] ?>'">
                    <td><?= e($f['nome']) ?></td>
                    <td><?= e($instrumentos[$f['instrumento']] ?? $f['instrumento']) ?></td>
                    <td>
                        <?php if ($f['conta_apelido']): ?>
                            <?= e($f['conta_apelido']) ?>
                        <?php elseif ($f['instrumento'] !== 'manual'): ?>
                            <span class="badge text-bg-warning">Sem conta vinculada</span>
                        <?php else: ?>
                            <span class="text-muted">-</span>
                        <?php endif; ?>
                    </td>
                    <td><?= $f['ativo'] ? '<span class="badge text-bg-success">Ativo</span>' : '<span class="badge text-bg-secondary">Inativo</span>' ?></td>
                    <td class="text-end" onclick="event.stopPropagation();">
                        <form action="/formas_pagamento/excluir.php" method="post" class="d-inline" onsubmit="return confirm('Alterar situação desta forma de pagamento?');">
                            <input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="bi <?= $f['ativo'] ? 'bi-toggle-on' : 'bi-toggle-off' ?>"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
