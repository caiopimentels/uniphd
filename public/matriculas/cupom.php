<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();
exigirMatriculas();

// Confere o cupom digitado na matrícula (secretaria) para a prévia; o
// cupom é conferido de novo, com trava, ao salvar a matrícula.
$pdo = getDB();
$stmt = $pdo->prepare('SELECT curso_id FROM turmas WHERE id = ?');
$stmt->execute([(int) ($_GET['turma_id'] ?? 0)]);
$cursoId = $stmt->fetchColumn();
if ($cursoId === false) {
    responderJson(['ok' => false, 'mensagem' => 'Escolha a turma antes do cupom.']);
}
$validacao = validarCupom($pdo, (string) ($_GET['codigo'] ?? ''), (int) $cursoId, (int) ($_GET['aluno_id'] ?? 0) ?: null);
if (!$validacao['ok']) {
    responderJson(['ok' => false, 'mensagem' => $validacao['mensagem']]);
}
$cupom = $validacao['cupom'];
responderJson([
    'ok' => true,
    'codigo' => $cupom['codigo'],
    'tipo' => $cupom['tipo'],
    'valor' => (float) $cupom['valor'],
    'acumula' => (bool) $cupom['acumula'],
    'mensagem' => textoDescontoCupom($cupom) . ($cupom['acumula'] ? '' : ' · não acumula com bolsa nem pontualidade'),
]);
