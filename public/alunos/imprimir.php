<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();
exigirAlunos();

// Lista de alunos para imprimir: o mesmo filtro e a mesma ordem da tela,
// com todos os registros (sem paginação).
$pdo = getDB();
$podeVerFinanceiro = usuarioPodeVerInadimplencia();
$filtro = array_merge(['busca' => '', 'situacao' => '', 'financeiro' => ''], $_SESSION['filtro_alunos'] ?? []);
$alunos = ordenarLinhas(buscarListaAlunos($pdo, $filtro, $podeVerFinanceiro), estadoListagem('alunos', colunasListaAlunos()));

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
        <?= $descricaoFiltro ? e(implode(' · ', $descricaoFiltro)) . ' &middot; ' : '' ?><?= count($alunos) ?> aluno(s)
        &middot; Emitido em <?= formatDateTime(date('Y-m-d H:i:s')) ?>
    </p>

    <table>
        <thead>
            <tr>
                <th>RA</th><th>Nome</th><th>CPF</th><th>Telefone</th><th>E-mail</th><th class="text-end">Matrículas ativas</th>
                <?php if ($podeVerFinanceiro): ?><th>Financeiro</th><?php endif; ?>
                <th>Situação</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$alunos): ?>
            <tr><td colspan="8">Nenhum aluno encontrado.</td></tr>
        <?php endif; ?>
        <?php foreach ($alunos as $a): ?>
            <tr>
                <td><?= e($a['ra'] ?: '-') ?></td>
                <td><?= e($a['nome']) ?></td>
                <td><?= e($a['cpf'] ?: '-') ?></td>
                <td><?= e($a['telefone'] ?: '-') ?></td>
                <td><?= e($a['email'] ?: '-') ?></td>
                <td class="text-end"><?= (int) $a['matriculas_ativas'] ?></td>
                <?php if ($podeVerFinanceiro): ?>
                <td><?= (float) $a['valor_vencido'] > 0 ? 'Em atraso · ' . formatMoney($a['valor_vencido']) : 'Em dia' ?></td>
                <?php endif; ?>
                <td><?= $a['ativo'] ? 'Ativo' : 'Inativo' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</main>

</body>
</html>
