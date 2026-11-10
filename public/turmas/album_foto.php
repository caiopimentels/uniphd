<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();

// A equipe/professor vê uma foto do álbum (?id=, &tam=mini para a
// miniatura, &baixar=1 para baixar o original). Quem edita o portal também
// vê, para escolher fotos do álbum para os banners.
$pdo = getDB();
$stmt = $pdo->prepare('SELECT * FROM turma_fotos WHERE id = ?');
$stmt->execute([(int) ($_GET['id'] ?? 0)]);
$foto = $stmt->fetch();
if (!$foto || !(podeAlbumTurma($pdo, (int) $foto['turma_id']) || usuarioTemPermissao('gerenciar_portal'))) {
    http_response_code(404);
    exit('Foto não encontrada.');
}
enviarFotoAlbum($pdo, $foto, ($_GET['tam'] ?? '') === 'mini' ? 'mini' : 'foto', !empty($_GET['baixar']));
