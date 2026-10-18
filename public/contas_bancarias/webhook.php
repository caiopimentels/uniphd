<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirAdmin();

$pdo = getDB();
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

$carregarConta = function () use ($pdo, $id) {
    $stmt = $pdo->prepare('SELECT * FROM contas_bancarias WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch();
};
$conta = $carregarConta();
if (!$conta) {
    flash('danger', 'Conta bancária não encontrada.');
    redirect('/contas_bancarias/listar.php');
}
if ($conta['integracao'] !== 'itau') {
    flash('danger', 'O webhook só existe para conta com integração Itaú.');
    redirect('/contas_bancarias/form.php?id=' . $id);
}

$urls = urlsWebhookItau();
$pendenciaUrl = pendenciaUrlWebhook($urls['webhook']);
$aqui = '/contas_bancarias/webhook.php?id=' . $id;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = input('acao');

    if ($acao === 'gerar_credenciais') {
        gerarCredenciaisWebhook($pdo, $id);
        registrarAtividade('configuracoes', 'edicao', 'Credenciais do webhook Itaú geradas (' . $conta['apelido'] . ')');
        flash('success', 'Credenciais geradas.' . ($conta['webhook_cadastrado_em'] ? ' Cadastre o webhook no Itaú de novo — o par antigo deixou de valer.' : ''));
    } elseif ($acao === 'cadastrar') {
        if ($pendenciaUrl) {
            flash('danger', $pendenciaUrl);
        } else {
            $resultado = cadastrarWebhookItau($pdo, $conta, $urls, max(0.01, decimalInput('valor_minimo', 0.01)));
            registrarAtividade('configuracoes', 'edicao', 'Cadastro do webhook no Itaú (' . $conta['apelido'] . '): ' . ($resultado['ok'] ? 'sucesso' : 'falha'));
            flash($resultado['ok'] ? 'success' : 'danger', $resultado['ok'] ? 'Webhook cadastrado no Itaú. ' . (str_starts_with($resultado['mensagem'], 'O banco aceitou') ? '' : $resultado['mensagem']) : $resultado['mensagem']);
        }
    } elseif ($acao === 'excluir') {
        $resultado = excluirWebhookItau($pdo, $conta, input('id_notificacao'));
        registrarAtividade('configuracoes', 'exclusao', 'Webhook removido do Itaú (' . $conta['apelido'] . '): ' . input('id_notificacao'));
        flash($resultado['ok'] ? 'success' : 'danger', $resultado['ok'] ? 'Cadastro removido do Itaú.' : $resultado['mensagem']);
        redirect($aqui . '&consultar=1');
    } elseif ($acao === 'cadastrar_pix') {
        if ($pendenciaUrl) {
            flash('danger', $pendenciaUrl);
        } else {
            $resultado = cadastrarWebhookPixItau($pdo, $conta, $urls['pix']);
            registrarAtividade('configuracoes', 'edicao', 'Cadastro do aviso de Pix no Itaú (' . $conta['apelido'] . '): ' . ($resultado['ok'] ? 'sucesso' : 'falha'));
            flash($resultado['ok'] ? 'success' : 'danger', $resultado['ok'] ? 'Aviso de Pix cadastrado no Itaú.' : $resultado['mensagem']);
        }
        redirect($aqui . '&consultar_pix=1');
    } elseif ($acao === 'excluir_pix') {
        $resultado = excluirWebhookPixItau($pdo, $conta);
        registrarAtividade('configuracoes', 'exclusao', 'Aviso de Pix removido do Itaú (' . $conta['apelido'] . ')');
        flash($resultado['ok'] ? 'success' : 'danger', $resultado['ok'] ? 'Aviso de Pix removido do Itaú.' : $resultado['mensagem']);
        redirect($aqui . '&consultar_pix=1');
    }
    redirect($aqui);
}

// Consultar o Itaú só quando pedido: é uma chamada externa (token + mTLS).
$consulta = isset($_GET['consultar']) ? listarWebhooksItau($pdo, $conta) : null;
$consultaPix = isset($_GET['consultar_pix']) ? consultarWebhookPixItau($pdo, $conta) : null;

