<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirPermissaoAcao('trancar_matricula', 'Seu perfil não pode trancar matrículas.');

// Tranca (acao=trancar) ou destranca (acao=destrancar) a matrícula, com as
// regras de Configurações › Matrículas (includes/trancamento.php).
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/turmas/listar.php');
}
$pdo = getDB();
$id = (int) ($_POST['id'] ?? 0);
$data = input('data') ?: date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
    $data = date('Y-m-d');
}

if (input('acao') === 'destrancar') {
    $r = destrancarMatricula($pdo, $id, $data, (int) ($_POST['turma_id'] ?? 0));
} else {
    $previsao = input('previsao');
    $r = trancarMatricula($pdo, $id, $data, input('motivo'), preg_match('/^\d{4}-\d{2}-\d{2}$/', $previsao) ? $previsao : null);
}
flash($r['ok'] ? 'success' : 'danger', $r['mensagem']);
redirect('/matriculas/visualizar.php?id=' . $id);
