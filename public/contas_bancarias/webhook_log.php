<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirAdmin();

$pdo = getDB();

$filtros = [
    'data_inicio' => trim($_GET['data_inicio'] ?? ''),
    'data_fim' => trim($_GET['data_fim'] ?? ''),
    'endpoint' => in_array($_GET['endpoint'] ?? '', ['itau/boletos', 'itau/token'], true) ? $_GET['endpoint'] : '',
    'resultado' => in_array($_GET['resultado'] ?? '', ['ok', 'falha'], true) ? $_GET['resultado'] : '',
    'busca' => trim($_GET['busca'] ?? ''),
];
$pagina = max(1, (int) ($_GET['pagina'] ?? 1));
$resultado = buscarRequisicoesWebhook($pdo, $filtros, $pagina, 50);
$statusEvento = statusEventoWebhook();
$endpoints = ['itau/boletos' => 'Aviso de pagamento', 'itau/token' => 'Pedido de token'];

$pageTitle = 'Configurações';
$pageBreadcrumb = 'Administração · Contas bancárias · Registro do webhook';
$activeMenu = 'configuracoes';
$pageAction = '<a href="/contas_bancarias/listar.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Contas bancárias</a>';
require __DIR__ . '/../../includes/layout_header.php';
$abaAtiva = 'contas';
require __DIR__ . '/../../includes/abas_configuracoes.php';
?>

<p class="text-muted" style="max-width: 900px;">
    Tudo o que chegou nas URLs do webhook, exatamente como chegou — inclusive chamadas recusadas e pedidos de token —
    e o que o sistema respondeu. Nada aqui é apagado. Senhas e tokens aparecem mascarados.
    Cópia em arquivo no servidor: <code>storage/logs/webhook-AAAA-MM.log</code>.
</p>

