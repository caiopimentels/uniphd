<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirAdmin();

// Conta bancária › Conferir boletos: confere no Itaú, um a um, os boletos
// emitidos por esta conta de parcelas ainda pendentes. Se o banco mostrar
// pagamento, a parcela é baixada como no aviso do webhook.
//   GET              → a tela
//   GET lista=1      → boletos a conferir (JSON)
//   de / ate         → período de vencimento (padrão: todos os vencidos até ontem)
//   POST cobranca    → consulta um boleto e baixa se estiver pago (JSON)
$pdo = getDB();
$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM contas_bancarias WHERE id = ?');
$stmt->execute([$id]);
$contaTela = $stmt->fetch();
if (!$contaTela) {
    flash('danger', 'Conta bancária não encontrada.');
    redirect('/contas_bancarias/listar.php');
}

$dataValida = fn (string $campo) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET[$campo] ?? '')) ? $_GET[$campo] : '';
$filtrou = isset($_GET['de']) || isset($_GET['ate']);
$de = $dataValida('de');
$ate = $filtrou ? $dataValida('ate') : date('Y-m-d', strtotime('-1 day'));
$sqlAbertos = "FROM cobrancas_bancarias cb
         JOIN contas_receber cr ON cr.id = cb.conta_receber_id
         LEFT JOIN alunos a ON a.id = cr.aluno_id
         WHERE cb.conta_bancaria_id = ? AND cb.status = 'registrada' AND cr.status = 'pendente' AND cr.excluido_em IS NULL"
    . ($de !== '' ? ' AND cb.data_vencimento >= ?' : '') . ($ate !== '' ? ' AND cb.data_vencimento <= ?' : '');
$paramsAbertos = array_merge([$id], array_values(array_filter([$de, $ate])));

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !empty($_GET['lista'])) {
    $stmt = $pdo->prepare('SELECT cb.id, cb.nosso_numero, cb.valor, cb.data_vencimento, cr.descricao, a.nome AS aluno ' . $sqlAbertos . ' ORDER BY cb.data_vencimento, cb.id');
    $stmt->execute($paramsAbertos);
    $linhas = $stmt->fetchAll();
    responderJson(['boletos' => array_map(fn ($l) => [
        'id' => (int) $l['id'],
        'nosso_numero' => formatarNossoNumeroItau((int) $l['nosso_numero']),
        'quem' => $l['aluno'] ?: $l['descricao'],
        'descricao' => $l['descricao'],
        'vencimento' => formatDate($l['data_vencimento']),
        'valor' => formatMoney($l['valor']),
    ], $linhas)]);
}

// Conferência automática: horário (vazio = desligada) e quais boletos.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'agendar') {
    $voltar = '/contas_bancarias/conferir_boletos.php?id=' . $id;
    $horario = trim((string) ($_POST['conferencia_horario'] ?? ''));
    $ligada = !empty($_POST['conferencia_ligada']);
    if ($ligada && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $horario)) {
        flash('warning', 'Escolha o horário da conferência automática.');
        redirect($voltar);
    }
    $periodo = ($_POST['conferencia_periodo'] ?? '') === 'todos' ? 'todos' : 'vencidos';
    $pdo->prepare('UPDATE contas_bancarias SET conferencia_horario = ?, conferencia_periodo = ? WHERE id = ?')
        ->execute([$ligada ? $horario . ':00' : null, $periodo, $id]);
    registrarAtividade('configuracoes', 'edicao', "Conferência automática de boletos ({$contaTela['apelido']}): "
        . ($ligada ? "todos os dias às {$horario}, " . ($periodo === 'todos' ? 'todos em aberto' : 'só vencidos') : 'desligada'));
    flash('success', $ligada ? "Conferência automática ligada: todos os dias às {$horario}." : 'Conferência automática desligada.');
    redirect($voltar);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = $pdo->prepare(
        "SELECT cb.*, cr.status AS parcela_status FROM cobrancas_bancarias cb JOIN contas_receber cr ON cr.id = cb.conta_receber_id WHERE cb.id = ? AND cb.conta_bancaria_id = ?"
    );
    $stmt->execute([(int) ($_POST['cobranca'] ?? 0), $id]);
    $cobranca = $stmt->fetch();
    if (!$cobranca) {
        responderJson(['resultado' => 'erro', 'mensagem' => 'Boleto não encontrado.']);
    }
    $r = conferirCobrancaItau($pdo, $contaTela, $cobranca, 'Itaú (conferência manual)');
    if ($r['resultado'] === 'baixado') {
        registrarAtividade('contas_receber', 'baixa', 'Conferência de boletos no Itaú: nosso nº ' . formatarNossoNumeroItau((int) $cobranca['nosso_numero']) . ' estava pago — ' . $r['mensagem']);
    } elseif ($r['resultado'] === 'baixado_banco') {
        registrarAtividade('contas_receber', 'edicao', 'Conferência de boletos no Itaú: nosso nº ' . formatarNossoNumeroItau((int) $cobranca['nosso_numero']) . ' — ' . $r['mensagem']);
    }
    responderJson($r);
}

