<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirPermissaoAcao('gerenciar_materiais', 'Seu perfil não envia materiais.');

// Alunos que abriram um material (JSON) e, na turma, quantos ainda não.
$pdo = getDB();
$stmt = $pdo->prepare('SELECT * FROM materiais WHERE id = ?');
$stmt->execute([(int) ($_GET['id'] ?? 0)]);
$material = $stmt->fetch();
$pode = $material && ($material['turma_id'] ? podeMaterialTurma($pdo, (int) $material['turma_id']) : podeMaterialCurso($pdo, (int) $material['curso_id']));
if (!$pode) {
    responderJson(['sucesso' => false, 'alunos' => []]);
}
// Professor: só os alunos das turmas dele.
$prof = professorDoUsuario($pdo);
$filtro = '';
$params = [$material['id']];
if ($prof !== null) {
    $turmas = turmasDoProfessor($pdo, $prof) ?: [0];
    $filtro = " AND EXISTS (SELECT 1 FROM matriculas m WHERE m.aluno_id = a.id AND m.turma_id IN (" . implode(',', $turmas) . '))';
}
$stmt = $pdo->prepare("SELECT a.nome, ac.vezes, ac.ultimo_em FROM material_acessos ac JOIN alunos a ON a.id = ac.aluno_id WHERE ac.material_id = ? $filtro ORDER BY a.nome");
$stmt->execute($params);
$alunos = array_map(fn ($a) => ['nome' => $a['nome'], 'vezes' => (int) $a['vezes'], 'ultimo' => formatDateTime($a['ultimo_em'])], $stmt->fetchAll());
$faltam = null;
if ($material['turma_id']) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM matriculas m WHERE m.turma_id = ? AND m.status IN ('ativa', 'pendente', 'concluida')
                           AND NOT EXISTS (SELECT 1 FROM material_acessos ac WHERE ac.material_id = ? AND ac.aluno_id = m.aluno_id)");
    $stmt->execute([$material['turma_id'], $material['id']]);
    $faltam = (int) $stmt->fetchColumn();
}
responderJson(['sucesso' => true, 'alunos' => $alunos, 'faltam' => $faltam]);
