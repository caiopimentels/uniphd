<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responderJson(['sucesso' => false, 'mensagem' => 'Método inválido.']);
}

$novoTema = ($_POST['tema'] ?? '') === 'escuro' ? 'escuro' : 'claro';

$stmt = getDB()->prepare('UPDATE usuarios SET tema = ? WHERE id = ?');
$stmt->execute([$novoTema, usuarioAtualId()]);

$_SESSION['user_tema'] = $novoTema;

responderJson(['sucesso' => true, 'tema' => $novoTema]);
