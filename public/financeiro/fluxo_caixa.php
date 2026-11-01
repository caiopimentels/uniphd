<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirDRE();

$pdo = getDB();

// Saldo informado (só administrador): o caixa parte deste valor no fim do
// dia escolhido e ignora o que veio antes. Data vazia = volta a contar tudo.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && usuarioEhAdmin()) {
    $antes = buscarConfiguracoes($pdo);
    if (($_POST['acao'] ?? '') === 'remover_saldo') {
        $pdo->exec('UPDATE configuracoes SET caixa_saldo_data = NULL, caixa_saldo_valor = NULL WHERE id = 1');
        registrarAtividade('financeiro', 'edicao', 'Fluxo de caixa: saldo informado removido (era ' . formatMoney($antes['caixa_saldo_valor']) . ' em ' . formatDate($antes['caixa_saldo_data']) . ')');
        flash('success', 'Saldo informado removido. O fluxo de caixa volta a somar todo o histórico.');
    } else {
        $data = (string) ($_POST['saldo_data'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) || $data > date('Y-m-d') || trim((string) ($_POST['saldo_valor'] ?? '')) === '') {
            flash('danger', 'Informe a data (hoje ou antes) e o saldo da conta nesse dia.');
        } else {
            $valor = decimalInput('saldo_valor');
            $pdo->prepare('UPDATE configuracoes SET caixa_saldo_data = ?, caixa_saldo_valor = ? WHERE id = 1')->execute([$data, $valor]);
            registrarAtividade('financeiro', 'edicao', 'Fluxo de caixa: saldo informado ' . formatMoney($valor) . ' no fim de ' . formatDate($data)
                . ($antes['caixa_saldo_data'] ? ' (antes: ' . formatMoney($antes['caixa_saldo_valor']) . ' em ' . formatDate($antes['caixa_saldo_data']) . ')' : ''));
            flash('success', 'Saldo de ' . formatMoney($valor) . ' informado no fim de ' . formatDate($data) . '. O que entrou ou saiu antes disso não conta mais no fluxo de caixa.');
        }
    }
    redirect('/financeiro/fluxo_caixa.php');
}

// Lembra o último período usado nesta tela entre uma visita e outra, como
// em contas a pagar/receber.
$_SESSION['filtro_fluxo_caixa'] = array_merge(['data_inicio' => date('Y-m-01'), 'data_fim' => date('Y-m-t')], $_SESSION['filtro_fluxo_caixa'] ?? []);
if (isset($_GET['data_inicio']) || isset($_GET['data_fim'])) {
    $_SESSION['filtro_fluxo_caixa'] = [
        'data_inicio' => $_GET['data_inicio'] ?? '',
        'data_fim' => $_GET['data_fim'] ?? '',
    ];
}
$dataInicio = $_SESSION['filtro_fluxo_caixa']['data_inicio'];
$dataFim = $_SESSION['filtro_fluxo_caixa']['data_fim'];

$fluxo = calcularFluxoCaixa($pdo, $dataInicio, $dataFim);

$pageTitle = 'Fluxo de caixa';
$pageBreadcrumb = 'Relatórios';
$activeMenu = 'fluxo-caixa';
require __DIR__ . '/../../includes/layout_header.php';
?>

<div class="filtros">
    <form method="get">
        <?php require __DIR__ . '/../../includes/filtro_periodo.php'; ?>
    </form>
    <span class="ms-auto filtro-chip">Saldo final: <?= formatMoney($fluxo['saldo_final']) ?></span>
    <?php if (usuarioEhAdmin()): ?>
        <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#modal-saldo"><i class="bi bi-cash-stack"></i> Informar saldo</button>
    <?php endif; ?>
</div>

<?php if ($informado = $fluxo['saldo_informado']): ?>
    <div class="alert alert-info d-flex flex-wrap align-items-center gap-2 py-2">
        <i class="bi bi-info-circle"></i>
        <span>O caixa parte do saldo de <strong><?= formatMoney($informado['valor']) ?></strong> informado no fim de <strong><?= formatDate($informado['data']) ?></strong>.
            O que entrou ou saiu antes disso não conta aqui.</span>
    </div>
<?php endif; ?>

