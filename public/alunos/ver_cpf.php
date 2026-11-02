<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirPermissaoAcao('ver_cpf_completo', 'Seu perfil não pode ver o CPF completo dos alunos.');

// Olhinho do CPF (JSON): devolve o CPF completo de um aluno e registra quem
// viu — na tela ele fica mascarado (800.3**.***-**) para todo mundo.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responderJson(['sucesso' => false, 'mensagem' => 'Use o botão da tela.']);
}
$pdo = getDB();
$stmt = $pdo->prepare('SELECT id, nome, cpf FROM alunos WHERE id = ?');
$stmt->execute([(int) ($_POST['aluno'] ?? 0)]);
$aluno = $stmt->fetch();
if (!$aluno || !$aluno['cpf']) {
    responderJson(['sucesso' => false, 'mensagem' => 'Aluno sem CPF cadastrado.']);
}
registrarAtividade('alunos', 'visualizacao', 'Viu o CPF completo de ' . $aluno['nome']);
responderJson(['sucesso' => true, 'cpf' => cpfFormatado($aluno['cpf']), 'digitos' => apenasDigitos($aluno['cpf'])]);
