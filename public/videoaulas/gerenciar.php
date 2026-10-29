<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirPermissao('gerenciar_materiais', 'Seu perfil não envia materiais.');

// Videoaulas (curso online) de uma turma (?turma=ID: as do curso + as só da turma) ou de
// um curso (?curso=ID). Colar um ou vários links do YouTube (título e capa
// vêm sozinhos), organizar em seções, arrastar para mudar a ordem, liberar
// já / numa data / esconder, e ver quem assistiu.
$pdo = getDB();
$turmaId = (int) ($_GET['turma'] ?? 0);
$cursoId = (int) ($_GET['curso'] ?? 0);
$turma = null;
if ($turmaId) {
    $stmt = $pdo->prepare('SELECT t.*, c.nome AS curso_nome FROM turmas t JOIN cursos c ON c.id = t.curso_id WHERE t.id = ?');
    $stmt->execute([$turmaId]);
    $turma = $stmt->fetch();
    if (!$turma || !podeMaterialTurma($pdo, $turmaId)) {
        flash('danger', $turma ? 'Você não tem acesso a esta turma.' : 'Turma não encontrada.');
        redirect('/videoaulas/index.php');
    }
    $cursoId = (int) $turma['curso_id'];
}
$stmt = $pdo->prepare('SELECT * FROM cursos WHERE id = ?');
$stmt->execute([$cursoId]);
$curso = $stmt->fetch();
if (!$curso || (!$turma && !podeMaterialCurso($pdo, $cursoId))) {
    flash('danger', $curso ? 'Você não tem acesso a este curso.' : 'Curso não encontrado.');
    redirect('/videoaulas/index.php');
}
$podeCurso = podeMaterialCurso($pdo, $cursoId);
$secoes = $turma ? videoaulasDaTurma($pdo, $turmaId) : videoaulasDoCurso($pdo, $cursoId);
$sugestoes = secoesSugeridas($pdo, $cursoId);
$alunosNaTurma = 0;
if ($turma) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM matriculas WHERE turma_id = ? AND status IN ('ativa', 'pendente', 'concluida')");
    $stmt->execute([$turmaId]);
    $alunosNaTurma = (int) $stmt->fetchColumn();
}

$pageTitle = 'Videoaulas · ' . ($turma ? $turma['nome'] : $curso['nome']);
$pageBreadcrumb = 'Videoaulas · ' . ($turma ? $turma['curso_nome'] : 'Curso');
$activeMenu = 'videoaulas';
$pageAction = '<a href="/videoaulas/index.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Voltar</a>'
    . ($turma ? '<a href="/portal/videoaulas.php?previa=' . $turmaId . '" target="_blank" class="btn btn-outline-secondary"><i class="bi bi-eye"></i> Ver como o aluno</a>' : '')
    . '<button type="button" class="btn btn-brand" onclick="abrirNovo()"><i class="bi bi-plus-lg"></i> Adicionar vídeos</button>';
