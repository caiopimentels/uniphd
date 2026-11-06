<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
exigirPermissao('configurar_avisos_cobranca', 'Seu perfil não pode configurar os avisos automáticos.');

// Criar / alterar uma regra de aviso automático: quando (emissão, lembretes
// de vencimento em vários dias — antes, no dia, depois — ou pagamento), por onde (e-mail e/ou
// WhatsApp) e o texto de cada meio, com tags e prévia. "Enviar teste para
// mim" manda o e-mail desta regra para o e-mail do usuário logado.
$pdo = getDB();
$config = buscarConfiguracoes($pdo);
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$aviso = null;
if ($id) {
    $stmt = $pdo->prepare('SELECT * FROM cobranca_avisos WHERE id = ?');
    $stmt->execute([$id]);
    $aviso = $stmt->fetch();
    if (!$aviso) {
        flash('danger', 'Aviso não encontrado.');
        redirect('/financeiro/avisos/listar.php');
    }
}

// "5, 2" → [5, 2] (só números de 1 a 365, sem repetir, do maior para o menor).
$listaDias = function (string $texto): array {
    $dias = array_unique(array_filter(array_map('intval', preg_split('/[^0-9]+/', $texto)), fn ($d) => $d >= 1 && $d <= 365));
    rsort($dias);
    return array_values($dias);
};
$gatilhoInicial = array_key_exists($_GET['gatilho'] ?? '', gatilhosAvisoCobranca()) ? $_GET['gatilho'] : 'vencimento';
$diasAviso = $aviso ? diasDoAviso($aviso) : [-5, -2, 0];
$dados = $aviso ? [
    'nome' => $aviso['nome'],
    'gatilho' => $aviso['gatilho'],
    'horario' => substr($aviso['horario'], 0, 5),
    'so_com_boleto' => (bool) $aviso['so_com_boleto'],
    'enviar_email' => (bool) $aviso['enviar_email'],
    'enviar_whatsapp' => (bool) $aviso['enviar_whatsapp'],
    'email_assunto' => (string) $aviso['email_assunto'],
    'email_html' => (string) $aviso['email_html'],
    'anexar_boleto' => (bool) $aviso['anexar_boleto'],
    'whatsapp_texto' => (string) $aviso['whatsapp_texto'],
    'ativo' => (bool) $aviso['ativo'],
] : [
    'nome' => '', 'gatilho' => $gatilhoInicial, 'horario' => '08:00', 'so_com_boleto' => true,
    'enviar_email' => true, 'enviar_whatsapp' => false, 'anexar_boleto' => true, 'ativo' => true,
] + modeloAvisoCobranca($gatilhoInicial);
$erro = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dados = [
        'nome' => trim((string) ($_POST['nome'] ?? '')),
        'gatilho' => array_key_exists($_POST['gatilho'] ?? '', gatilhosAvisoCobranca()) ? $_POST['gatilho'] : 'vencimento',
        'horario' => preg_match('/^\d{2}:\d{2}$/', (string) ($_POST['horario'] ?? '')) ? $_POST['horario'] : '08:00',
        'so_com_boleto' => !empty($_POST['so_com_boleto']),
        'enviar_email' => !empty($_POST['enviar_email']),
        'enviar_whatsapp' => !empty($_POST['enviar_whatsapp']),
        'email_assunto' => trim((string) ($_POST['email_assunto'] ?? '')),
        'email_html' => limparHtmlContrato((string) ($_POST['email_html'] ?? ''), true),
        'anexar_boleto' => !empty($_POST['anexar_boleto']),
        'whatsapp_texto' => trim(str_replace("\r\n", "\n", (string) ($_POST['whatsapp_texto'] ?? ''))),
        'ativo' => !empty($_POST['ativo']),
    ];
    $diasAviso = array_merge(
        array_map(fn ($d) => -$d, $listaDias((string) ($_POST['dias_antes'] ?? ''))),
        !empty($_POST['no_dia']) ? [0] : [],
        $listaDias((string) ($_POST['dias_depois'] ?? '')),
    );
    sort($diasAviso);
    $temTextoEmail = trim(strip_tags($dados['email_html'], '<img>')) !== '';
    $erro = match (true) {
        $dados['nome'] === '' => 'Dê um nome ao aviso (ex.: "Lembretes de vencimento").',
        $dados['gatilho'] === 'vencimento' && !$diasAviso => 'Escolha em que dias avisar (antes, no dia e/ou depois do vencimento).',
        !$dados['enviar_email'] && !$dados['enviar_whatsapp'] => 'Escolha pelo menos um meio: e-mail ou WhatsApp.',
        $dados['enviar_email'] && ($dados['email_assunto'] === '' || !$temTextoEmail) => 'Preencha o assunto e o texto do e-mail.',
        $dados['enviar_whatsapp'] && $dados['whatsapp_texto'] === '' => 'Escreva o texto do WhatsApp.',
        default => null,
    };

    $campos = [
        $dados['nome'], (int) $dados['ativo'], $dados['gatilho'], $dados['gatilho'] === 'vencimento' ? implode(',', $diasAviso) : '', $dados['horario'] . ':00', (int) $dados['so_com_boleto'],
        (int) $dados['enviar_email'], (int) $dados['enviar_whatsapp'], $dados['email_assunto'], $dados['email_html'],
        (int) $dados['anexar_boleto'], $dados['whatsapp_texto'],
    ];

    // Enviar teste: e-mail desta regra para quem está logado, com uma
    // parcela de verdade (a pendente mais próxima com boleto, se houver).
    if (!$erro && ($_POST['acao'] ?? '') === 'teste') {
        $stmt = $pdo->prepare('SELECT email FROM usuarios WHERE id = ?');
        $stmt->execute([usuarioAtualId()]);
        $paraMim = (string) $stmt->fetchColumn();
        $cfgEmail = emailConfig($pdo);
        $servidor = servidorDisparoEmail($pdo, ['remetente' => 'sistema', 'usuario_id' => null], $cfgEmail, $config);
        $exemplo = $pdo->query(
            "SELECT cr.id, cr.aluno_id, cr.matricula_id, cr.descricao, cr.valor, cr.valor_recebido, cr.data_vencimento, cr.data_recebimento,
                    a.nome AS aluno_nome, a.email AS aluno_email, a.telefone AS aluno_telefone, m.turma_id,
                    cb.id AS cobranca_id, cb.linha_digitavel, cb.pix_copia_cola, '' AS referencia
             FROM contas_receber cr JOIN alunos a ON a.id = cr.aluno_id LEFT JOIN matriculas m ON m.id = cr.matricula_id
             LEFT JOIN cobrancas_bancarias cb ON cb.id = (SELECT MAX(c2.id) FROM cobrancas_bancarias c2 WHERE c2.conta_receber_id = cr.id AND c2.status = 'registrada')
             WHERE cr.status = 'pendente' AND cr.excluido_em IS NULL
             ORDER BY cb.id IS NULL, cr.data_vencimento >= CURDATE() DESC, ABS(DATEDIFF(cr.data_vencimento, CURDATE())) LIMIT 1"
        )->fetch();
        if (!$dados['enviar_email']) {
            $erro = 'O teste é do e-mail: marque o meio "E-mail".';
        } elseif (!$servidor['ok']) {
            $erro = 'O e-mail do sistema não está configurado (Configurações › Sistema).';
        } elseif (!filter_var($paraMim, FILTER_VALIDATE_EMAIL)) {
            $erro = 'Seu usuário não tem e-mail válido.';
        } elseif (!$exemplo) {
            $erro = 'Não há nenhuma parcela em aberto para usar de exemplo.';
        } else {
            $valores = valoresTagsAviso($pdo, [$exemplo], $config, true);
            $anexos = $dados['anexar_boleto'] && $exemplo['cobranca_id'] && ($pdf = pdfBoletoParcela($pdo, (int) $exemplo['id'])) ? [$pdf] : [];
            $mensagem = EmailMime::montar([
                'de' => $servidor['de'],
                'para' => [['nome' => usuarioAtualNome(), 'email' => $paraMim]],
                'assunto' => '[TESTE] ' . aplicarTagsAviso($dados['email_assunto'], $valores, false),
                'html' => imagensLocaisEmbutidas(aplicarTagsAviso($dados['email_html'], $valores, true)),
                'anexos' => $anexos,
            ]);
            $r = enviarMensagemSmtp($servidor['smtp'], $servidor['de']['email'], [$paraMim], $mensagem['bruto']);
            if ($r['sucesso']) {
                flash('success', 'Teste enviado para ' . $paraMim . ' (com os dados de ' . $exemplo['aluno_nome'] . (count($anexos) ? ' e o boleto em PDF' : '') . '). O aluno não recebeu nada.');
            } else {
                $erro = 'O teste não foi enviado: ' . $r['mensagem'];
            }
        }
        if (!$erro) {
            // Volta para a mesma tela com o que estava escrito (sem salvar).
            $_POST['acao'] = '';
        }
    } elseif (!$erro) {
        if ($aviso) {
            // Ligou agora: emissão e pagamento só contam o que vier daqui pra frente.
            $ligou = $dados['ativo'] && !$aviso['ativo'];
            $pdo->prepare(
                'UPDATE cobranca_avisos SET nome = ?, ativo = ?, gatilho = ?, dias = ?, horario = ?, so_com_boleto = ?, enviar_email = ?, enviar_whatsapp = ?,
                        email_assunto = ?, email_html = ?, anexar_boleto = ?, whatsapp_texto = ?, atualizado_em = NOW()'
                . ($ligou ? ', ativado_em = NOW()' : '') . ' WHERE id = ?'
            )->execute([...$campos, $id]);
            registrarAtividade('financeiro', 'edicao', 'Aviso automático alterado: ' . $dados['nome']);
        } else {
            $pdo->prepare(
                'INSERT INTO cobranca_avisos (nome, ativo, gatilho, dias, horario, so_com_boleto, enviar_email, enviar_whatsapp,
                        email_assunto, email_html, anexar_boleto, whatsapp_texto, usuario_nome) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([...$campos, usuarioAtualNome()]);
            registrarAtividade('financeiro', 'cadastro', 'Aviso automático criado: ' . $dados['nome']);
        }
        flash('success', 'Aviso "' . $dados['nome'] . '" salvo' . ($dados['ativo'] ? ' e ligado.' : ' (desligado).'));
        redirect('/financeiro/avisos/listar.php');
    }
}

