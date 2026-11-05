<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();
if (!usuarioPodeVerCursos() && !usuarioPodeVerAlunos()) {
    flash('danger', 'Seu perfil não tem acesso a matrículas.');
    redirect('/sem_acesso.php');
}

// Lista de matrículas para imprimir: o mesmo filtro, a mesma ordem e as
// mesmas colunas da tela, com todos os registros (sem paginação) — ou só
// as marcadas.
$pdo = getDB();
$podeVerFinanceiro = usuarioPodeVerInadimplencia();
$filtro = array_merge(['busca' => '', 'status' => [], 'cursos' => [], 'turmas' => [], 'postit' => '', 'financeiro' => ''], $_SESSION['filtro_matriculas'] ?? []);
if (!$podeVerFinanceiro) {
    $filtro['financeiro'] = '';
}
$matriculas = ordenarLinhas(buscarListaMatriculas($pdo, $filtro, $podeVerFinanceiro), estadoListagem('matriculas', colunasListaMatriculas()));
$matriculas = filtrarSelecionadas($matriculas);
$colunas = colunasVisiveis($pdo, 'matriculas', definicaoColunasTela($pdo, 'matriculas'));

$descricaoFiltro = array_filter([
    trim($filtro['busca']) !== '' ? 'Busca: ' . trim($filtro['busca']) : '',
    $filtro['status'] ? implode(' + ', array_map('matriculaStatusLabel', (array) $filtro['status'])) : '',
    $filtro['postit'] === 'com' ? 'Com post-it em aberto' : '',
    ['atraso' => 'Em atraso', 'em_dia' => 'Em dia'][$filtro['financeiro']] ?? '',
]);
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Matrículas</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="<?= e(assetUrl('/assets/css/relatorio.css')) ?>">
</head>
<body>

<div class="toolbar">
    <button type="button" onclick="window.location.href='/matriculas/listar.php'"><i class="bi bi-arrow-left"></i> Voltar</button>
    <button type="button" onclick="window.print()" class="btn-imprimir"><i class="bi bi-printer"></i> Imprimir lista</button>
</div>

<main class="relatorio">
    <h1>Matrículas</h1>
    <p class="relatorio-meta">
        <?= !empty($_GET['ids']) ? 'Somente as selecionadas &middot; ' : ($descricaoFiltro ? e(implode(' · ', $descricaoFiltro)) . ' &middot; ' : '') ?><?= count($matriculas) ?> matrícula(s)
        &middot; Emitido em <?= formatDateTime(date('Y-m-d H:i:s')) ?>
    </p>

    <table>
        <thead><tr><?= cabecalhoColunasImpressao($colunas) ?></tr></thead>
        <tbody>
        <?php if (!$matriculas): ?>
            <tr><td colspan="<?= count($colunas) ?>">Nenhuma matrícula encontrada.</td></tr>
        <?php endif; ?>
        <?php foreach ($matriculas as $m): ?>
            <tr><?= celulasColunasImpressao($colunas, $m) ?></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</main>

</body>
</html>