require __DIR__ . '/../../includes/layout_header.php';
?>
<style>
    .vid-secao { background: var(--bs-body-bg); border: 1px solid var(--bs-border-color); border-radius: 14px; margin-bottom: 16px; overflow: hidden; }
    .vid-secao-topo { display: flex; align-items: center; gap: 10px; padding: 12px 16px; background: var(--bs-tertiary-bg); border-bottom: 1px solid var(--bs-border-color); font-weight: 600; }
    .vid-item { display: flex; align-items: center; gap: 14px; padding: 10px 16px; border-bottom: 1px solid var(--bs-border-color-translucent); background: var(--bs-body-bg); }
    .vid-item:last-child { border-bottom: 0; }
    .vid-alca { cursor: grab; color: var(--bs-secondary-color); font-size: 1.1rem; }
    .vid-capa { width: 120px; aspect-ratio: 16 / 9; border-radius: 8px; object-fit: cover; flex: none; background: #000; position: relative; }
    .vid-capa-box { position: relative; flex: none; }
    .vid-capa-box .dur { position: absolute; right: 4px; bottom: 4px; background: rgba(0,0,0,.8); color: #fff; font-size: 11px; padding: 0 4px; border-radius: 3px; }
    .vid-item .info { flex: 1; min-width: 0; }
    .vid-fantasma { opacity: .4; }
    .vid-onde { font-size: 11px; padding: 1px 7px; border-radius: 10px; border: 1px solid var(--bs-border-color); color: var(--bs-secondary-color); white-space: nowrap; }
</style>

<div class="d-flex flex-wrap gap-2 align-items-center mb-3 small text-muted">
    <i class="bi bi-info-circle"></i>
    <span>Arraste <i class="bi bi-grip-vertical"></i> para mudar a ordem. <?= $turma ? 'Aparecem para os ' . $alunosNaTurma . ' aluno(s) desta turma — os marcados "curso" valem para todas as turmas.' : 'Vídeos do curso valem para todas as turmas dele.' ?>
        Sem propaganda: use vídeos do seu canal como <strong>"Não listado"</strong> e sem monetização.</span>
</div>

<?php if (!$secoes): ?>
    <div class="secao text-center py-5">
        <i class="bi bi-play-btn fs-1 text-muted d-block mb-2"></i>
        <h6>Nenhuma videoaula ainda</h6>
        <p class="text-muted small">Cole os links do YouTube — um ou vários de uma vez. Título e capa vêm sozinhos.</p>
        <button type="button" class="btn btn-brand" onclick="abrirNovo()"><i class="bi bi-plus-lg"></i> Adicionar vídeos</button>
    </div>
<?php endif; ?>

<div id="secoes">
<?php foreach ($secoes as $secao => $videos): ?>
    <div class="vid-secao">
        <div class="vid-secao-topo"><i class="bi bi-collection-play"></i> <?= e($secao) ?> <span class="text-muted fw-normal small">(<?= count($videos) ?>)</span></div>
        <div class="vid-lista" data-secao="<?= e($secao) ?>">
            <?php foreach ($videos as $v): [$st, $cor] = situacaoMaterial($v); $pode = podeEditarMaterial($pdo, $v); ?>
                <div class="vid-item" data-id="<?= (int) $v['id'] ?>" data-video='<?= e(json_encode(['id' => (int) $v['id'], 'titulo' => $v['titulo'], 'descricao' => (string) $v['descricao'], 'secao' => $v['secao'],
                    'visivel' => (int) $v['visivel'], 'liberar_em' => $v['liberar_em'] ? str_replace(' ', 'T', substr($v['liberar_em'], 0, 16)) : '', 'youtube' => $v['youtube_id']], JSON_UNESCAPED_UNICODE)) ?>'>
                    <?php if ($pode): ?><i class="bi bi-grip-vertical vid-alca" title="Arraste para mudar a ordem"></i><?php else: ?><i class="bi bi-lock text-muted" title="Enviado por outra pessoa"></i><?php endif; ?>
                    <a class="vid-capa-box" href="https://www.youtube.com/watch?v=<?= e($v['youtube_id']) ?>" target="_blank" rel="noopener" title="Abrir no YouTube">
                        <img class="vid-capa" src="<?= e(capaYoutube($v['youtube_id'])) ?>" alt="" loading="lazy">
                        <?php if ($v['duracao_seg']): ?><span class="dur"><?= duracaoLegivel((int) $v['duracao_seg']) ?></span><?php endif; ?>
                    </a>
                    <div class="info">
                        <div class="fw-semibold text-truncate"><?= e($v['titulo']) ?></div>
                        <div class="small text-muted"><span class="vid-onde"><?= $v['turma_id'] ? 'turma' : 'curso' ?></span> · <?= e($v['usuario_nome'] ?? '') ?> em <?= formatDate($v['criado_em']) ?></div>
                    </div>
                    <span class="badge text-bg-<?= $cor ?>"><?= e($st) ?></span>
                    <button type="button" class="btn btn-sm btn-link text-secondary text-nowrap" onclick="verProgresso(<?= (int) $v['id'] ?>)" title="Quem assistiu"><i class="bi bi-people"></i> <?= (int) $v['alunos_concluiram'] ?>/<?= (int) $v['alunos_assistiram'] ?></button>
                    <?php if ($pode): ?>
                        <button type="button" class="btn btn-sm btn-link text-secondary" onclick="editar(this)" title="Editar"><i class="bi bi-pencil"></i></button>
                        <button type="button" class="btn btn-sm btn-link text-danger" onclick="excluir(this)" title="Excluir"><i class="bi bi-trash"></i></button>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endforeach; ?>
</div>

