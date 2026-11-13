<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();

// Todas as matrículas (menu Acadêmico > Matrículas), sem precisar passar
// pela turma. Mesmo acesso da tela da matrícula: quem vê alunos ou cursos.
if (!usuarioPodeVerCursos() && !usuarioPodeVerAlunos()) {
    flash('danger', 'Seu perfil não tem acesso a matrículas.');
    redirect('/sem_acesso.php');
}

$pdo = getDB();
$podeVerFinanceiro = usuarioPodeVerInadimplencia();

// Lembra o último filtro usado entre uma visita e outra. Começa nas
// matrículas em andamento (ativas, aguardando pagamento e trancadas).
$filtroPadrao = ['busca' => '', 'status' => ['ativa', 'pendente', 'trancada'], 'cursos' => [], 'turmas' => [], 'postit' => '', 'financeiro' => ''];
$_SESSION['filtro_matriculas'] = array_merge($filtroPadrao, $_SESSION['filtro_matriculas'] ?? []);
esquecerBuscaAoChegar($_SESSION['filtro_matriculas']);
if (array_intersect(array_keys($filtroPadrao), array_keys($_GET)) || isset($_GET['filtrar'])) {
    foreach ($filtroPadrao as $campo => $padrao) {
        $_SESSION['filtro_matriculas'][$campo] = is_array($padrao) ? array_values((array) ($_GET[$campo] ?? [])) : (string) ($_GET[$campo] ?? '');
    }
}
$filtro = $_SESSION['filtro_matriculas'];
if (!$podeVerFinanceiro) {
    $filtro['financeiro'] = '';
}

// Cursos e turmas para os filtros. Com curso marcado, só aparecem as turmas
// dele — e turma marcada de outro curso sai do filtro.
$cursos = $pdo->query('SELECT id, nome FROM cursos ORDER BY ativo DESC, nome')->fetchAll();
$opcoesCursos = array_column($cursos, 'nome', 'id');
$cursosMarcados = array_values(array_filter((array) $filtro['cursos'], fn ($c) => isset($opcoesCursos[(int) $c])));
$turmas = $pdo->query('SELECT t.id, t.nome, t.curso_id, c.nome AS curso_nome FROM turmas t JOIN cursos c ON c.id = t.curso_id'
    . ($cursosMarcados ? ' WHERE t.curso_id IN (' . implode(',', array_map('intval', $cursosMarcados)) . ')' : '')
    . ' ORDER BY c.nome, t.data_inicio DESC, t.nome')->fetchAll();
$opcoesTurmas = [];
foreach ($turmas as $t) {
    $opcoesTurmas[$t['id']] = count($cursosMarcados) === 1 ? $t['nome'] : $t['curso_nome'] . ' · ' . $t['nome'];
}
$turmasMarcadas = array_values(array_filter((array) $filtro['turmas'], fn ($t) => isset($opcoesTurmas[(int) $t])));
$filtro['cursos'] = $cursosMarcados;
$filtro['turmas'] = $turmasMarcadas;
$_SESSION['filtro_matriculas']['cursos'] = $cursosMarcados;
$_SESSION['filtro_matriculas']['turmas'] = $turmasMarcadas;

$matriculas = buscarListaMatriculas($pdo, $filtro, $podeVerFinanceiro);
$lista = estadoListagem('matriculas', colunasListaMatriculas());
$matriculas = ordenarLinhas($matriculas, $lista);
$matriculasPagina = paginarLinhas($matriculas, $lista);
$telaColunas = 'matriculas';
$definicaoColunas = definicaoColunasTela($pdo, $telaColunas);
$colunas = colunasVisiveis($pdo, $telaColunas, $definicaoColunas);

$opcoesStatus = ['ativa' => 'Ativas', 'pendente' => 'Aguardando pagamento', 'trancada' => 'Trancadas', 'concluida' => 'Concluídas', 'cancelada' => 'Canceladas'];
$statusMarcados = array_values(array_intersect((array) $filtro['status'], array_keys($opcoesStatus)));
$temFiltro = trim($filtro['busca']) !== '' || $statusMarcados !== $filtroPadrao['status'] || $cursosMarcados || $turmasMarcadas
    || $filtro['postit'] !== '' || $filtro['financeiro'] !== '';

