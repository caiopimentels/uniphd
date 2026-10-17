<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirAdmin();

$pdo = getDB();

$filtros = [
    'data_inicio' => trim($_GET['data_inicio'] ?? ''),
    'data_fim' => trim($_GET['data_fim'] ?? ''),
    'endpoint' => in_array($_GET['endpoint'] ?? '', ['itau/boletos', 'itau/pix', 'itau/token'], true) ? $_GET['endpoint'] : '',
    'resultado' => in_array($_GET['resultado'] ?? '', ['ok', 'falha'], true) ? $_GET['resultado'] : '',
    'busca' => trim($_GET['busca'] ?? ''),
];
$pagina = max(1, (int) ($_GET['pagina'] ?? 1));
$resultado = buscarRequisicoesWebhook($pdo, $filtros, $pagina, 50);
$statusEvento = statusEventoWebhook();
$endpoints = ['itau/boletos' => 'Aviso de pagamento', 'itau/pix' => 'Aviso de Pix', 'itau/token' => 'Pedido de token'];

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

<?php $linkPaginaWebhook = fn (int $p) => '?' . http_build_query(array_merge($filtros, ['pagina' => $p])); ?>
<?php require __DIR__ . '/../../includes/tabela_webhook_recebidos.php'; ?>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
