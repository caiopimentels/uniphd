<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirVerFinanceiro();

$pdo = getDB();
$id = (int) ($_GET['id'] ?? 0);

$previa = previaCobrancaItau($pdo, $id);
if (!$previa['parcela']) {
    flash('danger', 'Parcela não encontrada.');
    redirect('/financeiro/contas_receber/listar.php');
}

$parcela = $previa['parcela'];
$conta = $previa['conta'];
$aluno = $previa['aluno'];
$pronta = !$previa['pendencias'];
$json = json_encode($previa['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$voltar = $parcela['aluno_id']
    ? '/alunos/form.php?id=' . (int) $parcela['aluno_id'] . '&tab=financeiro'
    : '/financeiro/contas_receber/listar.php';

$pageTitle = 'Prévia da cobrança';
$pageBreadcrumb = 'Financeiro · Cobrança bancária';
$activeMenu = 'contas-receber';
$pageAction = '<a href="' . e($voltar) . '" class="btn btn-outline-secondary">Voltar</a>';
require __DIR__ . '/../../includes/layout_header.php';
?>

<div class="row g-4">
<div class="col-lg-5">
    <div class="secao">
        <div class="secao-titulo"><i class="bi bi-clipboard-check"></i> Conferência</div>

        <?php if ($pronta): ?>
            <div class="alert alert-success mb-3">
                <i class="bi bi-check-circle"></i> <strong>Tudo preenchido.</strong> Esta cobrança está pronta para ser enviada ao Itaú
                quando a integração (credenciais e certificado) for ligada.
            </div>
        <?php else: ?>
            <div class="alert alert-danger mb-3">
                <strong><?= count($previa['pendencias']) ?> pendência(s)</strong> impedem o envio:
                <ul class="mb-0 mt-1">
                    <?php foreach ($previa['pendencias'] as $p): ?><li><?= e($p) ?></li><?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($previa['avisos']): ?>
            <div class="alert alert-warning mb-3">
                <strong>Atenção:</strong>
                <ul class="mb-0 mt-1">
                    <?php foreach ($previa['avisos'] as $a): ?><li><?= e($a) ?></li><?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="card"><div class="card-body">
            <div class="kpi-rotulo">Parcela</div>
            <div class="mb-2"><?= e($parcela['descricao']) ?> · <strong><?= formatMoney($parcela['valor']) ?></strong> · vence <?= formatDate($parcela['data_vencimento']) ?></div>

            <div class="kpi-rotulo">Pagador</div>
            <div class="mb-2">
                <?php if ($aluno): ?>
                    <a href="/alunos/form.php?id=<?= (int) $aluno['id'] ?>"><?= e($aluno['nome']) ?></a><?= $aluno['cpf'] ? ' · CPF ' . e(cpfFormatado($aluno['cpf'])) : '' ?>
                <?php else: ?>-<?php endif; ?>
            </div>

            <div class="kpi-rotulo">Conta que recebe</div>
            <div class="mb-2">
                <?php if ($conta): ?>
                    <a href="/contas_bancarias/form.php?id=<?= (int) $conta['id'] ?>"><?= e($conta['apelido']) ?></a>
                    · ag. <?= e($conta['agencia']) ?> / cc <?= e($conta['conta']) ?><?= $conta['conta_digito'] !== null && $conta['conta_digito'] !== '' ? '-' . e($conta['conta_digito']) : '' ?>
                <?php else: ?>-<?php endif; ?>
            </div>

            <div class="kpi-rotulo">Tipo de cobrança</div>
            <div class="mb-2"><?= e(instrumentosCobranca()[$previa['instrumento']] ?? $previa['instrumento']) ?></div>

            <div class="kpi-rotulo">Nosso número</div>
            <div><?= $conta ? e(formatarNossoNumeroItau((int) $conta['proximo_nosso_numero'])) : '-' ?> <span class="small text-muted">(próximo da sequência — só é reservado no envio)</span></div>
        </div></div>
    </div>
</div>

<div class="col-lg-7">
    <div class="secao">
        <div class="secao-titulo">
            <i class="bi bi-braces"></i> Requisição para o Itaú (JSON)
            <button type="button" class="btn btn-sm btn-outline-secondary ms-auto" onclick="copiarJson(this)"><i class="bi bi-clipboard"></i> Copiar</button>
        </div>
        <pre id="json-previa" class="card card-body small mb-2" style="max-height: 640px; overflow: auto; white-space: pre;"><?= e($json) ?></pre>
        <div class="form-text">
            Montado a partir do modelo da API de Cobrança v2. Para validar sem registrar nada, o Itaú aceita
            <code>"etapa_processo_boleto": "simulacao"</code> no ambiente de testes (sandbox).
        </div>
    </div>

    <?php $sandbox = $conta && $conta['api_ambiente'] === 'sandbox'; ?>
    <?php if ($pronta || $sandbox): ?>
    <div class="secao">
        <div class="secao-titulo">
            <i class="bi bi-send-check"></i> Simular no banco
            <span class="ms-auto d-flex gap-2">
                <?php if ($sandbox): ?>
                    <button type="button" class="btn btn-sm btn-outline-secondary btn-simular" onclick="simularCobranca(this, 'exemplo')"><i class="bi bi-file-earmark-code"></i> Boleto de exemplo do Itaú</button>
                <?php endif; ?>
                <?php if ($pronta): ?>
                    <button type="button" class="btn btn-sm btn-outline-primary btn-simular" onclick="simularCobranca(this, 'parcela')"><i class="bi bi-play"></i> Simular esta parcela</button>
                <?php endif; ?>
            </span>
        </div>
        <div class="form-text mb-2">
            <strong>Simular esta parcela</strong> envia a cobrança como <code>simulacao</code>: o banco valida e responde, mas
            <strong>nada é registrado</strong> e o nosso número não avança.
            <?php if ($sandbox): ?>
                No sandbox, porém, o Itaú só responde aos cenários da documentação — dados reais voltam "Cenário de teste não mapeado".
                O <strong>boleto de exemplo</strong> é um desses cenários e serve para provar que a conexão funciona de ponta a ponta.
            <?php endif; ?>
        </div>
        <div id="resultado-simulacao" class="d-none">
            <div id="resultado-simulacao-alerta" class="alert py-2 mb-2"></div>
            <pre id="resultado-simulacao-json" class="card card-body small mb-0" style="max-height: 480px; overflow: auto; white-space: pre;"></pre>
        </div>
    </div>
    <?php endif; ?>
</div>
</div>

<script>
function copiarJson(botao) {
    navigator.clipboard.writeText(document.getElementById('json-previa').textContent).then(function () {
        botao.innerHTML = '<i class="bi bi-check-lg"></i> Copiado';
        setTimeout(function () { botao.innerHTML = '<i class="bi bi-clipboard"></i> Copiar'; }, 2000);
    });
}

function simularCobranca(botao, modo) {
    var botoes = document.querySelectorAll('.btn-simular');
    var rotulo = botao.innerHTML;
    botoes.forEach(function (b) { b.disabled = true; });
    botao.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Enviando...';

    var dados = new FormData();
    dados.append('id', '<?= (int) $id ?>');
    dados.append('modo', modo);

    fetch('/financeiro/cobranca_simular.php', { method: 'POST', body: dados, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) { return r.json(); })
        .then(function (r) {
            var alerta = document.getElementById('resultado-simulacao-alerta');
            alerta.className = 'alert py-2 mb-2 alert-' + (r.ok ? 'success' : 'danger');
            alerta.textContent = r.mensagem;
            document.getElementById('resultado-simulacao-json').textContent = r.resposta === null
                ? '(sem resposta do banco)'
                : (typeof r.resposta === 'string' ? r.resposta : JSON.stringify(r.resposta, null, 2));
            document.getElementById('resultado-simulacao').classList.remove('d-none');
        })
        .catch(function () {
            var alerta = document.getElementById('resultado-simulacao-alerta');
            alerta.className = 'alert py-2 mb-2 alert-danger';
            alerta.textContent = 'Erro ao falar com o servidor do sistema.';
            document.getElementById('resultado-simulacao').classList.remove('d-none');
        })
        .finally(function () {
            botoes.forEach(function (b) { b.disabled = false; });
            botao.innerHTML = rotulo;
        });
}
</script>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
