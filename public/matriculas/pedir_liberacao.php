<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();
exigirPermissaoAcao('gerenciar_matriculas', 'Seu perfil não pode matricular alunos.');

// A secretaria pede a um administrador a liberação para matricular um aluno
// inadimplente. O pedido aparece no sininho e em ⚙ › Liberações de matrícula.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responderJson(['sucesso' => false, 'mensagem' => 'Método inválido.']);
}

$pdo = getDB();
$alunoId = (int) ($_POST['aluno_id'] ?? 0);
$turmaId = (int) ($_POST['turma_id'] ?? 0) ?: null;
$motivo = mb_substr(input('motivo'), 0, 255);

$stmt = $pdo->prepare('SELECT nome FROM alunos WHERE id = ?');
$stmt->execute([$alunoId]);
$nome = $stmt->fetchColumn();
if (!$nome) {
    responderJson(['sucesso' => false, 'mensagem' => 'Escolha o aluno.']);
}
$atraso = valorAtrasoAluno($pdo, $alunoId);
if ($atraso <= 0) {
    responderJson(['sucesso' => false, 'mensagem' => 'Este aluno não tem parcela em atraso — pode matricular normalmente.']);
}
$stmt = $pdo->prepare("SELECT COUNT(*) FROM liberacoes_inadimplencia WHERE aluno_id = ? AND status = 'pendente'");
$stmt->execute([$alunoId]);
if ((int) $stmt->fetchColumn() > 0) {
    responderJson(['sucesso' => true, 'mensagem' => 'Já existe um pedido aguardando.']);
}
if ($turmaId) {
    $stmt = $pdo->prepare('SELECT id FROM turmas WHERE id = ?');
    $stmt->execute([$turmaId]);
    $turmaId = (int) $stmt->fetchColumn() ?: null;
}

$pdo->prepare('INSERT INTO liberacoes_inadimplencia (aluno_id, turma_id, valor_atraso, motivo, solicitado_por) VALUES (?, ?, ?, ?, ?)')
    ->execute([$alunoId, $turmaId, $atraso, $motivo ?: null, usuarioAtualId()]);
$id = (int) $pdo->lastInsertId();
criarNotificacao($pdo, 'liberacao', 'Pedido de liberação de matrícula',
    usuarioAtualNome() . ' pede para matricular ' . $nome . ' (' . formatMoney($atraso) . ' em atraso).', '/matriculas/liberacoes.php', $id);
registrarAtividade('matriculas', 'criacao', 'Pedido de liberação para matricular inadimplente: ' . $nome . ' (' . formatMoney($atraso) . ' em atraso)');

responderJson(['sucesso' => true, 'mensagem' => 'Pedido enviado.']);
