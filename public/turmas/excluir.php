<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();
exigirPermissaoAcao('excluir_turma', 'Seu perfil não pode excluir turmas.');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/turmas/listar.php');
}

$pdo = getDB();
$ids = array_values(array_unique(array_filter(array_map('intval', $_POST['ids'] ?? []))));

if (!$ids) {
    flash('danger', 'Selecione ao menos uma turma.');
    redirect('/turmas/listar.php');
}

$excluidas = 0;
$recusas = [];
foreach ($ids as $turmaId) {
    $resultado = excluirTurma($pdo, $turmaId);
    if ($resultado['ok']) {
        $excluidas++;
    } else {
        $recusas[] = $resultado['mensagem'];
    }
}

if ($excluidas) {
    flash('success', "{$excluidas} turma(s) excluída(s).");
}
foreach ($recusas as $mensagem) {
    flash('warning', $mensagem);
}

// Vindo da tela da própria turma e ela não existir mais, a lista é o único
// destino que faz sentido.
$voltar = (string) ($_POST['voltar'] ?? '');
if ($recusas && count($ids) === 1 && str_starts_with($voltar, '/turmas/')) {
    redirect($voltar);
}
redirect('/turmas/listar.php');
