<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();
exigirPermissaoAcao('cancelar_matricula', 'Seu perfil não pode cancelar matrículas.');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/turmas/listar.php');
}

$pdo = getDB();
$id = (int) ($_POST['id'] ?? 0);
$dataCancelamento = input('data_cancelamento') ?: date('Y-m-d');

$stmt = $pdo->prepare(
    'SELECT m.*, a.nome AS aluno_nome, t.nome AS turma_nome
     FROM matriculas m JOIN alunos a ON a.id = m.aluno_id JOIN turmas t ON t.id = m.turma_id
     WHERE m.id = ?'
);
$stmt->execute([$id]);
$matricula = $stmt->fetch();

if (!$matricula || !in_array($matricula['status'], ['ativa', 'pendente'], true)) {
    flash('danger', 'Só matrículas ativas ou aguardando pagamento podem ser canceladas.');
    redirect($matricula ? '/matriculas/visualizar.php?id=' . $id : '/turmas/listar.php');
}

$pdo->beginTransaction();

$stmt = $pdo->prepare("UPDATE matriculas SET status = 'cancelada', segura_vaga = 0, data_cancelamento = ? WHERE id = ?");
$stmt->execute([$dataCancelamento, $id]);
// Inscrição que nunca foi confirmada não deixa dívida: cai tudo que está em aberto.
$parcelasCanceladas = cancelarCobrancasFuturasMatricula($pdo, $id, $matricula['status'] === 'pendente' ? '0000-01-01' : $dataCancelamento);

$pdo->commit();

registrarAtividade(
    'matriculas', 'cancelamento',
    'Matrícula cancelada: ' . $matricula['aluno_nome'] . ' na turma ' . $matricula['turma_nome'] . " ({$parcelasCanceladas} parcela(s) futura(s) cancelada(s))"
);

flash('success', $matricula['aluno_nome'] . ": matrícula cancelada. {$parcelasCanceladas} mensalidade(s) a partir de " . formatDate($dataCancelamento) . ' foram canceladas.');
redirect(input('voltar') === 'turma' ? '/turmas/visualizar.php?id=' . (int) $matricula['turma_id'] : '/matriculas/visualizar.php?id=' . $id);
