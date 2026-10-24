<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();
exigirCursos();

$pdo = getDB();
$podeVerFinanceiro = usuarioPodeVerInadimplencia();

$situacoes = buscarSituacoesTurma($pdo);
$situacoesAtivas = array_values(array_filter($situacoes, fn ($s) => $s['ativo']));
$cursos = $pdo->query('SELECT id, nome FROM cursos ORDER BY nome')->fetchAll();

// Lembra o último filtro usado entre uma visita e outra. Sem filtro salvo,
// mostra só as turmas vivas (que ainda aceitam matrícula), que é o dia a
// dia. O campo "f" marca que o formulário foi enviado: sem ele não daria
// para diferenciar "desmarquei todas as situações" de "primeira visita".
$situacoesPadrao = array_map(
    fn ($s) => (int) $s['id'],
    array_filter($situacoes, fn ($s) => in_array($s['comportamento'], COMPORTAMENTOS_TURMA_ABERTA, true))
);
$_SESSION['filtro_turmas'] = array_merge(
    ['busca' => '', 'situacoes' => array_values($situacoesPadrao), 'cursos' => []],
    $_SESSION['filtro_turmas'] ?? []
);
if (isset($_GET['f'])) {
    $_SESSION['filtro_turmas'] = [
        'busca' => trim((string) ($_GET['busca'] ?? '')),
        'situacoes' => array_values(array_filter(array_map('intval', (array) ($_GET['situacoes'] ?? [])))),
        'cursos' => array_values(array_filter(array_map('intval', (array) ($_GET['cursos'] ?? [])))),
    ];
}
$filtro = $_SESSION['filtro_turmas'];
$busca = $filtro['busca'];
$situacoesFiltro = $filtro['situacoes'];
$cursosFiltro = $filtro['cursos'];

$turmas = buscarListaTurmas($pdo, $filtro);
$lista = estadoListagem('turmas', colunasListaTurmas());
$turmas = ordenarLinhas($turmas, $lista);
$turmasPagina = paginarLinhas($turmas, $lista);
$telaColunas = 'turmas';
$definicaoColunas = definicaoColunasTela($pdo, $telaColunas);
$colunas = colunasVisiveis($pdo, $telaColunas, $definicaoColunas);

$totalAlunos = array_sum(array_column($turmas, 'alunos_ativos'));
$totalVagasLivres = array_sum(array_map(fn ($t) => max(0, (int) $t['vagas'] - (int) $t['alunos_ativos'] - (int) $t['reservas']), $turmas));
$totalVencido = array_sum(array_column($turmas, 'valor_vencido'));

// Chips do que está filtrado agora, cada um removível sozinho.
$nomesSituacao = array_column($situacoes, 'nome', 'id');
$nomesCurso = array_column($cursos, 'nome', 'id');
$linkFiltro = function (array $situacoesLink, array $cursosLink) use ($busca): string {
    return '/turmas/listar.php?' . http_build_query(['f' => 1, 'busca' => $busca, 'situacoes' => $situacoesLink, 'cursos' => $cursosLink]);
};
$chips = [];
foreach ($situacoesFiltro as $sid) {
    $chips[] = ['Situação', $nomesSituacao[$sid] ?? '?', $linkFiltro(array_values(array_diff($situacoesFiltro, [$sid])), $cursosFiltro)];
}
foreach ($cursosFiltro as $cid) {
    $chips[] = ['Curso', $nomesCurso[$cid] ?? '?', $linkFiltro($situacoesFiltro, array_values(array_diff($cursosFiltro, [$cid])))];
}
$linkLimpar = '/turmas/listar.php?f=1';

$pageTitle = 'Turmas';
$pageBreadcrumb = 'Acadêmico';
$activeMenu = 'turmas';
$pageAction = usuarioPodeCriarTurma() ? '<a href="/turmas/form.php" class="btn btn-brand"><i class="bi bi-plus-lg"></i> Nova turma</a>' : '';
require __DIR__ . '/../../includes/layout_header.php';
?>

<button type="button" class="btn btn-outline-secondary btn-sm btn-acoes" onclick="alternarLateral()">
    <i class="bi bi-list-ul"></i> Ações e totais
</button>

<div class="pedidos-layout">
<div class="pedidos-conteudo">