$stmt = $pdo->prepare(
    'SELECT we.*, cr.descricao AS parcela_descricao
     FROM webhook_eventos we LEFT JOIN contas_receber cr ON cr.id = we.conta_receber_id
     WHERE we.conta_bancaria_id = ? ORDER BY we.id DESC LIMIT 50'
);
$stmt->execute([$id]);
$eventos = $stmt->fetchAll();

$clientSecret = descriptografar($conta['webhook_client_secret_cripto']);
$statusEvento = statusEventoWebhook();

$pageTitle = 'Configurações';
$pageBreadcrumb = 'Administração · Contas bancárias · Webhook';
$activeMenu = 'configuracoes';
$pageAction = '<a href="/contas_bancarias/form.php?id=' . $id . '" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Voltar à conta</a>';
require __DIR__ . '/../../includes/layout_header.php';
$abaAtiva = 'contas';
require __DIR__ . '/../../includes/abas_configuracoes.php';
$contaAbaId = $id;
$contaAba = 'webhook';
require __DIR__ . '/../../includes/abas_conta_bancaria.php';
?>

<div class="secao" style="max-width: 900px;">
    <div class="secao-titulo"><i class="bi bi-broadcast"></i> Aviso de pagamento (webhook) · <?= e($conta['apelido']) ?></div>
    <p class="text-muted">
        Quando um boleto desta conta é pago, o Itaú avisa o sistema e a parcela é baixada sozinha.
        Os dois avisos são cadastrados: <strong>baixa operacional</strong> (o aluno pagou) e
        <strong>baixa efetiva</strong> (o dinheiro caiu). A parcela é baixada no primeiro que chegar.
    </p>

    <?php if ($pendenciaUrl): ?>
        <div class="alert alert-warning py-2"><?= e($pendenciaUrl) ?></div>
    <?php endif; ?>

    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label">URL do webhook</label>
            <input type="text" class="form-control" readonly value="<?= e($urls['webhook']) ?>">
        </div>
        <div class="col-md-6">
            <label class="form-label">URL de autenticação (token OAuth)</label>
            <input type="text" class="form-control" readonly value="<?= e($urls['token']) ?>">
        </div>
        <div class="col-md-6">
            <label class="form-label">Client ID do webhook</label>
            <input type="text" class="form-control" readonly value="<?= e($conta['webhook_client_id'] ?: '— ainda não gerado —') ?>">
        </div>
        <div class="col-md-6">
            <label class="form-label">Client Secret do webhook</label>
            <?php if ($clientSecret): ?>
                <div class="input-group">
                    <input type="password" id="webhook_secret" class="form-control" readonly value="<?= e($clientSecret) ?>">
                    <button type="button" class="btn btn-outline-secondary" onclick="const c = document.getElementById('webhook_secret'); c.type = c.type === 'password' ? 'text' : 'password';"><i class="bi bi-eye"></i></button>
                </div>
            <?php else: ?>
                <input type="text" class="form-control" readonly value="— ainda não gerado —">
            <?php endif; ?>
            <div class="form-text">São do sistema, não do Itaú: é com eles que o banco se identifica ao avisar. Vão no cadastro automaticamente.</div>
        </div>
    </div>

    <div class="d-flex flex-wrap gap-2 align-items-end mt-3">
        <form method="post" onsubmit="return <?= $conta['webhook_client_id'] ? "confirm('Gerar um par novo invalida o atual. Depois será preciso cadastrar no Itaú de novo. Continuar?')" : 'true' ?>;">
            <input type="hidden" name="id" value="<?= $id ?>">
            <input type="hidden" name="acao" value="gerar_credenciais">
            <button type="submit" class="btn btn-outline-secondary"><i class="bi bi-key"></i> <?= $conta['webhook_client_id'] ? 'Gerar novas credenciais' : 'Gerar credenciais' ?></button>
        </form>

        <?php if ($conta['webhook_client_id']): ?>
            <form method="post" class="d-flex gap-2 align-items-end">
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="acao" value="cadastrar">
                <div>
                    <label class="form-label small mb-1">Avisar a partir de (R$)</label>
                    <input type="text" name="valor_minimo" class="form-control" style="width: 120px;" value="0,01">
                </div>
                <button type="submit" class="btn btn-brand" <?= $pendenciaUrl ? 'disabled' : '' ?>><i class="bi bi-cloud-upload"></i> Cadastrar no Itaú</button>
            </form>
        <?php endif; ?>

        <a href="<?= e($aqui) ?>&consultar=1" class="btn btn-outline-secondary"><i class="bi bi-search"></i> Consultar no Itaú</a>
    </div>

    <?php if ($conta['webhook_cadastrado_em']): ?>
        <p class="small text-muted mt-2 mb-0">Último cadastro feito por aqui: <?= formatDateTime($conta['webhook_cadastrado_em']) ?> (ambiente: <?= $conta['api_ambiente'] === 'producao' ? 'Produção' : 'Sandbox' ?>).</p>
    <?php endif; ?>
