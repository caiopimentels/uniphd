<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirPermissao('disparar_email', 'Seu perfil não pode disparar e-mails em massa.');

// Novo disparo em massa: para quem (turma, curso, alunos em andamento,
// todos ou escolhidos), de qual e-mail, assunto e texto com tags
// ({nome_aluno}...), anexos. Antes: contagem, prévia com um aluno e envio
// de teste para mim. ?turma=ID já escolhe a turma.
$pdo = getDB();
$cfg = emailConfig($pdo);
$config = buscarConfiguracoes($pdo);
$minhaCaixa = caixaUsuario($pdo, (int) usuarioAtualId());
$sistemaPronto = $config['smtp_host'] && (trim((string) $config['smtp_remetente_email']) ?: trim((string) $config['smtp_usuario']));

$dados = ['publico' => !empty($_GET['turma']) ? 'turma' : 'turma', 'ids' => array_filter([(int) ($_GET['turma'] ?? 0)]), 'remetente' => $cfg['disparo_remetente'],
    'assunto' => '', 'html' => '<p>Olá, {primeiro_nome}!</p><p><br></p>'];
$erro = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dados = [
        'publico' => array_key_exists(input('publico'), publicosDisparo()) ? input('publico') : 'turma',
        'ids' => array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['ids'] ?? []))))),
        'remetente' => input('remetente') === 'sistema' ? 'sistema' : 'usuario',
        'assunto' => trim((string) ($_POST['assunto'] ?? '')),
        'html' => (string) ($_POST['html'] ?? ''),
    ];
    $html = limparHtmlContrato(salvarImagensColadasAviso($dados['html']), true);
    [$anexos, $erro] = anexosDoFormulario($_FILES['anexos'] ?? [], $cfg['anexo_max_mb']);
    $destinos = destinatariosDisparo($pdo, $dados['publico'], $dados['ids']);
    if (!$erro && in_array($dados['publico'], ['turma', 'curso', 'alunos'], true) && !$dados['ids']) {
        $erro = 'Escolha para quem vai o disparo.';
    }
    if (!$erro && $dados['assunto'] === '') {
        $erro = 'Coloque o assunto.';
    }
    if (!$erro && trim(strip_tags($html, '<img>')) === '') {
        $erro = 'Escreva a mensagem.';
    }
    if (!$erro && !$destinos) {
        $erro = 'Nenhum aluno com e-mail nesse grupo.';
    }
    if (!$erro && $dados['remetente'] === 'usuario' && !$minhaCaixa['configurada']) {
        $erro = 'Sua caixa de e-mail não está configurada: escolha "e-mail do sistema" ou configure sua caixa.';
    }
    if (!$erro && $dados['remetente'] === 'sistema' && !$sistemaPronto) {
        $erro = 'O e-mail do sistema não está configurado (Configurações › Sistema).';
    }

    // Só testar: manda para o próprio usuário (pelo mesmo e-mail do disparo),
    // com as tags do 1º aluno.
    if (!$erro && ($_POST['acao'] ?? '') === 'teste') {
        $valores = valoresTagsEmail($pdo, $destinos[0]['aluno_id'], $destinos[0]['turma_id'], $config);
        $servidor = servidorDisparoEmail($pdo, ['remetente' => $dados['remetente'], 'usuario_id' => usuarioAtualId()], $cfg, $config);
        $paraMim = $minhaCaixa['email'] ?: strtolower((string) ($_SESSION['user_email'] ?? ''));
        $mensagem = EmailMime::montar([
            'de' => $servidor['de'],
            'para' => [['nome' => usuarioAtualNome(), 'email' => $paraMim]],
            'assunto' => '[TESTE] ' . aplicarTagsEmail($dados['assunto'], $valores, false),
            'html' => imagensLocaisEmbutidas(aplicarTagsEmail($html, $valores)),
            'anexos' => $anexos,
        ]);
        $r = $paraMim ? enviarMensagemSmtp($servidor['smtp'], $servidor['de']['email'], [$paraMim], $mensagem['bruto']) : ['sucesso' => false, 'mensagem' => 'Seu usuário não tem e-mail.'];
        responderJson(['sucesso' => $r['sucesso'], 'mensagem' => $r['sucesso'] ? 'Teste enviado para ' . $paraMim . ' (com os dados de ' . $destinos[0]['nome'] . ').' : $r['mensagem']]);
    }
    if ($erro && ($_POST['acao'] ?? '') === 'teste') {
        responderJson(['sucesso' => false, 'mensagem' => $erro]);
    }

    if (!$erro) {
        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO email_disparos (assunto, corpo_html, publico, filtro, remetente, usuario_id, usuario_nome, total) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([mb_substr($dados['assunto'], 0, 250), $html, $dados['publico'], json_encode($dados['ids']), $dados['remetente'], usuarioAtualId(), usuarioAtualNome(), count($destinos)]);
        $id = (int) $pdo->lastInsertId();
        $stmt = $pdo->prepare('INSERT INTO email_disparo_destinos (disparo_id, aluno_id, turma_id, nome, email) VALUES (?,?,?,?,?)');
        foreach ($destinos as $d) {
            $stmt->execute([$id, $d['aluno_id'], $d['turma_id'], $d['nome'], $d['email']]);
        }
        // Anexos: guardados fora da pasta pública, um por arquivo.
        $guardados = [];
        if ($anexos) {
            $dir = BASE_PATH . EMAIL_PASTA_DISPAROS . '/' . $id;
            @mkdir($dir, 0700, true);
            foreach ($anexos as $i => $a) {
                $arquivo = $i . '-' . bin2hex(random_bytes(6)) . '.bin';
                file_put_contents($dir . '/' . $arquivo, $a['conteudo']);
                $guardados[] = ['nome' => $a['nome'], 'tipo' => $a['tipo'], 'arquivo' => $arquivo];
            }
            $pdo->prepare('UPDATE email_disparos SET anexos = ? WHERE id = ?')->execute([json_encode($guardados, JSON_UNESCAPED_UNICODE), $id]);
        }
        $pdo->commit();
        registrarAtividade('email', 'criacao', 'Disparo de e-mail "' . $dados['assunto'] . '" para ' . count($destinos) . ' aluno(s)');
        flash('success', 'Disparo criado: ' . count($destinos) . ' e-mail(s) na fila. Eles saem aos poucos (até ' . $cfg['disparo_por_hora'] . ' por hora).');
        redirect('/email/disparo.php?id=' . $id);
    }
}

