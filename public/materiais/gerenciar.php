<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirPermissao('gerenciar_materiais', 'Seu perfil não envia materiais.');

// Materiais de uma turma (?turma=ID: os do curso + os só desta turma) ou de
// um curso (?curso=ID: valem para todas as turmas dele). Enviar arquivos
// (vários de uma vez) ou links, por local e módulo; liberar já, numa data
// ou deixar escondido; ver quem baixou. Na turma, a equipe também libera
// outros professores (ex.: um por módulo).
$pdo = getDB();
$turmaId = (int) ($_GET['turma'] ?? 0);
$cursoId = (int) ($_GET['curso'] ?? 0);
$turma = null;
if ($turmaId) {
    $stmt = $pdo->prepare('SELECT t.*, c.nome AS curso_nome, p.nome AS professor_nome FROM turmas t JOIN cursos c ON c.id = t.curso_id LEFT JOIN professores p ON p.id = t.professor_id WHERE t.id = ?');
    $stmt->execute([$turmaId]);
    $turma = $stmt->fetch();
    if (!$turma || !podeMaterialTurma($pdo, $turmaId)) {
        flash('danger', $turma ? 'Você não tem acesso aos materiais desta turma.' : 'Turma não encontrada.');
        redirect('/materiais/index.php');
    }
    $cursoId = (int) $turma['curso_id'];
}
$stmt = $pdo->prepare('SELECT * FROM cursos WHERE id = ?');
$stmt->execute([$cursoId]);
$curso = $stmt->fetch();
if (!$curso || (!$turma && !podeMaterialCurso($pdo, $cursoId))) {
    flash('danger', $curso ? 'Você não tem acesso aos materiais deste curso.' : 'Curso não encontrado.');
    redirect('/materiais/index.php');
}
$podeCurso = podeMaterialCurso($pdo, $cursoId);
$ehEquipe = professorDoUsuario($pdo) === null;

$materiais = $turma ? materiaisDaTurma($pdo, $turmaId) : materiaisDoCurso($pdo, $cursoId);
$doCurso = array_values(array_filter($materiais, fn ($m) => $m['curso_id']));
$daTurma = array_values(array_filter($materiais, fn ($m) => $m['turma_id']));
$locais = locaisMaterial($pdo);
$stmt = $pdo->prepare('SELECT id, nome FROM curso_modulos WHERE curso_id = ? ORDER BY ordem, id');
$stmt->execute([$cursoId]);
$modulos = $stmt->fetchAll();
$ftp = ftpMateriais($pdo);
$cota = cotaMateriais($pdo);

// Professores com acesso (turma): o principal e os liberados.
$professoresTurma = [];
$todosProfessores = [];
if ($turma && $ehEquipe) {
    $stmt = $pdo->prepare('SELECT p.id, p.nome, (SELECT COUNT(*) FROM usuarios u WHERE u.professor_id = p.id AND u.ativo = 1) AS tem_usuario
                           FROM turma_professores tp JOIN professores p ON p.id = tp.professor_id WHERE tp.turma_id = ? ORDER BY p.nome');
    $stmt->execute([$turmaId]);
    $professoresTurma = $stmt->fetchAll();
    $todosProfessores = $pdo->query('SELECT id, nome FROM professores WHERE ativo = 1 ORDER BY nome')->fetchAll();
}
$alunosNaTurma = 0;
if ($turma) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM matriculas WHERE turma_id = ? AND status IN ('ativa', 'pendente', 'concluida')");
    $stmt->execute([$turmaId]);
    $alunosNaTurma = (int) $stmt->fetchColumn();
}

$pageTitle = 'Materiais · ' . ($turma ? $turma['nome'] : $curso['nome']);
$pageBreadcrumb = 'Materiais · ' . ($turma ? $turma['curso_nome'] : 'Curso');
$activeMenu = 'materiais';
$pageAction = '<a href="/materiais/index.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Voltar</a>'
    . ($turma && $ehEquipe ? '<a href="/turmas/visualizar.php?id=' . $turmaId . '" class="btn btn-outline-secondary">Ver turma</a>' : '')
    . '<button type="button" class="btn btn-brand" onclick="abrirEnvio()"><i class="bi bi-cloud-arrow-up"></i> Enviar material</button>';
