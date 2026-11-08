<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirAluno();

// Uma foto do álbum da turma, pelo código (não pelo id): só para aluno com
// acesso ao conteúdo da turma (ativa, pendente, concluída) e foto não
// escondida. &tam=mini = miniatura; &baixar=1 = baixa o original.
$pdo = getDB();
$codigo = preg_replace('/[^a-f0-9]/', '', (string) ($_GET['c'] ?? ''));
$stmt = $pdo->prepare('SELECT f.* FROM turma_fotos f
                       WHERE f.codigo = ? AND f.visivel = 1
                         AND EXISTS (SELECT 1 FROM matriculas m WHERE m.turma_id = f.turma_id AND m.aluno_id = ? AND m.status IN (' . statusAcessoConteudoPortal($pdo) . '))');
$stmt->execute([$codigo, alunoAtualId()]);
$foto = $stmt->fetch();
if (!$foto) {
    http_response_code(404);
    exit('Foto não encontrada.');
}
enviarFotoAlbum($pdo, $foto, ($_GET['tam'] ?? '') === 'mini' ? 'mini' : 'foto', !empty($_GET['baixar']));
