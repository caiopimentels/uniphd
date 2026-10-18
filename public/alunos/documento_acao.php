<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();

// Aba "Documentos" da ficha do aluno: enviar (pela secretaria), aprovar,
// recusar, dispensar ou reabrir um documento.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/alunos/listar.php');
}
$pdo = getDB();
$alunoId = (int) ($_POST['aluno_id'] ?? 0);
$voltar = '/alunos/form.php?id=' . $alunoId . '&tab=documentos';
$stmt = $pdo->prepare('SELECT * FROM curso_documentos WHERE id = ?');
$stmt->execute([(int) ($_POST['curso_documento_id'] ?? 0)]);
$doc = $stmt->fetch();
$stmt = $pdo->prepare('SELECT id FROM alunos WHERE id = ?');
$stmt->execute([$alunoId]);
if (!$doc || !$stmt->fetchColumn()) {
    flash('danger', 'Documento não encontrado.');
    redirect($voltar);
}

$acao = input('acao');
if ($acao === 'enviar') {
    exigirPermissaoAcao('editar_aluno', 'Seu perfil não pode enviar documentos pelo aluno.');
    $resultado = salvarDocumentoAluno($pdo, $alunoId, $doc, $_FILES['arquivo'] ?? [], usuarioAtualNome());
} else {
    exigirPermissaoAcao('avaliar_documentos', 'Seu perfil não pode aprovar, recusar ou dispensar documentos.');
    $resultado = avaliarDocumentoAluno($pdo, $alunoId, $doc, $acao, (string) ($_POST['motivo'] ?? ''));
}
if ($resultado['ok']) {
    registrarAtividade('alunos', 'edicao', 'Documento "' . $doc['nome'] . '" do aluno #' . $alunoId . ': ' . $resultado['mensagem']);
}
flash($resultado['ok'] ? 'success' : 'danger', $resultado['mensagem']);
redirect($voltar);
