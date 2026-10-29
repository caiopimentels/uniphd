<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirPermissaoAcao('gerenciar_materiais', 'Seu perfil não envia materiais.');

// Videoaulas (JSON): adicionar (um ou vários links), editar, excluir,
// reordenar (arrastar) e ?acao=progresso (quem assistiu um vídeo).
$pdo = getDB();
$acao = (string) ($_REQUEST['acao'] ?? 'salvar');

$buscar = function (int $id) use ($pdo): ?array {
    $stmt = $pdo->prepare('SELECT * FROM videoaulas WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
};

if ($acao === 'progresso') {
    $video = $buscar((int) ($_GET['id'] ?? 0));
    $pode = $video && ($video['turma_id'] ? podeMaterialTurma($pdo, (int) $video['turma_id']) : podeMaterialCurso($pdo, (int) $video['curso_id']));
    if (!$pode) {
        responderJson(['sucesso' => false, 'alunos' => []]);
    }
    // Professor: só os alunos das turmas dele.
    $prof = professorDoUsuario($pdo);
    $filtro = $prof !== null ? ' AND EXISTS (SELECT 1 FROM matriculas m WHERE m.aluno_id = a.id AND m.turma_id IN (' . implode(',', turmasDoProfessor($pdo, $prof) ?: [0]) . '))' : '';
    $stmt = $pdo->prepare("SELECT a.nome, p.maximo_seg, p.concluido_em FROM videoaula_progresso p JOIN alunos a ON a.id = p.aluno_id WHERE p.videoaula_id = ? $filtro ORDER BY p.concluido_em IS NULL, a.nome");
    $stmt->execute([$video['id']]);
    $duracao = max(1, (int) $video['duracao_seg']);
    $alunos = array_map(fn ($l) => ['nome' => $l['nome'], 'concluido' => (bool) $l['concluido_em'], 'pct' => $l['concluido_em'] ? 100 : min(99, (int) round($l['maximo_seg'] / $duracao * 100))], $stmt->fetchAll());
    $faltam = null;
    if ($video['turma_id']) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM matriculas m WHERE m.turma_id = ? AND m.status IN ('ativa', 'pendente', 'concluida')
                               AND NOT EXISTS (SELECT 1 FROM videoaula_progresso p WHERE p.videoaula_id = ? AND p.aluno_id = m.aluno_id)");
        $stmt->execute([$video['turma_id'], $video['id']]);
        $faltam = (int) $stmt->fetchColumn();
    }
    responderJson(['sucesso' => true, 'alunos' => $alunos, 'faltam' => $faltam]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responderJson(['sucesso' => false]);
}

if ($acao === 'excluir') {
    $video = $buscar((int) ($_POST['id'] ?? 0));
    if (!$video || !podeEditarMaterial($pdo, $video)) {
        responderJson(['sucesso' => false, 'mensagem' => 'Você não pode excluir esta videoaula.']);
    }
    $pdo->prepare('DELETE FROM videoaulas WHERE id = ?')->execute([$video['id']]);
    registrarAtividade('materiais', 'exclusao', 'Videoaula "' . $video['titulo'] . '" excluída');
    responderJson(['sucesso' => true]);
}

if ($acao === 'ordem') {
    // Só mexe nos que este usuário pode editar; os outros ficam onde estão.
    $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
    $secoes = (array) ($_POST['secoes'] ?? []);
    $atualizar = $pdo->prepare('UPDATE videoaulas SET ordem = ?, secao = ? WHERE id = ?');
    foreach ($ids as $i => $id) {
        $video = $buscar($id);
        if ($video && podeEditarMaterial($pdo, $video)) {
            $atualizar->execute([($i + 1) * 10, mb_substr(trim((string) ($secoes[$i] ?? $video['secao'])), 0, 120) ?: $video['secao'], $id]);
        }
    }
    responderJson(['sucesso' => true]);
}

// Salvar (novo ou edição)
$liberacao = (string) ($_POST['liberacao'] ?? 'agora');
$liberarEm = $liberacao === 'data' && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', (string) ($_POST['liberar_em'] ?? '')) ? str_replace('T', ' ', $_POST['liberar_em']) . ':00' : null;
if ($liberacao === 'data' && !$liberarEm) {
    responderJson(['sucesso' => false, 'mensagem' => 'Informe a data de liberação.']);
}
$visivel = $liberacao === 'escondido' ? 0 : 1;
$secao = mb_substr(trim((string) ($_POST['secao'] ?? '')), 0, 120) ?: 'Aulas';
$titulo = mb_substr(trim((string) ($_POST['titulo'] ?? '')), 0, 200);
$descricao = mb_substr(trim((string) ($_POST['descricao'] ?? '')), 0, 2000) ?: null;

$id = (int) ($_POST['id'] ?? 0);
if ($id) {
    $video = $buscar($id);
    if (!$video || !podeEditarMaterial($pdo, $video)) {
        responderJson(['sucesso' => false, 'mensagem' => 'Você não pode editar esta videoaula.']);
    }
    $pdo->prepare('UPDATE videoaulas SET titulo = ?, descricao = ?, secao = ?, visivel = ?, liberar_em = ? WHERE id = ?')
        ->execute([$titulo ?: $video['titulo'], $descricao, $secao, $visivel, $liberarEm, $id]);
    registrarAtividade('materiais', 'edicao', 'Videoaula "' . ($titulo ?: $video['titulo']) . '" editada');
    responderJson(['sucesso' => true]);
}

// Novo(s): um vídeo por linha.
$onde = ($_POST['onde'] ?? '') === 'curso' ? 'curso' : 'turma';
$turmaId = (int) ($_POST['turma_id'] ?? 0);
$cursoId = (int) ($_POST['curso_id'] ?? 0);
if ($onde === 'turma' ? !$turmaId || !podeMaterialTurma($pdo, $turmaId) : !$cursoId || !podeMaterialCurso($pdo, $cursoId)) {
    responderJson(['sucesso' => false, 'mensagem' => 'Você não pode adicionar vídeos aqui.']);
}
$linhas = array_values(array_filter(array_map('trim', preg_split('/[\r\n\s,]+/', (string) ($_POST['links'] ?? '')))));
if (!$linhas) {
    responderJson(['sucesso' => false, 'mensagem' => 'Cole pelo menos um link do YouTube.']);
}
if (count($linhas) > 50) {
    responderJson(['sucesso' => false, 'mensagem' => 'No máximo 50 vídeos de uma vez.']);
}
$videos = [];
$problemas = [];
foreach ($linhas as $linha) {
    $yt = extrairYoutubeId($linha);
    if (!$yt) {
        $problemas[] = 'Não é link do YouTube: ' . mb_strimwidth($linha, 0, 60, '…');
        continue;
    }
    $tituloYt = tituloYoutube($yt);
    if ($tituloYt === null) {
        $problemas[] = 'Vídeo indisponível (privado, apagado ou com incorporação desligada): ' . mb_strimwidth($linha, 0, 60, '…');
        continue;
    }
    $videos[] = ['id' => $yt, 'titulo' => count($linhas) === 1 && $titulo !== '' ? $titulo : $tituloYt];
}
if ($problemas) {
    responderJson(['sucesso' => false, 'mensagem' => implode("\n", $problemas) . ($videos ? "\n\nCorrija ou tire essas linhas e salve de novo." : '')]);
}

// Vão para o fim da lista.
$stmt = $pdo->prepare('SELECT COALESCE(MAX(ordem), 0) FROM videoaulas WHERE ' . ($onde === 'turma' ? 'turma_id = ? OR curso_id = (SELECT curso_id FROM turmas WHERE id = ?)' : 'curso_id = ?'));
$stmt->execute($onde === 'turma' ? [$turmaId, $turmaId] : [$cursoId]);
$ordem = (int) $stmt->fetchColumn();
$inserir = $pdo->prepare('INSERT INTO videoaulas (curso_id, turma_id, secao, titulo, descricao, youtube_id, ordem, codigo, visivel, liberar_em, usuario_id, usuario_nome) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
foreach ($videos as $v) {
    $ordem += 10;
    $inserir->execute([$onde === 'curso' ? $cursoId : null, $onde === 'turma' ? $turmaId : null, $secao, $v['titulo'], $descricao, $v['id'], $ordem,
        bin2hex(random_bytes(16)), $visivel, $liberarEm, usuarioAtualId(), usuarioAtualNome()]);
}
registrarAtividade('materiais', 'criacao', count($videos) . ' videoaula(s) adicionada(s) em "' . $secao . '"');
responderJson(['sucesso' => true, 'criados' => count($videos)]);