<form method="get" id="form-filtros">
    <input type="hidden" name="f" value="1">
    <div class="barra-filtros">
        <button type="button" class="btn-funil <?= $chips ? 'com-filtro' : '' ?>" onclick="alternarPainelFiltros()" title="Filtrar por situação e curso">
            <i class="bi bi-funnel-fill"></i>
            <?php if ($chips): ?><span class="funil-contador"><?= count($chips) ?></span><?php endif; ?>
        </button>

        <div class="campo-busca">
            <i class="bi bi-search"></i>
            <input type="text" name="busca" value="<?= e($busca) ?>" placeholder="Pesquisar por turma, curso ou professor">
        </div>
        <div class="ms-auto"><?php $colunasParte = 'botao'; require __DIR__ . '/../../includes/painel_colunas.php'; ?></div>
    </div>

    <?php if ($chips || $busca !== ''): ?>
    <div class="chips-filtros">
        <?php foreach ($chips as [$rotulo, $valor, $link]): ?>
            <span class="chip-ativo">
                <?= e($rotulo) ?>: <strong><?= e($valor) ?></strong>
                <a href="<?= e($link) ?>" title="Remover este filtro">&times;</a>
            </span>
        <?php endforeach; ?>
        <a href="<?= e($linkLimpar) ?>" class="chip-limpar">Limpar tudo</a>
    </div>
    <?php endif; ?>

    <div class="painel-filtros oculto" id="painel-filtros">
        <div class="painel-filtros-cabecalho">
            <span><i class="bi bi-funnel"></i> Filtrar <span class="small text-muted">(marque quantas quiser; nada marcado = todas)</span></span>
            <button type="button" class="btn-close btn-sm" onclick="alternarPainelFiltros()"></button>
        </div>

        <div class="painel-filtros-grade">
            <div>
                <label class="form-label">Situação</label>
                <?php foreach ($situacoes as $sit): ?>
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" name="situacoes[]" value="<?= (int) $sit['id'] ?>" id="fs-<?= (int) $sit['id'] ?>"
                               <?= in_array((int) $sit['id'], $situacoesFiltro, true) ? 'checked' : '' ?>>
                        <label class="form-check-label d-flex align-items-center gap-2" for="fs-<?= (int) $sit['id'] ?>">
                            <span class="ponto-status" style="background: <?= e($sit['cor']) ?>;"></span>
                            <?= e($sit['nome']) ?><?= $sit['ativo'] ? '' : ' <span class="text-muted small">(inativa)</span>' ?>
                        </label>
                    </div>
                <?php endforeach; ?>
            </div>

            <div>
                <label class="form-label">Curso</label>
                <?php if (!$cursos): ?><div class="text-muted small">Nenhum curso cadastrado.</div><?php endif; ?>
                <?php foreach ($cursos as $c): ?>
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" name="cursos[]" value="<?= (int) $c['id'] ?>" id="fc-<?= (int) $c['id'] ?>"
                               <?= in_array((int) $c['id'], $cursosFiltro, true) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="fc-<?= (int) $c['id'] ?>"><?= e($c['nome']) ?></label>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="painel-filtros-rodape">
            <a href="<?= e($linkLimpar) ?>" class="btn btn-sm btn-outline-secondary">Limpar</a>
            <button type="submit" class="btn btn-sm btn-brand">Aplicar</button>
        </div>
    </div>
</form>
<?php $colunasParte = 'painel'; require __DIR__ . '/../../includes/painel_colunas.php'; ?>