</div>

<div class="secao" style="max-width: 900px;">
    <div class="secao-titulo"><i class="bi bi-lightning-charge"></i> Aviso de Pix na hora</div>
    <p class="text-muted">
        O aviso de boleto acima só chega no processamento noturno do Itaú. Este avisa <strong>na hora</strong> em que o
        aluno paga pelo QR Code: o sistema confirma o Pix no banco e baixa a parcela.
    </p>
    <div class="alert alert-warning small">
        <i class="bi bi-exclamation-triangle"></i> O Itaú guarda <strong>um único endereço de aviso por chave Pix</strong>.
        Se outro sistema já recebe os avisos da chave <strong><?= e($conta['chave_pix'] ?: '(sem chave)') ?></strong>, cadastrar aqui
        tira o aviso dele. Clique em "Consultar no Itaú" antes, para ver o que está cadastrado.
    </div>
    <?php if (!$conta['chave_pix']): ?>
        <div class="alert alert-danger py-2">Cadastre a chave Pix em "Dados da conta" para usar o aviso de Pix.</div>
    <?php else: ?>
        <label class="form-label">Endereço que será cadastrado</label>
        <input type="text" class="form-control mb-1" readonly value="<?= e($urls['pix']) ?>" style="max-width: 600px;">
        <div class="form-text mb-3">Sem o "/pix" no final: o Itaú acrescenta sozinho ao avisar.</div>
        <div class="d-flex flex-wrap gap-2">
            <a href="<?= e($aqui) ?>&consultar_pix=1" class="btn btn-outline-secondary"><i class="bi bi-search"></i> Consultar no Itaú</a>
            <form method="post" onsubmit="return confirm(<?= e(json_encode('Cadastrar o aviso de Pix desta chave para este sistema? Se outro sistema recebia os avisos desta chave, ele deixa de receber.')) ?>);">
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="acao" value="cadastrar_pix">
                <button type="submit" class="btn btn-brand" <?= $pendenciaUrl ? 'disabled' : '' ?>><i class="bi bi-cloud-upload"></i> Cadastrar no Itaú</button>
            </form>
        </div>
        <?php if ($conta['webhook_pix_cadastrado_em']): ?>
            <p class="small text-muted mt-2 mb-0">Último cadastro feito por aqui: <?= formatDateTime($conta['webhook_pix_cadastrado_em']) ?>.</p>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($consultaPix): ?>
        <div class="mt-3">
            <?php if (!$consultaPix['ok']): ?>
                <div class="alert alert-danger py-2 mb-0"><?= e($consultaPix['mensagem']) ?></div>
            <?php elseif (!$consultaPix['url']): ?>
                <div class="alert alert-secondary py-2 mb-0">Nenhum aviso de Pix cadastrado no Itaú para esta chave.</div>
            <?php else: ?>
                <div class="alert <?= $consultaPix['url'] === $urls['pix'] ? 'alert-success' : 'alert-warning' ?> py-2 mb-0 d-flex align-items-center gap-2">
                    <div class="flex-grow-1">
                        Cadastrado no Itaú: <code><?= e($consultaPix['url']) ?></code>
                        <div class="small"><?= $consultaPix['url'] === $urls['pix'] ? 'É este sistema — os Pix já avisam na hora.' : 'É outro endereço (outro sistema?). Cadastrar aqui substitui.' ?></div>
                    </div>
                    <form method="post" onsubmit="return confirm('Remover o aviso de Pix desta chave no Itaú? Nenhum sistema receberá mais os avisos de Pix dela.');">
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <input type="hidden" name="acao" value="excluir_pix">
                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Remover do Itaú"><i class="bi bi-trash"></i></button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php if ($consulta): ?>
