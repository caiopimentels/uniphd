<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();
exigirPermissaoAcao('editar_professor', 'Seu perfil não pode ativar/desativar professores.');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/professores/listar.php');
}

$id = (int) ($_POST['id'] ?? 0);
$pdo = getDB();
$stmt = $pdo->prepare('UPDATE professores SET ativo = NOT ativo WHERE id = ?');
$stmt->execute([$id]);

$stmt = $pdo->prepare('SELECT nome, ativo FROM professores WHERE id = ?');
$stmt->execute([$id]);
$professor = $stmt->fetch();
if ($professor) {
    registrarAtividade('professores', 'edicao', 'Professor ' . ($professor['ativo'] ? 'ativado' : 'desativado') . ': ' . $professor['nome']);
}

flash('success', 'Situação do professor atualizada.');
redirect('/professores/listar.php');
