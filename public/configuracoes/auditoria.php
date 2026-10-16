<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirAdmin();

$pdo = getDB();

$dataInicio = trim($_GET['data_inicio'] ?? '');
$dataFim = trim($_GET['data_fim'] ?? '');
$modulo = trim($_GET['modulo'] ?? '');
$usuarioId = (int) ($_GET['usuario_id'] ?? 0);
$pagina = max(1, (int) ($_GET['pagina'] ?? 1));

$resultado = buscarLogAtividades($pdo, [
    'data_inicio' => $dataInicio,
    'data_fim' => $dataFim,
    'modulo' => $modulo,
    'usuario_id' => $usuarioId ?: null,
], $pagina, 50);

$usuarios = $pdo->query('SELECT id, nome FROM usuarios ORDER BY nome')->fetchAll();

$queryBase = [
    'data_inicio' => $dataInicio,
    'data_fim' => $dataFim,
    'modulo' => $modulo,
    'usuario_id' => $usuarioId ?: '',
];

$pageTitle = 'Auditoria';
$pageBreadcrumb = 'Administração · Configurações';
$activeMenu = 'configuracoes';
require __DIR__ . '/../../includes/layout_header.php';
$abaAtiva = 'auditoria';
require __DIR__ . '/../../includes/abas_configuracoes.php';
?>

<div class="filtros">
    <form method="get" class="row g-2 align-items-end w-100">
        <div class="col-auto">
            <label class="form-label small mb-1">De</label>
            <input type="date" name="data_inicio" class="form-control form-control-sm" value="<?= e($dataInicio) ?>">
        </div>
        <div class="col-auto">
            <label class="form-label small mb-1">Até</label>
            <input type="date" name="data_fim" class="form-control form-control-sm" value="<?= e($dataFim) ?>">
        </div>
        <div class="col-auto">
            <label class="form-label small mb-1">Módulo</label>
            <select name="modulo" class="form-select form-select-sm">
                <option value="">Todos</option>
                <?php foreach (modulosAuditoria() as $chave => $nome): ?>
                    <option value="<?= e($chave) ?>" <?= $modulo === $chave ? 'selected' : '' ?>><?= e($nome) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-auto">
            <label class="form-label small mb-1">Usuário</label>
            <select name="usuario_id" class="form-select form-select-sm">
                <option value="">Todos</option>
                <?php foreach ($usuarios as $u): ?>
                    <option value="<?= (int) $u['id'] ?>" <?= $usuarioId === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['nome']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-auto">
            <button class="btn btn-sm btn-outline-secondary" type="submit"><i class="bi bi-search"></i> Filtrar</button>
            <a href="/configuracoes/auditoria.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x-lg"></i> Limpar</a>
        </div>
        <div class="col-auto ms-auto text-muted small">
            <?= $resultado['total'] ?> registro(s)
        </div>
    </form>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle">
            <thead>
            <tr>
                <th>Data/hora</th>
                <th>Usuário</th>
                <th>Módulo</th>
                <th>Operação</th>
                <th>Descrição</th>
                <th>IP</th>
                <th>Dispositivo</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$resultado['itens']): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">Nenhum registro encontrado.</td></tr>
            <?php endif; ?>
            <?php foreach ($resultado['itens'] as $log): ?>
                <tr class="<?= $log['operacao'] === 'login_falha' ? 'table-danger' : '' ?>">
                    <td class="text-nowrap"><?= formatDateTime($log['criado_em']) ?></td>
                    <td><?= e($log['usuario_nome']) ?></td>
                    <td><?= e(modulosAuditoria()[$log['modulo']] ?? $log['modulo']) ?></td>
                    <td><?= e(operacoesAuditoria()[$log['operacao']] ?? ucfirst(str_replace('_', ' ', $log['operacao']))) ?></td>
                    <td><?= e($log['descricao']) ?></td>
                    <td class="text-nowrap"><?= e($log['ip'] ?: '-') ?></td>
                    <td class="text-nowrap"><?= e(resumirUserAgent($log['user_agent'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($resultado['total_paginas'] > 1): ?>
    <div class="card-footer bg-white">
        <nav>
            <ul class="pagination pagination-sm mb-0">
                <?php for ($i = 1; $i <= $resultado['total_paginas']; $i++): ?>
                    <li class="page-item <?= $i === $resultado['pagina'] ? 'active' : '' ?>">
                        <a class="page-link" href="?<?= http_build_query(array_merge($queryBase, ['pagina' => $i])) ?>"><?= $i ?></a>
                    </li>
                <?php endfor; ?>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
