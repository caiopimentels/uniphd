<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();
exigirPermissaoAcao('editar_curso', 'Seu perfil não pode ativar/desativar cursos.');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/cursos/listar.php');
}

$id = (int) ($_POST['id'] ?? 0);
$pdo = getDB();
$stmt = $pdo->prepare('UPDATE cursos SET ativo = NOT ativo WHERE id = ?');
$stmt->execute([$id]);

$stmt = $pdo->prepare('SELECT nome, ativo FROM cursos WHERE id = ?');
$stmt->execute([$id]);
$curso = $stmt->fetch();
if ($curso) {
    registrarAtividade('cursos', 'edicao', 'Curso ' . ($curso['ativo'] ? 'ativado' : 'desativado') . ': ' . $curso['nome']);
}

flash('success', 'Situação do curso atualizada.');
redirect('/cursos/listar.php');
