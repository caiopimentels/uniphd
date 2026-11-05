<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();

// Ações num post-it que o usuário pode ver: resolver / reabrir (quem cria
// post-its ou uma das pessoas escolhidas nele), excluir (quem escreveu ou o administrador) e
// "não me avisar mais" (cada um para si). Responde JSON quando chamado por
// fetch (o aviso que abre sozinho) e volta para a tela no formulário.
$pdo = getDB();
$id = (int) ($_POST['id'] ?? 0);
$acao = (string) ($_POST['acao'] ?? '');
$voltar = (string) ($_POST['voltar'] ?? '');

$responder = function (bool $ok, string $mensagem) use (&$voltar, &$postit) {
    if (ehRequisicaoAjax()) {
        responderJson(['sucesso' => $ok, 'mensagem' => $mensagem]);
    }
    if (!preg_match('#^/[^/\\\\]#', $voltar)) {
        $voltar = !empty($postit) ? '/alunos/form.php?id=' . (int) $postit['aluno_id'] : '/';
    }
    flash($ok ? 'success' : 'danger', $mensagem);
    redirect($voltar);
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $responder(false, 'Use os botões do post-it.');
}

$stmt = $pdo->prepare('SELECT p.*, a.nome AS aluno_nome FROM aluno_postits p JOIN alunos a ON a.id = p.aluno_id WHERE p.id = ?');
$stmt->execute([$id]);
$postit = $stmt->fetch();
if (!$postit || !usuarioVePostit($pdo, $id)) {
    $postit = null;
    $responder(false, 'Post-it não encontrado (talvez já tenha sido excluído).');
}

$usuarioId = (int) usuarioAtualId();
$avisado = postitAvisaUsuario($pdo, $id, $usuarioId, (int) ($_SESSION['user_perfil_id'] ?? 0));
$resumo = resumoHistoricoPostit($postit['texto'], $postit['visibilidade']);
$alunoId = (int) $postit['aluno_id'];
$matriculaId = $postit['matricula_id'] ? (int) $postit['matricula_id'] : null;

switch ($acao) {
    case 'resolver':
    case 'reabrir':
        if (!usuarioTemPermissao('usar_postits') && !$avisado) {
            $responder(false, 'Seu perfil não pode mexer neste post-it.');
        }
        $resolver = $acao === 'resolver';
        $pdo->prepare('UPDATE aluno_postits SET resolvido_em = ' . ($resolver ? 'NOW()' : 'NULL') . ', resolvido_por = ? WHERE id = ?')
            ->execute([$resolver ? usuarioAtualNome() : null, $id]);
        registrarHistoricoAluno($pdo, $alunoId, 'postit', ($resolver ? 'Resolvido: ' : 'Reaberto: ') . $resumo, $matriculaId);
        $responder(true, $resolver ? 'Post-it marcado como resolvido — não avisa mais ninguém.' : 'Post-it reaberto.');
        break;

    case 'excluir':
        if (!usuarioPodeAlterarPostit($postit)) {
            $responder(false, 'Só quem escreveu o post-it (ou o administrador) pode excluí-lo.');
        }
        $pdo->prepare('DELETE FROM aluno_postits WHERE id = ?')->execute([$id]);
        registrarAtividade('alunos', 'exclusao', 'Post-it excluído em ' . $postit['aluno_nome']);
        registrarHistoricoAluno($pdo, $alunoId, 'postit', 'Excluído: ' . $resumo, $matriculaId);
        $responder(true, 'Post-it excluído.');
        break;

    case 'dispensar':
        $pdo->prepare(
            'INSERT INTO aluno_postit_vistos (postit_id, usuario_id, vezes, dispensado_em) VALUES (?,?,0,NOW())
             ON DUPLICATE KEY UPDATE dispensado_em = NOW()'
        )->execute([$id, $usuarioId]);
        $responder(true, 'Este post-it não vai mais abrir para você.');
        break;

    default:
        $responder(false, 'Ação desconhecida.');
}
