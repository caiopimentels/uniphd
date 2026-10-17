<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
exigirVerFinanceiro();

$pdo = getDB();
// Repete, depois da página, a baixa no banco de boletos cancelados que falhou.
agendarBaixaBoletosCancelados();

// Lembra o último filtro usado nesta tela entre uma visita e outra — sem
// isso, todo acesso pelo menu resetava pra "Pendentes" e perdia o período
// escolhido, obrigando a escolher tudo de novo só pra ajustar a data.
$_SESSION['filtro_contas_receber'] = array_merge(['status' => 'pendente', 'data_inicio' => '', 'data_fim' => '', 'agrupar' => '', 'busca' => ''], $_SESSION['filtro_contas_receber'] ?? []);
if (isset($_GET['status']) || isset($_GET['data_inicio']) || isset($_GET['data_fim']) || isset($_GET['agrupar']) || isset($_GET['busca'])) {
    $_SESSION['filtro_contas_receber'] = [
        'status' => $_GET['status'] ?? '',
        'data_inicio' => $_GET['data_inicio'] ?? '',
        'data_fim' => $_GET['data_fim'] ?? '',
        'agrupar' => $_GET['agrupar'] ?? '',
        'busca' => trim((string) ($_GET['busca'] ?? '')),
    ];
}
$opcoesAgrupar = opcoesAgruparReceber();
$agrupar = array_key_exists($_SESSION['filtro_contas_receber']['agrupar'], $opcoesAgrupar) ? $_SESSION['filtro_contas_receber']['agrupar'] : '';
$status = $_SESSION['filtro_contas_receber']['status'];
$dataInicio = $_SESSION['filtro_contas_receber']['data_inicio'];
$dataFim = $_SESSION['filtro_contas_receber']['data_fim'];
$busca = $_SESSION['filtro_contas_receber']['busca'];

$contas = buscarContasReceber($pdo, $_SESSION['filtro_contas_receber']);
$totalExibido = array_sum(array_map('valorExibidoReceber', $contas));
$grupos = agruparContasReceber($contas, $agrupar);

// Seleção para baixa em lote só para quem pode dar baixa.
$temPendente = usuarioPodeBaixarReceber() && array_filter($contas, fn ($c) => $c['status'] === 'pendente');
$formasPagamento = $pdo->query('SELECT id, nome FROM formas_pagamento WHERE ativo = 1 ORDER BY nome')->fetchAll();

$labelTotal = match ($status) {
    'pendente' => 'Total pendente',
    'pago' => 'Total recebido',
    'cancelado' => 'Total cancelado',
    default => 'Total geral',
};

$queryImpressao = http_build_query([
    'status' => $status,
    'data_inicio' => $dataInicio,
    'data_fim' => $dataFim,
    'agrupar' => $agrupar,
    'busca' => $busca,
]);

$pageTitle = 'Contas a receber';
$pageBreadcrumb = 'Financeiro';
$activeMenu = 'contas-receber';
$pageAction = '<a href="/financeiro/contas_receber/imprimir.php?' . e($queryImpressao) . '" target="_blank" id="link-imprimir" class="btn btn-outline-secondary"><i class="bi bi-printer"></i> Imprimir</a> '
    . (usuarioPodeLancarReceber() ? '<a href="/financeiro/contas_receber/form.php" class="btn btn-brand"><i class="bi bi-plus-lg"></i> Incluir lançamento</a>' : '');
require __DIR__ . '/../../../includes/layout_header.php';
?>

