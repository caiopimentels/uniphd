<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();
exigirMatriculas();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/turmas/listar.php');
}

$pdo = getDB();
$id = (int) ($_POST['id'] ?? 0);

$pdo->beginTransaction();
$stmt = $pdo->prepare(
    'SELECT m.*, a.nome AS aluno_nome, t.nome AS turma_nome, t.vagas
     FROM matriculas m JOIN alunos a ON a.id = m.aluno_id JOIN turmas t ON t.id = m.turma_id
     WHERE m.id = ? FOR UPDATE'
);
$stmt->execute([$id]);
$matricula = $stmt->fetch();

if (!$matricula || $matricula['status'] !== 'pendente') {
    $pdo->rollBack();
    flash('danger', 'Só inscrições aguardando pagamento podem ser confirmadas.');
    redirect($matricula ? '/matriculas/visualizar.php?id=' . $id : '/turmas/listar.php');
}
$pdo->prepare('SELECT id FROM turmas WHERE id = ? FOR UPDATE')->execute([$matricula['turma_id']]);
$ocupadas = vagasOcupadasTurma($pdo, (int) $matricula['turma_id'], $id);
if ($ocupadas >= (int) $matricula['vagas']) {
    $pdo->rollBack();
    flash('danger', "A turma está cheia ({$ocupadas}/{$matricula['vagas']}). Aumente o número de alunos da turma antes de confirmar.");
    redirect('/matriculas/visualizar.php?id=' . $id);
}
$pdo->prepare("UPDATE matriculas SET status = 'ativa', segura_vaga = 0, lista_espera = 0, pagamento_ate = NULL WHERE id = ?")->execute([$id]);
$pdo->commit();
avisarMinimoTurma($pdo, (int) $matricula['turma_id']);

registrarAtividade('matriculas', 'matricula', 'Inscrição do portal confirmada manualmente: ' . $matricula['aluno_nome'] . ' na turma ' . $matricula['turma_nome']);
flash('success', $matricula['aluno_nome'] . ': matrícula confirmada. O aluno já está ativo na turma.');
redirect(input('voltar') === 'turma' ? '/turmas/visualizar.php?id=' . (int) $matricula['turma_id'] : '/matriculas/visualizar.php?id=' . $id);
