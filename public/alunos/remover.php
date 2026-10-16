<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();
exigirPermissaoAcao('excluir_aluno', 'Seu perfil não pode excluir alunos.');

// Exclui o cadastro de vez. Só para quem nunca teve matrícula nem
// lançamento financeiro (cadastro feito por engano); os demais se desativa,
// para não perder o histórico.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/alunos/listar.php');
}

$pdo = getDB();
$id = (int) ($_POST['id'] ?? 0);
$stmt = $pdo->prepare(
    'SELECT a.nome,
            (SELECT COUNT(*) FROM matriculas WHERE aluno_id = a.id) AS matriculas,
            (SELECT COUNT(*) FROM contas_receber WHERE aluno_id = a.id) AS lancamentos
     FROM alunos a WHERE a.id = ?'
);
$stmt->execute([$id]);
$aluno = $stmt->fetch();
if (!$aluno) {
    flash('danger', 'Aluno não encontrado.');
    redirect('/alunos/listar.php');
}
if ($aluno['matriculas'] || $aluno['lancamentos']) {
    flash('danger', $aluno['nome'] . ' tem matrícula ou lançamento financeiro e não pode ser excluído — desative o cadastro em vez disso.');
    redirect('/alunos/listar.php');
}

$pdo->prepare('DELETE FROM alunos WHERE id = ?')->execute([$id]);
registrarAtividade('alunos', 'exclusao', 'Aluno excluído: ' . $aluno['nome']);
flash('success', 'Cadastro de ' . $aluno['nome'] . ' excluído.');
redirect('/alunos/listar.php');
