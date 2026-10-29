<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();

// Não lidas da caixa de entrada (ícone do envelope no topo). Guardado 60 s.
header('Content-Type: application/json');
if (!usuarioPodeUsarEmail() || !caixaUsuario(getDB(), (int) usuarioAtualId())['configurada']) {
    echo json_encode(['nao_lidas' => null]);
    exit;
}
// Libera a sessão antes de falar com o servidor de e-mail (não trava as outras abas).
$n = naoLidasEmail(getDB());
session_write_close();
echo json_encode(['nao_lidas' => $n]);