<div class="card">
    <div class="table-responsive" style="overflow: visible;">
        <table class="table table-hover mb-0 align-middle">
            <thead>
                <tr>
                    <th style="width: 36px;"><input type="checkbox" class="form-check-input" id="marcar-todos"></th>
                    <?= cabecalhoColunas($colunas, $lista) ?><th></th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$turmas): ?>
                <tr><td colspan="<?= count($colunas) + 2 ?>" class="text-center text-muted py-4">Nenhuma turma encontrada.</td></tr>
            <?php endif; ?>
            <?php foreach ($turmasPagina as $t): ?>
                <?php $lotada = (int) $t['alunos_ativos'] + (int) $t['reservas'] >= (int) $t['vagas']; ?>
                <tr class="cursor-pointer" onclick="abrirLinhaTurma(event, '/turmas/visualizar.php?id=<?= (int) $t['id'] ?>')">
                    <td>
                        <input type="checkbox" class="form-check-input checkbox-turma" value="<?= (int) $t['id'] ?>"
                               data-alunos="<?= (int) $t['alunos_ativos'] ?>">
                    </td>
                    <?= celulasColunas($colunas, $t) ?>
                    <td>
                        <div class="dropdown">
                            <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="dropdown">
                                <i class="bi bi-three-dots-vertical"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><a class="dropdown-item" href="/turmas/visualizar.php?id=<?= (int) $t['id'] ?>"><i class="bi bi-eye"></i> Ver turma</a></li>
                                <?php if (usuarioPodeEditarTurma()): ?><li><a class="dropdown-item" href="/turmas/form.php?id=<?= (int) $t['id'] ?>"><i class="bi bi-pencil"></i> Editar</a></li><?php endif; ?>
                                <?php if (usuarioPodeCriarTurma()): ?><li><a class="dropdown-item" href="/turmas/form.php?duplicar=<?= (int) $t['id'] ?>"><i class="bi bi-copy"></i> Duplicar</a></li><?php endif; ?>
                                <?php if (usuarioPodeGerenciarMatriculas() && in_array($t['comportamento'], COMPORTAMENTOS_TURMA_ABERTA, true) && !$lotada): ?>
                                    <li><a class="dropdown-item" href="/matriculas/form.php?turma_id=<?= (int) $t['id'] ?>"><i class="bi bi-person-plus"></i> Matricular aluno</a></li>
                                <?php endif; ?>
                                <?php
                                    // Mesma regra do servidor: concluir/reabrir pede concluir_turma.
                                    $situacoesLinha = array_filter($situacoesAtivas, fn ($sit) => ($sit['comportamento'] === 'concluida' || $t['comportamento'] === 'concluida')
                                        ? usuarioPodeConcluirTurma() : usuarioPodeEditarTurma());
                                ?>
                                <?php if ($situacoesLinha): ?>
                                <li><hr class="dropdown-divider"></li>
                                <li><h6 class="dropdown-header">Situação</h6></li>
                                <?php endif; ?>
                                <?php foreach ($situacoesLinha as $sit): ?>
                                    <li>
                                        <button type="button" class="dropdown-item d-flex align-items-center gap-2"
                                                onclick="alterarSituacao([<?= (int) $t['id'] ?>], <?= (int) $sit['id'] ?>)">
                                            <span class="ponto-status" style="background: <?= e($sit['cor']) ?>;"></span>
                                            <?= e($sit['nome']) ?>
                                            <?php if ((int) $sit['id'] === (int) $t['situacao_id']): ?><i class="bi bi-check-lg ms-auto"></i><?php endif; ?>
                                        </button>
                                    </li>
                                <?php endforeach; ?>
                                <?php if (usuarioPodeExcluirTurma()): ?>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <button type="button" class="dropdown-item text-danger" onclick="excluirTurmas([<?= (int) $t['id'] ?>])">
                                        <i class="bi bi-trash3"></i> Excluir
                                    </button>
                                </li>
                                <?php endif; ?>
                            </ul>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= rodapePaginacao($lista) ?>
</div>

</div><!-- /pedidos-conteudo -->

<aside class="pedidos-lateral">
    <div class="lateral-bloco">
        <div class="lateral-titulo">Lista</div>
        <a href="/turmas/imprimir.php" target="_blank" class="lateral-acao" data-imprimir=".checkbox-turma"><i class="bi bi-printer"></i> Imprimir lista</a>
    </div>

    <div class="lateral-bloco">
        <div class="lateral-titulo">Alterar em massa</div>
        <?php if (usuarioPodeEditarTurma() || usuarioPodeConcluirTurma()): ?>
        <button type="button" class="lateral-acao" onclick="abrirModalSituacao()">
            <i class="bi bi-bookmark-check"></i> Situação das turmas
        </button>
        <?php endif; ?>
        <?php if (usuarioPodeExcluirTurma()): ?>
        <button type="button" class="lateral-acao text-danger" onclick="excluirSelecionadas()">
            <i class="bi bi-trash3"></i> Excluir selecionadas
        </button>
        <?php endif; ?>
        <?php if (usuarioEhAdmin()): ?>
            <a href="/situacoes_turma/listar.php" class="lateral-acao">
                <i class="bi bi-gear"></i> Cadastrar situações
            </a>
        <?php endif; ?>
    </div>

    <div class="lateral-bloco">
        <div class="lateral-titulo">Informações</div>

        <div class="lateral-info">
            <span class="lateral-info-rotulo">Turmas na lista</span>
            <span class="lateral-info-valor"><?= count($turmas) ?></span>
        </div>
        <div class="lateral-info">
            <span class="lateral-info-rotulo">Alunos ativos</span>
            <span class="lateral-info-valor"><?= $totalAlunos ?></span>
        </div>
        <div class="lateral-info">
            <span class="lateral-info-rotulo">Vagas livres</span>
            <span class="lateral-info-valor"><?= $totalVagasLivres ?></span>
        </div>
        <?php if ($podeVerFinanceiro): ?>
        <div class="lateral-info">
            <span class="lateral-info-rotulo">Em atraso nestas turmas</span>
            <span class="lateral-info-valor <?= $totalVencido > 0 ? 'estoque-baixo' : '' ?>"><?= formatMoney($totalVencido) ?></span>
        </div>
        <?php endif; ?>
        <div class="lateral-info">
            <span class="lateral-info-rotulo">Selecionadas</span>
            <span class="lateral-info-valor destaque" id="info-selecionadas">0</span>
        </div>
        <div class="lateral-info">
            <span class="lateral-info-rotulo">Alunos nas selecionadas</span>
            <span class="lateral-info-valor" id="info-alunos-selecionadas">0</span>
        </div>
    </div>