require __DIR__ . '/../../includes/layout_header.php';

$lista = function (array $itens, string $vazio) use ($pdo) {
    if (!$itens) {
        echo '<div class="text-muted small py-3 text-center">' . $vazio . '</div>';
        return;
    }
    $porLocal = [];
    foreach ($itens as $m) {
        $porLocal[$m['local_nome'] . '|' . $m['local_icone']][] = $m;
    }
    foreach ($porLocal as $chave => $grupo) {
        [$local, $icone] = explode('|', $chave);
        echo '<div class="material-local"><i class="bi ' . e($icone) . '"></i> ' . e($local) . ' <span class="text-muted fw-normal">(' . count($grupo) . ')</span></div>';
        foreach ($grupo as $m) {
            [$st, $cor] = situacaoMaterial($m);
            $pode = podeEditarMaterial($pdo, $m);
            echo '<div class="material-item" data-material=\'' . e(json_encode([
                'id' => (int) $m['id'], 'titulo' => $m['titulo'], 'descricao' => (string) $m['descricao'], 'local_id' => (int) $m['local_id'], 'modulo_id' => (int) $m['curso_modulo_id'],
                'tipo' => $m['tipo'], 'link' => (string) $m['link'], 'visivel' => (int) $m['visivel'], 'liberar_em' => $m['liberar_em'] ? str_replace(' ', 'T', substr($m['liberar_em'], 0, 16)) : '',
                'onde' => $m['turma_id'] ? 'turma' : 'curso', 'arquivo' => (string) $m['arquivo_nome'],
            ], JSON_UNESCAPED_UNICODE)) . '\'>';
            echo '<i class="bi ' . ($m['tipo'] === 'link' ? 'bi-link-45deg text-primary' : iconeArquivo($m['arquivo_tipo'], $m['arquivo_nome'])) . ' fs-4"></i>';
            echo '<div class="flex-grow-1" style="min-width:0"><div class="fw-semibold text-truncate">' . e($m['titulo']) . '</div><div class="small text-muted">';
            echo ($m['modulo_nome'] ? '<span class="badge text-bg-light border fw-normal me-1">' . e($m['modulo_nome']) . '</span>' : '')
                . ($m['tipo'] === 'link' ? e(parse_url($m['link'], PHP_URL_HOST) ?: 'link') : e($m['arquivo_nome']) . ' · ' . tamanhoLegivel((int) $m['tamanho']))
                . ' · ' . e($m['usuario_nome'] ?: '') . ' em ' . formatDate($m['criado_em']);
            echo '</div>' . ($m['descricao'] ? '<div class="small">' . e($m['descricao']) . '</div>' : '') . '</div>';
            echo '<span class="badge text-bg-' . $cor . '">' . e($st) . '</span>';
            echo '<button type="button" class="btn btn-sm btn-link text-secondary text-nowrap" onclick="verAcessos(' . (int) $m['id'] . ')" title="Quem abriu"><i class="bi bi-eye"></i> ' . (int) $m['alunos_acessaram'] . '</button>';
            echo '<a class="btn btn-sm btn-link text-secondary" href="/materiais/baixar.php?id=' . (int) $m['id'] . '" target="_blank" title="' . ($m['tipo'] === 'link' ? 'Abrir link' : 'Baixar') . '"><i class="bi ' . ($m['tipo'] === 'link' ? 'bi-box-arrow-up-right' : 'bi-download') . '"></i></a>';
            if ($pode) {
                echo '<button type="button" class="btn btn-sm btn-link text-secondary" onclick="editar(this)" title="Editar"><i class="bi bi-pencil"></i></button>'
                    . '<button type="button" class="btn btn-sm btn-link text-danger" onclick="excluir(this)" title="Excluir"><i class="bi bi-trash"></i></button>';
            }
            echo '</div>';
        }
    }
};
?>
<style>
    .material-local { font-weight: 600; margin: 14px 0 6px; color: var(--accent-dark, var(--accent)); }
    .material-local:first-child { margin-top: 0; }
    .material-item { display: flex; align-items: center; gap: 12px; padding: 10px 12px; border: 1px solid var(--bs-border-color); border-radius: 10px; margin-bottom: 6px; background: var(--bs-body-bg); }
    .zona-arquivos { border: 2px dashed var(--bs-border-color); border-radius: 12px; padding: 22px; text-align: center; cursor: pointer; }
    .zona-arquivos.sobre { border-color: var(--accent); background: var(--accent-soft); }
