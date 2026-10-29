<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirPermissao('usar_email', 'Seu perfil não usa o e-mail pelo sistema.');

// Escrever e-mail pela caixa do usuário: Para/Cc/Cco com sugestões (alunos
// e usuários), editor com fonte, tamanho, cores, alinhamento, listas, links
// e imagens, anexos e assinatura. Também responder (?responder=1, &todos=1)
// e encaminhar (?encaminhar=1) um e-mail (pasta + uid); ?para=email já
// preenche o destinatário. Rascunho: salva na pasta Rascunhos do servidor
// (botão e a cada minuto, acao=rascunho via fetch) e ?rascunho=UID reabre.
// Cada salvamento grava um novo e apaga o anterior (IMAP não edita).
$pdo = getDB();
$cfg = emailConfig($pdo);
$caixa = caixaUsuario($pdo, (int) usuarioAtualId());
if (!$caixa['configurada']) {
    flash('warning', 'Configure sua caixa de e-mail antes de escrever.');
    redirect('/email/minha_caixa.php');
}

$pastaOrigem = (string) ($_REQUEST['pasta'] ?? '');
$uidOrigem = (int) ($_REQUEST['uid'] ?? 0);
$modo = !empty($_REQUEST['encaminhar']) ? 'encaminhar' : (!empty($_REQUEST['responder']) ? 'responder' : 'novo');
$original = null;
$bruto = null;
// Rascunho reaberto (?rascunho=UID) ou o salvo nesta tela (rascunho_uid).
$pastaRascunhos = null;
try {
    $pastaRascunhos = pastaEspecial(pastasCaixa($pdo), 'rascunhos');
} catch (RuntimeException $e) {
}
$rascunhoUid = (int) ($_REQUEST['rascunho_uid'] ?? $_GET['rascunho'] ?? 0);
$rascunho = null;
$brutoRascunho = null;
if (!empty($_GET['rascunho']) && $pastaRascunhos && $_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        if ($brutoRascunho = mensagemBrutaCaixa($pdo, $pastaRascunhos, $rascunhoUid)) {
            $rascunho = EmailMime::analisar($brutoRascunho);
            // Rascunho de uma resposta/encaminhamento: volta ao modo dele.
            if (preg_match('/^(responder|encaminhar)\|(\d+)\|(.+)$/', $rascunho['origem'], $m)) {
                $modo = $m[1];
                $uidOrigem = (int) $m[2];
                $pastaOrigem = $m[3];
            }
        }
    } catch (RuntimeException $e) {
        flash('danger', $e->getMessage());
    }
}
if (!$rascunho && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $rascunhoUid = 0;
}
if ($modo !== 'novo' && $uidOrigem) {
    try {
        if (in_array($pastaOrigem, array_column(pastasCaixa($pdo), 'nome'), true) && ($bruto = mensagemBrutaCaixa($pdo, $pastaOrigem, $uidOrigem))) {
            $original = EmailMime::analisar($bruto);
        } else {
            $bruto = null;
        }
    } catch (RuntimeException $e) {
        flash('danger', $e->getMessage());
    }
}

