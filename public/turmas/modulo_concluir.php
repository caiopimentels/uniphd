<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();

// Concluir (com a data) ou reabrir um módulo do curso nesta turma. Concluir
// deixa disponíveis os documentos ligados ao módulo.
$pdo = getDB();
$turmaId = (int) ($_POST['turma_id'] ?? 0);
$moduloId = (int) ($_POST['modulo_id'] ?? 0);
$voltar = '/turmas/visualizar.php?id=' . $turmaId;
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !(usuarioPodeConcluirTurma() || usuarioPodeEditarTurma())) {
    flash('danger', 'Seu perfil não pode concluir módulos da turma.');
    redirect($turmaId ? $voltar : '/turmas/listar.php');
}

$stmt = $pdo->prepare('SELECT cm.nome, t.nome AS turma_nome FROM turmas t JOIN curso_modulos cm ON cm.curso_id = t.curso_id AND cm.id = ? WHERE t.id = ?');
$stmt->execute([$moduloId, $turmaId]);
$modulo = $stmt->fetch();
if (!$modulo) {
    flash('danger', 'Módulo não encontrado nesta turma.');
    redirect($turmaId ? $voltar : '/turmas/listar.php');
}

$reabrir = !empty($_POST['reabrir']);
$r = concluirModuloTurma($pdo, $turmaId, $moduloId, $reabrir ? null : (string) ($_POST['data'] ?? ''));
if ($r['ok']) {
    registrarAtividade('turmas', 'edicao', 'Módulo "' . $modulo['nome'] . '" ' . ($reabrir ? 'reaberto' : 'concluído') . ' na turma ' . $modulo['turma_nome']);
}
flash($r['ok'] ? 'success' : 'danger', $r['mensagem']);
redirect($voltar);
