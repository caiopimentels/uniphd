<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();
exigirAlunos();

// Lista de alunos para imprimir: o mesmo filtro, a mesma ordem e as mesmas
// colunas da tela, com todos os registros (sem paginação) — ou só os marcados.
$pdo = getDB();
$podeVerFinanceiro = usuarioPodeVerInadimplencia();
$filtro = array_merge(['busca' => '', 'situacao' => '', 'financeiro' => ''], $_SESSION['filtro_alunos'] ?? []);
$alunos = ordenarLinhas(buscarListaAlunos($pdo, $filtro, $podeVerFinanceiro), estadoListagem('alunos', colunasListaAlunos()));
$alunos = filtrarSelecionadas($alunos); // marcados na tela: só eles
$colunas = colunasVisiveis($pdo, 'alunos', definicaoColunasTela($pdo, 'alunos'));

$descricaoFiltro = array_filter([
    trim($filtro['busca']) !== '' ? 'Busca: ' . trim($filtro['busca']) : '',
    ['ativo' => 'Ativos', 'inativo' => 'Inativos'][$filtro['situacao']] ?? '',
    $podeVerFinanceiro ? (['atraso' => 'Em atraso', 'em_dia' => 'Em dia'][$filtro['financeiro']] ?? '') : '',
]);
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Alunos</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="<?= e(assetUrl('/assets/css/relatorio.css')) ?>">
</head>
<body>

<div class="toolbar">
    <button type="button" onclick="window.location.href='/alunos/listar.php'"><i class="bi bi-arrow-left"></i> Voltar</button>
    <button type="button" onclick="window.print()" class="btn-imprimir"><i class="bi bi-printer"></i> Imprimir lista</button>
</div>

<main class="relatorio">
    <h1>Alunos</h1>
    <p class="relatorio-meta">
        <?= !empty($_GET['ids']) ? 'Somente os selecionados &middot; ' : ($descricaoFiltro ? e(implode(' · ', $descricaoFiltro)) . ' &middot; ' : '') ?><?= count($alunos) ?> aluno(s)
        &middot; Emitido em <?= formatDateTime(date('Y-m-d H:i:s')) ?>
    </p>

    <table>
        <thead><tr><?= cabecalhoColunasImpressao($colunas) ?></tr></thead>
        <tbody>
        <?php if (!$alunos): ?>
            <tr><td colspan="<?= count($colunas) ?>">Nenhum aluno encontrado.</td></tr>
        <?php endif; ?>
        <?php foreach ($alunos as $a): ?>
            <tr><?= celulasColunasImpressao($colunas, $a) ?></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</main>

</body>
</html>
