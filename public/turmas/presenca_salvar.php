<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();

// Presença do módulo (janela "Presença" na tela da turma): marca os alunos
// escolhidos como presentes (na data informada), com falta, ou limpa.
// Responde em JSON com a presença do módulo atualizada.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responderJson(['ok' => false, 'mensagem' => 'Requisição inválida.']);
}
if (!(usuarioPodeConcluirTurma() || usuarioPodeEditarTurma())) {
    responderJson(['ok' => false, 'mensagem' => 'Seu perfil não pode marcar presença.']);
}

$pdo = getDB();
$turmaId = (int) ($_POST['turma_id'] ?? 0);
$moduloId = (int) ($_POST['modulo_id'] ?? 0);
$acao = (string) ($_POST['acao'] ?? '');
$data = (string) ($_POST['data'] ?? '');
$alunos = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['alunos'] ?? [])))));

$stmt = $pdo->prepare('SELECT cm.nome, t.nome AS turma_nome FROM turmas t JOIN curso_modulos cm ON cm.curso_id = t.curso_id AND cm.id = ? WHERE t.id = ?');
$stmt->execute([$moduloId, $turmaId]);
$modulo = $stmt->fetch();
if (!$modulo) {
    responderJson(['ok' => false, 'mensagem' => 'Módulo não encontrado nesta turma.']);
}
if (!in_array($acao, ['presente', 'falta', 'limpar'], true) || !$alunos) {
    responderJson(['ok' => false, 'mensagem' => 'Selecione pelo menos um aluno.']);
}
if ($acao === 'presente' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) || !strtotime($data))) {
    responderJson(['ok' => false, 'mensagem' => 'Informe a data da presença.']);
}

// Só alunos matriculados (ativos ou concluídos) nesta turma.
$marcadores = implode(',', array_fill(0, count($alunos), '?'));
$stmt = $pdo->prepare("SELECT aluno_id FROM matriculas WHERE turma_id = ? AND status IN ('ativa', 'concluida') AND aluno_id IN ($marcadores)");
$stmt->execute([$turmaId, ...$alunos]);
$alunos = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

if ($acao === 'limpar') {
    if ($alunos) {
        $pdo->prepare("DELETE FROM turma_presencas WHERE turma_id = ? AND curso_modulo_id = ? AND aluno_id IN (" . implode(',', $alunos) . ')')
            ->execute([$turmaId, $moduloId]);
    }
} else {
    $stmt = $pdo->prepare(
        'INSERT INTO turma_presencas (turma_id, curso_modulo_id, aluno_id, presente, data, registrado_por) VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE presente = VALUES(presente), data = VALUES(data), registrado_por = VALUES(registrado_por)'
    );
    foreach ($alunos as $alunoId) {
        $stmt->execute([$turmaId, $moduloId, $alunoId, $acao === 'presente' ? 1 : 0, $acao === 'presente' ? $data : null, usuarioAtualNome()]);
    }
}
registrarAtividade('turmas', 'edicao', 'Presença do módulo "' . $modulo['nome'] . '" (turma ' . $modulo['turma_nome'] . '): ' . count($alunos) . ' aluno(s) — '
    . match ($acao) { 'presente' => 'presentes em ' . formatDate($data), 'falta' => 'falta', default => 'marcação limpa' });

responderJson(['ok' => true, 'presencas' => presencasModuloTurma($pdo, $turmaId, $moduloId)]);
