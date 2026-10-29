<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirPermissaoAcao('usar_email', 'Seu perfil não usa o e-mail pelo sistema.');

// Ações na caixa do usuário (JSON): lida, nao_lida, importante,
// nao_importante, mover (destino) e excluir (vai para a Lixeira; na
// Lixeira, apaga de vez).
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responderJson(['sucesso' => false]);
}
$pdo = getDB();
$acao = (string) ($_POST['acao'] ?? '');
$pasta = (string) ($_POST['pasta'] ?? 'INBOX');
$uids = array_values(array_filter(array_map('intval', (array) ($_POST['uids'] ?? []))));
if (!$uids) {
    responderJson(['sucesso' => false, 'mensagem' => 'Nenhum e-mail selecionado.']);
}
try {
    $pastas = pastasCaixa($pdo);
    $nomes = array_column($pastas, 'nome');
    if (!in_array($pasta, $nomes, true)) {
        responderJson(['sucesso' => false, 'mensagem' => 'Pasta não encontrada.']);
    }
    $imap = abrirCaixa($pdo);
    $imap->abrirPasta($pasta);
    switch ($acao) {
        case 'lida':
        case 'nao_lida':
            $imap->marcar($uids, '\\Seen', $acao === 'lida');
            break;
        case 'importante':
        case 'nao_importante':
            $imap->marcar($uids, '\\Flagged', $acao === 'importante');
            break;
        case 'mover':
            $destino = (string) ($_POST['destino'] ?? '');
            if (!in_array($destino, $nomes, true) || $destino === $pasta) {
                responderJson(['sucesso' => false, 'mensagem' => 'Escolha outra pasta.']);
            }
            $imap->mover($uids, $destino);
            break;
        case 'excluir':
            if (pastaEspecial($pastas, 'lixeira') === $pasta) {
                $imap->apagarDeVez($uids);
            } else {
                $imap->mover($uids, garantirPastaEspecial($pdo, 'lixeira'));
            }
            break;
        default:
            responderJson(['sucesso' => false, 'mensagem' => 'Ação desconhecida.']);
    }
    esquecerNaoLidasEmail();
    responderJson(['sucesso' => true]);
} catch (RuntimeException $e) {
    responderJson(['sucesso' => false, 'mensagem' => $e->getMessage()]);
}