<div class="filtros">
    <form method="get">
        <select name="status" class="form-select filtro-controle" style="width:170px" onchange="this.form.submit()">
            <option value="pendente" <?= $status === 'pendente' ? 'selected' : '' ?>>Pendentes</option>
            <option value="pago" <?= $status === 'pago' ? 'selected' : '' ?>>Recebidas</option>
            <option value="cancelado" <?= $status === 'cancelado' ? 'selected' : '' ?>>Canceladas</option>
            <option value="">Todas</option>
        </select>
        <div class="input-group filtro-busca">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input type="search" name="busca" class="form-control" placeholder="Aluno (nome ou CPF)" value="<?= e($busca) ?>" onsearch="this.form.submit()">
        </div>
        <?php require __DIR__ . '/../../../includes/filtro_periodo.php'; ?>
        <select name="agrupar" class="form-select filtro-controle" style="width:190px" onchange="this.form.submit()" title="Agrupar a lista">
            <?php foreach ($opcoesAgrupar as $valorOpcao => $rotulo): ?>
                <option value="<?= e($valorOpcao) ?>" <?= $agrupar === $valorOpcao ? 'selected' : '' ?>><?= e($rotulo) ?></option>
            <?php endforeach; ?>
        </select>
    </form>
    <?php if ($contas): ?>
        <span class="ms-auto filtro-chip"><?= e($labelTotal) ?>: <?= formatMoney($totalExibido) ?></span>
    <?php endif; ?>
</div>

