<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();
exigirCursos();

$pdo = getDB();
$busca = trim($_GET['busca'] ?? '');

$cursos = buscarListaCursos($pdo, $busca);
$lista = estadoListagem('cursos', colunasListaCursos());
$cursos = ordenarLinhas($cursos, $lista);
$cursosPagina = paginarLinhas($cursos, $lista);
$linkImprimir = '/cursos/imprimir.php?' . http_build_query(['busca' => $busca]);

$pageTitle = 'Cursos';
$pageBreadcrumb = 'Acadêmico';
$activeMenu = 'cursos';
$pageAction = usuarioPodeCriarCurso() ? '<a href="/cursos/form.php" class="btn btn-brand"><i class="bi bi-plus-lg"></i> Incluir curso</a>' : '';
$acoesMassa = array_values(array_filter([
    usuarioPodeEditarCurso() ? ['Ativar selecionados', 'bi-toggle-on', '/cursos/massa.php', 'ativar', 'Ativar {n} curso(s)?', ''] : null,
    usuarioPodeEditarCurso() ? ['Desativar selecionados', 'bi-toggle-off', '/cursos/massa.php', 'desativar', 'Desativar {n} curso(s)? Eles deixam de aparecer para novas turmas.', ''] : null,
    usuarioPodeExcluirCurso() ? ['Excluir selecionados', 'bi-trash3', '/cursos/massa.php', 'excluir', 'Excluir {n} curso(s)? Curso com turma não é excluído. Esta ação não pode ser desfeita.', 'text-danger'] : null,
]));
$informacoes = [
    ['Cursos na lista', count($cursos), ''],
    ['Ativos', count(array_filter($cursos, fn ($c) => $c['ativo'])), ''],
    ['Inativos', count(array_filter($cursos, fn ($c) => !$c['ativo'])), ''],
    ['Turmas abertas', array_sum(array_column($cursos, 'turmas_abertas')), ''],
    ['Turmas (total)', array_sum(array_column($cursos, 'turmas_total')), ''],
];
require __DIR__ . '/../../includes/layout_header.php';
?>

<div class="filtros">
    <form method="get">
        <input type="text" name="busca" class="form-control form-control-sm" placeholder="Buscar por nome"
               value="<?= e($busca) ?>" style="width: 300px;">
        <button class="btn btn-sm btn-outline-secondary" type="submit"><i class="bi bi-search"></i> Buscar</button>
    </form>
    <?php if ($busca !== ''): ?>
        <span class="filtro-chip">Busca: <?= e($busca) ?> <a href="/cursos/listar.php">×</a></span>
    <?php endif; ?>
    <span class="ms-auto text-muted small"><?= count($cursos) ?> registro(s)</span>
</div>

<button type="button" class="btn btn-outline-secondary btn-sm btn-acoes" onclick="alternarLateral()">
    <i class="bi bi-list-ul"></i> Ações e totais
</button>

<div class="pedidos-layout">
<div class="pedidos-conteudo">

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead><tr><th style="width: 36px;"><input type="checkbox" class="form-check-input selecao-todos" title="Marcar todos"></th><?= thOrdenavel('Curso', 'curso', $lista) ?><?= thOrdenavel('Carga horária', 'carga', $lista, 'text-end') ?><?= thOrdenavel('Turmas abertas', 'abertas', $lista, 'text-center') ?><?= thOrdenavel('Turmas (total)', 'total', $lista, 'text-center') ?><?= thOrdenavel('Situação', 'situacao', $lista) ?><th class="text-end">Ações</th></tr></thead>
            <tbody>
            <?php if (!$cursos): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">Nenhum curso cadastrado.</td></tr>
            <?php endif; ?>
            <?php foreach ($cursosPagina as $c): ?>
                <tr class="cursor-pointer" onclick="window.location='/cursos/form.php?id=<?= (int) $c['id'] ?>'">
                    <td style="width: 36px;" onclick="event.stopPropagation();"><input type="checkbox" class="form-check-input selecao-item" value="<?= (int) $c['id'] ?>"></td>
                    <td><?= e($c['nome']) ?></td>
                    <td class="text-end"><?= $c['carga_horaria'] ? (int) $c['carga_horaria'] . ' h' : '-' ?></td>
                    <td class="text-center"><?= (int) $c['turmas_abertas'] ?></td>
                    <td class="text-center"><?= (int) $c['turmas_total'] ?></td>
                    <td><?= $c['ativo'] ? '<span class="badge text-bg-success">Ativo</span>' : '<span class="badge text-bg-secondary">Inativo</span>' ?></td>
                    <td class="text-end" onclick="event.stopPropagation();">
                        <?php if (usuarioPodeExcluirCurso()): ?>
                        <form action="/cursos/remover.php" method="post" class="d-inline" onsubmit="return confirm(<?= e(json_encode('Excluir ' . $c['nome'] . '? Só é possível se não houver turma ligada a ele. Esta ação não pode ser desfeita.')) ?>);">
                            <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Excluir"><i class="bi bi-trash"></i></button>
                        </form>
                        <?php endif; ?>
                        <?php if (usuarioPodeEditarCurso()): ?>
                        <form action="/cursos/excluir.php" method="post" class="d-inline" onsubmit="return confirm('Alterar situação deste curso?');">
                            <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-secondary" title="Ativar/desativar"><i class="bi <?= $c['ativo'] ? 'bi-toggle-on' : 'bi-toggle-off' ?>"></i></button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= rodapePaginacao($lista) ?>
</div>

</div><!-- /pedidos-conteudo -->
<?php require __DIR__ . '/../../includes/painel_lateral.php'; ?>
</div><!-- /pedidos-layout -->

<script src="/assets/js/selecao-massa.js"></script>
<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
