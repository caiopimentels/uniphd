<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirAdmin();

$pdo = getDB();
$contas = $pdo->query(
    "SELECT cb.*,
            (SELECT GROUP_CONCAT(fp.nome ORDER BY fp.nome SEPARATOR ', ') FROM formas_pagamento fp WHERE fp.conta_bancaria_id = cb.id) AS formas
     FROM contas_bancarias cb
     ORDER BY cb.ativo DESC, cb.padrao DESC, cb.apelido"
)->fetchAll();

$pageTitle = 'Configurações';
$pageBreadcrumb = 'Administração';
$activeMenu = 'configuracoes';
$pageAction = '<a href="/contas_bancarias/form.php" class="btn btn-brand"><i class="bi bi-plus-lg"></i> Incluir conta</a>';
require __DIR__ . '/../../includes/layout_header.php';
$abaAtiva = 'contas';
require __DIR__ . '/../../includes/abas_configuracoes.php';
?>

<p class="text-muted">
    Contas onde a instituição recebe. Uma conta com <strong>integração</strong> gera boleto/Pix direto no banco;
    as formas de pagamento (Boleto, Pix...) são ligadas a ela em <a href="/formas_pagamento/listar.php">Formas de pagamento</a>.
</p>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead><tr><th>Conta</th><th>Banco</th><th>Agência / conta</th><th>Integração</th><th>Formas vinculadas</th><th class="text-end">Próx. nosso nº</th><th>Situação</th><th class="text-end">Ações</th></tr></thead>
            <tbody>
            <?php if (!$contas): ?>
                <tr><td colspan="8" class="text-center text-muted py-4">Nenhuma conta bancária cadastrada.</td></tr>
            <?php endif; ?>
            <?php foreach ($contas as $c): ?>
                <tr class="cursor-pointer" onclick="window.location='/contas_bancarias/form.php?id=<?= (int) $c['id'] ?>'">
                    <td>
                        <?= e($c['apelido']) ?>
                        <?php if ($c['padrao']): ?><span class="badge text-bg-info">Padrão</span><?php endif; ?>
                    </td>
                    <td><?= e($c['banco_codigo']) ?> · <?= e($c['banco_nome']) ?></td>
                    <td><?= e($c['agencia']) ?> / <?= e($c['conta']) ?><?= $c['conta_digito'] !== null && $c['conta_digito'] !== '' ? '-' . e($c['conta_digito']) : '' ?></td>
                    <td>
                        <?php if ($c['integracao'] === 'itau'): ?>
                            <span class="badge text-bg-warning"><i class="bi bi-plug"></i> Itaú</span>
                        <?php else: ?>
                            <span class="text-muted small">Sem integração</span>
                        <?php endif; ?>
                    </td>
                    <td class="small"><?= e($c['formas'] ?: '-') ?></td>
                    <td class="text-end"><?= $c['integracao'] === 'itau' ? e(formatarNossoNumeroItau((int) $c['proximo_nosso_numero'])) : '-' ?></td>
                    <td><?= $c['ativo'] ? '<span class="badge text-bg-success">Ativa</span>' : '<span class="badge text-bg-secondary">Inativa</span>' ?></td>
                    <td class="text-end" onclick="event.stopPropagation();">
                        <form action="/contas_bancarias/excluir.php" method="post" class="d-inline" onsubmit="return confirm('Ativar/desativar esta conta?');">
                            <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-secondary" title="Ativar/desativar"><i class="bi <?= $c['ativo'] ? 'bi-toggle-on' : 'bi-toggle-off' ?>"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
