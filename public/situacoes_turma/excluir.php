<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/situacoes_turma/listar.php');
}

$pdo = getDB();
$id = (int) ($_POST['id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM situacoes_turma WHERE id = ?');
$stmt->execute([$id]);
$situacao = $stmt->fetch();

if (!$situacao) {
    flash('danger', 'Situação não encontrada.');
    redirect('/situacoes_turma/listar.php');
}

// Desativar só esconde a situação das escolhas; turmas que já estão nela
// continuam como estão. A padrão não pode sumir, senão turma nova nasce sem
// situação.
if ($situacao['ativo'] && $situacao['padrao']) {
    flash('danger', 'Esta é a situação padrão de turmas novas. Marque outra como padrão antes de desativá-la.');
    redirect('/situacoes_turma/listar.php');
}

$stmt = $pdo->prepare('UPDATE situacoes_turma SET ativo = NOT ativo WHERE id = ?');
$stmt->execute([$id]);

registrarAtividade('turmas', 'edicao', 'Situação de turma ' . ($situacao['ativo'] ? 'desativada' : 'ativada') . ': ' . $situacao['nome']);

flash('success', 'Situação ' . ($situacao['ativo'] ? 'desativada' : 'ativada') . '.');
redirect('/situacoes_turma/listar.php');
