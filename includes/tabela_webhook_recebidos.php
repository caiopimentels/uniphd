<?php
// Tabela dos avisos que o banco mandou ao webhook (webhook_requisicoes):
// usada no "Registro do webhook" e na Auditoria da conta. Espera
// $resultado (buscarRequisicoesWebhook), $endpoints, $statusEvento e
// $linkPaginaWebhook (fn (int $pagina): string).
?>
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
        <a class="btn btn-sm btn-outline-secondary <?= $resultado['pagina'] <= 1 ? 'disabled' : '' ?>" href="<?= e($linkPaginaWebhook($resultado['pagina'] - 1)) ?>">‹ Mais recentes</a>
        <span class="small text-muted">Página <?= $resultado['pagina'] ?> de <?= $resultado['total_paginas'] ?></span>
        <a class="btn btn-sm btn-outline-secondary <?= $resultado['pagina'] >= $resultado['total_paginas'] ? 'disabled' : '' ?>" href="<?= e($linkPaginaWebhook($resultado['pagina'] + 1)) ?>">Mais antigas ›</a>
    </div>
    <?php endif; ?>
</div>

