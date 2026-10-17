<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
exigirPermissaoAcao('excluir_receber', 'Seu perfil não pode excluir lançamentos a receber.');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/financeiro/contas_receber/listar.php');
}

$id = (int) ($_POST['id'] ?? 0);
$pdo = getDB();

$stmt = $pdo->prepare('SELECT * FROM contas_receber WHERE id = ?');
$stmt->execute([$id]);
$conta = $stmt->fetch();

if (!$conta) {
    flash('danger', 'Lançamento não encontrado.');
    redirect('/financeiro/contas_receber/listar.php');
}

$voltar = match (input('voltar')) {
    'aluno' => $conta['aluno_id'] ? '/alunos/form.php?id=' . (int) $conta['aluno_id'] . '&tab=financeiro' : null,
    'matricula' => $conta['matricula_id'] ? '/matriculas/visualizar.php?id=' . (int) $conta['matricula_id'] : null,
    default => null,
} ?? '/financeiro/contas_receber/listar.php';

$resultado = excluirParcelaReceber($pdo, $id);
flash(!$resultado['ok'] ? 'danger' : ($resultado['aguardando'] ? 'warning' : 'success'), $resultado['mensagem']);
redirect($voltar);
