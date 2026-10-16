<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();
exigirAlunos();

$pdo = getDB();
$podeVerFinanceiro = usuarioPodeVerInadimplencia();

// Lembra o último filtro usado entre uma visita e outra.
$filtroPadrao = ['busca' => '', 'situacao' => '', 'financeiro' => ''];
$_SESSION['filtro_alunos'] = array_merge($filtroPadrao, $_SESSION['filtro_alunos'] ?? []);
if (array_intersect(array_keys($filtroPadrao), array_keys($_GET))) {
    foreach ($filtroPadrao as $campo => $vazio) {
        $_SESSION['filtro_alunos'][$campo] = $_GET[$campo] ?? '';
    }
}
$filtro = $_SESSION['filtro_alunos'];

$busca = trim($filtro['busca']);
$situacao = $filtro['situacao'];
$financeiro = $podeVerFinanceiro ? $filtro['financeiro'] : '';

$where = '1=1';
$params = [];

if ($busca !== '') {
    $where .= ' AND (a.nome LIKE ? OR a.cpf LIKE ? OR a.telefone LIKE ? OR a.email LIKE ?)';
    $like = "%{$busca}%";
    $params = [$like, $like, $like, $like];
}

if ($situacao === 'ativo') {
    $where .= ' AND a.ativo = 1';
} elseif ($situacao === 'inativo') {
    $where .= ' AND a.ativo = 0';
}

$having = '';
if ($financeiro === 'atraso') {
    $having = 'HAVING valor_vencido > 0';
} elseif ($financeiro === 'em_dia') {
    $having = 'HAVING valor_vencido = 0';
}

$stmt = $pdo->prepare(
    "SELECT a.*,
            (SELECT COUNT(*) FROM matriculas m WHERE m.aluno_id = a.id AND m.status = 'ativa') AS matriculas_ativas,
            (SELECT COALESCE(SUM(cr.valor), 0) FROM contas_receber cr
              WHERE cr.aluno_id = a.id AND cr.status = 'pendente' AND cr.data_vencimento < CURDATE()) AS valor_vencido
     FROM alunos a
     WHERE {$where}
     {$having}
     ORDER BY a.nome ASC"
);
$stmt->execute($params);
$alunos = $stmt->fetchAll();

$temFiltro = $busca !== '' || $situacao !== '' || $financeiro !== '';

$pageTitle = 'Alunos';
$pageBreadcrumb = 'Acadêmico';
$activeMenu = 'alunos';
$pageAction = usuarioPodeCadastrarAluno() ? '<a href="/alunos/form.php" class="btn btn-brand"><i class="bi bi-plus-lg"></i> Incluir aluno</a>' : '';
// Painel lateral: ações em massa (conforme o perfil) e totais da lista.
$acoesMassa = array_values(array_filter([
    usuarioPodeAtivarAluno() ? ['Ativar selecionados', 'bi-toggle-on', '/alunos/massa.php', 'ativar', 'Ativar {n} aluno(s)?', ''] : null,
    usuarioPodeAtivarAluno() ? ['Desativar selecionados', 'bi-toggle-off', '/alunos/massa.php', 'desativar', 'Desativar {n} aluno(s)? Eles não conseguem entrar no portal nem ser matriculados.', ''] : null,
    usuarioPodeExcluirAluno() ? ['Excluir selecionados', 'bi-trash3', '/alunos/massa.php', 'excluir', 'Excluir {n} aluno(s)? Quem tiver matrícula ou lançamento financeiro não é excluído. Esta ação não pode ser desfeita.', 'text-danger'] : null,
]));
$emAtraso = array_filter($alunos, fn ($a) => (float) $a['valor_vencido'] > 0);
$informacoes = array_values(array_filter([
    ['Alunos na lista', count($alunos), ''],
    ['Ativos', count(array_filter($alunos, fn ($a) => $a['ativo'])), ''],
    ['Inativos', count(array_filter($alunos, fn ($a) => !$a['ativo'])), ''],
    ['Com matrícula ativa', count(array_filter($alunos, fn ($a) => (int) $a['matriculas_ativas'] > 0)), ''],
    $podeVerFinanceiro ? ['Em atraso', count($emAtraso) . ' · ' . formatMoney(array_sum(array_column($emAtraso, 'valor_vencido'))), $emAtraso ? 'estoque-baixo' : ''] : null,
]));
require __DIR__ . '/../../includes/layout_header.php';
?>

