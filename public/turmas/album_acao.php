<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();

// Ações nas fotos do álbum (JSON): legenda, mostrar/esconder dos alunos,
// usar como capa da turma, pôr no banner do portal, excluir. ids[] = uma ou várias fotos da turma.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responderJson(['sucesso' => false]);
}
$pdo = getDB();
$turmaId = (int) ($_POST['turma_id'] ?? 0);
if (!$turmaId || !podeAlbumTurma($pdo, $turmaId)) {
    responderJson(['sucesso' => false, 'mensagem' => 'Você não pode mexer no álbum desta turma.']);
}
$ids = array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])));
$fotos = array_values(array_filter(fotosDaTurma($pdo, $turmaId), fn ($f) => in_array((int) $f['id'], $ids, true)));
if (!$fotos) {
    responderJson(['sucesso' => false, 'mensagem' => 'Foto não encontrada.']);
}
$lista = implode(',', array_map(fn ($f) => (int) $f['id'], $fotos));

switch ((string) ($_POST['acao'] ?? '')) {
    case 'legenda':
        $legenda = mb_substr(trim((string) ($_POST['legenda'] ?? '')), 0, 255) ?: null;
        $pdo->prepare('UPDATE turma_fotos SET legenda = ? WHERE id = ?')->execute([$legenda, $fotos[0]['id']]);
        responderJson(['sucesso' => true]);

    case 'mostrar':
    case 'esconder':
        $pdo->exec('UPDATE turma_fotos SET visivel = ' . ($_POST['acao'] === 'mostrar' ? 1 : 0) . " WHERE id IN ($lista)");
        registrarAtividade('materiais', 'edicao', count($fotos) . ' foto(s) do álbum da turma #' . $turmaId . ($_POST['acao'] === 'mostrar' ? ' liberadas para os alunos' : ' escondidas dos alunos'));
        responderJson(['sucesso' => true]);

    case 'capa':
        $r = usarFotoComoCapa($pdo, $fotos[0]);
        if ($r['ok']) {
            registrarAtividade('turmas', 'edicao', 'Capa da turma #' . $turmaId . ' trocada por uma foto do álbum');
        }
        responderJson(['sucesso' => $r['ok'], 'mensagem' => $r['mensagem']]);

    case 'banner':
        // Copia para o carrossel de um banner do site (Portal › Site do portal).
        if (!usuarioTemPermissao('gerenciar_portal')) {
            responderJson(['sucesso' => false, 'mensagem' => 'Seu perfil não pode editar o portal do aluno.']);
        }
        $blocoId = (int) ($_POST['bloco_id'] ?? 0);
        if ($blocoId && !in_array($blocoId, array_map('intval', array_column(bannersPortal($pdo), 'id')), true)) {
            responderJson(['sucesso' => false, 'mensagem' => 'Banner não encontrado.']);
        }
        $blocoId = $blocoId ?: criarBannerPortal($pdo);
        $link = !empty($_POST['link_turma']) ? '/portal/turma.php?id=' . $turmaId : null;
        $erros = [];
        foreach ($fotos as $f) {
            $r = fotoParaBanner($pdo, $f, $blocoId, $link);
            if (!$r['ok']) {
                $erros[] = $r['mensagem'];
            }
        }
        $foram = count($fotos) - count($erros);
        if ($foram) {
            registrarAtividade('portal', 'edicao', $foram . ' foto(s) do álbum da turma #' . $turmaId . ' colocadas no banner do portal');
        }
        responderJson(['sucesso' => $foram > 0, 'mensagem' => implode(' ', array_unique($erros)), 'bloco_id' => $blocoId, 'foram' => $foram]);

    case 'excluir':
        $pdo->exec("DELETE FROM turma_fotos WHERE id IN ($lista)");
        foreach ($fotos as $f) {
            apagarFotoAlbum($pdo, $f);
        }
        registrarAtividade('materiais', 'exclusao', count($fotos) . ' foto(s) excluída(s) do álbum da turma #' . $turmaId);
        responderJson(['sucesso' => true]);
}
responderJson(['sucesso' => false, 'mensagem' => 'Ação desconhecida.']);