$fmtEndereco = fn (array $p) => $p['nome'] ? $p['nome'] . ' <' . $p['email'] . '>' : $p['email'];
$assinatura = $caixa['assinatura'] ? '<p><br></p><p>--</p>' . limparHtmlContrato($caixa['assinatura'], true) : '';
$dados = ['para' => (string) ($_GET['para'] ?? ''), 'cc' => '', 'cco' => '', 'assunto' => (string) ($_GET['assunto'] ?? ''), 'html' => '<p><br></p>' . $assinatura];
$anexosOriginais = [];
if ($original) {
    $citacao = $original['html'] !== null ? limparHtmlContrato($original['html'], true) : nl2br(e((string) $original['texto']));
    $quando = $original['data'] ? formatDateTime($original['data']) : '';
    $assuntoBase = preg_replace('/^((re|res|enc|fwd|fw|rv)\s*:\s*)+/i', '', $original['assunto']);
    if ($modo === 'responder') {
        $responderPara = $original['responder_para'] ?: [$original['de']];
        // Respondendo um e-mail que eu mesmo mandei: vai para os destinatários dele.
        if (strtolower($original['de']['email']) === $caixa['email']) {
            $responderPara = $original['para'];
        }
        $dados['para'] = implode(', ', array_map($fmtEndereco, $responderPara));
        if (!empty($_GET['todos'])) {
            $jaVai = array_merge([$caixa['email']], array_column($responderPara, 'email'));
            $outros = array_filter([...$original['para'], ...$original['cc']], fn ($p) => !in_array(strtolower($p['email']), $jaVai, true));
            $dados['cc'] = implode(', ', array_map($fmtEndereco, array_values($outros)));
        }
        $dados['assunto'] = 'Re: ' . $assuntoBase;
        $dados['html'] = '<p><br></p>' . $assinatura . '<p><br></p><p>Em ' . e($quando) . ', ' . e($fmtEndereco($original['de'])) . ' escreveu:</p><blockquote>' . $citacao . '</blockquote>';
    } else {
        $dados['assunto'] = 'Enc: ' . $assuntoBase;
        $dados['html'] = '<p><br></p>' . $assinatura . '<p><br></p><p>---------- Mensagem encaminhada ----------<br>De: ' . e($fmtEndereco($original['de']))
            . '<br>Data: ' . e($quando) . '<br>Assunto: ' . e($original['assunto']) . '<br>Para: ' . e(implode(', ', array_map($fmtEndereco, $original['para']))) . '</p>' . $citacao;
        $anexosOriginais = array_values(array_filter($original['anexos'], fn ($a) => !$a['inline']));
    }
}

// Reabrindo um rascunho: os campos e os anexos vêm dele.
if ($rascunho) {
    $dados = [
        'para' => implode(', ', array_map($fmtEndereco, $rascunho['para'])),
        'cc' => implode(', ', array_map($fmtEndereco, $rascunho['cc'])),
        'cco' => implode(', ', array_map($fmtEndereco, $rascunho['cco'])),
        'assunto' => $rascunho['assunto'],
        'html' => $rascunho['html'] !== null ? $rascunho['html'] : nl2br(e((string) $rascunho['texto'])),
    ];
    // Imagens coladas no texto (cid:) voltam a ser imagens dentro do editor.
    foreach ($rascunho['anexos'] as $a) {
        if ($a['cid'] && ($parte = EmailMime::parte($brutoRascunho, $a['id']))) {
            $dados['html'] = str_replace('cid:' . $a['cid'], 'data:' . $a['tipo'] . ';base64,' . base64_encode($parte['conteudo']), $dados['html']);
        }
    }
    $dados['html'] = limparHtmlContrato($dados['html'], true);
    $anexosOriginais = array_values(array_filter($rascunho['anexos'], fn ($a) => !$a['inline']));
}
// De onde vêm os anexos marcados (anexos_originais): o rascunho salvo ou o e-mail encaminhado.
$fonteAnexos = null;
if ($rascunhoUid && $pastaRascunhos && $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['anexos_do_rascunho'])) {
    try {
        if ($b = mensagemBrutaCaixa($pdo, $pastaRascunhos, $rascunhoUid)) {
            $fonteAnexos = ['bruto' => $b, 'anexos' => EmailMime::analisar($b)['anexos']];
        }
    } catch (RuntimeException $e) {
    }
} elseif ($original && $bruto) {
    $fonteAnexos = ['bruto' => $bruto, 'anexos' => $original['anexos']];
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $fonteAnexos && !empty($_POST['anexos_do_rascunho'])) {
    $anexosOriginais = array_values(array_filter($fonteAnexos['anexos'], fn ($a) => !$a['inline']));
}
$apagarRascunho = function () use ($pdo, $pastaRascunhos, &$rascunhoUid): void {
    if ($rascunhoUid && $pastaRascunhos) {
        try {
            $imap = abrirCaixa($pdo);
            $imap->abrirPasta($pastaRascunhos);
            $imap->apagarDeVez([$rascunhoUid]);
        } catch (RuntimeException $e) {
        }
        $rascunhoUid = 0;
    }
};

