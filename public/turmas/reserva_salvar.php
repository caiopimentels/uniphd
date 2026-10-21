<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();
exigirMatriculas();

// Guarda uma vaga da turma para alguém (aluno cadastrado ou só o nome). A
// vaga deixa de aparecer no site e só a secretaria matricula nela.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/turmas/listar.php');
}

$pdo = getDB();
$turmaId = (int) ($_POST['turma_id'] ?? 0);
$alunoId = (int) ($_POST['aluno_id'] ?? 0) ?: null;
$nome = mb_substr(input('nome'), 0, 150);
$telefone = mb_substr(apenasDigitos(input('telefone')), 0, 30);
$observacao = mb_substr(input('observacao'), 0, 255);
$voltar = '/turmas/visualizar.php?id=' . $turmaId;

if ($alunoId) {
    $stmt = $pdo->prepare('SELECT nome, telefone FROM alunos WHERE id = ?');
    $stmt->execute([$alunoId]);
    $aluno = $stmt->fetch();
    if (!$aluno) {
        $alunoId = null;
    } else {
        $nome = $nome ?: $aluno['nome'];
        $telefone = $telefone ?: (string) $aluno['telefone'];
    }
}
if ($nome === '') {
    flash('danger', 'Informe para quem é a vaga reservada.');
    redirect($voltar);
}

$pdo->beginTransaction();
$stmt = $pdo->prepare(
    'SELECT t.id, t.nome, t.vagas, s.comportamento FROM turmas t JOIN situacoes_turma s ON s.id = t.situacao_id WHERE t.id = ? FOR UPDATE'
);
$stmt->execute([$turmaId]);
$turma = $stmt->fetch();
if (!$turma) {
    $pdo->rollBack();
    flash('danger', 'Turma não encontrada.');
    redirect('/turmas/listar.php');
}
if (!in_array($turma['comportamento'], COMPORTAMENTOS_TURMA_ABERTA, true)) {
    $pdo->rollBack();
    flash('danger', 'Esta turma não aceita novas matrículas, então não dá para reservar vaga.');
    redirect($voltar);
}
$ocupadas = vagasOcupadasTurma($pdo, $turmaId);
if ($ocupadas >= (int) $turma['vagas']) {
    $pdo->rollBack();
    flash('danger', "A turma está cheia ({$ocupadas}/{$turma['vagas']}, contando matrículas e reservas). Aumente o número de alunos da turma para reservar.");
    redirect($voltar);
}
if ($alunoId) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM matriculas WHERE turma_id = ? AND aluno_id = ? AND status <> 'cancelada'");
    $stmt->execute([$turmaId, $alunoId]);
    if ((int) $stmt->fetchColumn() > 0) {
        $pdo->rollBack();
        flash('danger', $nome . ' já está matriculado nesta turma.');
        redirect($voltar);
    }
}
$stmt = $pdo->prepare('INSERT INTO turma_reservas (turma_id, aluno_id, nome, telefone, observacao, usuario_id) VALUES (?, ?, ?, ?, ?, ?)');
$stmt->execute([$turmaId, $alunoId, $nome, $telefone ?: null, $observacao ?: null, usuarioAtualId()]);
$pdo->commit();

registrarAtividade('matriculas', 'criacao', 'Vaga reservada para ' . $nome . ' na turma ' . $turma['nome']);
flash('success', 'Vaga reservada para ' . $nome . '. Ela não aparece mais no site.');
redirect($voltar);
