<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();
exigirCursos();

// Lista de turmas para imprimir: o mesmo filtro e a mesma ordem da tela,
// com todos os registros (sem paginação).
$pdo = getDB();
$filtro = array_merge(['busca' => '', 'situacoes' => [], 'cursos' => []], $_SESSION['filtro_turmas'] ?? []);
$turmas = ordenarLinhas(buscarListaTurmas($pdo, $filtro), estadoListagem('turmas', colunasListaTurmas()));

$nomesSituacao = array_column(buscarSituacoesTurma($pdo), 'nome', 'id');
$nomesCurso = array_column($pdo->query('SELECT id, nome FROM cursos')->fetchAll(), 'nome', 'id');
$descricaoFiltro = array_filter([
    $filtro['busca'] !== '' ? 'Busca: ' . $filtro['busca'] : '',
    $filtro['situacoes'] ? 'Situação: ' . implode(', ', array_map(fn ($id) => $nomesSituacao[$id] ?? '?', $filtro['situacoes'])) : '',
    $filtro['cursos'] ? 'Curso: ' . implode(', ', array_map(fn ($id) => $nomesCurso[$id] ?? '?', $filtro['cursos'])) : '',
]);
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Turmas</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="<?= e(assetUrl('/assets/css/relatorio.css')) ?>">
</head>
<body>

<div class="toolbar">
    <button type="button" onclick="window.location.href='/turmas/listar.php'"><i class="bi bi-arrow-left"></i> Voltar</button>
    <button type="button" onclick="window.print()" class="btn-imprimir"><i class="bi bi-printer"></i> Imprimir lista</button>
</div>

<main class="relatorio">
    <h1>Turmas</h1>
    <p class="relatorio-meta">
        <?= $descricaoFiltro ? e(implode(' · ', $descricaoFiltro)) . ' &middot; ' : '' ?><?= count($turmas) ?> turma(s)
        &middot; Emitido em <?= formatDateTime(date('Y-m-d H:i:s')) ?>
    </p>

    <table>
        <thead>
            <tr><th>Turma</th><th>Curso</th><th>Professor</th><th>Período</th><th class="text-end">Mensalidade</th><th class="text-end">Alunos / vagas</th><th>Situação</th></tr>
        </thead>
        <tbody>
        <?php if (!$turmas): ?>
            <tr><td colspan="7">Nenhuma turma encontrada.</td></tr>
        <?php endif; ?>
        <?php foreach ($turmas as $t): ?>
            <tr>
                <td><?= e($t['nome']) ?><?= $t['horario'] ? '<br><small>' . e($t['horario']) . '</small>' : '' ?></td>
                <td><?= e($t['curso_nome']) ?></td>
                <td><?= e($t['professor_nome'] ?: '-') ?></td>
                <td><?= e(periodoTurma($t)) ?></td>
                <td class="text-end"><?= formatMoney($t['valor_mensalidade']) ?></td>
                <td class="text-end"><?= (int) $t['alunos_ativos'] ?>/<?= (int) $t['vagas'] ?></td>
                <td><?= e($t['situacao_nome']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <?php if ($turmas): ?>
        <tfoot>
            <tr><th colspan="5" class="text-end">Alunos ativos</th><th class="text-end"><?= array_sum(array_column($turmas, 'alunos_ativos')) ?></th><th></th></tr>
        </tfoot>
        <?php endif; ?>
    </table>
</main>

</body>
</html>
