<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();
exigirPermissaoAcao('editar_aluno', 'Seu perfil não pode mexer nos anexos do aluno.');

// Anexos da ficha do aluno: guardar, liberar/esconder do aluno, excluir.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/alunos/listar.php');
}
$pdo = getDB();
$alunoId = (int) ($_POST['aluno_id'] ?? 0);
$voltar = '/alunos/form.php?id=' . $alunoId . '&tab=documentos';
$stmt = $pdo->prepare('SELECT id FROM alunos WHERE id = ?');
$stmt->execute([$alunoId]);
if (!$stmt->fetchColumn()) {
    flash('danger', 'Aluno não encontrado.');
    redirect('/alunos/listar.php');
}

$acao = input('acao');
if ($acao === 'enviar') {
    $r = salvarAnexoAluno($pdo, $alunoId, input('nome'), $_FILES['arquivo'] ?? [], isset($_POST['visivel_aluno']));
    flash($r['ok'] ? 'success' : 'danger', $r['mensagem']);
    redirect($voltar);
}

$stmt = $pdo->prepare('SELECT * FROM aluno_anexos WHERE id = ? AND aluno_id = ?');
$stmt->execute([(int) ($_POST['anexo_id'] ?? 0), $alunoId]);
$anexo = $stmt->fetch();
if (!$anexo) {
    flash('danger', 'Anexo não encontrado.');
    redirect($voltar);
}
if ($acao === 'visivel') {
    $pdo->prepare('UPDATE aluno_anexos SET visivel_aluno = 1 - visivel_aluno WHERE id = ?')->execute([$anexo['id']]);
    $texto = $anexo['visivel_aluno'] ? 'escondido do aluno' : 'liberado para o aluno';
    registrarHistoricoAluno($pdo, $alunoId, 'documento', 'Anexo "' . $anexo['nome'] . '" ' . $texto);
    flash('success', 'Anexo "' . $anexo['nome'] . '" ' . $texto . '.');
} elseif ($acao === 'excluir') {
    $pdo->prepare('DELETE FROM aluno_anexos WHERE id = ?')->execute([$anexo['id']]);
    @unlink(caminhoStorage('documentos/' . $anexo['arquivo']));
    registrarHistoricoAluno($pdo, $alunoId, 'documento', 'Anexo "' . $anexo['nome'] . '" excluído');
    registrarAtividade('alunos', 'exclusao', 'Anexo excluído da ficha do aluno #' . $alunoId . ': ' . $anexo['nome']);
    flash('success', 'Anexo excluído.');
}
redirect($voltar);
