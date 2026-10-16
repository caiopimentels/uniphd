<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirVerFinanceiro();

$pdo = getDB();

// Filtros lembrados entre visitas, como nas outras listas do financeiro.
$_SESSION['filtro_resultado_turmas'] = array_merge(['situacao' => '', 'curso_id' => '', 'busca' => ''], $_SESSION['filtro_resultado_turmas'] ?? []);
if (isset($_GET['situacao']) || isset($_GET['curso_id']) || isset($_GET['busca'])) {
    $_SESSION['filtro_resultado_turmas'] = [
        'situacao' => (string) ($_GET['situacao'] ?? ''),
        'curso_id' => (string) ($_GET['curso_id'] ?? ''),
        'busca' => trim((string) ($_GET['busca'] ?? '')),
    ];
}
$filtros = $_SESSION['filtro_resultado_turmas'];
$turmas = resultadoTurmas($pdo, $filtros);
$cursos = $pdo->query('SELECT id, nome FROM cursos ORDER BY nome')->fetchAll();

$soma = fn (string $campo) => array_sum(array_column($turmas, $campo));
$corResultado = fn (float $v) => $v < 0 ? 'text-danger' : ($v > 0 ? 'text-success' : '');

$pageTitle = 'Resultado por turma';
$pageBreadcrumb = 'Relatórios';
$activeMenu = 'resultado-turmas';
$pageAction = '<button type="button" class="btn btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer"></i> Imprimir</button>';
require __DIR__ . '/../../includes/layout_header.php';
?>

<div class="filtros nao-imprimir">
    <form method="get">
        <div class="input-group filtro-busca">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input type="search" name="busca" class="form-control" placeholder="Turma ou curso" value="<?= e($filtros['busca']) ?>" onsearch="this.form.submit()">
        </div>
        <select name="curso_id" class="form-select filtro-controle" style="width: 200px;" onchange="this.form.submit()">
            <option value="">Todos os cursos</option>
            <?php foreach ($cursos as $c): ?>
                <option value="<?= (int) $c['id'] ?>" <?= (string) $c['id'] === (string) $filtros['curso_id'] ? 'selected' : '' ?>><?= e($c['nome']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="situacao" class="form-select filtro-controle" style="width: 190px;" onchange="this.form.submit()">
            <option value="">Todas as situações</option>
            <option value="aberta" <?= $filtros['situacao'] === 'aberta' ? 'selected' : '' ?>>Abertas / em andamento</option>
            <option value="concluida" <?= $filtros['situacao'] === 'concluida' ? 'selected' : '' ?>>Concluídas</option>
            <option value="cancelada" <?= $filtros['situacao'] === 'cancelada' ? 'selected' : '' ?>>Canceladas</option>
        </select>
    </form>
</div>

<p class="small text-muted">
    <strong>Receita</strong> = parcelas das matrículas da turma (recebido + a receber). <strong>Custo</strong> = despesas lançadas para a turma.
    <strong>Resultado previsto</strong> = receita − custo. <strong>Em caixa</strong> = o que já entrou − o que já foi pago.
</p>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead>
                <tr>
                    <th>Turma</th><th class="text-center">Alunos</th>
                    <th class="text-end">Receita</th><th class="text-end">Recebido</th>
                    <th class="text-end">Custo</th><th class="text-end">Custo / aluno</th>
                    <th class="text-end">Resultado previsto</th><th class="text-end">Em caixa</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$turmas): ?>
                <tr><td colspan="8" class="text-center text-muted py-4">Nenhuma turma encontrada.</td></tr>
            <?php endif; ?>
            <?php foreach ($turmas as $t): ?>
                <tr class="cursor-pointer" onclick="window.location='/financeiro/turma_resultado.php?id=<?= (int) $t['id'] ?>'">
                    <td>
                        <?= e($t['curso_nome']) ?> · <strong><?= e($t['nome']) ?></strong>
                        <div class="small text-muted"><?= badgeSituacaoTurma($t['situacao_nome'], $t['situacao_cor']) ?> <?= e(periodoTurma($t)) ?></div>
                    </td>
                    <td class="text-center"><?= (int) $t['alunos'] ?></td>
                    <td class="text-end"><?= formatMoney($t['receita_total']) ?></td>
                    <td class="text-end">
                        <?= formatMoney($t['recebido']) ?>
                        <?php if ((float) $t['em_atraso'] > 0): ?><div class="small text-danger"><?= formatMoney($t['em_atraso']) ?> em atraso</div><?php endif; ?>
                    </td>
                    <td class="text-end">
                        <?= formatMoney($t['despesa_total']) ?>
                        <?php if ((float) $t['despesa_a_pagar'] > 0): ?><div class="small text-muted"><?= formatMoney($t['despesa_a_pagar']) ?> a pagar</div><?php endif; ?>
                    </td>
                    <td class="text-end"><?= $t['custo_por_aluno'] !== null ? formatMoney($t['custo_por_aluno']) : '-' ?></td>
                    <td class="text-end fw-semibold <?= $corResultado($t['resultado_previsto']) ?>"><?= formatMoney($t['resultado_previsto']) ?></td>
                    <td class="text-end <?= $corResultado($t['resultado_realizado']) ?>"><?= formatMoney($t['resultado_realizado']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <?php if (count($turmas) > 1): ?>
            <tfoot>
                <tr>
                    <th>Total (<?= count($turmas) ?> turmas)</th><th class="text-center"><?= (int) $soma('alunos') ?></th>
                    <th class="text-end"><?= formatMoney($soma('receita_total')) ?></th><th class="text-end"><?= formatMoney($soma('recebido')) ?></th>
                    <th class="text-end"><?= formatMoney($soma('despesa_total')) ?></th><th></th>
                    <th class="text-end <?= $corResultado($soma('resultado_previsto')) ?>"><?= formatMoney($soma('resultado_previsto')) ?></th>
                    <th class="text-end <?= $corResultado($soma('resultado_realizado')) ?>"><?= formatMoney($soma('resultado_realizado')) ?></th>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
