<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();
exigirMatriculas();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/turmas/listar.php');
}

$pdo = getDB();
$id = (int) ($_POST['id'] ?? 0);

$stmt = $pdo->prepare(
    'SELECT m.*, a.nome AS aluno_nome, t.nome AS turma_nome
     FROM matriculas m JOIN alunos a ON a.id = m.aluno_id JOIN turmas t ON t.id = m.turma_id
     WHERE m.id = ?'
);
$stmt->execute([$id]);
$matricula = $stmt->fetch();

if (!$matricula || $matricula['status'] !== 'ativa') {
    flash('danger', 'Só matrículas ativas podem ser concluídas.');
    redirect($matricula ? '/matriculas/visualizar.php?id=' . $id : '/turmas/listar.php');
}

$stmt = $pdo->prepare("UPDATE matriculas SET status = 'concluida' WHERE id = ?");
$stmt->execute([$id]);

registrarHistoricoAluno($pdo, (int) $matricula['aluno_id'], 'conclusao', 'Turma ' . $matricula['turma_nome'], (int) $matricula["id"]);
registrarAtividade('matriculas', 'conclusao', 'Matrícula concluída: ' . $matricula['aluno_nome'] . ' na turma ' . $matricula['turma_nome']);

flash('success', 'Matrícula marcada como concluída.');
redirect('/matriculas/visualizar.php?id=' . $id);