$erro = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'descartar_rascunho') {
    $apagarRascunho();
    responderJson(['sucesso' => true]);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dados = [
        'para' => (string) ($_POST['para'] ?? ''),
        'cc' => (string) ($_POST['cc'] ?? ''),
        'cco' => (string) ($_POST['cco'] ?? ''),
        'assunto' => trim((string) ($_POST['assunto'] ?? '')),
        'html' => (string) ($_POST['html'] ?? ''),
    ];
    [$anexos, $erro] = anexosDoFormulario($_FILES['anexos'] ?? [], $cfg['anexo_max_mb']);
    // Anexos do e-mail encaminhado (ou do rascunho) que continuaram marcados.
    if (!$erro && $fonteAnexos && !empty($_POST['anexos_originais'])) {
        foreach ((array) $_POST['anexos_originais'] as $parteId) {
            $p = EmailMime::parte($fonteAnexos['bruto'], (string) $parteId);
            foreach ($fonteAnexos['anexos'] as $a) {
                if ($p && $a['id'] === (string) $parteId) {
                    if ($motivo = motivoArquivoPerigoso($a['nome'], $p['conteudo'])) {
                        $erro = 'O anexo "' . $a['nome'] . '" do e-mail original está bloqueado por segurança: ' . $motivo . ' Desmarque-o para encaminhar.';
                        continue;
                    }
                    $anexos[] = ['nome' => $a['nome'], 'tipo' => $a['tipo'], 'conteudo' => $p['conteudo']];
                }
            }
        }
        if (array_sum(array_map(fn ($a) => strlen($a['conteudo']), $anexos)) > $cfg['anexo_max_mb'] * 1024 * 1024) {
            $erro = "Os anexos passam de {$cfg['anexo_max_mb']} MB no total.";
        }
    }
    // Salvar rascunho (fetch): grava na pasta Rascunhos e apaga o anterior.
    if (($_POST['acao'] ?? '') === 'rascunho') {
        if ($erro) {
            responderJson(['sucesso' => false, 'mensagem' => $erro]);
        }
        $lista = fn (string $v) => array_values(array_filter(EmailMime::enderecos($v), fn ($e) => filter_var($e['email'], FILTER_VALIDATE_EMAIL)));
        $montado = EmailMime::montar([
            'de' => ['nome' => $caixa['nome'], 'email' => $caixa['email']],
            'para' => $lista($dados['para']), 'cc' => $lista($dados['cc']), 'cco' => $lista($dados['cco']),
            'assunto' => $dados['assunto'], 'html' => imagensLocaisEmbutidas($dados['html']), 'anexos' => $anexos,
            'responde_a' => $modo === 'responder' && $original ? ($original['message_id'] ?: null) : null,
            'referencias' => $original['referencias'] ?? null,
            'rascunho' => true,
            'extras' => $modo !== 'novo' && $uidOrigem ? ['X-Sistema-Origem' => $modo . '|' . $uidOrigem . '|' . $pastaOrigem] : [],
        ]);
        try {
            $destino = garantirPastaEspecial($pdo, 'rascunhos');
            $imap = abrirCaixa($pdo);
            $imap->gravar($destino, $montado['bruto'], '\\Draft \\Seen');
            $imap->abrirPasta($destino);
            $novo = $imap->uidPorMessageId($montado['message_id']);
            if ($rascunhoUid && $novo && $novo !== $rascunhoUid) {
                $imap->apagarDeVez([$rascunhoUid]);
            }
        } catch (RuntimeException $e) {
            responderJson(['sucesso' => false, 'mensagem' => 'Não foi possível salvar o rascunho: ' . $e->getMessage()]);
        }
        // Anexos agora estão no rascunho: a tela passa a marcá-los de lá.
        $anexosSalvos = array_map(fn ($a) => ['id' => $a['id'], 'nome' => $a['nome']], array_values(array_filter(EmailMime::analisar($montado['bruto'])['anexos'], fn ($a) => !$a['inline'])));
        responderJson(['sucesso' => true, 'uid' => (int) $novo, 'hora' => date('H:i'), 'anexos' => $anexosSalvos]);
    }
    if (!$erro && $dados['assunto'] === '' && empty($_POST['sem_assunto'])) {
        $erro = 'Coloque um assunto (ou confirme enviar sem assunto).';
    }
    if (!$erro) {
        $r = enviarEmailUsuario($pdo, (int) usuarioAtualId(), $dados + [
            'anexos' => $anexos,
            'responde_a' => $modo === 'responder' && $original ? ($original['message_id'] ?: null) : null,
            'referencias' => $original['referencias'] ?? null,
        ]);
        if ($r['sucesso']) {
            $apagarRascunho();
            if ($modo === 'responder' && $original) {
                try {
                    $imap = abrirCaixa($pdo);
                    $imap->abrirPasta($pastaOrigem);
                    $imap->marcar([$uidOrigem], '\\Answered');
                } catch (RuntimeException $e) {
                }
            }
            flash('success', $r['mensagem']);
            redirect($original ? '/email/ler.php?' . http_build_query(['pasta' => $pastaOrigem, 'uid' => $uidOrigem]) : '/email/index.php');
        }
        $erro = $r['mensagem'];
    }
}

