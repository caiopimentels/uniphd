<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();

// Álbum de fotos da turma (?id=): enviar várias fotos de uma vez, legenda,
// esconder dos alunos, usar uma como capa da turma, baixar (uma, as
// marcadas ou todas num .zip) e excluir. Os alunos veem em Meus cursos ›
// Álbum. Equipe (editar turma) e professores da turma (materiais).
$pdo = getDB();
$turmaId = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT t.*, c.nome AS curso_nome FROM turmas t JOIN cursos c ON c.id = t.curso_id WHERE t.id = ?');
$stmt->execute([$turmaId]);
$turma = $stmt->fetch();
if (!$turma || !podeAlbumTurma($pdo, $turmaId)) {
    flash('danger', $turma ? 'Você não tem acesso ao álbum desta turma.' : 'Turma não encontrada.');
    redirect(usuarioTemPermissao('ver_cursos') ? '/turmas/listar.php' : '/materiais/index.php');
}
$fotos = fotosDaTurma($pdo, $turmaId);
$escondidas = count(array_filter($fotos, fn ($f) => !$f['visivel']));
$stmt = $pdo->prepare('SELECT COUNT(*) FROM matriculas WHERE turma_id = ? AND status IN (' . statusAcessoConteudoPortal($pdo) . ')');
$stmt->execute([$turmaId]);
$alunosVeem = (int) $stmt->fetchColumn();
$ftp = ftpMateriais($pdo);
$cota = cotaMateriais($pdo);
$capa = $turma['portal_imagem'] ?: null;
$veTurma = usuarioTemPermissao('ver_cursos');

$pageTitle = 'Álbum · ' . e($turma['nome']);
$pageBreadcrumb = 'Acadêmico · Turmas · ' . $turma['curso_nome'];
$activeMenu = $veTurma ? 'turmas' : 'materiais';
$pageAction = '<a href="' . ($veTurma ? '/turmas/visualizar.php?id=' . $turmaId : '/materiais/gerenciar.php?turma=' . $turmaId) . '" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Voltar</a>'
    . ($fotos ? '<a href="/turmas/album_zip.php?id=' . $turmaId . '" class="btn btn-outline-secondary" title="Todas as fotos num arquivo .zip"><i class="bi bi-file-earmark-zip"></i> Baixar todas</a>' : '')
    . '<button type="button" class="btn btn-brand" onclick="abrirEnvio()"><i class="bi bi-cloud-arrow-up"></i> Enviar fotos</button>';