$opcoes = [
    'turma' => array_map(fn ($t) => ['id' => (int) $t['id'], 'nome' => $t['curso_nome'] . ' · ' . $t['nome'], 'detalhe' => (int) $t['alunos'] . ' aluno(s) em andamento'],
        $pdo->query("SELECT t.id, t.nome, c.nome AS curso_nome, (SELECT COUNT(*) FROM matriculas m WHERE m.turma_id = t.id AND m.status IN ('ativa', 'pendente')) AS alunos
                     FROM turmas t JOIN cursos c ON c.id = t.curso_id ORDER BY t.data_inicio IS NULL, t.data_inicio DESC, c.nome, t.nome")->fetchAll()),
    'curso' => array_map(fn ($c) => ['id' => (int) $c['id'], 'nome' => $c['nome'], 'detalhe' => $c['ativo'] ? 'Curso' : 'Curso inativo'],
        $pdo->query('SELECT id, nome, ativo FROM cursos ORDER BY ativo DESC, nome')->fetchAll()),
    'alunos' => array_map(fn ($a) => ['id' => (int) $a['id'], 'nome' => $a['nome'], 'detalhe' => $a['email'] ?: 'sem e-mail', 'cpf' => (string) $a['cpf']],
        $pdo->query("SELECT id, nome, email, cpf FROM alunos WHERE ativo = 1 ORDER BY nome")->fetchAll()),
];
// Contagem de cada público "fixo", para mostrar na hora.
$contagemFixa = ['andamento' => count(destinatariosDisparo($pdo, 'andamento', [])), 'todos' => count(destinatariosDisparo($pdo, 'todos', []))];

$pageTitle = 'Novo disparo de e-mail';
$pageBreadcrumb = 'E-mail · Disparo em massa';
$activeMenu = 'email-disparos';
$pageAction = '<a href="/email/disparos.php" class="btn btn-outline-secondary">Cancelar</a>'
    . '<button type="button" class="btn btn-outline-secondary" onclick="enviarTeste(this)"><i class="bi bi-send-check"></i> Enviar teste para mim</button>'
    . '<button type="button" class="btn btn-brand" onclick="confirmarDisparo()"><i class="bi bi-megaphone"></i> Disparar</button>';
require __DIR__ . '/../../includes/layout_header.php';
?>
<link href="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.snow.css" rel="stylesheet">
<style>
    #editor-disparo { min-height: 320px; background: #fff; font-size: 14px; }
    #editor-disparo .ql-editor { min-height: 320px; }
    #editor-disparo img { max-width: 100%; }
    .tag-btn { cursor: pointer; font-family: ui-monospace, monospace; font-weight: 500; }
    .destino-chip { display: inline-flex; align-items: center; gap: 6px; background: var(--accent-soft); border: 1px solid var(--bs-border-color); border-radius: 16px; padding: 3px 6px 3px 12px; font-size: 13px; margin: 0 6px 6px 0; }
    .destino-chip button { border: 0; background: none; padding: 0 4px; color: #666; }
    .previa-email { border: 1px solid var(--bs-border-color); border-radius: 10px; padding: 16px; background: #fff; color: #202124; }
    .previa-email img { max-width: 100%; }
    .ql-snow .ql-picker.ql-font .ql-picker-label::before, .ql-snow .ql-picker.ql-font .ql-picker-item::before { content: attr(data-value) !important; }
    .ql-snow .ql-picker.ql-font .ql-picker-label:not([data-value])::before, .ql-snow .ql-picker.ql-font .ql-picker-item:not([data-value])::before { content: 'Fonte' !important; }
    .ql-snow .ql-picker.ql-font { width: 130px; }
    .ql-snow .ql-picker.ql-size .ql-picker-label::before, .ql-snow .ql-picker.ql-size .ql-picker-item::before { content: attr(data-value) !important; }
    .ql-snow .ql-picker.ql-size .ql-picker-label:not([data-value])::before, .ql-snow .ql-picker.ql-size .ql-picker-item:not([data-value])::before { content: 'Normal' !important; }
</style>

<?php if ($erro): ?><div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> <?= e($erro) ?><?= !empty($_FILES['anexos']['name'][0]) ? ' <span class="small">(anexe os arquivos de novo)</span>' : '' ?></div><?php endif; ?>

<form method="post" enctype="multipart/form-data" id="form-disparo">
<input type="hidden" name="html" id="campo-html">
<input type="hidden" name="acao" id="campo-acao" value="disparar">
<div class="row g-3">
    <div class="col-xl-8">
        <div class="secao">
            <div class="secao-titulo"><i class="bi bi-envelope-paper"></i> Mensagem</div>
            <div class="mb-3">
                <label class="form-label">Assunto *</label>
                <input type="text" name="assunto" id="assunto" class="form-control" maxlength="250" value="<?= e($dados['assunto']) ?>" placeholder="Ex.: {primeiro_nome}, sua aula de sábado mudou">
            </div>
            <div class="mb-2 small">
                <span class="text-muted me-1">Tags — clique para colocar no texto (cada aluno recebe com os dados dele):</span>
                <?php foreach (tagsEmail() as $tag => $rotulo): ?>
                    <span class="badge text-bg-light border tag-btn" title="<?= e($rotulo) ?>" onmousedown="event.preventDefault(); inserirTag('<?= e($tag) ?>')"><?= e($tag) ?></span>
                <?php endforeach; ?>
            </div>
            <div id="editor-disparo"><?= limparHtmlContrato($dados['html'], true) ?></div>
            <div class="mt-3">
                <label class="btn btn-sm btn-outline-secondary mb-0"><i class="bi bi-paperclip"></i> Anexar arquivos<input type="file" name="anexos[]" id="anexos" multiple hidden onchange="mostrarAnexos()"></label>
                <span class="small text-muted ms-2" id="lista-anexos">Até <?= (int) $cfg['anexo_max_mb'] ?> MB no total — vai igual para todos.</span>
            </div>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="secao">
            <div class="secao-titulo"><i class="bi bi-people"></i> Para quem</div>
            <select name="publico" id="publico" class="form-select mb-2" onchange="trocarPublico()">
                <?php foreach (publicosDisparo() as $chave => $rotulo): ?>
                    <option value="<?= $chave ?>" <?= $dados['publico'] === $chave ? 'selected' : '' ?>><?= e($rotulo) ?></option>
                <?php endforeach; ?>
            </select>
            <div id="area-escolha">
                <div class="typeahead mb-2">
                    <input type="search" id="busca-destino" class="form-control" autocomplete="off">
                    <div class="typeahead-lista d-none" id="lista-destino"></div>
                </div>
                <div id="chips"></div>
            </div>
            <div class="alert alert-light border py-2 small mb-0 mt-2" id="contagem"></div>
            <div class="form-text">Sai um e-mail por aluno, só para quem tem e-mail no cadastro; e-mail repetido recebe uma vez.</div>
        </div>
        <div class="secao">
            <div class="secao-titulo"><i class="bi bi-send"></i> Sai de</div>
            <div class="form-check">
                <input class="form-check-input" type="radio" name="remetente" value="usuario" id="rem-usuario" <?= $dados['remetente'] !== 'sistema' ? 'checked' : '' ?> <?= $minhaCaixa['configurada'] ? '' : 'disabled' ?>>
                <label class="form-check-label" for="rem-usuario">Minha caixa <span class="text-muted small">(<?= e($minhaCaixa['configurada'] ? $minhaCaixa['email'] : 'não configurada') ?>)</span></label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="radio" name="remetente" value="sistema" id="rem-sistema" <?= $dados['remetente'] === 'sistema' || !$minhaCaixa['configurada'] ? 'checked' : '' ?> <?= $sistemaPronto ? '' : 'disabled' ?>>
                <label class="form-check-label" for="rem-sistema">E-mail do sistema <span class="text-muted small">(<?= e($sistemaPronto ? (trim((string) $config['smtp_remetente_email']) ?: $config['smtp_usuario']) : 'não configurado') ?>)</span></label>
            </div>
            <div class="form-text">Ritmo: até <strong><?= (int) $cfg['disparo_por_hora'] ?> e-mails por hora</strong> (Configurações › E-mail), para o provedor não bloquear.</div>
        </div>
        <div class="secao">
            <div class="secao-titulo"><i class="bi bi-eye"></i> Prévia <span class="small text-muted fw-normal ms-1" id="previa-quem"></span></div>
            <div class="previa-email"><div class="fw-semibold mb-2" id="previa-assunto"></div><div id="previa-corpo" class="small"></div></div>
        </div>
    </div>
</div>
</form>

<?php require __DIR__ . '/../../includes/modal_confirmar.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.js"></script>
<script>
const FONTES = ['Arial', 'Verdana', 'Tahoma', 'Trebuchet MS', 'Georgia', 'Times New Roman', 'Courier New'];
const TAMANHOS = ['10px', '13px', '16px', '20px', '24px', '32px'];
const Fonte = Quill.import('attributors/style/font'); Fonte.whitelist = FONTES; Quill.register(Fonte, true);
const Tamanho = Quill.import('attributors/style/size'); Tamanho.whitelist = TAMANHOS; Quill.register(Tamanho, true);
['attributors/style/align', 'attributors/style/color', 'attributors/style/background'].forEach((a) => Quill.register(Quill.import(a), true));
const quill = new Quill('#editor-disparo', { theme: 'snow', modules: { toolbar: [
    [{ font: [false, ...FONTES] }, { size: [false, ...TAMANHOS] }],
    ['bold', 'italic', 'underline', 'strike'], [{ color: [] }, { background: [] }],
    [{ align: [] }, { list: 'ordered' }, { list: 'bullet' }], ['link', 'image', 'clean'],
] } });
let ultimoCampo = 'editor';
document.getElementById('assunto').addEventListener('focus', () => { ultimoCampo = 'assunto'; });
quill.on('selection-change', (s) => { if (s) ultimoCampo = 'editor'; });
function inserirTag(tag) {
    if (ultimoCampo === 'assunto') {
        const c = document.getElementById('assunto');
        const p = c.selectionStart ?? c.value.length;
        c.value = c.value.slice(0, p) + tag + c.value.slice(c.selectionEnd ?? p);
        c.focus(); c.selectionStart = c.selectionEnd = p + tag.length;
    } else {
        const p = (quill.getSelection(true) || { index: quill.getLength() - 1 }).index;
        quill.insertText(p, tag, 'user');
        quill.setSelection(p + tag.length, 0);
    }
    atualizarPrevia();
}
function mostrarAnexos() {
    const f = [...document.getElementById('anexos').files];
    document.getElementById('lista-anexos').innerHTML = f.length ? f.map((x) => '<i class="bi bi-paperclip"></i> ' + escapeHtml(x.name)).join(' · ') : '';
}

// ---- Para quem ----
const OPCOES = <?= json_encode($opcoes, JSON_UNESCAPED_UNICODE) ?>;
const CONTAGEM_FIXA = <?= json_encode($contagemFixa) ?>;
const ROTULO = { turma: 'Procurar turma ou curso...', curso: 'Procurar curso...', alunos: 'Procurar aluno (nome ou CPF)...' };
let escolhidos = { turma: [], curso: [], alunos: [] };
escolhidos[<?= json_encode($dados['publico']) ?>] = <?= json_encode(array_values($dados['ids'])) ?>;
const semAcento = (t) => String(t || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
const publico = () => document.getElementById('publico').value;
const campoBusca = document.getElementById('busca-destino');
const listaBusca = document.getElementById('lista-destino');
function trocarPublico() {
    const p = publico();
    document.getElementById('area-escolha').style.display = OPCOES[p] ? '' : 'none';
    campoBusca.placeholder = ROTULO[p] || '';
    campoBusca.value = '';
    desenharChips();
}
function desenharChips() {
    const p = publico();
    document.getElementById('chips').innerHTML = !OPCOES[p] ? '' : (escolhidos[p].map((id) => {
        const o = OPCOES[p].find((x) => x.id === id);
        return `<span class="destino-chip">${escapeHtml(o ? o.nome : '#' + id)}<input type="hidden" name="ids[]" value="${id}"><button type="button" onclick="tirar(${id})"><i class="bi bi-x-lg"></i></button></span>`;
    }).join('') || '<div class="small text-muted">Nenhum escolhido ainda.</div>');
    contar();
}
window.tirar = (id) => { const p = publico(); escolhidos[p] = escolhidos[p].filter((x) => x !== id); desenharChips(); };
function listar() {
    const p = publico();
    const t = semAcento(campoBusca.value.trim());
    const dig = campoBusca.value.replace(/\D/g, '');
    const achados = OPCOES[p].filter((o) => !escolhidos[p].includes(o.id) && (!t || semAcento(o.nome).includes(t) || (dig.length >= 3 && String(o.cpf || '').includes(dig)))).slice(0, 12);
    listaBusca.innerHTML = achados.map((o) => `<button type="button" class="typeahead-item" data-id="${o.id}"><span class="typeahead-nome">${escapeHtml(o.nome)}</span><span class="typeahead-meta">${escapeHtml(o.detalhe)}</span></button>`).join('') || '<div class="typeahead-vazio">Nada encontrado.</div>';
    listaBusca.classList.remove('d-none');
}
campoBusca.addEventListener('input', listar);
campoBusca.addEventListener('focus', listar);
listaBusca.addEventListener('click', (ev) => { const b = ev.target.closest('.typeahead-item'); if (!b) return; escolhidos[publico()].push(Number(b.dataset.id)); campoBusca.value = ''; desenharChips(); listar(); campoBusca.focus(); });
document.addEventListener('click', (ev) => { if (!ev.target.closest('.typeahead')) listaBusca.classList.add('d-none'); });

// Quantos recebem + prévia com o 1º aluno (pergunta ao servidor).
let totalDestinos = 0;
let esperaContagem;
function contar() {
    clearTimeout(esperaContagem);
    esperaContagem = setTimeout(async () => {
        const dados = new FormData();
        dados.append('acao', 'contar');
        dados.append('publico', publico());
        (escolhidos[publico()] || []).forEach((i) => dados.append('ids[]', i));
        const r = await (await fetch('/email/disparo_acao.php', { method: 'POST', body: dados })).json();
        totalDestinos = r.total || 0;
        document.getElementById('contagem').innerHTML = `<i class="bi bi-envelope"></i> <strong>${totalDestinos}</strong> e-mail(s) vão sair`
            + (r.sem_email ? ` · <span class="text-warning-emphasis">${r.sem_email} aluno(s) sem e-mail ficam de fora</span>` : '')
            + (r.horas ? `<div class="text-muted">Leva cerca de ${r.horas} para sair tudo.</div>` : '');
        window.primeiroDestino = r.primeiro || null;
        atualizarPrevia();
    }, 250);
}
let esperaPrevia;
function atualizarPrevia() {
    clearTimeout(esperaPrevia);
    esperaPrevia = setTimeout(async () => {
        const dados = new FormData();
        dados.append('acao', 'previa');
        dados.append('assunto', document.getElementById('assunto').value);
        dados.append('html', quill.getSemanticHTML());
        if (window.primeiroDestino) { dados.append('aluno_id', window.primeiroDestino.aluno_id); dados.append('turma_id', window.primeiroDestino.turma_id || ''); }
        const r = await (await fetch('/email/disparo_acao.php', { method: 'POST', body: dados })).json();
        document.getElementById('previa-quem').textContent = window.primeiroDestino ? '— como ' + window.primeiroDestino.nome + ' recebe' : '';
        document.getElementById('previa-assunto').textContent = r.assunto || '(sem assunto)';
        document.getElementById('previa-corpo').innerHTML = r.html || '';
    }, 400);
}
quill.on('text-change', atualizarPrevia);
document.getElementById('assunto').addEventListener('input', atualizarPrevia);

// ---- Testar e disparar ----
async function enviarTeste(botao) {
    document.getElementById('campo-html').value = quill.getSemanticHTML();
    document.getElementById('campo-acao').value = 'teste';
    botao.disabled = true;
    try {
        const r = await (await fetch('/email/disparo_novo.php', { method: 'POST', body: new FormData(document.getElementById('form-disparo')) })).json();
        await confirmarNoModal({ titulo: r.sucesso ? 'Teste enviado' : 'Não deu certo', html: escapeHtml(r.mensagem), botao: 'Ok', classeBotao: r.sucesso ? 'btn-brand' : 'btn-danger' });
    } catch (e) {
        await confirmarNoModal({ titulo: 'Não deu certo', html: 'Não foi possível enviar o teste.', botao: 'Ok' });
    }
    botao.disabled = false;
    document.getElementById('campo-acao').value = 'disparar';
}
async function confirmarDisparo() {
    if (!totalDestinos) {
        await confirmarNoModal({ titulo: 'Ninguém para receber', html: 'Escolha para quem vai o disparo (e confira se os alunos têm e-mail).', botao: 'Ok' });
        return;
    }
    const ok = await confirmarNoModal({ titulo: 'Disparar e-mail', html: `Vão sair <strong>${totalDestinos}</strong> e-mail(s), um para cada aluno, com o assunto <strong>${escapeHtml(document.getElementById('assunto').value || '(sem assunto)')}</strong>.<br><span class="small text-muted">Eles saem aos poucos; dá para pausar ou cancelar na tela do disparo.</span>`, botao: 'Disparar' });
    if (!ok) return;
    document.getElementById('campo-html').value = quill.getSemanticHTML();
    document.getElementById('campo-acao').value = 'disparar';
    document.getElementById('form-disparo').submit();
}
// escapeHtml (rodapé) só existe depois que a página termina de carregar.
document.addEventListener('DOMContentLoaded', trocarPublico);
</script>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
