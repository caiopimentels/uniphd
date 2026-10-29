<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirPermissaoAcao('gerenciar_materiais', 'Seu perfil não envia materiais.');

// Libera / tira outro professor da turma (acesso aos materiais). Só a
// equipe (usuário que não é professor). JSON.
$pdo = getDB();
$turmaId = (int) ($_POST['turma_id'] ?? 0);
$professorId = (int) ($_POST['professor_id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || professorDoUsuario($pdo) !== null || !$turmaId || !$professorId) {
    responderJson(['sucesso' => false, 'mensagem' => 'Sem permissão.']);
}
if (($_POST['acao'] ?? '') === 'liberar') {
    $pdo->prepare('INSERT IGNORE INTO turma_professores (turma_id, professor_id) VALUES (?, ?)')->execute([$turmaId, $professorId]);
} else {
    $pdo->prepare('DELETE FROM turma_professores WHERE turma_id = ? AND professor_id = ?')->execute([$turmaId, $professorId]);
}
registrarAtividade('materiais', 'edicao', 'Professor #' . $professorId . (($_POST['acao'] ?? '') === 'liberar' ? ' liberado na' : ' tirado da') . ' turma #' . $turmaId);
responderJson(['sucesso' => true]);
