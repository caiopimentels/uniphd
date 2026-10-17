<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();
exigirPermissaoAcao('ativar_aluno', 'Seu perfil não pode ativar/desativar alunos.');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/alunos/listar.php');
}

$id = (int) ($_POST['id'] ?? 0);
$pdo = getDB();
$stmt = $pdo->prepare('UPDATE alunos SET ativo = NOT ativo WHERE id = ?');
$stmt->execute([$id]);

$stmt = $pdo->prepare('SELECT nome, ativo FROM alunos WHERE id = ?');
$stmt->execute([$id]);
$aluno = $stmt->fetch();
if ($aluno) {
    registrarAtividade('alunos', 'edicao', 'Aluno ' . ($aluno['ativo'] ? 'ativado' : 'desativado') . ': ' . $aluno['nome']);
    registrarHistoricoAluno($pdo, $id, 'situacao', $aluno['ativo'] ? 'Aluno ativado' : 'Aluno desativado');
}

flash('success', 'Situação do aluno atualizada.');
redirect('/alunos/listar.php');