</style>

<div class="row g-3">
    <div class="<?= $turma && $ehEquipe ? 'col-xl-8' : 'col-12' ?>">
        <?php if ($turma): ?>
            <div class="secao">
                <div class="secao-titulo"><i class="bi bi-people"></i> Só desta turma <span class="small text-muted fw-normal ms-1"><?= e($turma['nome']) ?> · <?= $alunosNaTurma ?> aluno(s) veem</span></div>
                <?php $lista($daTurma, 'Nenhum material extra nesta turma. Use "Enviar material" e escolha "Só desta turma".'); ?>
            </div>
        <?php endif; ?>
        <div class="secao">
            <div class="secao-titulo"><i class="bi bi-journal-bookmark"></i> Do curso <span class="small text-muted fw-normal ms-1"><?= e($curso['nome']) ?> — aparece em todas as turmas dele</span></div>
            <?php $lista($doCurso, 'Nenhum material do curso ainda.'); ?>
        </div>
    </div>
    <?php if ($turma && $ehEquipe): ?>
    <div class="col-xl-4">
        <div class="secao">
            <div class="secao-titulo"><i class="bi bi-person-video3"></i> Professores com acesso</div>
            <p class="small text-muted">Professor com usuário no sistema (perfil "Professor", ligado ao cadastro dele em Usuários) envia e edita os materiais desta turma.</p>
            <div class="mb-2"><i class="bi bi-star-fill text-warning"></i> <?= $turma['professor_nome'] ? e($turma['professor_nome']) . ' <span class="small text-muted">(professor da turma)</span>' : '<span class="text-muted">Turma sem professor principal</span>' ?></div>
            <?php foreach ($professoresTurma as $p): ?>
                <div class="d-flex align-items-center gap-2 mb-1"><i class="bi bi-person"></i> <?= e($p['nome']) ?><?= $p['tem_usuario'] ? '' : ' <span class="badge text-bg-light border fw-normal" title="Sem usuário ligado: ainda não consegue entrar">sem usuário</span>' ?>
                    <button type="button" class="btn btn-sm btn-link text-danger ms-auto p-0" onclick="professor('tirar', <?= (int) $p['id'] ?>)" title="Tirar"><i class="bi bi-x-lg"></i></button></div>
            <?php endforeach; ?>
            <div class="input-group input-group-sm mt-2">
                <select id="novo-professor" class="form-select"><option value="">+ Liberar outro professor...</option>
                    <?php foreach ($todosProfessores as $p): if ((int) $p['id'] === (int) $turma['professor_id'] || in_array((int) $p['id'], array_map('intval', array_column($professoresTurma, 'id')), true)) continue; ?>
                        <option value="<?= (int) $p['id'] ?>"><?= e($p['nome']) ?></option>
                    <?php endforeach; ?></select>
                <button type="button" class="btn btn-outline-secondary" onclick="professor('liberar', document.getElementById('novo-professor').value)">Liberar</button>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Enviar / editar -->
