<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();
exigirCursos();

$pdo = getDB();
$busca = trim($_GET['busca'] ?? '');

$where = '1=1';
$params = [];
if ($busca !== '') {
    $where .= ' AND (p.nome LIKE ? OR p.cpf LIKE ? OR p.email LIKE ?)';
    $params = ["%{$busca}%", "%{$busca}%", "%{$busca}%"];
}

$stmt = $pdo->prepare(
    "SELECT p.*,
            (SELECT COUNT(*) FROM turmas t JOIN situacoes_turma s ON s.id = t.situacao_id
              WHERE t.professor_id = p.id AND s.comportamento IN ('aberta', 'em_andamento')) AS turmas_abertas
     FROM professores p
     WHERE {$where}
     ORDER BY p.ativo DESC, p.nome ASC"
);
$stmt->execute($params);
$professores = $stmt->fetchAll();

$pageTitle = 'Professores';
$pageBreadcrumb = 'Acadêmico';
$activeMenu = 'professores';
$pageAction = usuarioPodeGerenciarProfessores() ? '<a href="/professores/form.php" class="btn btn-brand"><i class="bi bi-plus-lg"></i> Incluir professor</a>' : '';
$acoesMassa = array_values(array_filter([
    usuarioPodeEditarProfessor() ? ['Ativar selecionados', 'bi-toggle-on', '/professores/massa.php', 'ativar', 'Ativar {n} professor(es)?', ''] : null,
    usuarioPodeEditarProfessor() ? ['Desativar selecionados', 'bi-toggle-off', '/professores/massa.php', 'desativar', 'Desativar {n} professor(es)?', ''] : null,
    usuarioPodeExcluirProfessor() ? ['Excluir selecionados', 'bi-trash3', '/professores/massa.php', 'excluir', 'Excluir {n} professor(es)? Quem tem turma não é excluído. Esta ação não pode ser desfeita.', 'text-danger'] : null,
]));
$informacoes = [
    ['Professores na lista', count($professores), ''],
    ['Ativos', count(array_filter($professores, fn ($p) => $p['ativo'])), ''],
    ['Inativos', count(array_filter($professores, fn ($p) => !$p['ativo'])), ''],
    ['Com turma aberta', count(array_filter($professores, fn ($p) => (int) $p['turmas_abertas'] > 0)), ''],
];
require __DIR__ . '/../../includes/layout_header.php';
?>

<div class="filtros">
    <form method="get">
        <input type="text" name="busca" class="form-control form-control-sm" placeholder="Buscar por nome, CPF ou e-mail"
               value="<?= e($busca) ?>" style="width: 300px;">
        <button class="btn btn-sm btn-outline-secondary" type="submit"><i class="bi bi-search"></i> Buscar</button>
    </form>
    <?php if ($busca !== ''): ?>
        <span class="filtro-chip">Busca: <?= e($busca) ?> <a href="/professores/listar.php">×</a></span>
    <?php endif; ?>
    <span class="ms-auto text-muted small"><?= count($professores) ?> registro(s)</span>
</div>

<button type="button" class="btn btn-outline-secondary btn-sm btn-acoes" onclick="alternarLateral()">
    <i class="bi bi-list-ul"></i> Ações e totais
</button>

<div class="pedidos-layout">
<div class="pedidos-conteudo">

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead><tr><th style="width: 36px;"><input type="checkbox" class="form-check-input selecao-todos" title="Marcar todos"></th><th>Nome</th><th>Formação</th><th>Telefone</th><th>E-mail</th><th class="text-center">Turmas abertas</th><th>Situação</th><th class="text-end">Ações</th></tr></thead>
            <tbody>
            <?php if (!$professores): ?>
                <tr><td colspan="8" class="text-center text-muted py-4">Nenhum professor cadastrado.</td></tr>
            <?php endif; ?>
            <?php foreach ($professores as $p): ?>
                <tr class="cursor-pointer" onclick="window.location='/professores/form.php?id=<?= (int) $p['id'] ?>'">
                    <td style="width: 36px;" onclick="event.stopPropagation();"><input type="checkbox" class="form-check-input selecao-item" value="<?= (int) $p['id'] ?>"></td>
                    <td><?= e($p['nome']) ?></td>
                    <td><?= e($p['formacao']) ?: '-' ?></td>
                    <td><?= e($p['telefone']) ?: '-' ?></td>
                    <td><?= e($p['email']) ?: '-' ?></td>
                    <td class="text-center"><?= (int) $p['turmas_abertas'] ?></td>
                    <td><?= $p['ativo'] ? '<span class="badge text-bg-success">Ativo</span>' : '<span class="badge text-bg-secondary">Inativo</span>' ?></td>
                    <td class="text-end" onclick="event.stopPropagation();">
                        <?php if (usuarioPodeExcluirProfessor()): ?>
                        <form action="/professores/remover.php" method="post" class="d-inline" onsubmit="return confirm(<?= e(json_encode('Excluir ' . $p['nome'] . '? Só é possível se não houver turma ligada a ele. Esta ação não pode ser desfeita.')) ?>);">
                            <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Excluir"><i class="bi bi-trash"></i></button>
                        </form>
                        <?php endif; ?>
                        <?php if (usuarioPodeEditarProfessor()): ?>
                        <form action="/professores/excluir.php" method="post" class="d-inline" onsubmit="return confirm('Alterar situação deste professor?');">
                            <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-secondary" title="Ativar/desativar"><i class="bi <?= $p['ativo'] ? 'bi-toggle-on' : 'bi-toggle-off' ?>"></i></button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

</div><!-- /pedidos-conteudo -->
<?php require __DIR__ . '/../../includes/painel_lateral.php'; ?>
</div><!-- /pedidos-layout -->

<script src="/assets/js/selecao-massa.js"></script>
<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
