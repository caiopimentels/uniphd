<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();
exigirCupons();

$pdo = getDB();
$cupons = $pdo->query(
    "SELECT cp.*,
            (SELECT COUNT(*) FROM matriculas m WHERE m.cupom_id = cp.id AND m.status <> 'cancelada') AS usos,
            (SELECT GROUP_CONCAT(c.nome ORDER BY c.nome SEPARATOR ', ') FROM cupom_cursos cc JOIN cursos c ON c.id = cc.curso_id WHERE cc.cupom_id = cp.id) AS cursos
     FROM cupons cp
     ORDER BY cp.ativo DESC, cp.criado_em DESC"
)->fetchAll();
$hoje = date('Y-m-d');

// Situação de hoje: inativo, ainda não começou, vencido, esgotado ou valendo.
$situacao = function (array $c) use ($hoje): array {
    return match (true) {
        !$c['ativo'] => ['Inativo', 'text-bg-secondary'],
        $c['validade_inicio'] && $hoje < $c['validade_inicio'] => ['Começa ' . formatDate($c['validade_inicio']), 'text-bg-info'],
        $c['validade_fim'] && $hoje > $c['validade_fim'] => ['Vencido', 'text-bg-secondary'],
        $c['quantidade'] !== null && (int) $c['usos'] >= (int) $c['quantidade'] => ['Esgotado', 'text-bg-warning'],
        default => ['Valendo', 'text-bg-success'],
    };
};

$pageTitle = 'Cupons de desconto';
$pageBreadcrumb = 'Financeiro';
$pageAction = '<a href="/cupons/form.php" class="btn btn-brand"><i class="bi bi-plus-lg"></i> Incluir cupom</a>';
$activeMenu = 'cupons';
require __DIR__ . '/../../includes/layout_header.php';
?>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead><tr><th>Código</th><th>Desconto</th><th>Cursos</th><th>Validade</th><th class="text-center">Usados</th><th>Situação</th><th class="text-center">Ativo</th><th class="text-end">Ações</th></tr></thead>
            <tbody>
            <?php if (!$cupons): ?>
                <tr><td colspan="8" class="text-center text-muted py-4">Nenhum cupom cadastrado.</td></tr>
            <?php endif; ?>
            <?php foreach ($cupons as $c): [$rotuloSituacao, $classeSituacao] = $situacao($c); ?>
                <tr class="cursor-pointer" onclick="window.location='/cupons/form.php?id=<?= (int) $c['id'] ?>'">
                    <td>
                        <span class="fw-semibold font-monospace"><?= e($c['codigo']) ?></span>
                        <?php if ($c['descricao']): ?><div class="small text-muted"><?= e($c['descricao']) ?></div><?php endif; ?>
                    </td>
                    <td>
                        <?= e(textoDescontoCupom($c)) ?>
                        <div class="small text-muted">
                            <?= $c['uma_vez_por_aluno'] ? 'Uma vez por aluno' : 'Sem limite por aluno' ?> · <?= $c['acumula'] ? 'acumula com outros descontos' : 'não acumula' ?>
                        </div>
                    </td>
                    <td><?= e($c['cursos'] ?: 'Todos os cursos') ?></td>
                    <td class="text-nowrap">
                        <?php if ($c['validade_inicio'] || $c['validade_fim']): ?>
                            <?= $c['validade_inicio'] ? formatDate($c['validade_inicio']) : '...' ?> a <?= $c['validade_fim'] ? formatDate($c['validade_fim']) : '...' ?>
                        <?php else: ?>
                            <span class="text-muted">Sem prazo</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center"><?= (int) $c['usos'] ?><?= $c['quantidade'] !== null ? ' / ' . (int) $c['quantidade'] : ' <span class="text-muted small">(ilimitado)</span>' ?></td>
                    <td><span class="badge <?= $classeSituacao ?>"><?= e($rotuloSituacao) ?></span></td>
                    <td class="text-center" onclick="event.stopPropagation();">
                        <form action="/cupons/situacao.php" method="post" class="d-inline-block">
                            <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                            <div class="form-check form-switch d-inline-block mb-0" title="<?= $c['ativo'] ? 'Desativar' : 'Ativar' ?> o cupom">
                                <input class="form-check-input" type="checkbox" role="switch" <?= $c['ativo'] ? 'checked' : '' ?> onchange="this.form.submit()">
                            </div>
                        </form>
                    </td>
                    <td class="text-end text-nowrap" onclick="event.stopPropagation();">
                        <?php if (!(int) $c['usos']): ?>
                        <form action="/cupons/excluir.php" method="post" class="d-inline" onsubmit="return confirm(<?= e(json_encode('Excluir o cupom ' . $c['codigo'] . '?')) ?>);">
                            <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Excluir (só cupom nunca usado)"><i class="bi bi-trash"></i></button>
                        </form>
                        <?php endif; ?>
                        <a href="/cupons/form.php?id=<?= (int) $c['id'] ?>&editar=1" class="btn btn-sm btn-outline-secondary" title="Editar"><i class="bi bi-pencil"></i></a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