// Sugestões de destinatário: alunos e usuários com e-mail.
$contatos = [];
foreach ($pdo->query("SELECT nome, email FROM alunos WHERE email IS NOT NULL AND email <> '' AND ativo = 1 ORDER BY nome") as $a) {
    $contatos[] = ['n' => $a['nome'], 'e' => strtolower($a['email']), 't' => 'Aluno'];
}
foreach ($pdo->query("SELECT nome, COALESCE(NULLIF(email_caixa, ''), email) AS email FROM usuarios WHERE ativo = 1 ORDER BY nome") as $u) {
    $contatos[] = ['n' => $u['nome'], 'e' => strtolower($u['email']), 't' => 'Equipe'];
}

$pageTitle = ['novo' => 'Escrever e-mail', 'responder' => 'Responder', 'encaminhar' => 'Encaminhar'][$modo];
$pageBreadcrumb = 'E-mail · de ' . $caixa['email'];
$activeMenu = 'email';
require __DIR__ . '/../../includes/layout_header.php';
?>
<link href="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.snow.css" rel="stylesheet">
<style>
    .escrever-card { max-width: 980px; background: var(--bs-body-bg); border: 1px solid var(--bs-border-color); border-radius: 12px; }
    .escrever-linha { display: flex; align-items: center; gap: 8px; border-bottom: 1px solid var(--bs-border-color); padding: 2px 14px; position: relative; }
    .escrever-linha label { width: 60px; color: var(--bs-secondary-color); font-size: 14px; margin: 0; flex: none; }
    .escrever-linha input { border: 0; box-shadow: none !important; padding: 9px 0; background: transparent; }
    .sugestoes { position: absolute; left: 70px; top: 100%; z-index: 30; background: var(--bs-body-bg); border: 1px solid var(--bs-border-color); border-radius: 8px; box-shadow: 0 6px 20px rgba(0,0,0,.15); min-width: 340px; max-height: 280px; overflow-y: auto; }
    .sugestoes button { display: block; width: 100%; text-align: left; border: 0; background: none; padding: 7px 12px; font-size: 14px; }
    .sugestoes button:hover, .sugestoes button.ativa { background: var(--accent-soft); }
    #editor-email { min-height: 340px; font-size: 14px; border: 0; }
    #editor-email .ql-editor { min-height: 340px; }
    #editor-email img { max-width: 100%; }
    #editor-email blockquote { border-left: 2px solid #ccc; padding-left: 10px; color: #555; }
    .ql-toolbar.ql-snow { border: 0; border-bottom: 1px solid var(--bs-border-color); }
    .ql-container.ql-snow { border: 0; }
    .escrever-rodape { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; padding: 12px 14px; border-top: 1px solid var(--bs-border-color); }
    .chip-anexo { display: inline-flex; align-items: center; gap: 6px; background: var(--bs-tertiary-bg); border: 1px solid var(--bs-border-color); border-radius: 8px; padding: 4px 8px; font-size: 13px; }
    .chip-anexo button { border: 0; background: none; padding: 0 2px; color: #666; }
    .ql-snow .ql-picker.ql-font .ql-picker-label::before, .ql-snow .ql-picker.ql-font .ql-picker-item::before { content: attr(data-value) !important; }
    .ql-snow .ql-picker.ql-font .ql-picker-label:not([data-value])::before, .ql-snow .ql-picker.ql-font .ql-picker-item:not([data-value])::before { content: 'Fonte' !important; }
    .ql-snow .ql-picker.ql-font { width: 130px; }
    .ql-snow .ql-picker.ql-size .ql-picker-label::before, .ql-snow .ql-picker.ql-size .ql-picker-item::before { content: attr(data-value) !important; }
    .ql-snow .ql-picker.ql-size .ql-picker-label:not([data-value])::before, .ql-snow .ql-picker.ql-size .ql-picker-item:not([data-value])::before { content: 'Normal' !important; }
</style>

<?php if ($erro): ?><div class="alert alert-danger" style="max-width: 980px;"><i class="bi bi-exclamation-triangle"></i> <?= e($erro) ?><?= !empty($_FILES['anexos']['name'][0]) ? ' <span class="small">(anexe os arquivos de novo)</span>' : '' ?></div><?php endif; ?>

<form method="post" enctype="multipart/form-data" id="form-email" onsubmit="return prepararEnvioEmail()">
    <input type="hidden" name="html" id="campo-html">
    <input type="hidden" name="pasta" value="<?= e($pastaOrigem) ?>">
    <input type="hidden" name="uid" value="<?= (int) $uidOrigem ?>">
    <input type="hidden" name="<?= $modo === 'encaminhar' ? 'encaminhar' : ($modo === 'responder' ? 'responder' : 'novo') ?>" value="1">
    <input type="hidden" name="sem_assunto" id="sem-assunto" value="">
    <input type="hidden" name="rascunho_uid" id="rascunho-uid" value="<?= (int) $rascunhoUid ?>">
    <?php if ($rascunho || ($rascunhoUid && !empty($_POST['anexos_do_rascunho']))): ?><input type="hidden" name="anexos_do_rascunho" value="1"><?php endif; ?>
    <div class="escrever-card">
        <div class="escrever-linha">
            <label for="para">Para</label>
            <input type="text" name="para" id="para" class="form-control campo-endereco" autocomplete="off" value="<?= e($dados['para']) ?>" placeholder="nome ou e-mail — separe vários por vírgula">
            <a href="#" class="small text-nowrap" onclick="this.remove(); document.querySelectorAll('.linha-copia').forEach((l) => l.classList.remove('d-none')); return false;" <?= $dados['cc'] || $dados['cco'] ? 'style="display:none"' : '' ?>>Cc Cco</a>
        </div>
        <div class="escrever-linha linha-copia <?= $dados['cc'] ? '' : 'd-none' ?>">
            <label for="cc">Cc</label>
            <input type="text" name="cc" id="cc" class="form-control campo-endereco" autocomplete="off" value="<?= e($dados['cc']) ?>">
        </div>
        <div class="escrever-linha linha-copia <?= $dados['cco'] ? '' : 'd-none' ?>">
            <label for="cco" title="Cópia oculta: os outros não veem">Cco</label>
            <input type="text" name="cco" id="cco" class="form-control campo-endereco" autocomplete="off" value="<?= e($dados['cco']) ?>">
        </div>
        <div class="escrever-linha">
            <label for="assunto">Assunto</label>
            <input type="text" name="assunto" id="assunto" class="form-control" maxlength="250" value="<?= e($dados['assunto']) ?>">
        </div>
        <div id="editor-email"><?= $_SERVER['REQUEST_METHOD'] === 'POST' ? limparHtmlContrato($dados['html'], true) : $dados['html'] ?></div>

            <div class="px-3 pt-2 small <?= $anexosOriginais ? '' : 'd-none' ?>" id="anexos-salvos">
                <div class="text-muted mb-1"><?= $rascunho || !empty($_POST['anexos_do_rascunho']) ? 'Anexos do rascunho:' : 'Anexos do e-mail original:' ?></div>
                <?php foreach ($anexosOriginais as $a): ?>
                    <label class="chip-anexo me-1 mb-1"><input type="checkbox" class="form-check-input m-0" name="anexos_originais[]" value="<?= e($a['id']) ?>" checked> <i class="bi bi-paperclip"></i> <?= e($a['nome']) ?></label>
                <?php endforeach; ?>
            </div>
        <div class="px-3 pt-2" id="lista-anexos"></div>

        <div class="escrever-rodape">
            <button type="submit" class="btn btn-brand rounded-pill px-4"><i class="bi bi-send"></i> Enviar</button>
            <label class="btn btn-outline-secondary rounded-pill mb-0" title="Anexar arquivos (até <?= (int) $cfg['anexo_max_mb'] ?> MB no total)">
                <i class="bi bi-paperclip"></i> Anexar
                <input type="file" id="escolher-anexos" multiple hidden>
            </label>
            <input type="file" name="anexos[]" id="anexos" multiple hidden>
            <button type="button" class="btn btn-outline-secondary rounded-pill" id="btn-rascunho" onclick="salvarRascunho(false)"><i class="bi bi-file-earmark"></i> Salvar rascunho</button>
            <span class="small text-muted" id="total-anexos"></span>
            <span class="small text-muted" id="status-rascunho"><?= $rascunho ? '<i class="bi bi-file-earmark-check"></i> Rascunho aberto' : '' ?></span>
            <a href="<?= e($original ? '/email/ler.php?' . http_build_query(['pasta' => $pastaOrigem, 'uid' => $uidOrigem]) : '/email/index.php') ?>" class="btn btn-link text-secondary ms-auto" onclick="return descartar()"><i class="bi bi-trash"></i> Descartar</a>
        </div>
    </div>
</form>

<?php require __DIR__ . '/../../includes/modal_confirmar.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.js"></script>
<script>
// ---- Editor: formatação em estilo embutido (o que os leitores de e-mail entendem) ----
const FONTES = ['Arial', 'Verdana', 'Tahoma', 'Trebuchet MS', 'Georgia', 'Times New Roman', 'Courier New', 'Comic Sans MS'];
const TAMANHOS = ['10px', '13px', '16px', '20px', '24px', '32px'];
const Fonte = Quill.import('attributors/style/font');
Fonte.whitelist = FONTES;
Quill.register(Fonte, true);
const Tamanho = Quill.import('attributors/style/size');
Tamanho.whitelist = TAMANHOS;
Quill.register(Tamanho, true);
Quill.register(Quill.import('attributors/style/align'), true);
Quill.register(Quill.import('attributors/style/color'), true);
Quill.register(Quill.import('attributors/style/background'), true);
const quill = new Quill('#editor-email', {
    theme: 'snow',
    placeholder: 'Escreva sua mensagem...',
    modules: { toolbar: [
        [{ font: [false, ...FONTES] }, { size: [false, ...TAMANHOS] }],
        ['bold', 'italic', 'underline', 'strike'],
        [{ color: [] }, { background: [] }],
        [{ align: [] }, { list: 'ordered' }, { list: 'bullet' }, 'blockquote'],
        ['link', 'image', 'clean'],
    ] },
});
quill.setSelection(0, 0);
let alterado = false;
quill.on('text-change', () => { alterado = true; });

// ---- Anexos: vão se somando; o campo real recebe todos na hora de enviar ----
const LIMITE = <?= (int) $cfg['anexo_max_mb'] ?> * 1024 * 1024;
let arquivos = [];
document.getElementById('escolher-anexos').addEventListener('change', (ev) => {
    arquivos.push(...ev.target.files);
    ev.target.value = '';
    desenharAnexos();
});
function desenharAnexos() {
    const total = arquivos.reduce((s, f) => s + f.size, 0);
    document.getElementById('lista-anexos').innerHTML = arquivos.map((f, i) =>
        `<span class="chip-anexo me-1 mb-1"><i class="bi bi-paperclip"></i> ${escapeHtml(f.name)} <span class="text-muted">${tamanho(f.size)}</span><button type="button" onclick="arquivos.splice(${i}, 1); desenharAnexos()" title="Tirar"><i class="bi bi-x-lg"></i></button></span>`).join('');
    const info = document.getElementById('total-anexos');
    info.textContent = arquivos.length ? tamanho(total) + ' de ' + tamanho(LIMITE) : '';
    info.classList.toggle('text-danger', total > LIMITE);
    const dt = new DataTransfer();
    arquivos.forEach((f) => dt.items.add(f));
    document.getElementById('anexos').files = dt.files;
    alterado = true;
}
const tamanho = (b) => (b >= 1048576 ? (b / 1048576).toFixed(1).replace('.', ',') + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB');

// ---- Sugestões de destinatário (alunos e equipe), no trecho depois da última vírgula ----
const CONTATOS = <?= json_encode($contatos, JSON_UNESCAPED_UNICODE) ?>;
const semAcento = (t) => String(t || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
document.querySelectorAll('.campo-endereco').forEach((campo) => {
    const lista = document.createElement('div');
    lista.className = 'sugestoes d-none';
    campo.parentElement.appendChild(lista);
    let ativo = 0;
    const termo = () => semAcento(campo.value.split(/[,;]/).pop().trim());
    const desenhar = () => {
        const t = termo();
        if (t.length < 2) { lista.classList.add('d-none'); return; }
        const achados = CONTATOS.filter((c) => semAcento(c.n).includes(t) || c.e.includes(t)).slice(0, 8);
        if (!achados.length) { lista.classList.add('d-none'); return; }
        ativo = Math.min(ativo, achados.length - 1);
        lista.innerHTML = achados.map((c, i) => `<button type="button" class="${i === ativo ? 'ativa' : ''}" data-v="${escapeHtml(c.n + ' <' + c.e + '>')}"><strong>${escapeHtml(c.n)}</strong> <span class="text-muted">${escapeHtml(c.e)}</span> <span class="badge text-bg-light border fw-normal">${c.t}</span></button>`).join('');
        lista.classList.remove('d-none');
    };
    const escolher = (valor) => {
        const partes = campo.value.split(/[,;]/);
        partes.pop();
        campo.value = [...partes.map((p) => p.trim()).filter(Boolean), valor].join(', ') + ', ';
        lista.classList.add('d-none');
        campo.focus();
    };
    campo.addEventListener('input', () => { ativo = 0; desenhar(); });
    campo.addEventListener('keydown', (ev) => {
        const botoes = lista.querySelectorAll('button');
        if (lista.classList.contains('d-none') || !botoes.length) return;
        if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') { ev.preventDefault(); ativo = (ativo + (ev.key === 'ArrowDown' ? 1 : -1) + botoes.length) % botoes.length; desenhar(); }
        if (ev.key === 'Enter' || ev.key === 'Tab') { ev.preventDefault(); escolher(botoes[ativo].dataset.v); }
        if (ev.key === 'Escape') lista.classList.add('d-none');
    });
    lista.addEventListener('mousedown', (ev) => { const b = ev.target.closest('button'); if (b) { ev.preventDefault(); escolher(b.dataset.v); } });
    campo.addEventListener('blur', () => setTimeout(() => lista.classList.add('d-none'), 150));
});

// ---- Enviar / descartar ----
let enviando = false;
function prepararEnvioEmail() {
    if (enviando) return true;
    if (!document.getElementById('para').value.trim() && !document.getElementById('cc').value.trim() && !document.getElementById('cco').value.trim()) {
        confirmarNoModal({ titulo: 'Falta o destinatário', html: 'Coloque pelo menos um e-mail em <strong>Para</strong>.', botao: 'Ok' });
        return false;
    }
    if (arquivos.reduce((s, f) => s + f.size, 0) > LIMITE) {
        confirmarNoModal({ titulo: 'Anexos grandes demais', html: 'Os anexos passam do limite de ' + tamanho(LIMITE) + '. Tire algum arquivo.', botao: 'Ok' });
        return false;
    }
    if (!document.getElementById('assunto').value.trim() && !document.getElementById('sem-assunto').value) {
        confirmarNoModal({ titulo: 'Sem assunto', html: 'Enviar este e-mail sem assunto?', botao: 'Enviar assim' }).then((ok) => {
            if (ok) { document.getElementById('sem-assunto').value = '1'; document.getElementById('form-email').requestSubmit(); }
        });
        return false;
    }
    document.getElementById('campo-html').value = quill.getSemanticHTML();
    enviando = true;
    alterado = false;
    const botao = document.querySelector('#form-email [type=submit]');
    botao.disabled = true;
    botao.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Enviando...';
    return true;
}
function descartar() {
    const temRascunho = Number(document.getElementById('rascunho-uid').value) > 0;
    if (!alterado && !temRascunho) return true;
    const destino = event.currentTarget.href;
    confirmarNoModal({ titulo: 'Descartar e-mail', html: 'O que você escreveu será perdido' + (temRascunho ? ' e o rascunho salvo será apagado' : '') + '.', botao: 'Descartar', classeBotao: 'btn-danger' }).then(async (ok) => {
        if (!ok) return;
        alterado = false;
        clearInterval(autoSalvar);
        if (temRascunho) {
            const dados = new FormData();
            dados.append('acao', 'descartar_rascunho');
            dados.append('rascunho_uid', document.getElementById('rascunho-uid').value);
            try { await fetch(location.pathname, { method: 'POST', body: dados }); } catch (e) {}
        }
        location.href = destino;
    });
    return false;
}

// ---- Rascunho: botão e automático a cada minuto (se mudou algo) ----
let salvandoRascunho = false;
async function salvarRascunho(automatico) {
    if (salvandoRascunho || enviando) return;
    if (automatico && !alterado) return;
    const status = document.getElementById('status-rascunho');
    salvandoRascunho = true;
    status.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Salvando rascunho...';
    document.getElementById('campo-html').value = quill.getSemanticHTML();
    const dados = new FormData(document.getElementById('form-email'));
    dados.set('acao', 'rascunho');
    try {
        const r = await (await fetch(location.pathname, { method: 'POST', body: dados })).json();
        if (r.sucesso) {
            document.getElementById('rascunho-uid').value = r.uid || 0;
            // Os anexos já foram para o rascunho: daqui em diante vêm de lá.
            if (r.uid) passarAnexosParaRascunho(r.anexos || []);
            alterado = false;
            status.innerHTML = '<i class="bi bi-file-earmark-check"></i> Rascunho salvo às ' + r.hora;
        } else {
            status.innerHTML = '<span class="text-danger"><i class="bi bi-exclamation-triangle"></i> ' + escapeHtml(r.mensagem) + '</span>';
        }
    } catch (e) {
        status.innerHTML = '<span class="text-danger">Não foi possível salvar o rascunho agora.</span>';
    } finally {
        salvandoRascunho = false;
    }
}
// Depois de salvo, os anexos (novos e os que vinham marcados) já estão no
// rascunho: a lista passa a ser a dele e os arquivos escolhidos saem da fila.
function passarAnexosParaRascunho(anexos) {
    const caixaAnexos = document.getElementById('anexos-salvos');
    caixaAnexos.innerHTML = '<div class="text-muted mb-1">Anexos do rascunho:</div>' + anexos.map((a) =>
        `<label class="chip-anexo me-1 mb-1"><input type="checkbox" class="form-check-input m-0" name="anexos_originais[]" value="${escapeHtml(a.id)}" checked> <i class="bi bi-paperclip"></i> ${escapeHtml(a.nome)}</label>`).join('');
    caixaAnexos.classList.toggle('d-none', !anexos.length);
    if (!document.querySelector('[name=anexos_do_rascunho]')) {
        document.getElementById('form-email').insertAdjacentHTML('afterbegin', '<input type="hidden" name="anexos_do_rascunho" value="1">');
    }
    arquivos = [];
    desenharAnexos();
}
const autoSalvar = setInterval(() => salvarRascunho(true), 60000);
window.addEventListener('beforeunload', (ev) => { if (alterado) { ev.preventDefault(); ev.returnValue = ''; } });
</script>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