<div class="modal fade" id="modal-video" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg">
        <form class="modal-content" id="form-video" onsubmit="salvar(event)">
            <div class="modal-header"><h5 class="modal-title" id="titulo-modal">Adicionar vídeos</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <input type="hidden" name="id" id="v-id">
                <input type="hidden" name="turma_id" value="<?= (int) $turmaId ?>">
                <input type="hidden" name="curso_id" value="<?= (int) $cursoId ?>">
                <div class="row g-3">
                    <div class="col-12" id="bloco-links">
                        <label class="form-label">Links do YouTube *</label>
                        <textarea name="links" id="v-links" class="form-control" rows="4" placeholder="https://www.youtube.com/watch?v=...&#10;https://youtu.be/...&#10;(um por linha — cada um vira uma aula, na ordem)"></textarea>
                        <div class="form-text">Funciona com links normais, curtos (youtu.be), Shorts e lives. O vídeo precisa estar Público ou <strong>Não listado</strong> (Privado não toca).</div>
                    </div>
                    <div class="col-12 d-none" id="bloco-capa"><div class="d-flex gap-3 align-items-center"><img id="v-capa" class="vid-capa" src="" alt=""><div class="small text-muted">Para trocar o vídeo, exclua e adicione outro.</div></div></div>
                    <?php if ($turma): ?>
                    <div class="col-12" id="bloco-onde">
                        <label class="form-label">Para quem</label>
                        <div class="d-flex flex-wrap gap-3">
                            <label class="form-check"><input class="form-check-input" type="radio" name="onde" value="turma" checked> <span class="form-check-label">Só desta turma</span></label>
                            <label class="form-check"><input class="form-check-input" type="radio" name="onde" value="curso" <?= $podeCurso ? '' : 'disabled' ?>> <span class="form-check-label">Todas as turmas do curso</span></label>
                        </div>
                    </div>
                    <?php else: ?><input type="hidden" name="onde" value="curso"><?php endif; ?>
                    <div class="col-md-6">
                        <label class="form-label">Seção</label>
                        <input type="text" name="secao" id="v-secao" class="form-control" maxlength="120" list="lista-secoes" value="Aulas" required>
                        <datalist id="lista-secoes"><?php foreach ($sugestoes as $sg): ?><option value="<?= e($sg) ?>"><?php endforeach; ?></datalist>
                        <div class="form-text">Agrupa as aulas (ex.: "Módulo 1", "Vídeos complementares").</div>
                    </div>
                    <div class="col-md-6" id="bloco-titulo">
                        <label class="form-label">Título <span class="text-muted small" id="dica-titulo">(em branco: o do YouTube)</span></label>
                        <input type="text" name="titulo" id="v-titulo" class="form-control" maxlength="200">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Descrição <span class="text-muted small">(opcional — aparece embaixo do vídeo)</span></label>
                        <textarea name="descricao" id="v-descricao" class="form-control" rows="2" maxlength="2000"></textarea>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Os alunos veem</label>
                        <div class="d-flex flex-wrap gap-3 align-items-center">
                            <label class="form-check"><input class="form-check-input" type="radio" name="liberacao" value="agora" checked onchange="trocarLiberacao()"> <span class="form-check-label">Já</span></label>
                            <label class="form-check"><input class="form-check-input" type="radio" name="liberacao" value="data" onchange="trocarLiberacao()"> <span class="form-check-label">A partir de</span></label>
                            <input type="datetime-local" name="liberar_em" id="v-liberar" class="form-control form-control-sm" style="width: auto" disabled>
                            <label class="form-check"><input class="form-check-input" type="radio" name="liberacao" value="escondido" onchange="trocarLiberacao()"> <span class="form-check-label">Escondido</span></label>
                        </div>
                    </div>
                </div>
                <div class="alert alert-danger mt-3 mb-0 d-none" id="erro"></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-brand" id="botao-salvar"><i class="bi bi-check-lg"></i> Salvar</button></div>
        </form>
    </div>
</div>

<div class="modal fade" id="modal-progresso" tabindex="-1">
    <div class="modal-dialog modal-dialog-scrollable"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">Quem assistiu</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body" id="lista-progresso"></div>
    </div></div>
</div>

