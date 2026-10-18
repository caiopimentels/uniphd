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

// Parcelas em aberto podem ser marcadas para as ações do painel lateral.
$temPendente = (bool) array_filter($contas, fn ($c) => $c['status'] === 'pendente');

// Painel lateral: ações sobre as marcadas (conforme o perfil) e totais da lista.
$acoesMassa = array_values(array_filter([
    usuarioPodeBaixarReceber() ? ['Dar baixa nas selecionadas', 'bi-check2-all', '', '', '', '', 'abrirBaixaLote()'] : null,
    ['Gerar boleto + Pix das selecionadas', 'bi-upc-scan', '', '', '', '', "acaoLoteReceber('gerar_boletos', 'Gerar boleto + Pix no Itaú para {n} parcela(s)? As que já têm boleto ficam como estão.')"],
    usuarioPodeEditarFinanceiroAluno() ? ['Mudar forma de pagamento', 'bi-credit-card', '', '', '', '', "abrirModalLote('modal-forma-lote')"] : null,
    usuarioPodeEditarFinanceiroAluno() && usuarioPodeExcluirReceber() ? ['Reparcelar selecionadas', 'bi-calculator', '', '', '', '', 'abrirReparcelar()'] : null,
    usuarioPodeExcluirReceber() ? ['Excluir selecionadas', 'bi-trash3', '', '', '', 'text-danger', "acaoLoteReceber('excluir', 'Excluir {n} parcela(s)? As que têm boleto no Itaú recebem a baixa no banco e ficam como canceladas; as pagas pelo banco não saem.')"] : null,
]));
$hoje = date('Y-m-d');
$pendentesLista = array_filter($contas, fn ($c) => $c['status'] === 'pendente');
$vencidasLista = array_filter($pendentesLista, fn ($c) => $c['data_vencimento'] < $hoje);
$pagasLista = array_filter($contas, fn ($c) => $c['status'] === 'pago');
$informacoes = [
    ['Lançamentos na lista', count($contas), ''],
    ['Em aberto', count($pendentesLista) . ' · ' . formatMoney(array_sum(array_column($pendentesLista, 'valor'))), ''],
    ['Vencidas', count($vencidasLista) . ' · ' . formatMoney(array_sum(array_column($vencidasLista, 'valor'))), $vencidasLista ? 'estoque-baixo' : ''],
    ['Recebidas', count($pagasLista) . ' · ' . formatMoney(array_sum(array_map(fn ($c) => (float) ($c['valor_recebido'] ?? $c['valor']), $pagasLista))), ''],
];
$formasPagamento = $pdo->query("SELECT id, nome, instrumento = 'manual' AS na_secretaria FROM formas_pagamento WHERE ativo = 1 ORDER BY nome")->fetchAll();