$sistemaPronto = $config['smtp_host'] && (trim((string) $config['smtp_remetente_email']) ?: trim((string) $config['smtp_usuario']));
$whatsPronto = whatsappConfigurado(whatsappConfig($pdo));
$tags = tagsAvisoCobranca() + tagsEmail();
unset($tags['{valor_em_atraso}'], $tags['{parcelas_em_atraso}'], $tags['{vencimento_mais_antigo}']);
$tags += ['{valor_em_atraso}' => 'Total em atraso do aluno (todas as parcelas)'];
$modelos = [];
foreach (array_keys(gatilhosAvisoCobranca()) as $g) {
    $modelos[$g] = modeloAvisoCobranca($g);
}

$pageTitle = $aviso ? 'Aviso: ' . e($aviso['nome']) : 'Novo aviso automático';
$pageBreadcrumb = 'Financeiro · Avisos automáticos';
$activeMenu = 'avisos-cobranca';
$pageAction = '<a href="/financeiro/avisos/listar.php" class="btn btn-outline-secondary">Cancelar</a>'
    . '<button type="button" class="btn btn-outline-secondary" onclick="enviarForm(\'teste\')" title="Manda este e-mail para você, com os dados de uma parcela de verdade"><i class="bi bi-send-check"></i> Enviar teste para mim</button>'
    . '<button type="button" class="btn btn-brand" onclick="enviarForm(\'salvar\')"><i class="bi bi-check-lg"></i> Salvar</button>';