<div class="secao" style="max-width: 900px;">
    <div class="secao-titulo"><i class="bi bi-cloud"></i> Cadastrado no Itaú</div>
    <?php if (!$consulta['ok']): ?>
        <div class="alert alert-danger py-2"><?= e($consulta['mensagem']) ?></div>
    <?php elseif (!$consulta['cadastros']): ?>
        <p class="text-muted mb-0">Nenhum webhook cadastrado para o beneficiário <?= e(idBeneficiarioItau($conta)) ?>.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead><tr><th>Tipo</th><th>URL</th><th>Valor mínimo</th><th>Criado em</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($consulta['cadastros'] as $c): ?>
                    <tr>
                        <td><?= e((string) ($c['tipo_notificacao'] ?? '')) ?></td>
                        <td class="small">
                            <?= e((string) ($c['webhook_url'] ?? '')) ?>
                            <?php if (($c['webhook_url'] ?? '') !== $urls['webhook']): ?><span class="badge text-bg-warning">diferente da URL atual</span><?php endif; ?>
                        </td>
                        <td><?= isset($c['valor_minimo']) ? formatMoney($c['valor_minimo']) : '-' ?></td>
                        <td class="small"><?= e(isset($c['data_criacao']) ? formatDateTime(date('Y-m-d H:i:s', strtotime($c['data_criacao']))) : '-') ?></td>
                        <td class="text-end">
                            <form method="post" onsubmit="return confirm('Remover este cadastro do Itaú? Os avisos deste tipo param de chegar.');">
                                <input type="hidden" name="id" value="<?= $id ?>">
                                <input type="hidden" name="acao" value="excluir">
                                <input type="hidden" name="id_notificacao" value="<?= e((string) ($c['id_notificacao_boleto'] ?? '')) ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="secao" style="max-width: 900px;">
    <div class="secao-titulo">
        <i class="bi bi-list-check"></i> Últimos avisos recebidos
        <a href="/contas_bancarias/webhook_log.php" class="btn btn-sm btn-outline-secondary ms-auto"><i class="bi bi-journal-code"></i> Registro completo das chamadas</a>
    </div>
    <?php if (!$eventos): ?>
        <p class="text-muted mb-0">Nenhum aviso recebido ainda.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead><tr><th>Quando</th><th>Tipo</th><th>Nosso número</th><th>Valor pago</th><th>Resultado</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($eventos as $ev): [$cor, $rotulo] = $statusEvento[$ev['status']] ?? ['secondary', $ev['status']]; ?>
                    <tr>
                        <td class="small"><?= formatDateTime($ev['criado_em']) ?></td>
                        <td class="small"><?= e($ev['tipo_notificacao'] ?: '-') ?></td>
                        <td><?= $ev['nosso_numero'] ? e(formatarNossoNumeroItau((int) $ev['nosso_numero'])) : '-' ?></td>
                        <td><?= $ev['valor_pago'] !== null ? formatMoney($ev['valor_pago']) : '-' ?></td>
                        <td>
                            <span class="badge text-bg-<?= $cor ?>"><?= e($rotulo) ?></span>
                            <div class="small text-muted"><?= e($ev['mensagem']) ?><?= $ev['parcela_descricao'] ? ' · ' . e($ev['parcela_descricao']) : '' ?></div>
                        </td>
                        <td>
                            <details><summary class="small">payload</summary><pre class="small mb-0" style="white-space: pre-wrap; max-width: 360px;"><?= e(json_encode(json_decode($ev['payload']), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: $ev['payload']) ?></pre></details>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