$stmt = $pdo->prepare('SELECT COUNT(*) AS qtd, COALESCE(SUM(cb.valor), 0) AS total ' . $sqlAbertos);
$stmt->execute($paramsAbertos);
$ontem = date('Y-m-d', strtotime('-1 day'));
$atalhos = [
    'Vencidos' => ['', $ontem],
    'Vencidos este mês' => [date('Y-m-01'), $ontem],
    'A vencer' => [date('Y-m-d'), ''],
    'Todos' => ['', ''],
];
$abertos = $stmt->fetch();

$pageTitle = 'Configurações';
$pageBreadcrumb = 'Administração · Contas bancárias · Conferir boletos';
$activeMenu = 'configuracoes';
$pageAction = '<a href="/contas_bancarias/listar.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Contas bancárias</a>';
require __DIR__ . '/../../includes/layout_header.php';
$abaAtiva = 'contas';
require __DIR__ . '/../../includes/abas_configuracoes.php';
$contaAbaId = $id;
$contaAba = 'conferir';
require __DIR__ . '/../../includes/abas_conta_bancaria.php';
?>
<div style="max-width: 980px;">
    <form method="get" class="card mb-3"><div class="card-body d-flex flex-wrap align-items-end gap-2">
        <input type="hidden" name="id" value="<?= (int) $id ?>">
        <div><label class="form-label small mb-1">Vencimento de</label><input type="date" name="de" class="form-control form-control-sm" value="<?= e($de) ?>"></div>
        <div><label class="form-label small mb-1">até</label><input type="date" name="ate" class="form-control form-control-sm" value="<?= e($ate) ?>"></div>
        <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-funnel"></i> Filtrar</button>
        <div class="d-flex flex-wrap gap-1 ms-md-2">
            <?php foreach ($atalhos as $rotulo => [$aDe, $aAte]): ?>
                <a class="btn btn-sm <?= $aDe === $de && $aAte === $ate ? 'btn-brand' : 'btn-light border' ?>" href="?<?= e(http_build_query(['id' => $id, 'de' => $aDe, 'ate' => $aAte])) ?>"><?= e($rotulo) ?></a>
            <?php endforeach; ?>
        </div>
        <div class="small text-muted w-100">Deixe uma data em branco para não limitar daquele lado.</div>
    </div></form>
    <div class="card mb-3"><div class="card-body d-flex flex-wrap align-items-center gap-3">
        <div class="flex-grow-1">
            <div class="fw-semibold"><?= e($contaTela['apelido']) ?> — boletos ainda não pagos no sistema, <?= $de === '' && $ate === '' ? 'todos os vencimentos' : ($de === '' ? 'vencimento até ' . formatDate($ate) : ($ate === '' ? 'vencimento a partir de ' . formatDate($de) : 'vencimento de ' . formatDate($de) . ' a ' . formatDate($ate))) ?></div>
            <div class="small text-muted"><?= (int) $abertos['qtd'] ?> boleto(s) · <?= formatMoney($abertos['total']) ?>. A conferência pergunta ao Itaú a situação de cada um; o que estiver pago é baixado na hora, como no aviso automático.</div>
        </div>
        <button type="button" class="btn btn-brand" id="btn-conferir" onclick="conferirBoletos()" <?= (int) $abertos['qtd'] ? '' : 'disabled' ?>><i class="bi bi-arrow-repeat"></i> Conferir agora</button>
    </div></div>
    <?php $agendada = $contaTela['conferencia_horario'] !== null; ?>
    <form method="post" class="card mb-3" id="ultima-conferencia"><div class="card-body">
        <input type="hidden" name="acao" value="agendar">
        <div class="d-flex flex-wrap align-items-end gap-3">
            <div class="form-check form-switch mb-1">
                <input class="form-check-input" type="checkbox" role="switch" id="conferencia-ligada" name="conferencia_ligada" value="1" <?= $agendada ? 'checked' : '' ?> onchange="document.getElementById('conferencia-campos').classList.toggle('d-none', !this.checked)">
                <label class="form-check-label fw-semibold" for="conferencia-ligada">Conferir automaticamente todos os dias</label>
            </div>
            <div id="conferencia-campos" class="d-flex flex-wrap align-items-end gap-2 <?= $agendada ? '' : 'd-none' ?>">
                <div><label class="form-label small mb-1">Horário</label><input type="time" name="conferencia_horario" class="form-control form-control-sm" value="<?= e(substr((string) ($contaTela['conferencia_horario'] ?? '07:00'), 0, 5)) ?>"></div>
                <div><label class="form-label small mb-1">Quais boletos</label>
                    <select name="conferencia_periodo" class="form-select form-select-sm">
                        <option value="vencidos" <?= $contaTela['conferencia_periodo'] !== 'todos' ? 'selected' : '' ?>>Só os vencidos</option>
                        <option value="todos" <?= $contaTela['conferencia_periodo'] === 'todos' ? 'selected' : '' ?>>Todos em aberto (inclui a vencer)</option>
                    </select>
                </div>
            </div>
            <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-check-lg"></i> Salvar</button>
        </div>
        <div class="small text-muted mt-2">
            Faz sozinho o mesmo que o "Conferir agora", uma vez por dia no horário escolhido. O sino só avisa se der erro.
            <?php if ($contaTela['conferencia_ultima_em']): ?>
                <br>Última conferência automática: <?= formatDateTime($contaTela['conferencia_ultima_em']) ?><?= $contaTela['conferencia_ultima_resumo'] ? ' — ' . e($contaTela['conferencia_ultima_resumo']) : '' ?>
            <?php endif; ?>
        </div>
        <?php
        $itensUltima = json_decode((string) ($contaTela['conferencia_ultima_itens'] ?? ''), true) ?: [];
        $estiloItem = [
            'baixado' => ['bi-check-circle-fill text-success', 'Estava pago — baixado'],
            'baixado_banco' => ['bi-slash-circle text-secondary', 'Baixado no banco sem pagamento'],
            'atencao' => ['bi-exclamation-triangle-fill text-warning', 'Não encontrado no banco'],
            'erro' => ['bi-x-circle-fill text-danger', 'Erro na consulta'],
        ];
        ?>
        <?php if ($itensUltima): ?>
            <div class="table-responsive mt-3"><table class="table table-sm align-middle mb-0">
                <?php foreach ($itensUltima as $it): [$icone, $rotulo] = $estiloItem[$it['resultado']] ?? $estiloItem['erro']; ?>
                    <tr>
                        <td style="width: 28px"><i class="bi <?= $icone ?>"></i></td>
                        <td><a href="#" onclick="abrirParcela(<?= (int) $it['parcela'] ?>, 'historico'); return false" class="fw-semibold" title="Ver a parcela e o histórico"><?= e($it['quem']) ?></a><div class="small text-muted"><?= e($it['descricao']) ?> · nº <?= e($it['nosso_numero']) ?></div></td>
                        <td class="small text-nowrap"><?= formatDate($it['vencimento']) ?><br><?= formatMoney($it['valor']) ?></td>
                        <td class="small"><div class="fw-semibold"><?= e($rotulo) ?></div><div class="text-muted"><?= e($it['mensagem']) ?></div></td>
                    </tr>
                <?php endforeach; ?>
            </table></div>
        <?php endif; ?>
    </div></form>
    <div id="conferir-painel" class="card d-none"><div class="card-body">
        <div id="conferir-status" class="mb-2 small text-muted">Buscando boletos...</div>
        <div class="progress mb-3" style="height: 8px"><div class="progress-bar" id="conferir-barra" style="width: 0%"></div></div>
        <div class="d-flex flex-wrap gap-2 mb-3 small" id="conferir-resumo"></div>
        <div class="table-responsive"><table class="table table-sm align-middle mb-0"><tbody id="conferir-lista"></tbody></table></div>
    </div></div>