<div class="modal fade" id="modal-material" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <form class="modal-content" id="form-material" onsubmit="enviar(event)">
            <div class="modal-header"><h5 class="modal-title" id="titulo-modal">Enviar material</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <input type="hidden" name="id" id="m-id">
                <input type="hidden" name="turma_id" value="<?= (int) $turmaId ?>">
                <input type="hidden" name="curso_id" value="<?= (int) $cursoId ?>">
                <div class="row g-3">
                    <?php if ($turma): ?>
                    <div class="col-12" id="bloco-onde">
                        <label class="form-label">Para quem</label>
                        <div class="d-flex flex-wrap gap-3">
                            <label class="form-check"><input class="form-check-input" type="radio" name="onde" value="turma" checked> <span class="form-check-label">Só desta turma (<?= e($turma['nome']) ?>)</span></label>
                            <label class="form-check"><input class="form-check-input" type="radio" name="onde" value="curso" <?= $podeCurso ? '' : 'disabled' ?>> <span class="form-check-label">Todas as turmas do curso</span></label>
                        </div>
                    </div>
                    <?php else: ?><input type="hidden" name="onde" value="curso"><?php endif; ?>
                    <div class="col-md-6">
                        <label class="form-label">Local *</label>
                        <select name="local_id" id="m-local" class="form-select" required>
                            <?php foreach ($locais as $l): ?><option value="<?= (int) $l['id'] ?>"><?= e($l['nome']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Módulo <span class="text-muted small">(opcional)</span></label>
                        <select name="curso_modulo_id" id="m-modulo" class="form-select"><option value="">— Geral —</option>
                            <?php foreach ($modulos as $mo): ?><option value="<?= (int) $mo['id'] ?>"><?= e($mo['nome']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12" id="bloco-tipo">
                        <div class="btn-group btn-group-sm" role="group">
                            <input type="radio" class="btn-check" name="tipo" id="tipo-arquivo" value="arquivo" checked onchange="trocarTipo()"><label class="btn btn-outline-secondary" for="tipo-arquivo"><i class="bi bi-file-earmark-arrow-up"></i> Arquivo(s)</label>
                            <input type="radio" class="btn-check" name="tipo" id="tipo-link" value="link" onchange="trocarTipo()"><label class="btn btn-outline-secondary" for="tipo-link"><i class="bi bi-link-45deg"></i> Link (vídeo, Drive...)</label>
                        </div>
                    </div>
                    <div class="col-12" id="bloco-arquivo">
                        <div class="zona-arquivos" id="zona" onclick="document.getElementById('m-arquivos').click()">
                            <i class="bi bi-cloud-arrow-up fs-2 d-block"></i>
                            <span id="zona-texto">Clique ou arraste os arquivos aqui — vários de uma vez (cada um vira um material). Até <?= (int) $ftp['max_mb'] ?> MB cada.</span>
                        </div>
                        <input type="file" id="m-arquivos" name="arquivos[]" multiple hidden onchange="mostrarArquivos()">
                        <div class="form-text" id="m-arquivo-atual"></div>
                        <div class="small mt-1 <?= $cota['pct'] >= 90 ? 'text-danger' : 'text-muted' ?>"><i class="bi bi-hdd"></i> Espaço: <?= tamanhoLegivel($cota['usado']) ?> de <?= tamanhoLegivel($cota['limite']) ?> usados (sobram <?= tamanhoLegivel($cota['livre']) ?>).</div>
                    </div>
                    <div class="col-12 d-none" id="bloco-link">
                        <label class="form-label">Endereço *</label>
                        <input type="url" name="link" id="m-link" class="form-control" placeholder="https://www.youtube.com/watch?v=...">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Título <span class="text-muted small" id="dica-titulo">(em branco: o nome do arquivo)</span></label>
                        <input type="text" name="titulo" id="m-titulo" class="form-control" maxlength="200">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Descrição <span class="text-muted small">(opcional)</span></label>
                        <textarea name="descricao" id="m-descricao" class="form-control" rows="2" maxlength="1000"></textarea>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Os alunos veem</label>
                        <div class="d-flex flex-wrap gap-3 align-items-center">
                            <label class="form-check"><input class="form-check-input" type="radio" name="liberacao" value="agora" checked onchange="trocarLiberacao()"> <span class="form-check-label">Já</span></label>
                            <label class="form-check"><input class="form-check-input" type="radio" name="liberacao" value="data" onchange="trocarLiberacao()"> <span class="form-check-label">A partir de</span></label>
                            <input type="datetime-local" name="liberar_em" id="m-liberar" class="form-control form-control-sm" style="width: auto" disabled>
                            <label class="form-check"><input class="form-check-input" type="radio" name="liberacao" value="escondido" onchange="trocarLiberacao()"> <span class="form-check-label">Escondido (só a equipe vê)</span></label>
                        </div>
                    </div>
                </div>
                <div class="mt-3 d-none" id="progresso"><div class="progress" style="height: 18px"><div class="progress-bar progress-bar-striped progress-bar-animated" id="barra" style="width: 0%">0%</div></div><div class="small text-muted mt-1" id="progresso-texto"></div></div>
                <div class="alert alert-danger mt-3 mb-0 d-none" id="erro-envio"></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-brand" id="botao-enviar"><i class="bi bi-cloud-arrow-up"></i> Enviar</button></div>
        </form>
    </div>
</div>

<div class="modal fade" id="modal-acessos" tabindex="-1">
    <div class="modal-dialog modal-dialog-scrollable"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">Quem abriu</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body" id="lista-acessos"></div>
    </div></div>
</div>

<?php require __DIR__ . '/../../includes/modal_confirmar.php'; ?>
<script>
const MAX_BYTES = <?= (int) $ftp['max_mb'] ?> * 1024 * 1024;
const TURMA = <?= (int) $turmaId ?>;
const CURSO = <?= (int) $cursoId ?>;
const modal = () => bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-material'));
function trocarTipo() {
    const link = document.getElementById('tipo-link').checked;
    document.getElementById('bloco-arquivo').classList.toggle('d-none', link);
    document.getElementById('bloco-link').classList.toggle('d-none', !link);
    document.getElementById('dica-titulo').textContent = link ? '*' : '(em branco: o nome do arquivo)';
}
function trocarLiberacao() {
    document.getElementById('m-liberar').disabled = document.querySelector('[name=liberacao]:checked').value !== 'data';
}
function mostrarArquivos() {
    const f = [...document.getElementById('m-arquivos').files];
    const grandes = f.filter((x) => x.size > MAX_BYTES);
    document.getElementById('zona-texto').innerHTML = f.length
        ? f.map((x) => `<div>${escapeHtml(x.name)} <span class="text-muted">(${(x.size / 1048576).toFixed(1).replace('.', ',')} MB)</span>${x.size > MAX_BYTES ? ' <span class="text-danger">— grande demais</span>' : ''}</div>`).join('')
        : 'Clique ou arraste os arquivos aqui.';
    document.getElementById('m-titulo').placeholder = f.length > 1 ? 'Vários arquivos: cada um com o nome dele' : '';
    document.getElementById('m-titulo').disabled = f.length > 1;
}
const zona = document.getElementById('zona');
zona.addEventListener('dragover', (ev) => { ev.preventDefault(); zona.classList.add('sobre'); });
zona.addEventListener('dragleave', () => zona.classList.remove('sobre'));
zona.addEventListener('drop', (ev) => { ev.preventDefault(); zona.classList.remove('sobre'); document.getElementById('m-arquivos').files = ev.dataTransfer.files; mostrarArquivos(); });

function limparForm() {
    const f = document.getElementById('form-material');
    f.reset();
    document.getElementById('m-id').value = '';
    document.getElementById('m-titulo').disabled = false;
    document.getElementById('m-arquivo-atual').textContent = '';
    document.getElementById('m-arquivos').setAttribute('multiple', '');
    document.getElementById('erro-envio').classList.add('d-none');
    document.getElementById('progresso').classList.add('d-none');
    document.getElementById('bloco-tipo').classList.remove('d-none');
    document.getElementById('bloco-onde')?.classList.remove('d-none');
    mostrarArquivos(); trocarTipo(); trocarLiberacao();
}
function abrirEnvio() {
    limparForm();
    document.getElementById('titulo-modal').textContent = 'Enviar material';
    modal().show();
}
function editar(botao) {
    const m = JSON.parse(botao.closest('.material-item').dataset.material);
    limparForm();
    document.getElementById('titulo-modal').textContent = 'Editar material';
    document.getElementById('m-id').value = m.id;
    document.getElementById('m-titulo').value = m.titulo;
    document.getElementById('m-descricao').value = m.descricao;
    document.getElementById('m-local').value = m.local_id;
    document.getElementById('m-modulo').value = m.modulo_id || '';
    document.getElementById('tipo-' + m.tipo).checked = true;
    document.getElementById('m-link').value = m.link;
    document.getElementById('bloco-onde')?.classList.add('d-none');
    document.querySelector(`[name=liberacao][value=${!m.visivel ? 'escondido' : (m.liberar_em ? 'data' : 'agora')}]`).checked = true;
    document.getElementById('m-liberar').value = m.liberar_em;
    trocarTipo(); trocarLiberacao();
    if (m.tipo === 'arquivo') {
        document.getElementById('m-arquivo-atual').textContent = 'Arquivo atual: ' + m.arquivo + ' — escolha outro só se quiser trocar.';
        document.getElementById('m-arquivos').removeAttribute('multiple');
    }
    modal().show();
}
function enviar(ev) {
    ev.preventDefault();
    const erro = document.getElementById('erro-envio');
    erro.classList.add('d-none');
    const arquivos = [...document.getElementById('m-arquivos').files];
    if (arquivos.reduce((s, x) => s + x.size, 0) > <?= (int) $cota['livre'] ?>) { erro.textContent = 'Sem espaço: sobram <?= e(tamanhoLegivel($cota['livre'])) ?> para materiais.'; erro.classList.remove('d-none'); return; }
    if (arquivos.some((x) => x.size > MAX_BYTES)) { erro.textContent = 'Algum arquivo passa do limite de ' + (MAX_BYTES / 1048576) + ' MB.'; erro.classList.remove('d-none'); return; }
    const dados = new FormData(document.getElementById('form-material'));
    const botao = document.getElementById('botao-enviar');
    botao.disabled = true;
    const xhr = new XMLHttpRequest();
    xhr.open('POST', '/materiais/salvar.php');
    xhr.setRequestHeader('X-CSRF-Token', document.querySelector('meta[name=csrf-token]').content);
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    if (arquivos.length) {
        document.getElementById('progresso').classList.remove('d-none');
        xhr.upload.onprogress = (e) => {
            if (!e.lengthComputable) return;
            const p = Math.round(e.loaded / e.total * 100);
            const barra = document.getElementById('barra');
            barra.style.width = p + '%'; barra.textContent = p + '%';
            document.getElementById('progresso-texto').textContent = p < 100 ? 'Enviando...' : 'Guardando os arquivos' + (<?= json_encode($ftp['destino'] === 'ftp') ?> ? ' no FTP' : '') + '...';
        };
    }
    xhr.onload = () => {
        botao.disabled = false;
        let r = null;
        try { r = JSON.parse(xhr.responseText); } catch (e) {}
        if (r && r.sucesso) { location.reload(); return; }
        erro.textContent = (r && r.mensagem) || (xhr.status === 413 ? 'Arquivo grande demais para o servidor.' : 'Não foi possível enviar (o servidor pode ter recusado o tamanho).');
        erro.classList.remove('d-none');
        document.getElementById('progresso').classList.add('d-none');
    };
    xhr.onerror = () => { botao.disabled = false; erro.textContent = 'A conexão caiu durante o envio.'; erro.classList.remove('d-none'); };
    xhr.send(dados);
}
async function excluir(botao) {
    const m = JSON.parse(botao.closest('.material-item').dataset.material);
    if (!(await confirmarNoModal({ titulo: 'Excluir material', html: `<strong>${escapeHtml(m.titulo)}</strong> some para os alunos${m.tipo === 'arquivo' ? ' e o arquivo é apagado' : ''}. Não dá para desfazer.`, botao: 'Excluir', classeBotao: 'btn-danger' }))) return;
    const dados = new FormData();
    dados.append('id', m.id);
    const r = await (await fetch('/materiais/excluir.php', { method: 'POST', body: dados })).json();
    if (r.sucesso) location.reload(); else confirmarNoModal({ titulo: 'Não foi possível', html: escapeHtml(r.mensagem || ''), botao: 'Ok' });
}
async function verAcessos(id) {
    const area = document.getElementById('lista-acessos');
    area.innerHTML = '<span class="text-muted small">Carregando...</span>';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-acessos')).show();
    const r = await (await fetch('/materiais/acessos.php?id=' + id)).json();
    area.innerHTML = r.alunos && r.alunos.length
        ? '<table class="table table-sm mb-0">' + r.alunos.map((a) => `<tr><td>${escapeHtml(a.nome)}</td><td class="small text-end text-muted">${a.vezes}x · ${escapeHtml(a.ultimo)}</td></tr>`).join('') + '</table>'
          + (r.faltam !== null ? `<div class="small text-muted mt-2">${r.faltam} aluno(s) da turma ainda não abriram.</div>` : '')
        : '<div class="text-muted small">Nenhum aluno abriu ainda.</div>';
}
async function professor(acao, professorId) {
    if (!professorId) return;
    const dados = new FormData();
    dados.append('acao', acao);
    dados.append('turma_id', TURMA);
    dados.append('professor_id', professorId);
    const r = await (await fetch('/materiais/professores.php', { method: 'POST', body: dados })).json();
    if (r.sucesso) location.reload();
}
</script>
<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