require __DIR__ . '/../../includes/layout_header.php';
?>
<style>
    .album-grade { display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: 12px; }
    .album-foto { position: relative; border-radius: 10px; overflow: hidden; background: var(--bs-tertiary-bg); border: 2px solid transparent; }
    .album-foto.marcada { border-color: var(--accent); }
    .album-foto img { width: 100%; aspect-ratio: 1; object-fit: cover; display: block; cursor: zoom-in; }
    .album-foto.escondida img { opacity: .45; }
    .album-foto .marcar { position: absolute; top: 8px; left: 8px; width: 20px; height: 20px; cursor: pointer; }
    .album-foto .selos { position: absolute; top: 6px; right: 6px; display: flex; gap: 4px; }
    .album-foto .acoes { position: absolute; left: 0; right: 0; bottom: 0; display: flex; justify-content: center; gap: 2px; padding: 4px;
        background: linear-gradient(transparent, rgba(0,0,0,.65)); opacity: 0; transition: opacity .15s; }
    .album-foto:hover .acoes, .album-foto:focus-within .acoes { opacity: 1; }
    .album-foto .acoes .btn { color: #fff; padding: 2px 7px; }
    .album-legenda { font-size: 12px; padding: 4px 8px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    @media (hover: none) { .album-foto .acoes { opacity: 1; } }
    .zona-arquivos { border: 2px dashed var(--bs-border-color); border-radius: 12px; padding: 22px; text-align: center; cursor: pointer; }
    .zona-arquivos.sobre { border-color: var(--accent); background: var(--accent-soft); }
    #modal-ver .modal-content { background: #111; color: #fff; }
    #modal-ver img { max-width: 100%; max-height: 78vh; display: block; margin: 0 auto; }
    #modal-ver .seta { position: absolute; top: 50%; transform: translateY(-50%); font-size: 2rem; color: #fff; background: rgba(0,0,0,.35); border: 0; border-radius: 50%; width: 48px; height: 48px; line-height: 1; }
</style>

<div class="row g-3 mb-3">
    <div class="col-md-4 col-lg-3">
        <div class="card h-100"><div class="card-body">
            <div class="kpi-rotulo mb-2">Capa da turma</div>
            <?php if ($capa): ?>
                <img src="<?= e($capa) ?>" alt="" class="w-100 rounded" style="aspect-ratio: 16/9; object-fit: cover;">
            <?php else: ?>
                <div class="rounded d-flex align-items-center justify-content-center text-muted" style="aspect-ratio: 16/9; background: var(--bs-tertiary-bg)"><i class="bi bi-image fs-2"></i></div>
            <?php endif; ?>
            <div class="small text-muted mt-2">Aparece no site, em "Meus cursos" do aluno e na tela da turma. Para trocar, clique na <i class="bi bi-star"></i> de uma foto.</div>
        </div></div>
    </div>
    <div class="col-md-8 col-lg-9">
        <div class="card h-100"><div class="card-body">
            <div class="row g-3">
                <div class="col-6 col-lg-3"><div class="kpi-rotulo">Fotos</div><div class="kpi-valor"><?= count($fotos) ?></div><?php if ($escondidas): ?><div class="small text-muted"><?= $escondidas ?> escondida(s) dos alunos</div><?php endif; ?></div>
                <div class="col-6 col-lg-3"><div class="kpi-rotulo">Alunos que veem</div><div class="kpi-valor"><?= $alunosVeem ?></div><div class="small text-muted">no portal, em Meus cursos › Álbum</div></div>
                <div class="col-12 col-lg-6"><div class="kpi-rotulo">Espaço (materiais + fotos)</div>
                    <div class="progress mt-2" style="height: 8px"><div class="progress-bar <?= $cota['pct'] >= 90 ? 'bg-danger' : '' ?>" style="width: <?= $cota['pct'] ?>%"></div></div>
                    <div class="small text-muted mt-1"><?= tamanhoLegivel($cota['usado']) ?> de <?= tamanhoLegivel($cota['limite']) ?> · até <?= (int) $ftp['max_mb'] ?> MB por foto</div></div>
            </div>
        </div></div>
    </div>
</div>

<div class="secao">
    <div class="secao-titulo d-flex flex-wrap align-items-center gap-2">
        <i class="bi bi-images"></i> Fotos
        <?php if ($fotos): ?>
            <label class="form-check ms-3 mb-0 small fw-normal"><input type="checkbox" class="form-check-input" id="marcar-todas" onchange="marcarTodas(this.checked)"> <span class="form-check-label">Marcar todas</span></label>
            <span id="barra-marcadas" class="d-none ms-auto d-flex flex-wrap gap-1 align-items-center">
                <span class="small text-muted me-1" id="qtd-marcadas"></span>
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="acaoMarcadas('mostrar')"><i class="bi bi-eye"></i> Mostrar aos alunos</button>
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="acaoMarcadas('esconder')"><i class="bi bi-eye-slash"></i> Esconder</button>
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="baixarMarcadas()"><i class="bi bi-download"></i> Baixar</button>
                <button type="button" class="btn btn-sm btn-outline-danger" onclick="acaoMarcadas('excluir')"><i class="bi bi-trash"></i> Excluir</button>
            </span>
        <?php endif; ?>
    </div>
    <?php if (!$fotos): ?>
        <div class="card"><div class="card-body text-center text-muted py-5">
            <i class="bi bi-images fs-1 d-block mb-2"></i>
            Nenhuma foto ainda. Clique em <strong>Enviar fotos</strong> — dá para escolher ou arrastar várias de uma vez.
        </div></div>
    <?php else: ?>
        <div class="album-grade">
            <?php foreach ($fotos as $i => $f): $ehCapa = fotoEhCapa($f, $capa); ?>
                <div class="album-foto <?= $f['visivel'] ? '' : 'escondida' ?>" data-foto='<?= e(json_encode(['id' => (int) $f['id'], 'legenda' => (string) $f['legenda'], 'nome' => $f['arquivo_nome'], 'visivel' => (int) $f['visivel']], JSON_UNESCAPED_UNICODE)) ?>'>
                    <img src="/turmas/album_foto.php?id=<?= (int) $f['id'] ?>&amp;tam=mini" alt="<?= e($f['legenda'] ?: $f['arquivo_nome']) ?>" loading="lazy" onclick="verFoto(<?= $i ?>)">
                    <input type="checkbox" class="form-check-input marcar" onchange="atualizarMarcadas()" title="Marcar">
                    <div class="selos">
                        <?php if ($ehCapa): ?><span class="badge text-bg-warning"><i class="bi bi-star-fill"></i> Capa</span><?php endif; ?>
                        <?php if (!$f['visivel']): ?><span class="badge text-bg-secondary" title="Os alunos não veem"><i class="bi bi-eye-slash"></i> Escondida</span><?php endif; ?>
                    </div>
                    <div class="acoes">
                        <?php if (!$ehCapa): ?><button type="button" class="btn btn-sm btn-link" onclick="acao(this, 'capa')" title="Usar como capa da turma"><i class="bi bi-star"></i></button><?php endif; ?>
                        <button type="button" class="btn btn-sm btn-link" onclick="editarLegenda(this)" title="Legenda"><i class="bi bi-pencil"></i></button>
                        <button type="button" class="btn btn-sm btn-link" onclick="acao(this, '<?= $f['visivel'] ? 'esconder' : 'mostrar' ?>')" title="<?= $f['visivel'] ? 'Esconder dos alunos' : 'Mostrar aos alunos' ?>"><i class="bi <?= $f['visivel'] ? 'bi-eye-slash' : 'bi-eye' ?>"></i></button>
                        <a class="btn btn-sm btn-link" href="/turmas/album_foto.php?id=<?= (int) $f['id'] ?>&amp;baixar=1" title="Baixar"><i class="bi bi-download"></i></a>
                        <button type="button" class="btn btn-sm btn-link" onclick="acao(this, 'excluir')" title="Excluir"><i class="bi bi-trash"></i></button>
                    </div>
                    <?php if ($f['legenda']): ?><div class="album-legenda" title="<?= e($f['legenda']) ?>"><?= e($f['legenda']) ?></div><?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Enviar fotos -->
<div class="modal fade" id="modal-envio" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg">
        <form class="modal-content" onsubmit="enviar(event)">
            <div class="modal-header"><h5 class="modal-title"><i class="bi bi-cloud-arrow-up"></i> Enviar fotos</h5><button type="button" class="btn-close" data-bs-dismiss="modal" id="fechar-envio"></button></div>
            <div class="modal-body">
                <div class="zona-arquivos" id="zona" onclick="document.getElementById('arquivos').click()">
                    <i class="bi bi-images fs-2 d-block"></i>
                    <span id="zona-texto">Clique ou arraste as fotos aqui — várias de uma vez. JPG, PNG ou WEBP, até <?= (int) $ftp['max_mb'] ?> MB cada.</span>
                </div>
                <input type="file" id="arquivos" multiple hidden accept="image/jpeg,image/png,image/webp" onchange="mostrarArquivos()">
                <div class="mt-3">
                    <label class="form-label">Os alunos veem</label>
                    <div class="d-flex flex-wrap gap-3">
                        <label class="form-check"><input class="form-check-input" type="radio" name="visivel" value="1" checked> <span class="form-check-label">Já</span></label>
                        <label class="form-check"><input class="form-check-input" type="radio" name="visivel" value="0"> <span class="form-check-label">Escondidas (libero depois)</span></label>
                    </div>
                </div>
                <div class="mt-3 d-none" id="progresso"><div class="progress" style="height: 18px"><div class="progress-bar progress-bar-striped progress-bar-animated" id="barra" style="width: 0%"></div></div><div class="small text-muted mt-1" id="progresso-texto"></div></div>
                <div class="alert alert-danger mt-3 mb-0 d-none small" id="erros-envio"></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" id="cancelar-envio">Cancelar</button><button type="submit" class="btn btn-brand" id="botao-enviar"><i class="bi bi-cloud-arrow-up"></i> Enviar</button></div>
        </form>
    </div>
</div>

<!-- Legenda -->
<div class="modal fade" id="modal-legenda" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" onsubmit="salvarLegenda(event)">
            <div class="modal-header"><h5 class="modal-title">Legenda da foto</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <input type="hidden" id="legenda-id">
                <input type="text" id="legenda-texto" class="form-control" maxlength="255" placeholder="Ex.: Aula prática de 12/10">
                <div class="form-text">Os alunos veem a legenda embaixo da foto. Em branco: sem legenda.</div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-brand">Salvar</button></div>
        </form>
    </div>
</div>

<!-- Ver foto -->
<div class="modal fade" id="modal-ver" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header border-0"><div class="small" id="ver-contador"></div><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
            <div class="modal-body position-relative pt-0">
                <img id="ver-img" alt="">
                <button type="button" class="seta" style="left: 12px" onclick="passar(-1)" title="Anterior"><i class="bi bi-chevron-left"></i></button>
                <button type="button" class="seta" style="right: 12px" onclick="passar(1)" title="Próxima"><i class="bi bi-chevron-right"></i></button>
            </div>
            <div class="modal-footer border-0 justify-content-between"><div id="ver-legenda"></div><a id="ver-baixar" class="btn btn-sm btn-light"><i class="bi bi-download"></i> Baixar</a></div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../../includes/modal_confirmar.php'; ?>
<script>
const TURMA = <?= (int) $turmaId ?>;
const MAX_BYTES = <?= (int) $ftp['max_mb'] ?> * 1024 * 1024;
const LIVRE = <?= (int) $cota['livre'] ?>;
const fotos = [...document.querySelectorAll('.album-foto')].map((el) => JSON.parse(el.dataset.foto));
let atual = 0;

// Ver em tela grande, com setas (teclado também)
function verFoto(i) {
    atual = (i + fotos.length) % fotos.length;
    const f = fotos[atual];
    document.getElementById('ver-img').src = '/turmas/album_foto.php?id=' + f.id;
    document.getElementById('ver-legenda').textContent = f.legenda;
    document.getElementById('ver-contador').textContent = (atual + 1) + ' de ' + fotos.length + (f.visivel ? '' : ' · escondida dos alunos');
    document.getElementById('ver-baixar').href = '/turmas/album_foto.php?id=' + f.id + '&baixar=1';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-ver')).show();
}
function passar(d) { verFoto(atual + d); }
document.addEventListener('keydown', (ev) => {
    if (!document.getElementById('modal-ver').classList.contains('show')) return;
    if (ev.key === 'ArrowLeft') passar(-1);
    if (ev.key === 'ArrowRight') passar(1);
});

// Marcar várias
const marcadas = () => [...document.querySelectorAll('.album-foto')].filter((el) => el.querySelector('.marcar').checked).map((el) => JSON.parse(el.dataset.foto).id);
function atualizarMarcadas() {
    document.querySelectorAll('.album-foto').forEach((el) => el.classList.toggle('marcada', el.querySelector('.marcar').checked));
    const n = marcadas().length;
    document.getElementById('barra-marcadas')?.classList.toggle('d-none', !n);
    if (document.getElementById('qtd-marcadas')) document.getElementById('qtd-marcadas').textContent = n + ' marcada(s):';
}
function marcarTodas(sim) {
    document.querySelectorAll('.album-foto .marcar').forEach((c) => { c.checked = sim; });
    atualizarMarcadas();
}
function baixarMarcadas() { location.href = '/turmas/album_zip.php?id=' + TURMA + '&fotos=' + marcadas().join(','); }

async function postar(acao, ids, extra = {}) {
    const dados = new FormData();
    dados.append('turma_id', TURMA);
    dados.append('acao', acao);
    ids.forEach((id) => dados.append('ids[]', id));
    Object.entries(extra).forEach(([k, v]) => dados.append(k, v));
    const r = await (await fetch('/turmas/album_acao.php', { method: 'POST', body: dados, headers: { 'X-Requested-With': 'XMLHttpRequest' } })).json();
    if (r.sucesso) location.reload();
    else confirmarNoModal({ titulo: 'Não foi possível', html: escapeHtml(r.mensagem || 'Tente de novo.'), botao: 'Ok' });
}
async function confirmarExclusao(n) {
    return confirmarNoModal({ titulo: 'Excluir foto' + (n > 1 ? 's' : ''), html: (n > 1 ? `<strong>${n} fotos</strong> somem` : 'A foto some') + ' do álbum e para os alunos. Não dá para desfazer.', botao: 'Excluir', classeBotao: 'btn-danger' });
}
async function acao(botao, qual) {
    const f = JSON.parse(botao.closest('.album-foto').dataset.foto);
    if (qual === 'excluir' && !(await confirmarExclusao(1))) return;
    if (qual === 'capa' && !(await confirmarNoModal({ titulo: 'Usar como capa', html: 'Esta foto passa a ser a capa da turma no site, em "Meus cursos" dos alunos e na tela da turma.', botao: 'Usar como capa' }))) return;
    postar(qual, [f.id]);
}
async function acaoMarcadas(qual) {
    const ids = marcadas();
    if (qual === 'excluir' && !(await confirmarExclusao(ids.length))) return;
    postar(qual, ids);
}
function editarLegenda(botao) {
    const f = JSON.parse(botao.closest('.album-foto').dataset.foto);
    document.getElementById('legenda-id').value = f.id;
    document.getElementById('legenda-texto').value = f.legenda;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-legenda')).show();
    setTimeout(() => document.getElementById('legenda-texto').focus(), 300);
}
function salvarLegenda(ev) {
    ev.preventDefault();
    postar('legenda', [document.getElementById('legenda-id').value], { legenda: document.getElementById('legenda-texto').value });
}

// Enviar: uma foto por vez (barra "3 de 20"); as que falharem ficam listadas.
function abrirEnvio() {
    document.getElementById('arquivos').value = '';
    document.getElementById('erros-envio').classList.add('d-none');
    document.getElementById('progresso').classList.add('d-none');
    mostrarArquivos();
    bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-envio')).show();
}
function mostrarArquivos() {
    const f = [...document.getElementById('arquivos').files];
    const total = f.reduce((s, x) => s + x.size, 0);
    const grandes = f.filter((x) => x.size > MAX_BYTES).length;
    document.getElementById('zona-texto').innerHTML = f.length
        ? `<strong>${f.length} foto(s)</strong> escolhida(s) · ${(total / 1048576).toFixed(1).replace('.', ',')} MB` + (grandes ? ` · <span class="text-danger">${grandes} passa(m) de ${MAX_BYTES / 1048576} MB e não vão</span>` : '') + '<div class="small text-muted">Clique para escolher outras.</div>'
        : 'Clique ou arraste as fotos aqui — várias de uma vez. JPG, PNG ou WEBP, até ' + (MAX_BYTES / 1048576) + ' MB cada.';
}
const zona = document.getElementById('zona');
zona.addEventListener('dragover', (ev) => { ev.preventDefault(); zona.classList.add('sobre'); });
zona.addEventListener('dragleave', () => zona.classList.remove('sobre'));
zona.addEventListener('drop', (ev) => { ev.preventDefault(); zona.classList.remove('sobre'); document.getElementById('arquivos').files = ev.dataTransfer.files; mostrarArquivos(); });

function enviarUma(arquivo, visivel, aoProgresso) {
    return new Promise((resolve) => {
        const dados = new FormData();
        dados.append('turma_id', TURMA);
        dados.append('visivel', visivel);
        dados.append('foto', arquivo);
        const xhr = new XMLHttpRequest();
        xhr.open('POST', '/turmas/album_salvar.php');
        xhr.setRequestHeader('X-CSRF-Token', document.querySelector('meta[name=csrf-token]').content);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.upload.onprogress = (e) => { if (e.lengthComputable) aoProgresso(e.loaded / e.total); };
        xhr.onload = () => {
            let r = null;
            try { r = JSON.parse(xhr.responseText); } catch (e) {}
            resolve(r && r.sucesso ? null : ((r && r.mensagem) || `"${arquivo.name}": ` + (xhr.status === 413 ? 'grande demais para o servidor.' : 'não foi possível enviar.')));
        };
        xhr.onerror = () => resolve(`"${arquivo.name}": a conexão caiu.`);
        xhr.send(dados);
    });
}
async function enviar(ev) {
    ev.preventDefault();
    const todos = [...document.getElementById('arquivos').files];
    const erros = todos.filter((x) => x.size > MAX_BYTES).map((x) => `"${x.name}": passa de ${MAX_BYTES / 1048576} MB.`);
    const arquivos = todos.filter((x) => x.size <= MAX_BYTES);
    const caixaErros = document.getElementById('erros-envio');
    if (!arquivos.length) { caixaErros.innerHTML = erros.length ? erros.map(escapeHtml).join('<br>') : 'Escolha as fotos.'; caixaErros.classList.remove('d-none'); return; }
    if (arquivos.reduce((s, x) => s + x.size, 0) > LIVRE) { caixaErros.textContent = 'Sem espaço: sobram <?= e(tamanhoLegivel($cota['livre'])) ?> para materiais e fotos.'; caixaErros.classList.remove('d-none'); return; }
    const visivel = document.querySelector('[name=visivel]:checked').value;
    ['botao-enviar', 'cancelar-envio', 'fechar-envio'].forEach((id) => { document.getElementById(id).disabled = true; });
    caixaErros.classList.add('d-none');
    document.getElementById('progresso').classList.remove('d-none');
    const barra = document.getElementById('barra');
    let enviadas = 0;
    for (let i = 0; i < arquivos.length; i++) {
        document.getElementById('progresso-texto').textContent = `Enviando ${i + 1} de ${arquivos.length} — ${arquivos[i].name}`;
        const erro = await enviarUma(arquivos[i], visivel, (p) => {
            const pct = Math.round((i + p) / arquivos.length * 100);
            barra.style.width = pct + '%'; barra.textContent = pct + '%';
        });
        if (erro) erros.push(erro); else enviadas++;
    }
    if (!erros.length) { location.reload(); return; }
    ['botao-enviar', 'cancelar-envio', 'fechar-envio'].forEach((id) => { document.getElementById(id).disabled = false; });
    document.getElementById('progresso').classList.add('d-none');
    caixaErros.innerHTML = `<strong>${enviadas} de ${todos.length} foto(s) enviada(s).</strong> Não foram:<br>` + erros.map(escapeHtml).join('<br>');
    caixaErros.classList.remove('d-none');
    if (enviadas) document.getElementById('modal-envio').addEventListener('hidden.bs.modal', () => location.reload(), { once: true });
}
</script>
<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
