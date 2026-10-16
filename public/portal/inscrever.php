<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

$turmaId = (int) ($_POST['turma_id'] ?? $_GET['turma_id'] ?? 0);
if (!alunoLogado()) {
    // Depois do login/cadastro volta para a turma (o POST não sobrevive ao redirecionamento).
    flash('info', 'Entre ou crie sua conta para concluir a inscrição.');
    redirect('/portal/entrar.php?voltar=' . urlencode('/portal/turma.php?id=' . $turmaId));
}
exigirAluno();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/portal/turma.php?id=' . $turmaId);
}

$pdo = getDB();

// O boleto exige CPF e endereço completos — melhor pedir antes de inscrever.
$stmt = $pdo->prepare('SELECT * FROM alunos WHERE id = ?');
$stmt->execute([alunoAtualId()]);
$aluno = $stmt->fetch();
if (!validarCpf((string) $aluno['cpf']) || !enderecoCompletoAluno(enderecoPrincipalAluno($pdo, (int) $aluno['id']))) {
    flash('warning', 'Antes da inscrição, complete seu CPF e endereço — eles vão no boleto.');
    redirect('/portal/conta.php?voltar=' . urlencode('/portal/turma.php?id=' . $turmaId));
}

$resultado = inscreverAlunoPortal($pdo, (int) $aluno['id'], $turmaId);
flash($resultado['ok'] ? 'success' : 'danger', $resultado['mensagem']);
redirect($resultado['ok'] ? '/portal/meus_cursos.php' : '/portal/turma.php?id=' . $turmaId);