</aside>
</div><!-- /pedidos-layout -->

<div class="modal fade" id="modal-situacao" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Alterar situação</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-3" id="ms-resumo"></p>
                <label class="form-label">Nova situação</label>
                <select id="ms-situacao" class="form-select">
                    <?php foreach ($situacoesAtivas as $sit): ?>
                        <option value="<?= (int) $sit['id'] ?>"><?= e($sit['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text mt-2">
                    Situações de <strong>conclusão</strong> encerram a turma: matrículas ativas viram concluídas, inscrições não pagas são canceladas e reservas apagadas. Reabrir a turma volta os concluídos para ativos.
                    Situações de <strong>cancelamento</strong> só são aplicadas em turmas sem alunos ativos — as demais são puladas e listadas no aviso.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-brand" onclick="alterarSituacao(idsSelecionados(), document.getElementById('ms-situacao').value)">Aplicar</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modal-conclusao" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-flag"></i> Concluir turma</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-3" id="conclusao-resumo"></p>
                <div id="campo-conclusao-inicio" class="d-none">
                    <label class="form-label">Data de início *</label>
                    <input type="date" id="conclusao-inicio" class="form-control mb-1" max="<?= date('Y-m-d') ?>">
                    <div class="form-text mb-3" id="conclusao-inicio-ajuda"></div>
                </div>
                <label class="form-label">Data de conclusão *</label>
                <input type="date" id="conclusao-data" class="form-control" max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>">
                <div class="form-text mb-3">É a data de término que sai no certificado.</div>
                <div class="small text-muted">As turmas saem do site e não aceitam mais alunos: as matrículas ativas viram concluídas, inscrições não pagas são canceladas e as reservas apagadas.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-brand" onclick="enviarConclusao()"><i class="bi bi-check-lg"></i> Concluir</button>
            </div>
        </div>
    </div>
</div>

<script>
const situacoesTurma = <?= json_encode(array_column($situacoesAtivas, null, 'id'), JSON_UNESCAPED_UNICODE) ?>;
// Turmas ainda sem data de início: ao concluir, a data de início é pedida
// junto (ela sai no certificado: "realizado de ... a ...").
const turmasSemInicio = <?= json_encode(array_map(fn ($t) => (string) $t['id'], array_values(array_filter($turmas, fn ($t) => empty($t['data_inicio']))))) ?>;
const nomesTurmas = <?= json_encode(array_column(array_map(fn ($t) => ['id' => (string) $t['id'], 'nome' => $t['nome']], $turmas), 'nome', 'id'), JSON_UNESCAPED_UNICODE) ?>;

function alternarLateral() {
    document.querySelector('.pedidos-lateral').classList.toggle('aberta');
}

function alternarPainelFiltros() {
    document.getElementById('painel-filtros').classList.toggle('oculto');
}

// Não usa stopPropagation na célula do checkbox/menu: o Bootstrap escuta o
// clique no document para fechar o dropdown aberto. Em vez disso, ignora a
// navegação quando o clique começou em algo interativo.
function abrirLinhaTurma(event, url) {
    if (event.target.closest('input, button, a, .dropdown')) {
        return;
    }
    window.location = url;
}

function idsSelecionados() {
    return Array.from(document.querySelectorAll('.checkbox-turma:checked')).map((cb) => cb.value);
}

function atualizarSelecao() {
    const marcadas = document.querySelectorAll('.checkbox-turma:checked');
    document.getElementById('info-selecionadas').textContent = marcadas.length;
    document.getElementById('info-alunos-selecionadas').textContent =
        Array.from(marcadas).reduce((soma, cb) => soma + parseInt(cb.dataset.alunos || 0, 10), 0);
}

document.getElementById('marcar-todos').addEventListener('change', function () {
    document.querySelectorAll('.checkbox-turma').forEach((cb) => { cb.checked = this.checked; });
    atualizarSelecao();
});
document.querySelectorAll('.checkbox-turma').forEach((cb) => cb.addEventListener('change', atualizarSelecao));
atualizarSelecao();

// Monta um form isolado e envia — a tabela não fica dentro de um <form>
// para não aninhar com os formulários de outras ações.
function enviarPost(acao, campos) {
    const form = document.createElement('form');
    form.method = 'post';
    form.action = acao;
    Object.entries(campos).forEach(([nome, valor]) => {
        (Array.isArray(valor) ? valor : [valor]).forEach((v) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = Array.isArray(valor) ? nome + '[]' : nome;
            input.value = v;
            form.appendChild(input);
        });
    });
    document.body.appendChild(form);
    form.submit();
}

function alterarSituacao(ids, situacaoId) {
    if (!ids.length) {
        alert('Selecione ao menos uma turma na lista.');
        return;
    }
    const situacao = situacoesTurma[situacaoId];
    if (situacao && situacao.comportamento === 'concluida') {
        // Concluir pede a data de conclusão (vai para o certificado).
        conclusaoPendente = { ids: ids, situacaoId: situacaoId };
        document.getElementById('conclusao-resumo').textContent = ids.length + ' turma(s) → "' + situacao.nome + '"';
        const semInicio = ids.map(String).filter((id) => turmasSemInicio.includes(id));
        document.getElementById('campo-conclusao-inicio').classList.toggle('d-none', !semInicio.length);
        document.getElementById('conclusao-inicio-ajuda').textContent = semInicio.length
            ? 'Sem data de início: ' + semInicio.map((id) => nomesTurmas[id] || id).join(', ') + '. Ela também sai no certificado.' : '';
        const massa = document.getElementById('modal-situacao');
        if (massa) bootstrap.Modal.getOrCreateInstance(massa).hide();
        bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-conclusao')).show();
        return;
    }
    enviarPost('/turmas/alterar_situacao.php', {
        ids: ids,
        situacao_id: situacaoId,
        voltar: window.location.pathname + window.location.search,
    });
}

let conclusaoPendente = null;
function enviarConclusao() {
    const data = document.getElementById('conclusao-data').value;
    if (!data) { alert('Informe a data de conclusão.'); return; }
    const precisaInicio = !document.getElementById('campo-conclusao-inicio').classList.contains('d-none');
    const inicio = document.getElementById('conclusao-inicio').value;
    if (precisaInicio && !inicio) { alert('Informe a data de início da turma.'); return; }
    if (precisaInicio && inicio > data) { alert('A data de início não pode ser depois da conclusão.'); return; }
    enviarPost('/turmas/alterar_situacao.php', {
        ids: conclusaoPendente.ids,
        situacao_id: conclusaoPendente.situacaoId,
        data_conclusao: data,
        data_inicio: precisaInicio ? inicio : '',
        voltar: window.location.pathname + window.location.search,
    });
}

function abrirModalSituacao() {
    const ids = idsSelecionados();
    if (!ids.length) {
        alert('Selecione ao menos uma turma na lista.');
        return;
    }
    document.getElementById('ms-resumo').innerHTML = '<strong>' + ids.length + '</strong> turma(s) selecionada(s) serão alteradas.';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-situacao')).show();
}

function excluirTurmas(ids) {
    if (!confirm('Excluir ' + ids.length + ' turma(s)? As matrículas e parcelas em aberto delas também serão apagadas. '
        + 'Turmas com mensalidade já paga não são excluídas. Esta ação não pode ser desfeita.')) {
        return;
    }
    enviarPost('/turmas/excluir.php', { ids: ids });
}

function excluirSelecionadas() {
    const ids = idsSelecionados();
    if (!ids.length) {
        alert('Selecione ao menos uma turma na lista.');
        return;
    }
    excluirTurmas(ids);
}
</script>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
