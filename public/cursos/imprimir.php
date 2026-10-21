<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();
exigirCursos();

// Lista de cursos para imprimir: a mesma busca e a mesma ordem da tela,
// com todos os registros (sem paginação).
$pdo = getDB();
$busca = trim((string) ($_GET['busca'] ?? ''));
$cursos = ordenarLinhas(buscarListaCursos($pdo, $busca), estadoListagem('cursos', colunasListaCursos()));
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
        <?= $busca !== '' ? 'Busca: ' . e($busca) . ' &middot; ' : '' ?><?= count($cursos) ?> curso(s)
        &middot; Emitido em <?= formatDateTime(date('Y-m-d H:i:s')) ?>
    </p>

    <table>
        <thead>
            <tr><th>Curso</th><th class="text-end">Carga horária</th><th class="text-end">Turmas abertas</th><th class="text-end">Turmas (total)</th><th>Situação</th></tr>
        </thead>
        <tbody>
        <?php if (!$cursos): ?>
            <tr><td colspan="5">Nenhum curso encontrado.</td></tr>
        <?php endif; ?>
        <?php foreach ($cursos as $c): ?>
            <tr>
                <td><?= e($c['nome']) ?></td>
                <td class="text-end"><?= $c['carga_horaria'] ? (int) $c['carga_horaria'] . ' h' : '-' ?></td>
                <td class="text-end"><?= (int) $c['turmas_abertas'] ?></td>
                <td class="text-end"><?= (int) $c['turmas_total'] ?></td>
                <td><?= $c['ativo'] ? 'Ativo' : 'Inativo' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</main>

</body>
</html>