<div class="alert alert-light border d-none align-items-center gap-3 py-2 nao-imprimir" id="barra-lote">
    <span><strong id="lote-qtd">0</strong> parcela(s) selecionada(s) · <strong id="lote-total">R$ 0,00</strong></span>
    <button type="button" class="btn btn-sm btn-brand ms-auto" onclick="abrirBaixaLote()"><i class="bi bi-check2-all"></i> Baixar selecionadas</button>
    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="limparLote()">Limpar seleção</button>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle" id="tabela-receber">
            <thead><tr><th style="width: 36px;"><?php if ($temPendente): ?><input type="checkbox" class="form-check-input" id="lote-todos" title="Selecionar todas as pendentes"><?php endif; ?></th><th>Descrição</th><th>Aluno/Categoria</th><th>Vencimento</th><th class="text-end">Valor</th><th>Pago em</th><th class="text-end">Valor pago</th><th>Status</th><th class="text-end">Ações</th></tr></thead>
            <tbody>
            <?php if (!$contas): ?>
                <tr><td colspan="9" class="text-center text-muted py-4">Nenhuma conta encontrada.</td></tr>
            <?php endif; ?>
            <?php $indiceGrupo = 0; foreach ($grupos as $grupo): $indiceGrupo++; $pendentesGrupo = array_filter($grupo['contas'], fn ($c) => $c['status'] === 'pendente'); ?>
                <?php if ($agrupar): ?>
                    <tr class="table-light cabecalho-grupo cursor-pointer" data-grupo-id="<?= e($grupo['id']) ?>" title="Clique para abrir ou fechar">
                        <th onclick="event.stopPropagation();"><?php if ($pendentesGrupo && $temPendente): ?><input type="checkbox" class="form-check-input lote-grupo" data-grupo="<?= $indiceGrupo ?>" title="Selecionar as pendentes deste grupo"><?php endif; ?></th>
                        <th colspan="3">
                            <i class="bi bi-chevron-right seta-grupo me-1"></i>
                            <?= e($grupo['nome']) ?> <span class="text-muted fw-normal small">· <?= count($grupo['contas']) ?> lançamento(s)</span>
                            <?php if ($grupo['aluno_id']): ?><a href="/alunos/form.php?id=<?= (int) $grupo['aluno_id'] ?>&tab=financeiro" class="small fw-normal ms-2" onclick="event.stopPropagation();">ver aluno</a><?php endif; ?>
                        </th>
                        <th class="text-end"><?= formatMoney($grupo['total']) ?></th>
                        <th colspan="4"></th>
                    </tr>
                <?php endif; ?>
            <?php foreach ($grupo['contas'] as $c): ?>
                <?php $vencida = $c['status'] === 'pendente' && $c['data_vencimento'] < date('Y-m-d'); ?>
                <tr <?= $agrupar ? 'data-linha-grupo="' . e($grupo['id']) . '" style="display:none"' : '' ?> class="cursor-pointer" onclick="abrirHistoricoParcela(<?= (int) $c['id'] ?>)" title="Clique para ver o histórico desta parcela">
                    <td onclick="event.stopPropagation();">
                        <?php if ($c['status'] === 'pendente' && $temPendente): ?>
                            <input type="checkbox" class="form-check-input lote-item" value="<?= (int) $c['id'] ?>" data-grupo="<?= $indiceGrupo ?>" data-valor="<?= e((string) $c['valor']) ?>">
                        <?php endif; ?>
                    </td>
                    <td><?= e($c['descricao']) ?></td>
                    <td><?= e($c['aluno_nome'] ?: $c['categoria_nome'] ?: '-') ?></td>
                    <td class="<?= $vencida ? 'estoque-baixo' : '' ?>"><?= formatDate($c['data_vencimento']) ?></td>
                    <td class="text-end">
                    <?= formatMoney($c['valor']) ?>
                    <?php if ($c['status'] === 'pendente' && $c['desconto_valor'] !== null): ?>
                        <div class="small <?= (float) $c['desconto_valor'] > 0 ? 'text-success' : 'text-muted' ?>"><?= (float) $c['desconto_valor'] > 0 ? '− ' . formatMoney($c['desconto_valor']) . ' até ' . formatDate($c['desconto_ate'] ?: $c['data_vencimento']) : 'sem desconto' ?></div>
                    <?php endif; ?>
                </td>
                    <td><?= $c['status'] === 'pago' && $c['data_recebimento'] ? formatDate($c['data_recebimento']) : '-' ?></td>
                    <td class="text-end"><?= $c['status'] === 'pago' ? '<span class="text-success fw-semibold">' . formatMoney($c['valor_recebido'] ?? $c['valor']) . '</span>' : '-' ?></td>
                    <td><span class="badge <?= contaStatusBadgeClass($c['status']) ?>"><?= contaStatusLabel($c['status']) ?></span></td>
                    <td class="text-end" onclick="event.stopPropagation();">
                        <?php if ($c['status'] === 'pendente' && $c['aluno_id']): ?>
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="abrirPix(<?= (int) $c['id'] ?>)" title="Gerar / ver Pix (QR Code e copia e cola)"><i class="bi bi-qr-code"></i></button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="abrirBoleto(<?= (int) $c['id'] ?>)" title="Gerar / abrir boleto"><i class="bi bi-upc-scan"></i></button>
                        <?php endif; ?>
                        <?php if ($c['status'] === 'pendente' && usuarioPodeEditarFinanceiroAluno()): ?>
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="editarParcela(this)" data-parcela="<?= e(json_encode(dadosEdicaoParcela($pdo, $c), JSON_UNESCAPED_UNICODE)) ?>" title="<?= $c['boleto_no_banco'] ? 'Alterar (o boleto é alterado também no banco)' : 'Alterar valor, vencimento ou desconto' ?>"><i class="bi bi-pencil"></i></button>
                        <?php endif; ?>
                        <?php if ($c['status'] === 'pendente' && usuarioPodeBaixarReceber()): ?>
                            <a href="/financeiro/contas_receber/baixar.php?id=<?= (int) $c['id'] ?>" class="btn btn-sm btn-outline-success" title="Dar baixa (receber)"><i class="bi bi-check-lg"></i></a>
                        <?php endif; ?>
                        <?php if ($c['status'] === 'pago'): ?><a href="/financeiro/contas_receber/recibo.php?id=<?= (int) $c['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary" title="Imprimir recibo"><i class="bi bi-receipt"></i></a><?php endif; ?>
                            <?php if ($c['status'] === 'pago' && !$c['pago_pelo_banco'] && usuarioPodeBaixarReceber()): ?>
                                <form action="/financeiro/contas_receber/estornar.php" method="post" class="d-inline" onsubmit="return confirm(<?= e(json_encode('Estornar a baixa de ' . $c['descricao'] . '? A parcela volta a ficar em aberto, como se não tivesse sido paga.')) ?>);">
                                    <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                                    <input type="hidden" name="voltar" value="lista">
                                    <button type="submit" class="btn btn-sm btn-outline-warning" title="Estornar baixa (lançada errado)"><i class="bi bi-arrow-counterclockwise"></i></button>
                                </form>
                            <?php elseif ($c['status'] === 'pago' && $c['pago_pelo_banco']): ?>
                                <span class="text-muted small" title="Pago por boleto/Pix pelo banco — não pode ser estornado"><i class="bi bi-bank"></i></span>
                            <?php endif; ?>
                        <?php
                            // Excluir uma mensalidade some com ela de vez (inclusive do DRE);
                            // para deixar de cobrar mantendo o histórico, o caminho é cancelar a matrícula.
                            $avisoExclusao = ($c['boleto_no_banco'] ? 'O boleto desta parcela será baixado (cancelado) no Itaú e a parcela fica como cancelada. ' : '')
                                . ($c['matricula_id']
                                    ? 'Esta parcela foi gerada por uma matrícula. Excluir mesmo assim? Para parar de cobrar o aluno, prefira cancelar a matrícula.'
                                    : 'Excluir este lançamento?');
                        ?>
                        <?php if (usuarioPodeExcluirReceber()): ?>
                        <form action="/financeiro/contas_receber/excluir.php" method="post" class="d-inline" onsubmit="return confirm('<?= e($avisoExclusao) ?>');">
                            <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Excluir lançamento"><i class="bi bi-trash"></i></button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php endforeach; ?>
            </tbody>
            <?php if ($contas): ?>
            <tfoot><tr><th colspan="4" class="text-end"><?= e($labelTotal) ?></th><th class="text-end"><?= formatMoney($totalExibido) ?></th><th colspan="4"></th></tr></tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<div class="modal fade" id="modal-baixa-lote" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" action="/financeiro/contas_receber/baixar_lote.php" method="post" id="form-baixa-lote">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-check2-all"></i> Baixar <span id="lote-titulo"></span> parcela(s)</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="lote-ids"></div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Data do recebimento</label>
                        <input type="date" name="data_recebimento" class="form-control" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Forma de recebimento</label>
                        <select name="forma_pagamento_id" class="form-select">
                            <option value="">-</option>
                            <?php foreach ($formasPagamento as $f): ?>
                                <option value="<?= (int) $f['id'] ?>"><?= e($f['nome']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" name="usar_desconto" value="1" id="lote-desconto" checked>
                            <label class="form-check-label" for="lote-desconto">Aplicar o desconto de pontualidade nas parcelas pagas dentro do prazo</label>
                        </div>
                    </div>
                </div>
                <div class="small text-muted mt-3">Cada parcela é baixada pelo seu valor (com desconto, se marcado e dentro do prazo). Para valor diferente (juros, acerto), baixe a parcela sozinha pelo <i class="bi bi-check-lg"></i>.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-brand"><i class="bi bi-check-lg"></i> Confirmar baixa</button>
            </div>
        </form>
    </div>
</div>

<script>
// Agrupado: cada grupo começa fechado e abre/fecha ao clicar no título. Os
// abertos ficam lembrados neste navegador e vão junto para a impressão.
(function () {
    const cabecalhos = [...document.querySelectorAll('.cabecalho-grupo')];
    if (!cabecalhos.length) return;
    const chave = 'receber-grupos-abertos-<?= e($agrupar) ?>';
    let abertos = [];
    try { abertos = JSON.parse(localStorage.getItem(chave) || '[]'); } catch (e) { abertos = []; }
    const linkImprimir = document.getElementById('link-imprimir');
    const baseImprimir = linkImprimir ? linkImprimir.href : '';
    function aplicar(cab) {
        const aberto = abertos.includes(cab.dataset.grupoId);
        cab.querySelector('.seta-grupo').className = 'bi ' + (aberto ? 'bi-chevron-down' : 'bi-chevron-right') + ' seta-grupo me-1';
        document.querySelectorAll('[data-linha-grupo="' + cab.dataset.grupoId + '"]').forEach((l) => { l.style.display = aberto ? '' : 'none'; });
    }
    function salvar() {
        try { localStorage.setItem(chave, JSON.stringify(abertos)); } catch (e) { /* sem armazenamento: só não lembra */ }
        if (linkImprimir) {
            const visiveis = abertos.filter((id) => cabecalhos.some((c) => c.dataset.grupoId === id));
            linkImprimir.href = baseImprimir + visiveis.map((id) => '&abertos[]=' + encodeURIComponent(id)).join('');
        }
    }
    cabecalhos.forEach((cab) => {
        aplicar(cab);
        cab.addEventListener('click', () => {
            const id = cab.dataset.grupoId;
            abertos = abertos.includes(id) ? abertos.filter((x) => x !== id) : [...abertos, id];
            aplicar(cab);
            salvar();
        });
    });
    salvar();
})();

// Baixa em lote: marca as parcelas pendentes (uma a uma, por grupo ou
// todas) e confirma data e forma de recebimento uma vez só.
const itensLote = [...document.querySelectorAll('.lote-item')];
const moedaLote = (v) => v.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
function atualizarLote() {
    const marcados = itensLote.filter((c) => c.checked);
    document.getElementById('barra-lote').classList.toggle('d-none', !marcados.length);
    document.getElementById('barra-lote').classList.toggle('d-flex', marcados.length > 0);
    document.getElementById('lote-qtd').textContent = marcados.length;
    document.getElementById('lote-total').textContent = moedaLote(marcados.reduce((s, c) => s + parseFloat(c.dataset.valor), 0));
    const todos = document.getElementById('lote-todos');
    if (todos) {
        todos.checked = marcados.length === itensLote.length && marcados.length > 0;
        todos.indeterminate = marcados.length > 0 && marcados.length < itensLote.length;
    }
    document.querySelectorAll('.lote-grupo').forEach((g) => {
        const doGrupo = itensLote.filter((c) => c.dataset.grupo === g.dataset.grupo);
        const n = doGrupo.filter((c) => c.checked).length;
        g.checked = n === doGrupo.length && n > 0;
        g.indeterminate = n > 0 && n < doGrupo.length;
    });
}
itensLote.forEach((c) => c.addEventListener('change', atualizarLote));
document.getElementById('lote-todos')?.addEventListener('change', (e) => { itensLote.forEach((c) => { c.checked = e.target.checked; }); atualizarLote(); });
document.querySelectorAll('.lote-grupo').forEach((g) => g.addEventListener('change', () => {
    itensLote.filter((c) => c.dataset.grupo === g.dataset.grupo).forEach((c) => { c.checked = g.checked; });
    atualizarLote();
}));
function limparLote() { itensLote.forEach((c) => { c.checked = false; }); atualizarLote(); }
function abrirBaixaLote() {
    const marcados = itensLote.filter((c) => c.checked);
    if (!marcados.length) return;
    document.getElementById('lote-titulo').textContent = marcados.length;
    document.getElementById('lote-ids').innerHTML = marcados.map((c) => '<input type="hidden" name="ids[]" value="' + c.value + '">').join('');
    bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-baixa-lote')).show();
}
</script>

<?php require __DIR__ . '/../../../includes/modal_pix.php'; ?>
<?php require __DIR__ . '/../../../includes/modal_historico_parcela.php'; ?>
<?php $voltarEdicaoParcela = 'lista'; require __DIR__ . '/../../../includes/modal_editar_parcela.php'; ?>
<?php require __DIR__ . '/../../../includes/layout_footer.php'; ?>