<?php require __DIR__ . '/../../includes/modal_confirmar.php'; ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.2/Sortable.min.js"></script>
<script>
const modal = () => bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-video'));
function trocarLiberacao() { document.getElementById('v-liberar').disabled = document.querySelector('[name=liberacao]:checked').value !== 'data'; }
function limpar() {
    document.getElementById('form-video').reset();
    document.getElementById('v-id').value = '';
    document.getElementById('erro').classList.add('d-none');
    document.getElementById('bloco-links').classList.remove('d-none');
    document.getElementById('bloco-capa').classList.add('d-none');
    document.getElementById('bloco-onde')?.classList.remove('d-none');
    document.getElementById('dica-titulo').textContent = '(em branco: o do YouTube)';
    trocarLiberacao();
}
function abrirNovo() { limpar(); document.getElementById('titulo-modal').textContent = 'Adicionar vídeos'; modal().show(); setTimeout(() => document.getElementById('v-links').focus(), 300); }
function editar(botao) {
    const v = JSON.parse(botao.closest('.vid-item').dataset.video);
    limpar();
    document.getElementById('titulo-modal').textContent = 'Editar videoaula';
    document.getElementById('v-id').value = v.id;
    document.getElementById('v-titulo').value = v.titulo;
    document.getElementById('v-descricao').value = v.descricao;
    document.getElementById('v-secao').value = v.secao;
    document.getElementById('bloco-links').classList.add('d-none');
    document.getElementById('bloco-onde')?.classList.add('d-none');
    document.getElementById('bloco-capa').classList.remove('d-none');
    document.getElementById('v-capa').src = 'https://i.ytimg.com/vi/' + v.youtube + '/mqdefault.jpg';
    document.getElementById('dica-titulo').textContent = '';
    document.querySelector(`[name=liberacao][value=${!v.visivel ? 'escondido' : (v.liberar_em ? 'data' : 'agora')}]`).checked = true;
    document.getElementById('v-liberar').value = v.liberar_em;
    trocarLiberacao();
    modal().show();
}
async function salvar(ev) {
    ev.preventDefault();
    const botao = document.getElementById('botao-salvar');
    const erro = document.getElementById('erro');
    botao.disabled = true;
    botao.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Buscando no YouTube...';
    try {
        const r = await (await fetch('/videoaulas/salvar.php', { method: 'POST', body: new FormData(document.getElementById('form-video')) })).json();
        if (r.sucesso) { location.reload(); return; }
        erro.innerHTML = escapeHtml(r.mensagem || 'Não foi possível salvar.').replace(/\n/g, '<br>');
        erro.classList.remove('d-none');
    } catch (e) {
        erro.textContent = 'Não foi possível salvar agora.';
        erro.classList.remove('d-none');
    }
    botao.disabled = false;
    botao.innerHTML = '<i class="bi bi-check-lg"></i> Salvar';
}
async function excluir(botao) {
    const v = JSON.parse(botao.closest('.vid-item').dataset.video);
    if (!(await confirmarNoModal({ titulo: 'Excluir videoaula', html: `<strong>${escapeHtml(v.titulo)}</strong> sai da lista dos alunos (o vídeo continua no YouTube).`, botao: 'Excluir', classeBotao: 'btn-danger' }))) return;
    const dados = new FormData();
    dados.append('acao', 'excluir');
    dados.append('id', v.id);
    const r = await (await fetch('/videoaulas/salvar.php', { method: 'POST', body: dados })).json();
    if (r.sucesso) location.reload();
}
async function verProgresso(id) {
    const area = document.getElementById('lista-progresso');
    area.innerHTML = '<span class="small text-muted">Carregando...</span>';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-progresso')).show();
    const r = await (await fetch('/videoaulas/salvar.php?acao=progresso&id=' + id)).json();
    area.innerHTML = r.alunos && r.alunos.length
        ? '<table class="table table-sm align-middle mb-0">' + r.alunos.map((a) => `<tr><td>${escapeHtml(a.nome)}</td><td style="width: 45%"><div class="progress" style="height: 6px"><div class="progress-bar ${a.concluido ? 'bg-success' : ''}" style="width: ${a.pct}%"></div></div></td><td class="small text-end text-nowrap">${a.concluido ? '<span class="text-success"><i class="bi bi-check2"></i> concluiu</span>' : a.pct + '%'}</td></tr>`).join('') + '</table>'
          + (r.faltam !== null ? `<div class="small text-muted mt-2">${r.faltam} aluno(s) da turma ainda não abriram.</div>` : '')
        : '<div class="small text-muted">Nenhum aluno assistiu ainda.</div>';
}
// Arrastar para reordenar (dentro da seção ou para outra seção).
document.querySelectorAll('.vid-lista').forEach((lista) => new Sortable(lista, {
    group: 'videos', handle: '.vid-alca', animation: 150, ghostClass: 'vid-fantasma',
    onEnd: async () => {
        const dados = new FormData();
        dados.append('acao', 'ordem');
        document.querySelectorAll('.vid-lista').forEach((l) => l.querySelectorAll('.vid-item').forEach((item) => {
            dados.append('ids[]', item.dataset.id);
            dados.append('secoes[]', l.dataset.secao);
        }));
        await fetch('/videoaulas/salvar.php', { method: 'POST', body: dados });
    },
}));
</script>
<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