<div class="filtros">
    <form method="get" class="d-flex flex-wrap gap-2 align-items-center">
        <input type="text" name="busca" class="form-control filtro-controle" style="width:280px;"
               placeholder="Nome, CPF, telefone ou e-mail" value="<?= e($busca) ?>">

        <select name="situacao" class="form-select filtro-controle" style="width:170px" onchange="this.form.submit()">
            <option value="">Todas as situações</option>
            <option value="ativo" <?= $situacao === 'ativo' ? 'selected' : '' ?>>Ativos</option>
            <option value="inativo" <?= $situacao === 'inativo' ? 'selected' : '' ?>>Inativos</option>
        </select>

        <?php if ($podeVerFinanceiro): ?>
        <select name="financeiro" class="form-select filtro-controle" style="width:170px" onchange="this.form.submit()">
            <option value="">Todo financeiro</option>
            <option value="atraso" <?= $financeiro === 'atraso' ? 'selected' : '' ?>>Em atraso</option>
            <option value="em_dia" <?= $financeiro === 'em_dia' ? 'selected' : '' ?>>Em dia</option>
        </select>
        <?php endif; ?>

        <button type="submit" class="btn btn-sm btn-outline-secondary" title="Buscar"><i class="bi bi-search"></i></button>
        <?php if ($temFiltro): ?>
            <a href="/alunos/listar.php?busca=&situacao=&financeiro=" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x-lg"></i> Limpar</a>
        <?php endif; ?>
    </form>
    <span class="ms-auto text-muted small"><?= count($alunos) ?> registro(s)</span>
</div>

<button type="button" class="btn btn-outline-secondary btn-sm btn-acoes" onclick="alternarLateral()">
    <i class="bi bi-list-ul"></i> Ações e totais
</button>

<div class="pedidos-layout">
<div class="pedidos-conteudo">

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead>
                <tr>
                    <th style="width: 36px;"><input type="checkbox" class="form-check-input selecao-todos" title="Marcar todos"></th>
                    <th>Nome</th><th>CPF</th><th>Telefone</th><th class="text-center">Matrículas ativas</th>
                    <?php if ($podeVerFinanceiro): ?><th>Financeiro</th><?php endif; ?>
                    <th>Situação</th><th class="text-end">Ações</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$alunos): ?>
                <tr><td colspan="8" class="text-center text-muted py-4">Nenhum aluno encontrado.</td></tr>
            <?php endif; ?>
            <?php foreach ($alunos as $a): ?>
                <tr class="cursor-pointer" onclick="window.location='/alunos/form.php?id=<?= (int) $a['id'] ?>'">
                    <td style="width: 36px;" onclick="event.stopPropagation();"><input type="checkbox" class="form-check-input selecao-item" value="<?= (int) $a['id'] ?>"></td>
                    <td><?= e($a['nome']) ?></td>
                    <td><?= e($a['cpf']) ?: '-' ?></td>
                    <td><?= e($a['telefone']) ?: '-' ?></td>
                    <td class="text-center"><?= (int) $a['matriculas_ativas'] ?></td>
                    <?php if ($podeVerFinanceiro): ?>
                    <td>
                        <?php if ((float) $a['valor_vencido'] > 0): ?>
                            <span class="badge text-bg-danger">Em atraso · <?= formatMoney($a['valor_vencido']) ?></span>
                        <?php else: ?>
                            <span class="badge text-bg-success">Em dia</span>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                    <td><?= $a['ativo'] ? '<span class="badge text-bg-success">Ativo</span>' : '<span class="badge text-bg-secondary">Inativo</span>' ?></td>
                    <td class="text-end" onclick="event.stopPropagation();">
                        <?php if (usuarioPodeExcluirAluno()): ?>
                        <form action="/alunos/remover.php" method="post" class="d-inline" onsubmit="return confirm(<?= e(json_encode('Excluir o cadastro de ' . $a['nome'] . '? Só é possível se ele não tiver matrícula nem lançamento financeiro. Esta ação não pode ser desfeita.')) ?>);">
                            <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Excluir aluno"><i class="bi bi-trash"></i></button>
                        </form>
                        <?php endif; ?>
                        <?php if (usuarioPodeAtivarAluno()): ?>
                        <form action="/alunos/excluir.php" method="post" class="d-inline" onsubmit="return confirm('Alterar situação deste aluno?');">
                            <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-secondary" title="Ativar/desativar"><i class="bi <?= $a['ativo'] ? 'bi-toggle-on' : 'bi-toggle-off' ?>"></i></button>
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