$pageTitle = 'Matrículas';
$pageBreadcrumb = 'Acadêmico';
$activeMenu = 'matriculas';
$pageAction = usuarioPodeGerenciarMatriculas() ? '<a href="/matriculas/form.php" class="btn btn-brand"><i class="bi bi-plus-lg"></i> Nova matrícula</a>' : '';
$acoesMassa = [];
$linkImprimir = '/matriculas/imprimir.php';
$porStatus = array_count_values(array_column($matriculas, 'status'));
$emAtraso = array_filter($matriculas, fn ($m) => (float) $m['valor_vencido'] > 0);
$informacoes = array_values(array_filter([
    ['Matrículas na lista', count($matriculas), ''],
    ...array_map(fn ($s) => [$opcoesStatus[$s], $porStatus[$s] ?? 0, ''], array_keys(array_intersect_key($opcoesStatus, $porStatus))),
    ['Com post-it em aberto', count(array_filter($matriculas, fn ($m) => (int) $m['postits'] > 0)), ''],
    $podeVerFinanceiro ? ['Em atraso', count($emAtraso) . ' · ' . formatMoney(array_sum(array_column($emAtraso, 'valor_vencido'))), $emAtraso ? 'estoque-baixo' : ''] : null,
]));
require __DIR__ . '/../../includes/layout_header.php';
?>

<button type="button" class="btn btn-outline-secondary btn-sm btn-acoes" onclick="alternarLateral()">
    <i class="bi bi-list-ul"></i> Ações e totais
</button>

<div class="pedidos-layout">
<div class="pedidos-conteudo">

<div class="filtros">
    <form method="get" class="d-flex flex-wrap gap-2 align-items-center">
        <input type="hidden" name="filtrar" value="1">
        <input type="text" name="busca" class="form-control filtro-controle" style="width:250px;"
               placeholder="Aluno: nome, RA, CPF, telefone ou e-mail" value="<?= e($filtro['busca']) ?>">

        <?php require __DIR__ . '/../../includes/filtro_status.php'; ?>

        <?php
            $opcoesStatus = $opcoesCursos; $statusMarcados = $cursosMarcados;
            $filtroCampo = 'cursos'; $filtroTodos = 'Todos os cursos'; $filtroPlural = 'cursos'; $filtroLargura = 200;
            require __DIR__ . '/../../includes/filtro_status.php';
            $opcoesStatus = $opcoesTurmas; $statusMarcados = $turmasMarcadas;
            $filtroCampo = 'turmas'; $filtroTodos = 'Todas as turmas'; $filtroPlural = 'turmas'; $filtroLargura = 200;
            require __DIR__ . '/../../includes/filtro_status.php';
        ?>


        <?php if ($podeVerFinanceiro): ?>
        <select name="financeiro" class="form-select filtro-controle" style="width:160px" onchange="this.form.submit()">
            <option value="">Todo financeiro</option>
            <option value="atraso" <?= $filtro['financeiro'] === 'atraso' ? 'selected' : '' ?>>Em atraso</option>
            <option value="em_dia" <?= $filtro['financeiro'] === 'em_dia' ? 'selected' : '' ?>>Em dia</option>
        </select>
        <?php endif; ?>

        <button type="submit" class="btn btn-sm btn-outline-secondary" title="Buscar"><i class="bi bi-search"></i></button>
        <?php if ($temFiltro): ?>
            <a href="/matriculas/listar.php?filtrar=1&<?= e(http_build_query(['status' => $filtroPadrao['status']])) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x-lg"></i> Limpar</a>
        <?php endif; ?>
    </form>
    <div class="ms-auto"><?php require __DIR__ . '/../../includes/painel_colunas.php'; ?></div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead>
                <tr>
                    <th style="width: 36px;"><input type="checkbox" class="form-check-input selecao-todos" title="Marcar todos"></th>
                    <?= cabecalhoColunas($colunas, $lista) ?>
                </tr>
            </thead>
            <tbody>
            <?php if (!$matriculas): ?>
                <tr><td colspan="<?= count($colunas) + 1 ?>" class="text-center text-muted py-4">Nenhuma matrícula encontrada.</td></tr>
            <?php endif; ?>
            <?php foreach ($matriculasPagina as $m): ?>
                <tr class="cursor-pointer" onclick="window.location='/matriculas/visualizar.php?id=<?= (int) $m['id'] ?>'">
                    <td style="width: 36px;" onclick="event.stopPropagation();"><input type="checkbox" class="form-check-input selecao-item" value="<?= (int) $m['id'] ?>"></td>
                    <?= celulasColunas($colunas, $m) ?>
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
