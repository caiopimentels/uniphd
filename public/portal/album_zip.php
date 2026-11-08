<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirAluno();

// O aluno baixa todas as fotos do álbum da turma (?m=matrícula) num .zip.
$pdo = getDB();
$stmt = $pdo->prepare('SELECT m.turma_id, t.nome AS turma_nome, c.nome AS curso_nome FROM matriculas m JOIN turmas t ON t.id = m.turma_id JOIN cursos c ON c.id = t.curso_id
                       WHERE m.id = ? AND m.aluno_id = ? AND m.status IN (' . statusAcessoConteudoPortal($pdo) . ')');
$stmt->execute([(int) ($_GET['m'] ?? 0), alunoAtualId()]);
$matricula = $stmt->fetch();
if (!$matricula) {
    http_response_code(404);
    exit('Curso não encontrado.');
}
enviarZipAlbum($pdo, fotosDaTurma($pdo, (int) $matricula['turma_id'], true), 'Fotos ' . $matricula['curso_nome'] . ' - ' . $matricula['turma_nome']);
