<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

// Videoaulas do aluno (?m=matrícula, &v=código da aula): player grande,
// lista de aulas por seção ao lado, progresso do curso, continua de onde
// parou e passa para a próxima. ?previa=turma: a equipe vê como o aluno
// (sem guardar progresso).
$pdo = getDB();
$previaTurma = (int) ($_GET['previa'] ?? 0);
if ($previaTurma && usuarioLogado() && podeMaterialTurma($pdo, $previaTurma)) {
    $stmt = $pdo->prepare('SELECT 0 AS id, t.id AS turma_id, t.nome AS turma_nome, c.nome AS curso_nome FROM turmas t JOIN cursos c ON c.id = t.curso_id WHERE t.id = ?');
    $stmt->execute([$previaTurma]);
    $matricula = $stmt->fetch();
    $alunoId = null;
} else {
    exigirAluno();
    $alunoId = (int) alunoAtualId();
    $stmt = $pdo->prepare("SELECT m.id, m.turma_id, t.nome AS turma_nome, c.nome AS curso_nome FROM matriculas m JOIN turmas t ON t.id = m.turma_id JOIN cursos c ON c.id = t.curso_id
                           WHERE m.id = ? AND m.aluno_id = ? AND m.status IN (" . statusAcessoConteudoPortal($pdo) . ")");
    $stmt->execute([(int) ($_GET['m'] ?? 0), $alunoId]);
    $matricula = $stmt->fetch();
}
if (!$matricula) {
    flash('warning', 'Curso não encontrado.');
    redirect('/portal/meus_cursos.php');
}
$secoes = videoaulasDaTurma($pdo, (int) $matricula['turma_id'], true, $alunoId);
[$feitas, $total] = progressoVideoaulas($secoes);
$lista = array_merge(...array_values($secoes ?: [[]]));
// Aula aberta: a pedida, ou a primeira ainda não concluída.
$atual = null;
foreach ($lista as $v) {
    if (($_GET['v'] ?? '') === $v['codigo']) {
        $atual = $v;
    }
}
if (!$atual) {
    foreach ($lista as $v) {
        if (empty($v['concluido_em'])) {
            $atual = $v;
            break;
        }
    }
    $atual ??= $lista[0] ?? null;
}
$aulas = array_map(fn ($v) => [
    'codigo' => $v['codigo'], 'youtube' => $v['youtube_id'], 'titulo' => $v['titulo'], 'descricao' => (string) $v['descricao'], 'secao' => $v['secao'],
    'inicio' => !empty($v['concluido_em']) ? 0 : (int) ($v['posicao_seg'] ?? 0), 'concluida' => !empty($v['concluido_em']),
], $lista);

$tituloPagina = 'Videoaulas · ' . $matricula['curso_nome'];
require __DIR__ . '/../../includes/portal_header.php';
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/plyr/3.7.8/plyr.css">
<style>
    .va-topo { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 12px; margin-bottom: 16px; }
    .va-progresso { min-width: 240px; }
    .va-grade { display: grid; grid-template-columns: minmax(0, 1fr) 360px; gap: 20px; align-items: start; }
    @media (max-width: 992px) { .va-grade { grid-template-columns: 1fr; } }
    .va-player { position: relative; border-radius: 14px; overflow: hidden; background: #000; aspect-ratio: 16 / 9; }
    /* Esconde a barra de título e a logo do YouTube: o vídeo fica maior que a moldura. */
    .va-player .plyr__video-embed iframe { top: -50%; height: 200%; }
    .va-player .plyr { height: 100%; --plyr-color-main: var(--accent); }
    .va-player .plyr, .va-player .plyr__video-wrapper, .va-player .plyr__poster { background-color: #000; }
    .va-fim { position: absolute; inset: 0; background: rgba(0,0,0,.85); color: #fff; display: none; flex-direction: column; align-items: center; justify-content: center; text-align: center; gap: 12px; z-index: 5; padding: 20px; }
    .va-fim.ativo { display: flex; }
    .va-lista { background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb); border-radius: 14px; overflow: hidden; max-height: calc(100vh - 140px); overflow-y: auto; position: sticky; top: 80px; }
    .va-secao { padding: 10px 16px; font-weight: 700; font-size: 13px; background: #f8fafc; border-bottom: 1px solid var(--border, #e5e7eb); position: sticky; top: 0; z-index: 1; }
    .va-aula { display: flex; gap: 10px; align-items: flex-start; padding: 10px 16px; border-bottom: 1px solid #f1f5f9; cursor: pointer; color: inherit; text-decoration: none; }
    .va-aula:hover { background: var(--accent-soft); }
    .va-aula.atual { background: var(--accent-soft); box-shadow: inset 3px 0 0 var(--accent); }
    .va-aula .marca { font-size: 1.1rem; color: #94a3b8; flex: none; margin-top: 1px; }
    .va-aula.feita .marca { color: #16a34a; }
    .va-aula img { width: 96px; aspect-ratio: 16 / 9; object-fit: cover; border-radius: 6px; flex: none; }
    .va-aula .t { font-size: 14px; line-height: 1.3; }
</style>

<div class="va-topo">
    <div>
        <h1 class="portal-titulo mb-0">Videoaulas</h1>
        <p class="portal-sub mb-0"><?= e($matricula['curso_nome']) ?> · <?= e($matricula['turma_nome']) ?><?= $alunoId === null ? ' · <strong>prévia da equipe</strong> (não guarda progresso)' : '' ?></p>
    </div>
    <?php if ($total): ?>
        <div class="va-progresso">
            <div class="d-flex justify-content-between small mb-1"><span><strong id="feitas"><?= $feitas ?></strong> de <?= $total ?> aulas concluídas</span><span id="pct"><?= (int) round($feitas / $total * 100) ?>%</span></div>
            <div class="progress" style="height: 8px"><div class="progress-bar bg-success" id="barra" style="width: <?= $feitas / $total * 100 ?>%"></div></div>
        </div>
    <?php endif; ?>
</div>

<?php if (!$atual): ?>
    <div class="portal-card portal-vazio"><i class="bi bi-play-btn"></i>Ainda não há videoaulas para este curso.</div>
<?php else: ?>
<div class="va-grade">
    <div>
        <div class="va-player">
            <div id="player" data-plyr-provider="youtube" data-plyr-embed-id="<?= e($atual['youtube_id']) ?>"></div>
            <div class="va-fim" id="fim">
                <i class="bi bi-check-circle fs-1 text-success"></i>
                <div class="fs-5 fw-semibold">Aula concluída!</div>
                <div id="fim-proxima"></div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-outline-light" onclick="assistirDeNovo()"><i class="bi bi-arrow-counterclockwise"></i> Ver de novo</button>
                    <button type="button" class="btn btn-brand" id="botao-proxima" onclick="proxima()">Próxima aula <i class="bi bi-chevron-right"></i></button>
                </div>
            </div>
        </div>
        <div class="mt-3">
            <div class="small text-muted" id="aula-secao"></div>
            <h2 class="h5 mb-2" id="aula-titulo"></h2>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-sm btn-outline-secondary" id="botao-anterior" onclick="anterior()"><i class="bi bi-chevron-left"></i> Aula anterior</button>
                <button type="button" class="btn btn-sm btn-brand ms-auto" id="botao-seguinte" onclick="proxima()">Próxima aula <i class="bi bi-chevron-right"></i></button>
            </div>
        </div>
        <div class="portal-card mt-2 small" id="aula-descricao" style="white-space: pre-line; display: none"></div>
    </div>
    <div class="va-lista">
        <?php $i = 0; foreach ($secoes as $secao => $videos): ?>
            <div class="va-secao"><?= e($secao) ?></div>
            <?php foreach ($videos as $v): ?>
                <a class="va-aula <?= !empty($v['concluido_em']) ? 'feita' : '' ?>" data-i="<?= $i++ ?>" href="?<?= e(http_build_query(array_filter(['m' => $matricula['id'] ?: null, 'previa' => $previaTurma ?: null, 'v' => $v['codigo']]))) ?>">
                    <i class="bi <?= !empty($v['concluido_em']) ? 'bi-check-circle-fill' : 'bi-play-circle' ?> marca"></i>
                    <img src="<?= e(capaYoutube($v['youtube_id'])) ?>" alt="" loading="lazy">
                    <span><span class="t d-block"><?= e($v['titulo']) ?></span><?php if ($v['duracao_seg']): ?><span class="small text-muted"><?= duracaoLegivel((int) $v['duracao_seg']) ?></span><?php endif; ?></span>
                </a>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="mt-4"><a href="/portal/meus_cursos.php"><i class="bi bi-arrow-left"></i> Meus cursos</a></div>

<?php if ($atual): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/plyr/3.7.8/plyr.min.js"></script>
<script>
const AULAS = <?= json_encode($aulas, JSON_UNESCAPED_UNICODE) ?>;
const GUARDAR = <?= json_encode($alunoId !== null) ?>;
let indice = AULAS.findIndex((a) => a.codigo === <?= json_encode($atual['codigo']) ?>);
let ultimoEnvio = 0;
const player = new Plyr('#player', {
    youtube: { noCookie: true, rel: 0, showinfo: 0, iv_load_policy: 3, modestbranding: 1, playsinline: 1 },
    controls: ['play-large', 'play', 'progress', 'current-time', 'duration', 'mute', 'volume', 'settings', 'fullscreen'],
    settings: ['speed'],
    speed: { selected: 1, options: [0.75, 1, 1.25, 1.5, 1.75, 2] },
    i18n: { play: 'Assistir', pause: 'Pausar', mute: 'Sem som', unmute: 'Com som', enterFullscreen: 'Tela cheia', exitFullscreen: 'Sair da tela cheia', settings: 'Ajustes', speed: 'Velocidade', normal: 'Normal' },
});

function mostrarAula(i, tocar) {
    indice = i;
    const a = AULAS[i];
    document.getElementById('aula-titulo').textContent = a.titulo;
    document.getElementById('aula-secao').textContent = a.secao + ' · aula ' + (i + 1) + ' de ' + AULAS.length;
    const desc = document.getElementById('aula-descricao');
    desc.textContent = a.descricao;
    desc.style.display = a.descricao ? '' : 'none';
    document.getElementById('botao-anterior').disabled = i === 0;
    document.getElementById('botao-seguinte').disabled = i === AULAS.length - 1;
    document.querySelectorAll('.va-aula').forEach((el) => el.classList.toggle('atual', Number(el.dataset.i) === i));
    document.querySelector('.va-aula.atual')?.scrollIntoView({ block: 'nearest' });
    document.getElementById('fim').classList.remove('ativo');
    const url = new URL(location.href);
    url.searchParams.set('v', a.codigo);
    history.replaceState(null, '', url);
    if (tocar !== undefined) {
        player.source = { type: 'video', sources: [{ src: a.youtube, provider: 'youtube' }] };
        player.once('ready', () => { if (a.inicio > 5) player.currentTime = a.inicio; if (tocar) player.play(); });
    }
}

// Progresso: a cada 10 s tocando, ao pausar, ao trocar de aula e no fim.
async function enviarProgresso(forcar) {
    if (!GUARDAR) return;
    const agora = Date.now();
    if (!forcar && agora - ultimoEnvio < 10000) return;
    ultimoEnvio = agora;
    const a = AULAS[indice];
    const dados = new FormData();
    dados.append('c', a.codigo);
    dados.append('posicao', Math.floor(player.currentTime || 0));
    dados.append('duracao', Math.floor(player.duration || 0));
    try {
        const r = await (await fetch('/portal/video_progresso.php', { method: 'POST', body: dados, keepalive: true })).json();
        a.inicio = Math.floor(player.currentTime || 0);
        if (r.concluida && !a.concluida) marcarConcluida(indice);
    } catch (e) {}
}
function marcarConcluida(i) {
    AULAS[i].concluida = true;
    AULAS[i].inicio = 0;
    const el = document.querySelector(`.va-aula[data-i="${i}"]`);
    if (el) { el.classList.add('feita'); el.querySelector('.marca').className = 'bi bi-check-circle-fill marca'; }
    const feitas = AULAS.filter((a) => a.concluida).length;
    document.getElementById('feitas').textContent = feitas;
    document.getElementById('pct').textContent = Math.round(feitas / AULAS.length * 100) + '%';
    document.getElementById('barra').style.width = (feitas / AULAS.length * 100) + '%';
}
player.on('timeupdate', () => { if (player.playing) enviarProgresso(false); });
player.on('pause', () => enviarProgresso(true));
player.on('ended', () => {
    enviarProgresso(true);
    // Tela própria no fim (no lugar das sugestões do YouTube).
    const prox = AULAS[indice + 1];
    document.getElementById('fim-proxima').textContent = prox ? 'A seguir: ' + prox.titulo : 'Você chegou à última aula. 🎉';
    document.getElementById('botao-proxima').style.display = prox ? '' : 'none';
    document.getElementById('fim').classList.add('ativo');
});
player.once('ready', () => { const a = AULAS[indice]; if (a.inicio > 5) player.currentTime = a.inicio; });
window.addEventListener('pagehide', () => enviarProgresso(true));

function proxima() { if (indice < AULAS.length - 1) { enviarProgresso(true); mostrarAula(indice + 1, true); } }
function anterior() { if (indice > 0) { enviarProgresso(true); mostrarAula(indice - 1, true); } }
function assistirDeNovo() { document.getElementById('fim').classList.remove('ativo'); player.currentTime = 0; player.play(); }
document.querySelectorAll('.va-aula').forEach((el) => el.addEventListener('click', (ev) => {
    ev.preventDefault();
    enviarProgresso(true);
    mostrarAula(Number(el.dataset.i), true);
    window.scrollTo({ top: 0, behavior: 'smooth' });
}));
mostrarAula(indice);
</script>
<?php endif; ?>
<?php require __DIR__ . '/../../includes/portal_footer.php'; ?>
