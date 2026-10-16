<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();
exigirPermissaoAcao('cancelar_matricula', 'Seu perfil não pode excluir matrículas.');

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

if (!$matricula) {
    flash('danger', 'Matrícula não encontrada.');
    redirect('/turmas/listar.php');
}

// Excluir é para desfazer matrícula feita por engano. Se já entrou dinheiro,
// apagar levaria junto o registro do recebimento (e o caixa ficaria errado);
// nesse caso o caminho é cancelar.
$stmt = $pdo->prepare("SELECT COUNT(*) FROM contas_receber WHERE matricula_id = ? AND status = 'pago'");
$stmt->execute([$id]);
if ((int) $stmt->fetchColumn() > 0) {
    flash('danger', 'Esta matrícula já tem parcela paga e não pode ser excluída. Cancele a matrícula em vez disso.');
    redirect('/matriculas/visualizar.php?id=' . $id);
}

// Boleto já registrado no banco também não some apagando: cancelar.
$stmt = $pdo->prepare('SELECT COUNT(*) FROM cobrancas_bancarias cb JOIN contas_receber cr ON cr.id = cb.conta_receber_id WHERE cr.matricula_id = ?');
$stmt->execute([$id]);
if ((int) $stmt->fetchColumn() > 0) {
    flash('danger', 'Esta matrícula já tem boleto registrado no banco e não pode ser excluída. Cancele a matrícula em vez disso.');
    redirect('/matriculas/visualizar.php?id=' . $id);
}

$pdo->beginTransaction();
$pdo->prepare('DELETE FROM certificados WHERE matricula_id = ?')->execute([$id]);
$stmt = $pdo->prepare('DELETE FROM contas_receber WHERE matricula_id = ?');
$stmt->execute([$id]);
$stmt = $pdo->prepare('DELETE FROM matriculas WHERE id = ?');
$stmt->execute([$id]);
$pdo->commit();

registrarAtividade('matriculas', 'exclusao', 'Matrícula excluída: ' . $matricula['aluno_nome'] . ' na turma ' . $matricula['turma_nome']);

flash('success', $matricula['aluno_nome'] . ' foi excluído da turma (matrícula e parcelas apagadas).');
redirect('/turmas/visualizar.php?id=' . (int) $matricula['turma_id']);
