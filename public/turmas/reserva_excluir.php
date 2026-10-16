<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();
exigirMatriculas();

// Desfaz a reserva: a vaga volta a ficar livre (e aparece no site).
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/turmas/listar.php');
}

$pdo = getDB();
$stmt = $pdo->prepare('SELECT r.*, t.nome AS turma_nome FROM turma_reservas r JOIN turmas t ON t.id = r.turma_id WHERE r.id = ?');
$stmt->execute([(int) ($_POST['id'] ?? 0)]);
$reserva = $stmt->fetch();
if (!$reserva) {
    flash('danger', 'Reserva não encontrada (talvez já tenha virado matrícula).');
    redirect('/turmas/listar.php');
}

$pdo->prepare('DELETE FROM turma_reservas WHERE id = ?')->execute([$reserva['id']]);
registrarAtividade('matriculas', 'exclusao', 'Reserva de vaga excluída: ' . $reserva['nome'] . ' na turma ' . $reserva['turma_nome']);

flash('success', 'Reserva de ' . $reserva['nome'] . ' excluída. A vaga ficou livre.');
redirect('/turmas/visualizar.php?id=' . (int) $reserva['turma_id']);