</div>
<script>const URL_CONFERIR = <?= json_encode('/contas_bancarias/conferir_boletos.php?' . http_build_query(['id' => $id, 'de' => $de, 'ate' => $ate])) ?>;</script>
<script>
let conferindo = false;
let houveBaixa = false;
async function conferirBoletos() {
    const lista = document.getElementById('conferir-lista');
    const status = document.getElementById('conferir-status');
    const barra = document.getElementById('conferir-barra');
    const resumo = document.getElementById('conferir-resumo');
    lista.innerHTML = '';
    resumo.innerHTML = '';
    barra.style.width = '0%';
    barra.className = 'progress-bar progress-bar-striped progress-bar-animated';
    status.textContent = 'Buscando boletos...';
    houveBaixa = false;
    document.getElementById('conferir-painel').classList.remove('d-none');
    document.getElementById('btn-conferir').disabled = true;
    let boletos;
    try {
        boletos = (await (await fetch(URL_CONFERIR + '&lista=1')).json()).boletos;
    } catch (e) {
        status.innerHTML = '<span class="text-danger">Não foi possível buscar os boletos.</span>';
        return;
    }
    if (!boletos.length) {
        status.textContent = 'Nenhum boleto em aberto neste período.';
        barra.className = 'progress-bar bg-success';
        barra.style.width = '100%';
        return;
    }
    const cont = { aberto: 0, baixado: 0, baixado_banco: 0, atencao: 0, erro: 0 };
    const estilo = {
        aberto: ['bi-hourglass-split text-muted', 'Em aberto no banco'],
        baixado: ['bi-check-circle-fill text-success', 'Estava pago — baixado agora'],
        baixado_banco: ['bi-slash-circle text-secondary', 'Baixado no banco sem pagamento'],
        atencao: ['bi-exclamation-triangle-fill text-warning', 'Não encontrado no banco'],
        erro: ['bi-x-circle-fill text-danger', 'Erro na consulta'],
        ok: ['bi-check2 text-muted', 'Já resolvido'],
    };
    const pintarResumo = () => {
        resumo.innerHTML = `<span class="badge text-bg-light border">${cont.aberto} em aberto</span>`
            + `<span class="badge text-bg-success">${cont.baixado} baixado(s) agora</span>`
            + (cont.baixado_banco ? `<span class="badge text-bg-secondary">${cont.baixado_banco} baixado(s) no banco sem pagamento</span>` : '')
            + (cont.atencao ? `<span class="badge text-bg-warning">${cont.atencao} não encontrado(s)</span>` : '')
            + (cont.erro ? `<span class="badge text-bg-danger">${cont.erro} com erro</span>` : '');
    };
    conferindo = true;
    for (let i = 0; i < boletos.length && conferindo; i++) {
        const b = boletos[i];
        if (i > 0) await new Promise(ok => setTimeout(ok, 1000)); // 1 consulta por segundo ao banco
        status.textContent = `Conferindo ${i + 1} de ${boletos.length}...`;
        const tr = document.createElement('tr');
        tr.innerHTML = `<td style="width: 28px"><span class="spinner-border spinner-border-sm text-muted"></span></td>`
            + `<td><div class="fw-semibold">${escapeHtml(b.quem)}</div><div class="small text-muted">${escapeHtml(b.descricao)} · nº ${escapeHtml(b.nosso_numero)}</div></td>`
            + `<td class="small text-nowrap">${escapeHtml(b.vencimento)}<br>${escapeHtml(b.valor)}</td><td class="small resultado"></td>`;
        lista.prepend(tr);
        let r;
        try {
            const corpo = new FormData();
            corpo.append('cobranca', b.id);
            r = await (await fetch(URL_CONFERIR, { method: 'POST', body: corpo })).json();
        } catch (e) {
            r = { resultado: 'erro', mensagem: 'Falha de conexão.' };
        }
        const [icone, rotulo] = estilo[r.resultado] || estilo.erro;
        tr.cells[0].innerHTML = `<i class="bi ${icone}"></i>`;
        tr.querySelector('.resultado').innerHTML = `<div class="fw-semibold">${rotulo}</div><div class="text-muted">${escapeHtml(r.mensagem || '')}</div>`;
        if (r.resultado === 'aberto' || r.resultado === 'ok') {
            cont.aberto++;
            tr.classList.add('opacity-75');
        } else {
            cont[r.resultado in cont ? r.resultado : 'erro']++;
        }
        if (r.resultado === 'baixado') houveBaixa = true;
        barra.style.width = ((i + 1) / boletos.length * 100) + '%';
        pintarResumo();
    }
    barra.className = 'progress-bar ' + (cont.erro ? 'bg-warning' : 'bg-success');
    status.textContent = conferindo ? `Pronto: ${boletos.length} boleto(s) conferido(s).` + (cont.baixado ? ' As parcelas pagas já estão baixadas em Contas a receber.' : '') + (cont.erro ? ` ${cont.erro} não puderam ser conferido(s) — tente de novo mais tarde.` : (cont.baixado ? '' : ' Nenhum pagamento perdido.')) : 'Conferência interrompida.';
    conferindo = false;
    document.getElementById('btn-conferir').disabled = false;
}
</script>
<?php $voltarParcela = 'lista'; require __DIR__ . '/../../includes/modal_parcela.php'; ?>
<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
