<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();
exigirCursos();

// Lista de turmas para imprimir: o mesmo filtro, a mesma ordem e as mesmas
// colunas da tela, com todos os registros (sem paginação) — ou só as marcadas.
$pdo = getDB();
$filtro = array_merge(['busca' => '', 'situacoes' => [], 'cursos' => []], $_SESSION['filtro_turmas'] ?? []);
$turmas = ordenarLinhas(buscarListaTurmas($pdo, $filtro), estadoListagem('turmas', colunasListaTurmas()));
$turmas = filtrarSelecionadas($turmas); // marcadas na tela: só elas
$colunas = colunasVisiveis($pdo, 'turmas', definicaoColunasTela($pdo, 'turmas'));

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
        <?= !empty($_GET['ids']) ? 'Somente as selecionadas &middot; ' : ($descricaoFiltro ? e(implode(' · ', $descricaoFiltro)) . ' &middot; ' : '') ?><?= count($turmas) ?> turma(s)
        &middot; Emitido em <?= formatDateTime(date('Y-m-d H:i:s')) ?>
    </p>

    <table>
        <thead><tr><?= cabecalhoColunasImpressao($colunas) ?></tr></thead>
        <tbody>
        <?php if (!$turmas): ?>
            <tr><td colspan="<?= count($colunas) ?>">Nenhuma turma encontrada.</td></tr>
        <?php endif; ?>
        <?php foreach ($turmas as $t): ?>
            <tr><?= celulasColunasImpressao($colunas, $t) ?></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($turmas): ?><p class="relatorio-meta">Alunos ativos nestas turmas: <?= array_sum(array_column($turmas, 'alunos_ativos')) ?></p><?php endif; ?>
</main>

</body>
</html>
