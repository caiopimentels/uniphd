<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirAluno();

// Abre/baixa um material pelo código (não pelo id): só para aluno com
// matrícula ativa, pendente ou concluída na turma/curso, e já liberado.
// Conta o acesso (a equipe vê quem abriu).
$pdo = getDB();
$codigo = preg_replace('/[^a-f0-9]/', '', (string) ($_GET['c'] ?? ''));
$stmt = $pdo->prepare('SELECT * FROM materiais WHERE codigo = ? AND visivel = 1 AND (liberar_em IS NULL OR liberar_em <= NOW())');
$stmt->execute([$codigo]);
$material = $stmt->fetch();
if (!$material || !matriculaComAcessoMaterial($pdo, (int) alunoAtualId(), $material)) {
    http_response_code(404);
    exit('Material não encontrado ou ainda não liberado.');
}
registrarAcessoMaterial($pdo, (int) $material['id'], (int) alunoAtualId());
session_write_close(); // download grande não trava as outras abas do portal
if ($material['tipo'] === 'link') {
    redirect($material['link']);
}
enviarArquivoMaterial($pdo, $material, false);
