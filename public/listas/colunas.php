<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();

// Salva as colunas visíveis (e a ordem) de uma lista para o usuário logado.
$pdo = getDB();
$tela = (string) ($_POST['tela'] ?? '');
$definicao = array_key_exists($tela, telasComColunas()) ? definicaoColunasTela($pdo, $tela) : [];
if (!$definicao) {
    http_response_code(400);
    exit('Lista inválida.');
}
$chaves = array_values(array_unique(array_filter((array) ($_POST['colunas'] ?? []), fn ($c) => isset($definicao[$c]))));
if (!$chaves) {
    flash('warning', 'Deixe pelo menos uma coluna visível.');
} else {
    salvarColunasVisiveis($pdo, $tela, $chaves);
}

// Volta para a lista (só endereço interno).
$voltar = (string) ($_POST['voltar'] ?? '/');
redirect(str_starts_with($voltar, '/') && !str_starts_with($voltar, '//') ? $voltar : '/');
