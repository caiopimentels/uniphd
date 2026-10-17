<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/documento.php';
exigirAdmin();

// Auditoria de tudo o que a conta trocou com o banco: as chamadas que o
// sistema fez (emissão de boleto, validação, webhook, token) com o que foi
// enviado e a resposta, e os avisos que o banco mandou ao webhook.
$pdo = getDB();
$id = (int) ($_GET['id'] ?? 0);
$parcelaFiltro = (int) ($_GET['parcela'] ?? 0);
if (!$id && $parcelaFiltro) {
    // Vindo do aviso "boleto não foi gerado": acha a conta pela parcela.
    $stmt = $pdo->prepare('SELECT conta_bancaria_id FROM banco_chamadas WHERE conta_receber_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$parcelaFiltro]);
    $id = (int) $stmt->fetchColumn() ?: (int) $pdo->query("SELECT id FROM contas_bancarias WHERE integracao <> 'nenhuma' ORDER BY padrao DESC, id LIMIT 1")->fetchColumn();
}
$stmt = $pdo->prepare('SELECT * FROM contas_bancarias WHERE id = ?');
$stmt->execute([$id]);
$conta = $stmt->fetch();
if (!$conta) {
    flash('danger', 'Conta bancária não encontrada.');
    redirect('/contas_bancarias/listar.php');
}

$ver = ($_GET['ver'] ?? '') === 'recebidas' ? 'recebidas' : 'enviadas';
$filtros = [
    'data_inicio' => trim($_GET['data_inicio'] ?? ''),
    'data_fim' => trim($_GET['data_fim'] ?? ''),
    'quem' => trim($_GET['quem'] ?? ''),
    'operacao' => array_key_exists($_GET['operacao'] ?? '', operacoesChamadaBanco()) ? $_GET['operacao'] : '',
    'resultado' => in_array($_GET['resultado'] ?? '', ['ok', 'erro'], true) ? $_GET['resultado'] : '',
    'parcela' => $parcelaFiltro ?: '',
    'busca' => trim($_GET['busca'] ?? ''),
];
$pagina = max(1, (int) ($_GET['pagina'] ?? 1));
$base = ['id' => $id, 'ver' => $ver];

if ($ver === 'enviadas') {
    $resultado = buscarChamadasBanco($pdo, $id, $filtros, $pagina, 50);
} else {
    $filtrosWebhook = [
        'data_inicio' => $filtros['data_inicio'], 'data_fim' => $filtros['data_fim'], 'conta_bancaria_id' => $id,
        'resultado' => $filtros['resultado'] === 'erro' ? 'falha' : $filtros['resultado'],
        'busca' => $filtros['busca'],
    ];
    $resultado = buscarRequisicoesWebhook($pdo, $filtrosWebhook, $pagina, 50);
    $statusEvento = statusEventoWebhook();
    $endpoints = ['itau/boletos' => 'Aviso de pagamento', 'itau/pix' => 'Aviso de Pix', 'itau/token' => 'Pedido de token'];
    $linkPaginaWebhook = fn (int $p) => '?' . http_build_query(array_merge($base, $filtros, ['pagina' => $p]));
}
$usuarios = $pdo->query('SELECT id, nome FROM usuarios ORDER BY nome')->fetchAll();
$operacoes = operacoesChamadaBanco();

$pageTitle = 'Configurações';
$pageBreadcrumb = 'Administração · Contas bancárias · Auditoria do banco';
$activeMenu = 'configuracoes';
$pageAction = '<a href="/contas_bancarias/listar.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Contas bancárias</a>';
require __DIR__ . '/../../includes/layout_header.php';
$abaAtiva = 'contas';
require __DIR__ . '/../../includes/abas_configuracoes.php';
$contaAbaId = $id;
$contaAba = 'auditoria';
require __DIR__ . '/../../includes/abas_conta_bancaria.php';
?>

<p class="text-muted small" style="max-width: 900px;">
    <strong><?= e($conta['apelido']) ?></strong> — tudo o que o sistema enviou ao banco e o que o banco respondeu, e os avisos que o banco mandou.
    Nada aqui é apagado; senhas, segredos e tokens aparecem como <code>***</code>.
</p>

<ul class="nav nav-tabs mb-3">
    <li class="nav-item"><a class="nav-link <?= $ver === 'enviadas' ? 'active' : '' ?>" href="?<?= e(http_build_query(['id' => $id, 'ver' => 'enviadas'] + array_filter($filtros))) ?>"><i class="bi bi-box-arrow-up-right"></i> Enviado ao banco</a></li>
    <li class="nav-item"><a class="nav-link <?= $ver === 'recebidas' ? 'active' : '' ?>" href="?<?= e(http_build_query(['id' => $id, 'ver' => 'recebidas'] + array_filter(['data_inicio' => $filtros['data_inicio'], 'data_fim' => $filtros['data_fim']]))) ?>"><i class="bi bi-box-arrow-in-down-left"></i> Recebido do banco (webhook)</a></li>
</ul>

<div class="filtros">
    <form method="get" class="row g-2 align-items-end w-100">
        <input type="hidden" name="id" value="<?= (int) $id ?>">
        <input type="hidden" name="ver" value="<?= e($ver) ?>">
        <div class="col-auto">
            <label class="form-label small mb-1">De</label>
            <input type="date" name="data_inicio" class="form-control form-control-sm" value="<?= e($filtros['data_inicio']) ?>">
        </div>
        <div class="col-auto">
            <label class="form-label small mb-1">Até</label>
            <input type="date" name="data_fim" class="form-control form-control-sm" value="<?= e($filtros['data_fim']) ?>">
        </div>
        <?php if ($ver === 'enviadas'): ?>
            <div class="col-auto">
                <label class="form-label small mb-1">Aluno, CPF ou nosso número</label>
                <input type="text" name="busca" class="form-control form-control-sm" style="width: 220px;" placeholder="Ex.: Maria, 123.456.789-00, 10003" value="<?= e($filtros['busca']) ?>">
            </div>
            <div class="col-auto">
                <label class="form-label small mb-1">Quem pediu</label>
                <select name="quem" class="form-select form-select-sm">
                    <option value="">Todos</option>
                    <?php foreach ($usuarios as $u): ?>
                        <option value="u:<?= (int) $u['id'] ?>" <?= $filtros['quem'] === 'u:' . $u['id'] ? 'selected' : '' ?>><?= e($u['nome']) ?></option>
                    <?php endforeach; ?>
                    <option value="portal" <?= $filtros['quem'] === 'portal' ? 'selected' : '' ?>>Aluno pelo portal</option>
                    <option value="automatico" <?= $filtros['quem'] === 'automatico' ? 'selected' : '' ?>>Automático (sistema)</option>
                </select>
            </div>
            <div class="col-auto">
                <label class="form-label small mb-1">Operação</label>
                <select name="operacao" class="form-select form-select-sm">
                    <option value="">Todas</option>
                    <?php foreach ($operacoes as $chave => $rotulo): ?>
                        <option value="<?= e($chave) ?>" <?= $filtros['operacao'] === $chave ? 'selected' : '' ?>><?= e($rotulo) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php else: ?>
            <div class="col-auto">
                <label class="form-label small mb-1">Procurar no conteúdo</label>
                <input type="text" name="busca" class="form-control form-control-sm" placeholder="Nosso número, CPF, valor..." value="<?= e($_GET['busca'] ?? '') ?>">
            </div>
        <?php endif; ?>
        <div class="col-auto">
            <label class="form-label small mb-1">Resultado</label>
            <select name="resultado" class="form-select form-select-sm">
                <option value="">Todos</option>
                <option value="ok" <?= $filtros['resultado'] === 'ok' ? 'selected' : '' ?>>Deu certo</option>
                <option value="erro" <?= $filtros['resultado'] === 'erro' ? 'selected' : '' ?>>Com erro</option>
            </select>
        </div>
        <?php if ($parcelaFiltro): ?><input type="hidden" name="parcela" value="<?= $parcelaFiltro ?>"><?php endif; ?>
        <div class="col-auto">
            <button class="btn btn-sm btn-outline-secondary" type="submit"><i class="bi bi-search"></i> Filtrar</button>
            <a href="?id=<?= (int) $id ?>&ver=<?= e($ver) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x-lg"></i> Limpar</a>
        </div>
        <div class="col-auto ms-auto text-muted small">
            <?= $resultado['total'] ?> registro(s)<?= $parcelaFiltro ? ' · só a parcela nº ' . $parcelaFiltro : '' ?>
        </div>
    </form>
</div>

<?php if ($ver === 'recebidas'): ?>
    <?php require __DIR__ . '/../../includes/tabela_webhook_recebidos.php'; ?>
<?php else: ?>
<div class="card">
    <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle">
            <thead><tr><th>Data/hora</th><th>Operação</th><th>Quem pediu</th><th>Aluno · parcela</th><th>Boleto</th><th>Resposta</th><th></th></tr></thead>
            <tbody>
            <?php if (!$resultado['itens']): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">Nenhuma chamada registrada com esses filtros.</td></tr>
            <?php endif; ?>
            <?php foreach ($resultado['itens'] as $c): ?>
                <tr>
                    <td class="text-nowrap"><?= formatDateTime($c['criado_em']) ?></td>
                    <td><?= e($operacoes[$c['operacao']] ?? $c['operacao']) ?> <span class="text-muted small"><?= e($c['metodo']) ?></span></td>
                    <td>
                        <?php if ($c['origem'] === 'portal'): ?>
                            <i class="bi bi-globe2"></i> <?= e($c['aluno_nome'] ?: 'Aluno') ?> <span class="text-muted small">(portal)</span>
                        <?php elseif ($c['origem'] === 'automatico'): ?>
                            <span class="text-muted">Automático</span>
                        <?php else: ?>
                            <?= e($c['usuario_nome'] ?: '-') ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($c['conta_receber_id']): ?>
                            <?php if ($c['parcela_aluno']): ?><div class="fw-semibold"><?= e($c['parcela_aluno']) ?></div><?php endif; ?>
                            <div class="small">
                                <a href="?<?= e(http_build_query(['id' => $id, 'ver' => 'enviadas', 'parcela' => $c['conta_receber_id']])) ?>" title="Ver só esta parcela"><?= e($c['parcela_descricao'] ?: 'nº ' . $c['conta_receber_id']) ?></a>
                                <?php if ($c['parcela_cpf']): ?><span class="text-muted">· CPF <?= e(cpfFormatado((string) $c['parcela_cpf'])) ?></span><?php endif; ?>
                            </div>
                        <?php else: ?>
                            <span class="text-muted">-</span>
                        <?php endif; ?>
                    </td>
                    <td class="small">
                        <?php if ($c['nosso_numero']): ?>
                            <div>Nosso nº <strong><?= e(formatarNossoNumeroItau((int) $c['nosso_numero'])) ?></strong></div>
                            <?php if ($c['boleto']): ?>
                                <div class="text-muted"><?= formatMoney($c['boleto']['valor']) ?> · vence <?= formatDate($c['boleto']['data_vencimento']) ?></div>
                                <?php $situacoesBoleto = ['registrada' => ['success', 'No banco'], 'paga' => ['primary', 'Pago'], 'baixada' => ['secondary', 'Baixado'], 'erro' => ['danger', 'Não registrado'], 'pendente_envio' => ['warning', 'Enviando']];
                                [$corBoleto, $rotuloBoleto] = $situacoesBoleto[$c['boleto']['status']] ?? ['secondary', $c['boleto']['status']]; ?>
                                <span class="badge text-bg-<?= $corBoleto ?>"><?= e($rotuloBoleto) ?></span> <span class="text-muted">hoje no sistema</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="text-muted">-</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge text-bg-<?= $c['status_http'] === null ? 'dark' : ($c['sucesso'] ? 'success' : 'danger') ?>"><?= $c['status_http'] === null ? 'sem resposta' : (int) $c['status_http'] ?></span>
                        <?php if ($c['duracao_ms'] !== null): ?><span class="text-muted small"><?= (int) $c['duracao_ms'] ?> ms</span><?php endif; ?>
                        <?php if ($c['mensagem']): ?><div class="small text-danger"><?= e($c['mensagem']) ?></div><?php endif; ?>
                    </td>
                    <td class="text-end">
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#chamada-<?= (int) $c['id'] ?>"><i class="bi bi-code-slash"></i> Ver</button>
                    </td>
                </tr>
                <tr class="collapse" id="chamada-<?= (int) $c['id'] ?>">
                    <td colspan="7" class="bg-body-tertiary">
                        <?php if ($c['nosso_numero']): ?>
                            <div class="small mb-2">
                                <strong>Para achar no Itaú:</strong> nosso número <strong><?= e(formatarNossoNumeroItau((int) $c['nosso_numero'])) ?></strong>
                                (busca no app/internet banking, área de cobrança)
                                · ID do boleto na API: <code><?= e((string) idBoletoItau($conta, (int) $c['nosso_numero'])) ?></code>
                                <?php if ($c['boleto'] && $c['boleto']['linha_digitavel']): ?>· linha digitável <code><?= e($c['boleto']['linha_digitavel']) ?></code><?php endif; ?>
                                <?php if ($c['conta_receber_id']): ?>
                                    <button type="button" class="btn btn-sm btn-outline-primary ms-2" onclick="consultarBoletoNoItau(<?= (int) $c['conta_receber_id'] ?>, this, 'consulta-itau-<?= (int) $c['id'] ?>')"><i class="bi bi-search"></i> Consultar no Itaú agora</button>
                                <?php endif; ?>
                            </div>
                            <div id="consulta-itau-<?= (int) $c['id'] ?>" class="mb-2"></div>
                        <?php endif; ?>
                        <div class="small text-muted mb-2"><?= e($c['url']) ?><?= $c['correlation_id'] ? ' · correlationID ' . e($c['correlation_id']) : '' ?></div>
                        <div class="row g-3">
                            <div class="col-lg-6">
                                <div class="small fw-semibold mb-1">Enviado ao banco</div>
                                <pre class="small mb-0" style="white-space: pre-wrap; word-break: break-all; max-height: 420px; overflow: auto;"><?= $c['requisicao'] !== null ? e(formatarJsonLeitura($c['requisicao'])) : '<span class="text-muted">(sem corpo)</span>' ?></pre>
                            </div>
                            <div class="col-lg-6">
                                <div class="small fw-semibold mb-1">Resposta do banco</div>
                                <pre class="small mb-0" style="white-space: pre-wrap; word-break: break-all; max-height: 420px; overflow: auto;"><?= $c['resposta'] !== null ? e(formatarJsonLeitura($c['resposta'])) : '<span class="text-muted">(sem resposta)</span>' ?></pre>
                            </div>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($resultado['total_paginas'] > 1): ?>
    <div class="card-footer bg-white d-flex align-items-center gap-2">
        <?php $query = fn (int $p) => '?' . http_build_query(array_merge($base, array_filter($filtros), ['pagina' => $p])); ?>
        <a class="btn btn-sm btn-outline-secondary <?= $resultado['pagina'] <= 1 ? 'disabled' : '' ?>" href="<?= e($query($resultado['pagina'] - 1)) ?>">‹ Mais recentes</a>
        <span class="small text-muted">Página <?= $resultado['pagina'] ?> de <?= $resultado['total_paginas'] ?></span>
        <a class="btn btn-sm btn-outline-secondary <?= $resultado['pagina'] >= $resultado['total_paginas'] ? 'disabled' : '' ?>" href="<?= e($query($resultado['pagina'] + 1)) ?>">Mais antigas ›</a>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<script>
// Mostra o que o Itaú tem registrado do(s) boleto(s) da parcela.
function consultarBoletoNoItau(parcela, botao, alvoId) {
    const alvo = document.getElementById(alvoId);
    botao.disabled = true;
    alvo.innerHTML = '<div class="py-2"><span class="spinner-border spinner-border-sm text-secondary"></span> Consultando o Itaú...</div>';
    fetch('/financeiro/contas_receber/consultar_itau.php?id=' + encodeURIComponent(parcela), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then((r) => r.text())
        .then((html) => { alvo.innerHTML = html; })
        .catch(() => { alvo.innerHTML = '<div class="alert alert-danger mb-0">Não foi possível consultar o Itaú.</div>'; })
        .finally(() => { botao.disabled = false; });
}
</script>
<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