<div class="secao">
    <div class="secao-titulo"><i class="bi bi-bank"></i> Extrato de caixa · regime de caixa</div>

    <div class="row g-3 mb-3">
        <div class="col-6 col-md-3">
            <div class="card h-100"><div class="card-body">
                <div class="kpi-rotulo">Saldo inicial</div>
                <div class="kpi-valor"><?= formatMoney($fluxo['saldo_inicial']) ?></div>
            </div></div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card h-100"><div class="card-body">
                <div class="kpi-rotulo">Entradas</div>
                <div class="kpi-valor text-success"><?= formatMoney($fluxo['total_entradas']) ?></div>
            </div></div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card h-100"><div class="card-body">
                <div class="kpi-rotulo">Saídas</div>
                <div class="kpi-valor text-danger"><?= formatMoney($fluxo['total_saidas']) ?></div>
                <?php if ($fluxo['total_tarifas'] > 0): ?><div class="small text-muted">sendo <?= formatMoney($fluxo['total_tarifas']) ?> de tarifas do banco</div><?php endif; ?>
            </div></div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card h-100"><div class="card-body">
                <div class="kpi-rotulo">Saldo final</div>
                <div class="kpi-valor <?= $fluxo['saldo_final'] >= 0 ? '' : 'estoque-baixo' ?>"><?= formatMoney($fluxo['saldo_final']) ?></div>
            </div></div>
        </div>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead><tr><th>Data</th><th>Descrição</th><th>Aluno/Fornecedor</th><th class="text-end">Entrada</th><th class="text-end">Saída</th><th class="text-end">Saldo</th></tr></thead>
                <tbody>
                <tr class="table-light">
                    <td colspan="5" class="fw-semibold">
                        <?php if ($informado && $fluxo['inicio_efetivo'] === date('Y-m-d', strtotime($informado['data'] . ' +1 day'))): ?>
                            Saldo informado no fim de <?= formatDate($informado['data']) ?>
                        <?php else: ?>
                            Saldo inicial<?= $fluxo['inicio_efetivo'] ? ' em ' . formatDate($fluxo['inicio_efetivo']) : '' ?>
                        <?php endif; ?>
                    </td>
                    <td class="text-end fw-semibold"><?= formatMoney($fluxo['saldo_inicial']) ?></td>
                </tr>
                <?php if (!$fluxo['movimentos']): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">Nenhuma movimentação de caixa no período.</td></tr>
                <?php endif; ?>
                <?php foreach ($fluxo['movimentos'] as $mov): ?>
                    <tr>
                        <td><?= formatDate($mov['data']) ?></td>
                        <td><?= !empty($mov['tarifa']) ? '<i class="bi bi-bank text-muted"></i> ' : '' ?><?= e($mov['descricao']) ?></td>
                        <td><?= e($mov['origem']) ?></td>
                        <td class="text-end text-success"><?= $mov['tipo'] === 'entrada' ? formatMoney($mov['valor']) : '' ?></td>
                        <td class="text-end text-danger"><?= $mov['tipo'] === 'saida' ? formatMoney($mov['valor']) : '' ?></td>
                        <td class="text-end"><?= formatMoney($mov['saldo']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <?php if ($fluxo['movimentos']): ?>
                <tfoot>
                    <tr class="table-<?= $fluxo['saldo_final'] >= 0 ? 'success' : 'danger' ?>">
                        <th colspan="3" class="text-end fs-5">Saldo final</th>
                        <th class="text-end"><?= formatMoney($fluxo['total_entradas']) ?></th>
                        <th class="text-end"><?= formatMoney($fluxo['total_saidas']) ?></th>
                        <th class="text-end fs-5"><?= formatMoney($fluxo['saldo_final']) ?></th>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>
</div>

<?php if (usuarioEhAdmin()): ?>
<div class="modal fade" id="modal-saldo" tabindex="-1">
    <div class="modal-dialog">
        <form method="post" class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-cash-stack"></i> Informar o saldo da conta</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="small text-muted">Olhe o extrato do banco e digite o saldo no fim de um dia. O fluxo de caixa passa a partir desse valor e
                    <strong>ignora tudo o que entrou ou saiu antes</strong> — bom para começar do zero quando os lançamentos antigos estão incompletos.
                    Nada é apagado: as contas a receber e a pagar continuam como estão.</p>
                <div class="row g-2">
                    <div class="col-6">
                        <label class="form-label">Saldo no fim do dia</label>
                        <input type="date" name="saldo_data" class="form-control" max="<?= date('Y-m-d') ?>" value="<?= e($fluxo['saldo_informado']['data'] ?? date('Y-m-d')) ?>" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Saldo (R$)</label>
                        <input type="text" name="saldo_valor" class="form-control" inputmode="decimal" placeholder="0,00" required
                               value="<?= isset($fluxo['saldo_informado']) ? e(number_format($fluxo['saldo_informado']['valor'], 2, ',', '.')) : '' ?>">
                    </div>
                </div>
                <div class="form-text">Os lançamentos com data depois desse dia continuam entrando normalmente.</div>
            </div>
            <div class="modal-footer">
                <?php if ($fluxo['saldo_informado']): ?>
                    <button type="submit" name="acao" value="remover_saldo" formnovalidate class="btn btn-outline-danger me-auto"><i class="bi bi-x-lg"></i> Voltar a contar tudo</button>
                <?php endif; ?>
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-brand"><i class="bi bi-check-lg"></i> Salvar saldo</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
