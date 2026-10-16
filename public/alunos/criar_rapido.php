<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();
exigirPermissaoAcao('cadastrar_aluno', 'Seu perfil não pode cadastrar alunos.');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    responderJson(['sucesso' => false, 'mensagem' => 'Método inválido.']);
}

$pdo = getDB();

$nome = input('nome');
$cpf = input('cpf');
$telefone = input('telefone');
$email = input('email');

if ($nome === '') {
    responderJson(['sucesso' => false, 'mensagem' => 'O nome é obrigatório.']);
}
if ($cpf !== '' && !validarCpf($cpf)) {
    responderJson(['sucesso' => false, 'mensagem' => 'O CPF informado é inválido.']);
}
// Mesma regra do cadastro completo: compara só os dígitos, porque o mesmo
// CPF pode ter sido digitado com ou sem pontuação.
if ($cpf !== '') {
    $stmt = $pdo->prepare("SELECT nome FROM alunos WHERE REGEXP_REPLACE(cpf, '[^0-9]', '') = ?");
    $stmt->execute([apenasDigitos($cpf)]);
    $duplicado = $stmt->fetchColumn();
    if ($duplicado) {
        responderJson(['sucesso' => false, 'mensagem' => 'Já existe um aluno cadastrado com este CPF: ' . $duplicado . '. Pesquise por ele na busca.']);
    }
}

$stmt = $pdo->prepare('INSERT INTO alunos (nome, cpf, telefone, email, ativo) VALUES (?,?,?,?,1)');
$stmt->execute([$nome, $cpf ?: null, $telefone ?: null, $email ?: null]);
$id = (int) $pdo->lastInsertId();

registrarAtividade('alunos', 'criacao', 'Aluno cadastrado (cadastro rápido): ' . $nome);

responderJson([
    'sucesso' => true,
    'aluno' => ['id' => $id, 'nome' => $nome, 'cpf' => $cpf ?: null, 'telefone' => $telefone ?: null],
]);
