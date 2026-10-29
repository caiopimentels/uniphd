<?php

// ---------------------------------------------------------------------
// Videoaulas (YouTube) — "curso online" / vídeos complementares. Do curso
// (todas as turmas) ou só da turma, em seções, na ordem escolhida. O aluno
// assiste no portal num player próprio (sem título, sem "assistir no
// YouTube", sem sugestões no fim; youtube-nocookie) e o progresso fica
// guardado (continua de onde parou; concluída a partir de 90%).
// Mesmas permissões e regras dos materiais (materiais.php).
// ---------------------------------------------------------------------

/** Código do vídeo (11 caracteres) a partir de qualquer link do YouTube, ou null. */
function extrairYoutubeId(string $texto): ?string
{
    $texto = trim($texto);
    if (preg_match('/^[A-Za-z0-9_-]{11}$/', $texto)) {
        return $texto;
    }
    $padroes = [
        '#youtu\.be/([A-Za-z0-9_-]{11})#',
        '#youtube(?:-nocookie)?\.com/(?:embed|shorts|live|v)/([A-Za-z0-9_-]{11})#',
        '#youtube\.com/.*[?&]v=([A-Za-z0-9_-]{11})#',
    ];
    foreach ($padroes as $p) {
        if (preg_match($p, $texto, $m)) {
            return $m[1];
        }
    }
    return null;
}

/**
 * Título do vídeo pelo oEmbed do YouTube (sem chave de API). null se não
 * achar — vídeo privado, apagado ou com incorporação desligada.
 */
function tituloYoutube(string $id): ?string
{
    $ch = curl_init('https://www.youtube.com/oembed?format=json&url=' . rawurlencode('https://www.youtube.com/watch?v=' . $id));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 5]);
    $resposta = curl_exec($ch);
    $codigo = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($codigo !== 200 || !$resposta) {
        return null;
    }
    $dados = json_decode($resposta, true);
    return is_array($dados) && !empty($dados['title']) ? mb_substr((string) $dados['title'], 0, 200) : null;
}

function capaYoutube(string $id, bool $grande = false): string
{
    return 'https://i.ytimg.com/vi/' . rawurlencode($id) . '/' . ($grande ? 'hqdefault' : 'mqdefault') . '.jpg';
}

function duracaoLegivel(?int $segundos): string
{
    if (!$segundos) {
        return '';
    }
    $h = intdiv($segundos, 3600);
    $m = intdiv($segundos % 3600, 60);
    $s = $segundos % 60;
    return $h ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%d:%02d', $m, $s);
}

/**
 * Vídeos de uma turma (os do curso + os da turma), na ordem: seção (pela
 * primeira aula dela) e depois a ordem de cada vídeo. $alunoId junta o
 * progresso dele; $soLiberadas = só o que o aluno pode ver.
 */
function videoaulasDaTurma(PDO $pdo, int $turmaId, bool $soLiberadas = false, ?int $alunoId = null): array
{
    $sql = 'SELECT v.*' . ($alunoId ? ', p.posicao_seg, p.maximo_seg, p.concluido_em' : '') . ',
                   (SELECT COUNT(*) FROM videoaula_progresso pp WHERE pp.videoaula_id = v.id) AS alunos_assistiram,
                   (SELECT COUNT(*) FROM videoaula_progresso pp WHERE pp.videoaula_id = v.id AND pp.concluido_em IS NOT NULL) AS alunos_concluiram
            FROM videoaulas v' . ($alunoId ? ' LEFT JOIN videoaula_progresso p ON p.videoaula_id = v.id AND p.aluno_id = ' . (int) $alunoId : '') . '
            WHERE (v.turma_id = ? OR v.curso_id = (SELECT curso_id FROM turmas WHERE id = ?))';
    if ($soLiberadas) {
        $sql .= ' AND v.visivel = 1 AND (v.liberar_em IS NULL OR v.liberar_em <= NOW())';
    }
    $stmt = $pdo->prepare($sql . ' ORDER BY v.ordem, v.id');
    $stmt->execute([$turmaId, $turmaId]);
    return ordenarPorSecao($stmt->fetchAll());
}

function videoaulasDoCurso(PDO $pdo, int $cursoId): array
{
    $stmt = $pdo->prepare('SELECT v.*,
                                  (SELECT COUNT(*) FROM videoaula_progresso pp WHERE pp.videoaula_id = v.id) AS alunos_assistiram,
                                  (SELECT COUNT(*) FROM videoaula_progresso pp WHERE pp.videoaula_id = v.id AND pp.concluido_em IS NOT NULL) AS alunos_concluiram
                           FROM videoaulas v WHERE v.curso_id = ? ORDER BY v.ordem, v.id');
    $stmt->execute([$cursoId]);
    return ordenarPorSecao($stmt->fetchAll());
}

// Agrupa mantendo a ordem: cada seção entra onde aparece a 1ª aula dela.
function ordenarPorSecao(array $videos): array
{
    $secoes = [];
    foreach ($videos as $v) {
        $secoes[$v['secao']][] = $v;
    }
    return $secoes;
}

/** Seções já usadas no curso (sugestões ao cadastrar), mais os módulos do curso. */
function secoesSugeridas(PDO $pdo, int $cursoId): array
{
    $stmt = $pdo->prepare('SELECT DISTINCT v.secao FROM videoaulas v LEFT JOIN turmas t ON t.id = v.turma_id WHERE v.curso_id = ? OR t.curso_id = ? ORDER BY v.secao');
    $stmt->execute([$cursoId, $cursoId]);
    $secoes = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $stmt = $pdo->prepare('SELECT nome FROM curso_modulos WHERE curso_id = ? ORDER BY ordem, id');
    $stmt->execute([$cursoId]);
    return array_values(array_unique([...$secoes, ...$stmt->fetchAll(PDO::FETCH_COLUMN), 'Vídeos complementares']));
}

/**
 * Guarda onde o aluno parou. Concluída quando passa de 90% (ou faltam
 * menos de 20 s). Também aprende a duração do vídeo.
 */
function registrarProgressoVideo(PDO $pdo, array $video, int $alunoId, int $posicao, int $duracao): array
{
    $duracao = max(0, min($duracao, 6 * 3600));
    $posicao = max(0, min($posicao, $duracao ?: $posicao));
    if ($duracao && !$video['duracao_seg']) {
        $pdo->prepare('UPDATE videoaulas SET duracao_seg = ? WHERE id = ?')->execute([$duracao, $video['id']]);
    }
    $concluiu = $duracao > 0 && ($posicao >= $duracao * 0.9 || $duracao - $posicao <= 20);
    $pdo->prepare('INSERT INTO videoaula_progresso (videoaula_id, aluno_id, posicao_seg, maximo_seg, concluido_em) VALUES (?, ?, ?, ?, ?)
                   ON DUPLICATE KEY UPDATE posicao_seg = VALUES(posicao_seg), maximo_seg = GREATEST(maximo_seg, VALUES(maximo_seg)),
                       concluido_em = COALESCE(concluido_em, VALUES(concluido_em))')
        ->execute([$video['id'], $alunoId, $posicao, $posicao, $concluiu ? date('Y-m-d H:i:s') : null]);
    return ['concluida' => $concluiu];
}

/** Resumo do aluno numa turma: [assistidas, total]. */
function progressoVideoaulas(array $secoes): array
{
    $total = 0;
    $feitas = 0;
    foreach ($secoes as $videos) {
        foreach ($videos as $v) {
            $total++;
            $feitas += !empty($v['concluido_em']) ? 1 : 0;
        }
    }
    return [$feitas, $total];
}
