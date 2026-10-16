<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();
exigirMatriculas();

// Volta uma matrícula concluída para ativa (ex.: concluída por engano, ou a
// turma foi marcada como concluída e depois reaberta).
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/turmas/listar.php');
}

$pdo = getDB();
$id = (int) ($_POST['id'] ?? 0);

$pdo->beginTransaction();
$stmt = $pdo->prepare(
    'SELECT m.*, a.nome AS aluno_nome, t.nome AS turma_nome, t.vagas, s.comportamento
     FROM matriculas m JOIN alunos a ON a.id = m.aluno_id JOIN turmas t ON t.id = m.turma_id
     JOIN situacoes_turma s ON s.id = t.situacao_id
     WHERE m.id = ? FOR UPDATE'
);
$stmt->execute([$id]);
$matricula = $stmt->fetch();
$voltar = $matricula && input('voltar') === 'turma' ? '/turmas/visualizar.php?id=' . (int) $matricula['turma_id'] : '/matriculas/visualizar.php?id=' . $id;

if (!$matricula || $matricula['status'] !== 'concluida') {
    $pdo->rollBack();
    flash('danger', 'Só matrículas concluídas podem voltar a ficar ativas.');
    redirect($matricula ? $voltar : '/turmas/listar.php');
}
if (!in_array($matricula['comportamento'], COMPORTAMENTOS_TURMA_ABERTA, true)) {
    $pdo->rollBack();
    flash('danger', 'A turma está encerrada. Mude a situação da turma para "Em andamento" antes de reativar o aluno.');
    redirect($voltar);
}
$pdo->prepare('SELECT id FROM turmas WHERE id = ? FOR UPDATE')->execute([$matricula['turma_id']]);
$ocupadas = vagasOcupadasTurma($pdo, (int) $matricula['turma_id'], $id);
if ($ocupadas >= (int) $matricula['vagas']) {
    $pdo->rollBack();
    flash('danger', "A turma está cheia ({$ocupadas}/{$matricula['vagas']}). Aumente o número de alunos da turma antes de reativar.");
    redirect($voltar);
}
$pdo->prepare("UPDATE matriculas SET status = 'ativa' WHERE id = ?")->execute([$id]);
$pdo->commit();
avisarMinimoTurma($pdo, (int) $matricula['turma_id']);

registrarAtividade('matriculas', 'edicao', 'Matrícula reativada (estava concluída): ' . $matricula['aluno_nome'] . ' na turma ' . $matricula['turma_nome']);
flash('success', $matricula['aluno_nome'] . ' voltou a ficar ativo na turma.');
redirect($voltar);