$labelTotal = match ($status) {
    'pendente' => 'Total pendente',
    'pago' => 'Total recebido',
    'cancelado' => 'Total cancelado',
    'excluido' => 'Total excluído',
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
            <option value="" <?= $status === '' ? 'selected' : '' ?>>Todas</option>
            <option value="excluido" <?= $status === 'excluido' ? 'selected' : '' ?>>Excluídas</option>
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
</div>

<button type="button" class="btn btn-outline-secondary btn-sm btn-acoes nao-imprimir" onclick="alternarLateral()">
    <i class="bi bi-list-ul"></i> Ações e totais
</button>

<div class="pedidos-layout">
<div class="pedidos-conteudo">

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
                            <input type="checkbox" class="form-check-input lote-item" value="<?= (int) $c['id'] ?>" data-grupo="<?= $indiceGrupo ?>" data-valor="<?= e((string) $c['valor']) ?>" data-aluno="<?= (int) $c['aluno_id'] ?>">
                        <?php endif; ?>
                    </td>
                    <td><?= e($c['descricao']) ?><?php if ($c['forma_pagamento_id'] && ($nomeForma = meiosCobrancaForma($pdo, (int) $c['forma_pagamento_id'])['nome'])): ?><div class="small text-muted"><i class="bi bi-wallet2"></i> <?= e($nomeForma) ?></div><?php endif; ?></td>
                    <td><?= e($c['aluno_nome'] ?: $c['categoria_nome'] ?: '-') ?></td>
                    <td class="text-nowrap <?= $vencida ? 'estoque-baixo' : '' ?>"><?= formatDate($c['data_vencimento']) ?></td>
                    <td class="text-end text-nowrap">
                    <?= formatMoney($c['valor']) ?>
                    <?php if ($c['status'] === 'pendente' && $c['desconto_valor'] !== null): ?>
                        <div class="small <?= (float) $c['desconto_valor'] > 0 ? 'text-success' : 'text-muted' ?>"><?= (float) $c['desconto_valor'] > 0 ? '− ' . formatMoney($c['desconto_valor']) . ' até ' . formatDate($c['desconto_ate'] ?: $c['data_vencimento']) : 'sem desconto' ?></div>
                    <?php endif; ?>
                </td>
                    <td class="text-nowrap"><?= $c['status'] === 'pago' && $c['data_recebimento'] ? formatDate($c['data_recebimento']) : '-' ?></td>
                    <td class="text-end text-nowrap"><?= $c['status'] === 'pago' ? '<span class="text-success fw-semibold">' . formatMoney($c['valor_recebido'] ?? $c['valor']) . '</span>' : '-' ?></td>
                    <td><span class="badge <?= contaStatusBadgeClass($c['status']) ?>"><?= contaStatusLabel($c['status']) ?></span></td>
                    <td class="text-end text-nowrap" onclick="event.stopPropagation();">
                        <?php if ($c['status'] === 'pendente' && $c['aluno_id']): ?>
                            <?= botoesCobrancaParcela($pdo, $c) ?>
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
                                <span class="btn btn-sm acao-inativa" title="Pago por boleto/Pix pelo banco — não pode ser estornado"><i class="bi bi-bank"></i></span>
                            <?php endif; ?>
                        <?php
                            // Excluir uma mensalidade some com ela de vez (inclusive do DRE);
                            // para deixar de cobrar mantendo o histórico, o caminho é cancelar a matrícula.
                            $avisoExclusao = ($c['boleto_no_banco'] ? 'O boleto desta parcela será baixado (cancelado) no Itaú. ' : '')
                                . ($c['passou_pelo_banco'] ? 'Como ela já passou pelo banco, fica guardada no filtro "Excluídas". ' : '')
                                . ($c['matricula_id']
                                    ? 'Esta parcela foi gerada por uma matrícula. Excluir mesmo assim? Para parar de cobrar o aluno, prefira cancelar a matrícula.'
                                    : 'Excluir este lançamento?');
                        ?>
                        <?php if (usuarioPodeExcluirReceber()): ?>
                            <?php if (($c['status'] === 'pago' && $c['pago_pelo_banco']) || $c['excluido_em']): ?>
                                <span class="btn btn-sm acao-inativa" title="<?= $c['excluido_em'] ? 'Já excluída' : 'Pago pelo banco — não pode ser excluído' ?>"><i class="bi bi-trash"></i></span>
                            <?php else: ?>
                        <form action="/financeiro/contas_receber/excluir.php" method="post" class="d-inline" onsubmit="return confirm('<?= e($avisoExclusao) ?>');">
                            <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Excluir lançamento"><i class="bi bi-trash"></i></button>
                        </form>
                            <?php endif; ?>
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

</div><!-- /pedidos-conteudo -->
<?php require __DIR__ . '/../../../includes/painel_lateral.php'; ?>
</div><!-- /pedidos-layout -->

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

<div class="modal fade" id="modal-forma-lote" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" action="/financeiro/contas_receber/massa.php" method="post" onsubmit="return enviarModalLote(this, 'mudar_forma');">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-credit-card"></i> Forma de pagamento de <span class="lote-qtd"></span> parcela(s)</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <label class="form-label">Nova forma de pagamento</label>
                <select name="forma_pagamento_id" class="form-select">
                    <option value="">-</option>
                    <?php foreach ($formasPagamento as $f): ?><option value="<?= (int) $f['id'] ?>"><?= e($f['nome']) ?><?= $f['na_secretaria'] ? ' (na secretaria)' : '' ?></option><?php endforeach; ?>
                </select>
                <div class="small text-muted mt-2">Indo para cartão/dinheiro, o boleto/Pix que já estiver no banco é baixado (o aluno não paga duas vezes) e no portal aparece "pague na secretaria".</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-brand"><i class="bi bi-check-lg"></i> Mudar</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="modal-reparcelar" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <form class="modal-content" action="/financeiro/contas_receber/massa.php" method="post" onsubmit="return enviarModalLote(this, 'reparcelar');">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-calculator"></i> Reparcelar <span class="lote-qtd"></span> parcela(s)</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="small text-muted">As parcelas marcadas são trocadas pelas novas (as antigas são excluídas e o boleto delas, se houver, é baixado no banco). Ex.: R$ 1.000,00 em 3x no Pix.</p>
                <div class="row g-3">
                    <div class="col-6">
                        <label class="form-label">Valor total</label>
                        <input type="text" name="total" id="rp-total" class="form-control" required oninput="montarParcelasReparcelar()">
                        <div class="form-text" id="rp-soma"></div>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Dividir em</label>
                        <div class="input-group">
                            <input type="number" name="num_parcelas" id="rp-n" class="form-control" min="1" max="60" value="1" required oninput="montarParcelasReparcelar()">
                            <span class="input-group-text">x</span>
                        </div>
                    </div>
                    <div class="col-6">
                        <label class="form-label">1º vencimento</label>
                        <input type="date" name="primeiro_vencimento" id="rp-venc" class="form-control" required oninput="montarParcelasReparcelar()">
                    </div>
                    <div class="col-6">
                        <label class="form-label">Forma de pagamento</label>
                        <select name="forma_pagamento_id" class="form-select">
                            <option value="">-</option>
                            <?php foreach ($formasPagamento as $f): ?><option value="<?= (int) $f['id'] ?>"><?= e($f['nome']) ?><?= $f['na_secretaria'] ? ' (na secretaria)' : '' ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Descrição</label>
                        <input type="text" name="descricao" id="rp-descricao" class="form-control" maxlength="200" value="Renegociação">
                        <div class="form-text">Vira "Renegociação 1/3", "Renegociação 2/3"...</div>
                    </div>
                </div>
                <div class="fw-semibold mt-3 mb-1">Como fica o parcelamento</div>
                <div class="form-text mt-0 mb-2">Dá para mudar a data e o valor de cada parcela; a soma precisa fechar exatamente no valor total.</div>
                <table class="table table-sm align-middle mb-1">
                    <thead><tr><th style="width: 70px;">Parcela</th><th>Vencimento</th><th class="text-end">Valor</th></tr></thead>
                    <tbody id="rp-linhas"></tbody>
                    <tfoot><tr><th colspan="2" class="text-end">Soma das parcelas</th><th class="text-end" id="rp-soma-parcelas"></th></tr></tfoot>
                </table>
                <div class="small" id="rp-previa"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-brand" id="rp-confirmar"><i class="bi bi-check-lg"></i> Reparcelar</button>
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
    const info = document.getElementById('info-selecionadas');
    if (info) info.textContent = marcados.length + (marcados.length ? ' · ' + moedaLote(marcados.reduce((s, c) => s + parseFloat(c.dataset.valor), 0)) : '');
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
    if (!marcados.length) { alert('Marque ao menos uma parcela em aberto na lista.'); return; }
    document.getElementById('lote-titulo').textContent = marcados.length;
    document.getElementById('lote-ids').innerHTML = marcados.map((c) => '<input type="hidden" name="ids[]" value="' + c.value + '">').join('');
    bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-baixa-lote')).show();
}
// Mudar forma / reparcelar: modal com os campos; as marcadas vão junto.
function abrirModalLote(idModal) {
    const marcados = itensLote.filter((c) => c.checked);
    if (!marcados.length) { alert('Marque ao menos uma parcela em aberto na lista.'); return false; }
    document.querySelectorAll('#' + idModal + ' .lote-qtd').forEach((el) => { el.textContent = marcados.length; });
    bootstrap.Modal.getOrCreateInstance(document.getElementById(idModal)).show();
    return true;
}
function enviarModalLote(form, acao) {
    form.querySelectorAll('.lote-oculto').forEach((el) => el.remove());
    const marcados = itensLote.filter((c) => c.checked);
    [['acao', acao], ['voltar', window.location.pathname + window.location.search], ...marcados.map((c) => ['ids[]', c.value])].forEach(([nome, valor]) => {
        const input = document.createElement('input');
        input.type = 'hidden'; input.name = nome; input.value = valor; input.className = 'lote-oculto';
        form.appendChild(input);
    });
    return acao !== 'reparcelar' || confirm('Trocar as ' + marcados.length + ' parcela(s) marcadas pelas novas? As antigas serão excluídas.');
}
function abrirReparcelar() {
    const marcados = itensLote.filter((c) => c.checked);
    if (marcados.length && new Set(marcados.map((c) => c.dataset.aluno)).size > 1) { alert('Para reparcelar, marque parcelas de um aluno só.'); return; }
    if (!abrirModalLote('modal-reparcelar')) return;
    const soma = Math.round(marcados.reduce((s, c) => s + parseFloat(c.dataset.valor), 0) * 100) / 100;
    document.getElementById('rp-total').value = soma.toFixed(2).replace('.', ',');
    document.getElementById('rp-total').dataset.soma = soma;
    document.getElementById('rp-soma').textContent = 'Soma das marcadas: ' + moedaLote(soma);
    const hoje = new Date();
    document.getElementById('rp-venc').value = hoje.getFullYear() + '-' + String(hoje.getMonth() + 1).padStart(2, '0') + '-' + String(hoje.getDate()).padStart(2, '0');
    montarParcelasReparcelar();
}
// Parcelamento: monta as linhas (data e valor, mês a mês, última com a
// diferença de centavos); cada uma pode ser mudada, mas a soma tem que
// fechar no total — senão o botão fica bloqueado.
const centavosRp = (t) => Math.round((parseFloat(String(t || '0').replace(/\./g, '').replace(',', '.')) || 0) * 100);
function somarMesesRp(iso, meses) {
    const [a, m, d] = iso.split('-').map(Number);
    const alvo = new Date(a, m - 1 + meses, 1);
    const ultimo = new Date(alvo.getFullYear(), alvo.getMonth() + 1, 0).getDate();
    alvo.setDate(Math.min(d, ultimo));
    return alvo.getFullYear() + '-' + String(alvo.getMonth() + 1).padStart(2, '0') + '-' + String(alvo.getDate()).padStart(2, '0');
}
function montarParcelasReparcelar() {
    const total = centavosRp(document.getElementById('rp-total').value);
    const n = Math.min(60, Math.max(1, parseInt(document.getElementById('rp-n').value, 10) || 1));
    const primeiro = document.getElementById('rp-venc').value;
    const parte = Math.floor(total / n);
    let html = '';
    for (let i = 0; i < n; i++) {
        const valor = i === n - 1 ? total - parte * (n - 1) : parte;
        html += '<tr><td>' + (i + 1) + '/' + n + '</td>'
            + '<td><input type="date" name="parcela_venc[]" class="form-control form-control-sm" required value="' + (primeiro ? somarMesesRp(primeiro, i) : '') + '"></td>'
            + '<td><input type="text" name="parcela_valor[]" class="form-control form-control-sm text-end rp-valor" required value="' + (valor / 100).toFixed(2).replace('.', ',') + '" oninput="conferirReparcelar()"></td></tr>';
    }
    document.getElementById('rp-linhas').innerHTML = html;
    conferirReparcelar();
}
function conferirReparcelar() {
    const total = centavosRp(document.getElementById('rp-total').value);
    const soma = Math.round((parseFloat(document.getElementById('rp-total').dataset.soma) || 0) * 100);
    const valores = [...document.querySelectorAll('.rp-valor')].map((c) => centavosRp(c.value));
    const somaParcelas = valores.reduce((s, v) => s + v, 0);
    const diferenca = total - somaParcelas;
    const celula = document.getElementById('rp-soma-parcelas');
    celula.textContent = moedaLote(somaParcelas / 100);
    celula.className = 'text-end ' + (diferenca === 0 ? 'text-success' : 'text-danger');
    const problemas = [];
    if (total <= 0) problemas.push('Informe o valor total.');
    if (total > soma) problemas.push('O total não pode passar da soma das parcelas marcadas (' + moedaLote(soma / 100) + ').');
    if (valores.some((v) => v <= 0)) problemas.push('Toda parcela precisa ter valor.');
    if (diferenca > 0) problemas.push('Faltam ' + moedaLote(diferenca / 100) + ' para fechar o total de ' + moedaLote(total / 100) + '.');
    if (diferenca < 0) problemas.push('As parcelas passam ' + moedaLote(-diferenca / 100) + ' do total de ' + moedaLote(total / 100) + '.');
    let texto = problemas.length ? '<span class="text-danger">' + problemas.join(' ') + '</span>' : '<span class="text-success"><i class="bi bi-check-circle"></i> Fecha certinho: ' + moedaLote(total / 100) + '.</span>';
    if (!problemas.length && total < soma) texto += ' Desconto de <strong>' + moedaLote((soma - total) / 100) + '</strong> sobre a soma das marcadas.';
    document.getElementById('rp-previa').innerHTML = texto;
    document.getElementById('rp-confirmar').disabled = problemas.length > 0;
}
// Ações do painel lateral que vão direto ao servidor (gerar boletos, excluir).
function acaoLoteReceber(acao, pergunta) {
    const marcados = itensLote.filter((c) => c.checked);
    if (!marcados.length) { alert('Marque ao menos uma parcela em aberto na lista.'); return; }
    if (!confirm(pergunta.replace('{n}', marcados.length))) return;
    const form = document.createElement('form');
    form.method = 'post';
    form.action = '/financeiro/contas_receber/massa.php';
    [['acao', acao], ['voltar', window.location.pathname + window.location.search], ...marcados.map((c) => ['ids[]', c.value])].forEach(([nome, valor]) => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = nome;
        input.value = valor;
        form.appendChild(input);
    });
    document.body.appendChild(form);
    form.submit();
}
</script>
<script src="/assets/js/selecao-massa.js"></script>

<?php require __DIR__ . '/../../../includes/modal_pix.php'; ?>
<?php require __DIR__ . '/../../../includes/modal_historico_parcela.php'; ?>
<?php $voltarEdicaoParcela = 'lista'; require __DIR__ . '/../../../includes/modal_editar_parcela.php'; ?>
<?php require __DIR__ . '/../../../includes/layout_footer.php'; ?>
