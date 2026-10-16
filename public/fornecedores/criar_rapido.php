<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirFinanceiro();

// Cadastro rápido de fornecedor pela tela de conta a pagar (botão "+" ao
// lado da busca). O cadastro completo continua em Financeiro › Fornecedores.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    responderJson(['sucesso' => false, 'mensagem' => 'Método inválido.']);
}

$pdo = getDB();
$nome = mb_substr(input('nome'), 0, 150);
$documento = mb_substr(input('documento'), 0, 20);
$telefone = mb_substr(input('telefone'), 0, 20);
$email = mb_substr(input('email'), 0, 150);

if ($nome === '') {
    responderJson(['sucesso' => false, 'mensagem' => 'O nome é obrigatório.']);
}
$stmt = $pdo->prepare('SELECT nome FROM fornecedores WHERE nome = ?');
$stmt->execute([$nome]);
if ($stmt->fetchColumn()) {
    responderJson(['sucesso' => false, 'mensagem' => 'Já existe um fornecedor com este nome. Pesquise por ele na busca.']);
}

$stmt = $pdo->prepare('INSERT INTO fornecedores (nome, documento, telefone, email, ativo) VALUES (?,?,?,?,1)');
$stmt->execute([$nome, $documento ?: null, $telefone ?: null, $email ?: null]);
$id = (int) $pdo->lastInsertId();
registrarAtividade('fornecedores', 'criacao', 'Fornecedor cadastrado (cadastro rápido): ' . $nome);

responderJson(['sucesso' => true, 'fornecedor' => ['id' => $id, 'nome' => $nome, 'documento' => $documento]]);
