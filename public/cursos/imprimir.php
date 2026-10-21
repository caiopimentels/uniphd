<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();
exigirCursos();

// Lista de cursos para imprimir: a mesma busca, a mesma ordem e as mesmas
// colunas da tela, com todos os registros (sem paginação) — ou só os marcados.
$pdo = getDB();
$busca = trim((string) ($_GET['busca'] ?? ''));
$cursos = ordenarLinhas(buscarListaCursos($pdo, $busca), estadoListagem('cursos', colunasListaCursos()));
$cursos = filtrarSelecionadas($cursos); // marcados na tela: só eles
$colunas = colunasVisiveis($pdo, 'cursos', definicaoColunasTela($pdo, 'cursos'));
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Cursos</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="<?= e(assetUrl('/assets/css/relatorio.css')) ?>">
</head>
<body>

<div class="toolbar">
    <button type="button" onclick="window.location.href='/cursos/listar.php'"><i class="bi bi-arrow-left"></i> Voltar</button>
    <button type="button" onclick="window.print()" class="btn-imprimir"><i class="bi bi-printer"></i> Imprimir lista</button>
</div>

<main class="relatorio">
    <h1>Cursos</h1>
    <p class="relatorio-meta">
        <?= !empty($_GET['ids']) ? 'Somente os selecionados &middot; ' : ($busca !== '' ? 'Busca: ' . e($busca) . ' &middot; ' : '') ?><?= count($cursos) ?> curso(s)
        &middot; Emitido em <?= formatDateTime(date('Y-m-d H:i:s')) ?>
    </p>

    <table>
        <thead><tr><?= cabecalhoColunasImpressao($colunas) ?></tr></thead>
        <tbody>
        <?php if (!$cursos): ?>
            <tr><td colspan="<?= count($colunas) ?>">Nenhum curso encontrado.</td></tr>
        <?php endif; ?>
        <?php foreach ($cursos as $c): ?>
            <tr><?= celulasColunasImpressao($colunas, $c) ?></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</main>

</body>
</html>
