<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirPermissao('gerenciar_materiais', 'Seu perfil não envia materiais.');

// A equipe/professor abre ou baixa um material (sem contar como acesso de aluno).
$pdo = getDB();
$stmt = $pdo->prepare('SELECT * FROM materiais WHERE id = ?');
$stmt->execute([(int) ($_GET['id'] ?? 0)]);
$material = $stmt->fetch();
$pode = $material && ($material['turma_id'] ? podeMaterialTurma($pdo, (int) $material['turma_id']) : podeMaterialCurso($pdo, (int) $material['curso_id']));
if (!$pode) {
    http_response_code(404);
    exit('Material não encontrado.');
}
if ($material['tipo'] === 'link') {
    redirect($material['link']);
}
enviarArquivoMaterial($pdo, $material, false);