<div class="filtros">
    <form method="get" class="row g-2 align-items-end w-100">
        <div class="col-auto">
            <label class="form-label small mb-1">De</label>
            <input type="date" name="data_inicio" class="form-control form-control-sm" value="<?= e($filtros['data_inicio']) ?>">
        </div>
        <div class="col-auto">
            <label class="form-label small mb-1">Até</label>
            <input type="date" name="data_fim" class="form-control form-control-sm" value="<?= e($filtros['data_fim']) ?>">
        </div>
        <div class="col-auto">
            <label class="form-label small mb-1">Chamada</label>
            <select name="endpoint" class="form-select form-select-sm">
                <option value="">Todas</option>
                <?php foreach ($endpoints as $chave => $rotulo): ?>
                    <option value="<?= e($chave) ?>" <?= $filtros['endpoint'] === $chave ? 'selected' : '' ?>><?= e($rotulo) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-auto">
            <label class="form-label small mb-1">Resposta</label>
            <select name="resultado" class="form-select form-select-sm">
                <option value="">Todas</option>
                <option value="ok" <?= $filtros['resultado'] === 'ok' ? 'selected' : '' ?>>Aceitas (2xx)</option>
                <option value="falha" <?= $filtros['resultado'] === 'falha' ? 'selected' : '' ?>>Recusadas / erro</option>
            </select>
        </div>
        <div class="col-auto">
            <label class="form-label small mb-1">Procurar no conteúdo</label>
            <input type="text" name="busca" class="form-control form-control-sm" placeholder="Nosso número, CPF, valor..." value="<?= e($filtros['busca']) ?>">
        </div>
        <div class="col-auto">
            <button class="btn btn-sm btn-outline-secondary" type="submit"><i class="bi bi-search"></i> Filtrar</button>
            <a href="/contas_bancarias/webhook_log.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x-lg"></i> Limpar</a>
        </div>
        <div class="col-auto ms-auto text-muted small"><?= $resultado['total'] ?> chamada(s)</div>
    </form>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle">
            <thead>
            <tr><th>#</th><th>Data/hora</th><th>Chamada</th><th>Conta</th><th>IP</th><th>Resposta</th><th>Avisos processados</th><th></th></tr>
            </thead>
            <tbody>
            <?php if (!$resultado['itens']): ?>
                <tr><td colspan="8" class="text-center text-muted py-4">Nenhuma chamada registrada.</td></tr>
            <?php endif; ?>
            <?php foreach ($resultado['itens'] as $req): $ok = $req['status_http'] >= 200 && $req['status_http'] < 300; ?>
                <tr>
                    <td class="text-muted small"><?= (int) $req['id'] ?></td>
                    <td class="text-nowrap"><?= formatDateTime($req['criado_em']) ?></td>
                    <td><?= e($endpoints[$req['endpoint']] ?? $req['endpoint']) ?> <span class="text-muted small"><?= e($req['metodo']) ?></span></td>
                    <td><?= e($req['conta_apelido'] ?: '-') ?></td>
                    <td class="text-nowrap small"><?= e($req['ip'] ?: '-') ?></td>
                    <td>
                        <span class="badge text-bg-<?= $req['status_http'] === null ? 'dark' : ($ok ? 'success' : 'danger') ?>"><?= $req['status_http'] === null ? 'sem resposta' : (int) $req['status_http'] ?></span>
                        <?php if ($req['duracao_ms'] !== null): ?><span class="text-muted small"><?= (int) $req['duracao_ms'] ?> ms</span><?php endif; ?>
                    </td>
                    <td>
                        <?php foreach ($resultado['eventos'][$req['id']] ?? [] as $ev): [$cor, $rotulo] = $statusEvento[$ev['status']] ?? ['secondary', $ev['status']]; ?>
                            <div class="small">
                                <span class="badge text-bg-<?= $cor ?>"><?= e($rotulo) ?></span>
                                <?= $ev['nosso_numero'] ? 'NN ' . e(formatarNossoNumeroItau((int) $ev['nosso_numero'])) : '' ?>
                                <span class="text-muted"><?= e($ev['mensagem']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </td>
                    <td class="text-end">
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#req-<?= (int) $req['id'] ?>"><i class="bi bi-code-slash"></i> Ver</button>
                    </td>
                </tr>
                <tr class="collapse" id="req-<?= (int) $req['id'] ?>">
                    <td colspan="8" class="bg-body-tertiary">
                        <div class="row g-3">
                            <div class="col-lg-4">
                                <div class="small fw-semibold mb-1">Cabeçalhos recebidos</div>
                                <pre class="small mb-0" style="white-space: pre-wrap; word-break: break-all;"><?= e(formatarJsonLeitura($req['cabecalhos'])) ?></pre>
                            </div>
                            <div class="col-lg-5">
                                <div class="small fw-semibold mb-1">Corpo recebido</div>
                                <pre class="small mb-0" style="white-space: pre-wrap; word-break: break-all;"><?= $req['corpo'] !== '' ? e(formatarJsonLeitura($req['corpo'])) : '<span class="text-muted">(vazio)</span>' ?></pre>
                            </div>
                            <div class="col-lg-3">
                                <div class="small fw-semibold mb-1">O que o sistema respondeu</div>
                                <pre class="small mb-0" style="white-space: pre-wrap; word-break: break-all;"><?= e($req['resposta'] ?? '(a chamada não terminou — veja o log de erros do servidor)') ?></pre>
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
        <?php $query = fn (int $p) => '?' . http_build_query(array_merge($filtros, ['pagina' => $p])); ?>
        <a class="btn btn-sm btn-outline-secondary <?= $resultado['pagina'] <= 1 ? 'disabled' : '' ?>" href="<?= e($query($resultado['pagina'] - 1)) ?>">‹ Mais recentes</a>
        <span class="small text-muted">Página <?= $resultado['pagina'] ?> de <?= $resultado['total_paginas'] ?></span>
        <a class="btn btn-sm btn-outline-secondary <?= $resultado['pagina'] >= $resultado['total_paginas'] ? 'disabled' : '' ?>" href="<?= e($query($resultado['pagina'] + 1)) ?>">Mais antigas ›</a>
    </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