require __DIR__ . '/../../../includes/layout_header.php';
?>
<link href="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.snow.css" rel="stylesheet">
<style>
    #editor-aviso { min-height: 260px; background: #fff; font-size: 14px; }
    #editor-aviso .ql-editor { min-height: 260px; }
    .tag-btn { cursor: pointer; font-family: ui-monospace, monospace; font-weight: 500; }
    .previa-email { border: 1px solid var(--bs-border-color); border-radius: 10px; padding: 16px; background: #fff; color: #202124; }
    .previa-whats { background: #e7fbd8; color: #111; border-radius: 10px; padding: 10px 12px; white-space: pre-wrap; font-size: 13px; word-break: break-word; }
    .bloco-meio[data-desligado] { opacity: .45; }
</style>

<?php if ($erro): ?><div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> <?= e($erro) ?></div><?php endif; ?>

<form method="post" id="form-aviso">
<input type="hidden" name="id" value="<?= (int) $id ?>">
<input type="hidden" name="acao" id="campo-acao" value="salvar">
<input type="hidden" name="email_html" id="campo-html">
<div class="row g-3">
    <div class="col-xl-8">
        <div class="secao">
            <div class="secao-titulo"><i class="bi bi-alarm"></i> Quando</div>
            <div class="row g-3 align-items-end">
                <div class="col-md-5">
                    <label class="form-label">Nome do aviso *</label>
                    <input type="text" name="nome" class="form-control" maxlength="120" value="<?= e($dados['nome']) ?>" placeholder="Ex.: Lembretes de vencimento">
                </div>
                <div class="col-md-7">
                    <label class="form-label">Enviar</label>
                    <select name="gatilho" id="gatilho" class="form-select" onchange="trocarGatilho()">
                        <?php foreach (gatilhosAvisoCobranca() as $chave => $rotulo): ?>
                            <option value="<?= e($chave) ?>" <?= $dados['gatilho'] === $chave ? 'selected' : '' ?>><?= e($rotulo) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div data-so="vencimento" class="mt-3">
                <label class="form-label mb-1">Avisar nestes dias</label>
                <div class="row g-2 align-items-center">
                    <div class="col-md-4">
                        <div class="input-group">
                            <input type="text" name="dias_antes" class="form-control" inputmode="numeric" placeholder="ex.: 5, 2"
                                   value="<?= e(implode(', ', array_map('abs', array_filter($diasAviso, fn ($d) => $d < 0)))) ?>">
                            <span class="input-group-text">dias antes</span>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-check mb-0"><input type="checkbox" class="form-check-input" name="no_dia" value="1" <?= in_array(0, $diasAviso, true) ? 'checked' : '' ?>> No dia do vencimento</label>
                    </div>
                    <div class="col-md-5">
                        <div class="input-group">
                            <input type="text" name="dias_depois" class="form-control" inputmode="numeric" placeholder="ex.: 3, 7"
                                   value="<?= e(implode(', ', array_filter($diasAviso, fn ($d) => $d > 0))) ?>">
                            <span class="input-group-text">dias depois (se não pagou)</span>
                        </div>
                    </div>
                </div>
                <div class="small mt-2"><i class="bi bi-calendar-check"></i> <span id="resumo-dias" class="fw-semibold"></span></div>
                <div class="form-text">Separe os dias por vírgula, quantos quiser. Cada dia é um aviso separado; use a tag {quando_vence} para o texto dizer "vence em 5 dias", "vence hoje" ou "venceu há 3 dias".</div>

                <div class="row g-3 mt-1">
                    <div class="col-md-3">
                        <label class="form-label">Enviar a partir das</label>
                        <input type="time" name="horario" class="form-control" value="<?= e($dados['horario']) ?>">
                    </div>
                    <div class="col-md-9">
                        <label class="form-label">Quais parcelas recebem o lembrete</label>
                        <label class="form-check"><input type="radio" class="form-check-input" name="so_com_boleto" value="1" <?= $dados['so_com_boleto'] ? 'checked' : '' ?>>
                            Só as que já têm boleto/Pix gerado no banco</label>
                        <label class="form-check"><input type="radio" class="form-check-input" name="so_com_boleto" value="0" <?= $dados['so_com_boleto'] ? '' : 'checked' ?>>
                            Todas as parcelas em aberto <span class="small text-muted">— inclusive as sem boleto (combinadas em dinheiro, cartão, ou boleto ainda não emitido)</span></label>
                    </div>
                </div>
            </div>
            <div class="small text-muted mt-2" id="explica-gatilho"></div>
        </div>

        <div class="secao bloco-meio" id="bloco-email">
            <div class="secao-titulo d-flex align-items-center">
                <label class="form-check form-switch mb-0"><input type="checkbox" class="form-check-input" name="enviar_email" value="1" id="enviar-email" <?= $dados['enviar_email'] ? 'checked' : '' ?> onchange="atualizarMeios()"> <i class="bi bi-envelope"></i> E-mail</label>
                <?php if (!$sistemaPronto): ?><span class="badge text-bg-warning ms-2">E-mail do sistema não configurado</span><?php endif; ?>
            </div>
            <div class="mb-3">
                <label class="form-label">Assunto</label>
                <input type="text" name="email_assunto" id="assunto" class="form-control" maxlength="250" value="<?= e($dados['email_assunto']) ?>">
            </div>
            <div class="mb-2 small">
                <span class="text-muted me-1">Tags — clique para colocar no texto (cada aluno recebe com os dados dele):</span>
                <?php foreach ($tags as $tag => $rotulo): ?>
                    <span class="badge text-bg-light border tag-btn" title="<?= e($rotulo) ?>" onmousedown="event.preventDefault(); inserirTag('<?= e($tag) ?>')"><?= e($tag) ?></span>
                <?php endforeach; ?>
            </div>
            <div id="editor-aviso"><?= $dados['email_html'] ?></div>
            <label class="form-check mt-2" data-so="emissao vencimento">
                <input type="checkbox" class="form-check-input" name="anexar_boleto" value="1" <?= $dados['anexar_boleto'] ? 'checked' : '' ?>>
                Anexar o boleto em PDF (quando a parcela tiver boleto emitido)
            </label>
        </div>

        <div class="secao bloco-meio" id="bloco-whatsapp">
            <div class="secao-titulo d-flex align-items-center">
                <label class="form-check form-switch mb-0"><input type="checkbox" class="form-check-input" name="enviar_whatsapp" value="1" id="enviar-whatsapp" <?= $dados['enviar_whatsapp'] ? 'checked' : '' ?> onchange="atualizarMeios()"> <i class="bi bi-whatsapp"></i> WhatsApp</label>
                <?php if (!$whatsPronto): ?><span class="badge text-bg-warning ms-2">WhatsApp não configurado</span><?php endif; ?>
            </div>
            <textarea name="whatsapp_texto" id="whatsapp-texto" class="form-control" rows="8"><?= e($dados['whatsapp_texto']) ?></textarea>
            <div class="form-text">Texto simples (as mesmas tags do e-mail). *negrito* e _itálico_ funcionam no WhatsApp.</div>
        </div>
    </div>

    <div class="col-xl-4">
        <div class="secao">
            <div class="secao-titulo"><i class="bi bi-toggle-on"></i> Situação</div>
            <label class="form-check form-switch">
                <input type="checkbox" class="form-check-input" name="ativo" value="1" <?= $dados['ativo'] ? 'checked' : '' ?>>
                Ligado (envia sozinho)
            </label>
            <div class="form-text">Desligado, fica guardado sem enviar nada.</div>
        </div>
        <div class="secao">
            <div class="secao-titulo"><i class="bi bi-eye"></i> Prévia <span class="small text-muted fw-normal ms-1">com dados de exemplo</span></div>
            <div id="previa-email-area">
                <div class="small text-muted mb-1">E-mail</div>
                <div class="previa-email mb-3"><div class="fw-semibold mb-2" id="previa-assunto"></div><div id="previa-corpo" class="small"></div></div>
            </div>
            <div id="previa-whats-area">
                <div class="small text-muted mb-1">WhatsApp</div>
                <div class="previa-whats" id="previa-whats"></div>
            </div>
        </div>
    </div>
</div>
</form>

<script src="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.js"></script>
<script>
['attributors/style/align', 'attributors/style/color', 'attributors/style/background'].forEach((a) => Quill.register(Quill.import(a), true));
const quill = new Quill('#editor-aviso', { theme: 'snow', modules: { toolbar: [
    ['bold', 'italic', 'underline'], [{ color: [] }, { background: [] }],
    [{ align: [] }, { list: 'ordered' }, { list: 'bullet' }], ['link', 'clean'],
] } });
const MODELOS = <?= json_encode($modelos, JSON_UNESCAPED_UNICODE) ?>;
const EXPLICA = {
    emissao: 'Sai logo depois que a secretaria emite o boleto/Pix no banco (os que o próprio aluno gera no portal não contam). Vários boletos do mesmo aluno emitidos juntos vão numa mensagem só.',
    vencimento: 'Cada lembrete sai no dia certo, a partir do horário escolhido, só se a parcela ainda estiver em aberto. Se o aluno pagar antes, os próximos lembretes não saem.',
    pagamento: 'Sai quando o pagamento é confirmado (pelo banco ou pela baixa manual).',
};
const form = document.getElementById('form-aviso');
let ultimoCampo = 'editor';
document.getElementById('assunto').addEventListener('focus', () => { ultimoCampo = 'assunto'; });
document.getElementById('whatsapp-texto').addEventListener('focus', () => { ultimoCampo = 'whatsapp'; });
quill.on('selection-change', (s) => { if (s) ultimoCampo = 'editor'; });
quill.on('text-change', atualizarPrevia);
form.addEventListener('input', atualizarPrevia);

function inserirTag(tag) {
    if (ultimoCampo === 'editor') {
        const p = (quill.getSelection(true) || { index: quill.getLength() - 1 }).index;
        quill.insertText(p, tag, 'user');
        quill.setSelection(p + tag.length, 0);
    } else {
        const c = document.getElementById(ultimoCampo === 'assunto' ? 'assunto' : 'whatsapp-texto');
        const p = c.selectionStart ?? c.value.length;
        c.value = c.value.slice(0, p) + tag + c.value.slice(c.selectionEnd ?? p);
        c.focus(); c.selectionStart = c.selectionEnd = p + tag.length;
    }
    atualizarPrevia();
}

// Trocar o gatilho de um aviso novo traz o texto pronto daquele momento
// (só se o texto ainda for o modelo — o que foi escrito à mão fica).
let gatilhoAtual = form.gatilho.value;
function trocarGatilho() {
    const novo = form.gatilho.value;
    const antigo = MODELOS[gatilhoAtual];
    if (antigo && form.email_assunto.value === antigo.email_assunto) form.email_assunto.value = MODELOS[novo].email_assunto;
    if (antigo && quill.root.innerHTML.replace(/\s/g, '') === document.getElementById('modelo-html-' + gatilhoAtual)?.innerHTML.replace(/\s/g, '')) {
        quill.clipboard.dangerouslyPasteHTML(MODELOS[novo].email_html);
    }
    if (antigo && form.whatsapp_texto.value.trim() === antigo.whatsapp_texto.trim()) form.whatsapp_texto.value = MODELOS[novo].whatsapp_texto;
    gatilhoAtual = novo;
    mostrarCampos();
    atualizarPrevia();
}
function mostrarCampos() {
    const g = form.gatilho.value;
    form.querySelectorAll('[data-so]').forEach((el) => { el.style.display = el.dataset.so.split(' ').includes(g) ? '' : 'none'; });
    document.getElementById('explica-gatilho').textContent = EXPLICA[g] || '';
    resumirDias();
}
// "Vai avisar: 5 e 2 dias antes, no dia e 3 dias depois do vencimento".
const numerosDe = (t) => [...new Set(String(t).split(/[^0-9]+/).map(Number).filter((n) => n >= 1 && n <= 365))].sort((a, b) => b - a);
function juntar(lista) {
    const fim = lista[lista.length - 1];
    return (lista.length > 1 ? lista.slice(0, -1).join(', ') + ' e ' + fim : String(fim)) + (lista.length === 1 && fim === 1 ? ' dia' : ' dias');
}
function resumirDias() {
    const antes = numerosDe(form.dias_antes.value);
    const depois = numerosDe(form.dias_depois.value).reverse();
    const partes = [antes.length ? juntar(antes) + ' antes' : '', form.no_dia.checked ? 'no dia' : '', depois.length ? juntar(depois) + ' depois' : ''].filter(Boolean);
    const el = document.getElementById('resumo-dias');
    el.className = partes.length ? 'fw-semibold' : 'fw-semibold text-danger';
    el.textContent = partes.length
        ? 'Vai avisar ' + (partes.length > 1 ? partes.slice(0, -1).join(', ') + ' e ' + partes[partes.length - 1] : partes[0]) + ' do vencimento.'
        : 'Escolha pelo menos um dia.';
}
form.addEventListener('input', resumirDias);
form.addEventListener('change', resumirDias);
function atualizarMeios() {
    const email = document.getElementById('enviar-email').checked;
    const whats = document.getElementById('enviar-whatsapp').checked;
    document.getElementById('bloco-email').toggleAttribute('data-desligado', !email);
    document.getElementById('bloco-whatsapp').toggleAttribute('data-desligado', !whats);
    document.getElementById('previa-email-area').style.display = email ? '' : 'none';
    document.getElementById('previa-whats-area').style.display = whats ? '' : 'none';
}

// Prévia com dados inventados (o teste manda com dados de verdade).
function exemplo() {
    const venc = new Date();
    const g = form.gatilho.value;
    // Prévia do lembrete: o primeiro dia escolhido (ex.: 5 dias antes).
    const antes = numerosDe(form.dias_antes.value);
    const depois = numerosDe(form.dias_depois.value).reverse();
    const desde = antes.length ? -antes[0] : form.no_dia.checked ? 0 : depois.length ? depois[0] : 0;
    venc.setDate(venc.getDate() + (g === 'vencimento' ? -desde : g === 'emissao' ? 30 : 0));
    const quandoVence = desde === 0 ? 'vence hoje' : desde === -1 ? 'vence amanhã' : desde < 0 ? 'vence em ' + -desde + ' dias' : desde === 1 ? 'venceu ontem' : 'venceu há ' + desde + ' dias';
    const v = venc.toLocaleDateString('pt-BR');
    const parcela = { descricao: 'Mensalidade 3/12 - Turma Exemplo', valor: 'R$ 450,00', linha: '34191.79001 01043.510047 91020.150008 1 99990000045000', pix: '00020101021226...exemplo' };
    return {
        '{nome_aluno}': 'Maria da Silva Souza', '{primeiro_nome}': 'Maria', '{email_aluno}': 'maria@exemplo.com', '{cpf}': '123.***.***-**',
        '{curso}': 'Curso de Exemplo', '{turma}': 'Turma Exemplo', '{data_inicio_turma}': '10/02/2027', '{horario_turma}': 'Sábados, 8h às 12h',
        '{link_portal}': 'https://portal.exemplo.com/portal/', '{link_pagamento}': 'https://portal.exemplo.com/portal/financeiro.php',
        '{empresa}': <?= json_encode((string) ($config['empresa_nome_fantasia'] ?: ($config['empresa_razao_social'] ?: nomeSistema())), JSON_UNESCAPED_UNICODE) ?>,
        '{telefone_empresa}': <?= json_encode((string) ($config['empresa_telefone'] ?? ''), JSON_UNESCAPED_UNICODE) ?>,
        '{data_hoje}': new Date().toLocaleDateString('pt-BR'), '{valor_em_atraso}': 'R$ 0,00',
        '{valor_total}': parcela.valor, '{vencimento}': v, '{descricao_parcela}': parcela.descricao,
        '{linha_digitavel}': parcela.linha, '{quando_vence}': quandoVence, '{pix_copia_cola}': parcela.pix, '{data_pagamento}': new Date().toLocaleDateString('pt-BR'),
        '{parcelas}': parcela, // tratado à parte (HTML no e-mail, texto no WhatsApp)
    };
}
// escapeHtml() vem no rodapé (carrega depois desta página); aqui tem a própria.
const esc = (t) => String(t ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
function trocar(texto, valores, html) {
    return texto.replace(/\{[a-z_]+\}/g, (t) => {
        if (!(t in valores)) return t;
        if (t !== '{parcelas}') return html ? esc(valores[t]) : valores[t];
        const p = valores[t];
        return html
            ? '<div style="border:1px solid #ddd;border-radius:6px;padding:8px 10px;margin:6px 0"><strong>' + esc(p.descricao) + '</strong><br>Vencimento: ' + esc(valores['{vencimento}'])
              + ' · Valor: <strong>' + p.valor + '</strong><br>Linha digitável: <span style="font-family:monospace">' + p.linha + '</span><br>Pix copia e cola: <span style="font-family:monospace">' + p.pix + '</span></div>'
            : '• ' + p.descricao + ' — vence ' + valores['{vencimento}'] + ' — ' + p.valor + '\n  Linha digitável: ' + p.linha + '\n  Pix copia e cola: ' + p.pix;
    });
}
function atualizarPrevia() {
    const valores = exemplo();
    document.getElementById('previa-assunto').textContent = trocar(form.email_assunto.value, valores, false);
    document.getElementById('previa-corpo').innerHTML = trocar(quill.root.innerHTML, valores, true);
    document.getElementById('previa-whats').textContent = trocar(form.whatsapp_texto.value, valores, false);
}
function enviarForm(acao) {
    document.getElementById('campo-acao').value = acao;
    document.getElementById('campo-html').value = quill.root.innerHTML;
    form.submit();
}
mostrarCampos();
atualizarMeios();
atualizarPrevia();
</script>
<?php foreach ($modelos as $g => $m): ?><template id="modelo-html-<?= e($g) ?>"><?= $m['email_html'] ?></template><?php endforeach; ?>
<?php require __DIR__ . '/../../../includes/layout_footer.php'; ?>
