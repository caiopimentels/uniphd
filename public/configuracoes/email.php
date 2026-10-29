<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirAdmin();

// Configurações › E-mail: servidor das caixas dos usuários (receber pelo
// IMAP, enviar pelo SMTP) — igual para todos; cada usuário só tem o
// e-mail e a senha no cadastro dele. E o ritmo do disparo em massa.
$pdo = getDB();
$config = buscarConfiguracoes($pdo);

$campos = [
    'email_imap_host' => 'Servidor de entrada (IMAP)',
    'email_imap_porta' => 'Porta IMAP',
    'email_imap_seguranca' => 'Segurança IMAP',
    'email_smtp_host' => 'Servidor de saída (SMTP)',
    'email_smtp_porta' => 'Porta SMTP',
    'email_smtp_seguranca' => 'Segurança SMTP',
    'email_verificar_certificado' => 'Conferir certificado',
    'email_anexo_max_mb' => 'Limite de anexos (MB)',
    'email_disparo_por_hora' => 'Disparo: e-mails por hora',
    'email_disparo_remetente' => 'Disparo: sai de',
];
$segurancas = ['ssl' => 'SSL (porta 993 / 465)', 'tls' => 'STARTTLS (porta 143 / 587)', 'none' => 'Nenhuma (não recomendado)'];
$dados = [];
foreach ($campos as $campo => $rotulo) {
    $dados[$campo] = $config[$campo] ?? '';
}
$erros = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dados = [
        'email_imap_host' => strtolower(trim(input('email_imap_host'))),
        'email_imap_porta' => max(1, min(65535, (int) ($_POST['email_imap_porta'] ?? 993))),
        'email_imap_seguranca' => array_key_exists(input('email_imap_seguranca'), $segurancas) ? input('email_imap_seguranca') : 'ssl',
        'email_smtp_host' => strtolower(trim(input('email_smtp_host'))),
        'email_smtp_porta' => max(1, min(65535, (int) ($_POST['email_smtp_porta'] ?? 465))),
        'email_smtp_seguranca' => array_key_exists(input('email_smtp_seguranca'), $segurancas) ? input('email_smtp_seguranca') : 'ssl',
        'email_verificar_certificado' => isset($_POST['email_verificar_certificado']) ? 1 : 0,
        'email_anexo_max_mb' => max(1, min(50, (int) ($_POST['email_anexo_max_mb'] ?? 20))),
        'email_disparo_por_hora' => max(1, min(5000, (int) ($_POST['email_disparo_por_hora'] ?? 150))),
        'email_disparo_remetente' => input('email_disparo_remetente') === 'sistema' ? 'sistema' : 'usuario',
    ];
    foreach (['email_imap_host', 'email_smtp_host'] as $campo) {
        if ($dados[$campo] !== '' && !preg_match('/^[a-z0-9.-]+$/', $dados[$campo])) {
            $erros[] = $campos[$campo] . ': só o endereço do servidor (ex.: mail.seudominio.com.br), sem http:// nem barra.';
        }
    }
    if (!$erros) {
        $sets = implode(', ', array_map(fn ($c) => "{$c} = ?", array_keys($dados)));
        $pdo->prepare("UPDATE configuracoes SET {$sets} WHERE id = 1")->execute(array_map(fn ($v) => $v === '' ? null : $v, array_values($dados)));
        $antes = array_intersect_key($config, $dados);
        registrarAtividade('configuracoes', 'edicao', 'E-mail: ' . (descreverAlteracoes($antes, $dados, $campos) ?: 'sem alterações'));
        flash('success', 'Configurações de e-mail salvas.');
        redirect('/configuracoes/email.php');
    }
}

$cfg = emailConfig($pdo);
$minhaCaixa = caixaUsuario($pdo, (int) usuarioAtualId());

$pageTitle = 'Configurações';
$pageBreadcrumb = 'Administração';
$activeMenu = 'configuracoes';
$pageAction = '<button type="submit" form="form-email" class="btn btn-brand"><i class="bi bi-check-lg"></i> Salvar</button>';
require __DIR__ . '/../../includes/layout_header.php';
$abaAtiva = 'email';
require __DIR__ . '/../../includes/abas_configuracoes.php';
?>

<?php if ($erros): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($erros as $erro): ?><li><?= e($erro) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<p class="text-muted" style="max-width: 900px;">
    Servidor das <strong>caixas de e-mail dos usuários</strong> (menu E-mail). É o mesmo para todos: cada usuário só coloca o
    <strong>e-mail e a senha dele</strong> no cadastro (Usuários). Os dados ficam com o seu provedor de e-mail (hospedagem);
    em geral o servidor é <code>mail.seudominio.com.br</code>, com SSL nas portas 993 (entrada) e 465 (saída).
</p>

<?php $av = situacaoAntivirus(); ?>
<div class="alert <?= $av['ativo'] ? 'alert-success' : 'alert-warning' ?> py-2 small" style="max-width: 900px;"><i class="bi <?= $av['ativo'] ? 'bi-shield-check' : 'bi-shield-exclamation' ?>"></i> <strong>Antivírus:</strong> <?= e($av['texto']) ?></div>
<form method="post" id="form-email">
<div class="secao" style="max-width: 900px;">
    <div class="secao-titulo"><i class="bi bi-inbox"></i> Receber (entrada — IMAP)</div>
    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label">Servidor</label>
            <input type="text" name="email_imap_host" class="form-control" value="<?= e($dados['email_imap_host']) ?>" placeholder="<?= e($config['smtp_host'] ?: 'mail.seudominio.com.br') ?>">
            <div class="form-text">Em branco: usa o mesmo servidor de saída.</div>
        </div>
        <div class="col-md-2">
            <label class="form-label">Porta</label>
            <input type="number" name="email_imap_porta" class="form-control" value="<?= (int) ($dados['email_imap_porta'] ?: 993) ?>">
        </div>
        <div class="col-md-4">
            <label class="form-label">Segurança</label>
            <select name="email_imap_seguranca" class="form-select">
                <?php foreach ($segurancas as $v => $r): ?><option value="<?= $v ?>" <?= ($dados['email_imap_seguranca'] ?: 'ssl') === $v ? 'selected' : '' ?>><?= e($r) ?></option><?php endforeach; ?>
            </select>
        </div>
    </div>
</div>

<div class="secao" style="max-width: 900px;">
    <div class="secao-titulo"><i class="bi bi-send"></i> Enviar (saída — SMTP)</div>
    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label">Servidor</label>
            <input type="text" name="email_smtp_host" class="form-control" value="<?= e($dados['email_smtp_host']) ?>" placeholder="<?= e($config['smtp_host'] ?: 'mail.seudominio.com.br') ?>">
            <div class="form-text">Em branco: usa o do e-mail do sistema (Configurações › Sistema<?= $config['smtp_host'] ? ': ' . e($config['smtp_host']) . ':' . (int) $config['smtp_porta'] : '' ?>).</div>
        </div>
        <div class="col-md-2">
            <label class="form-label">Porta</label>
            <input type="number" name="email_smtp_porta" class="form-control" value="<?= (int) ($dados['email_smtp_porta'] ?: 465) ?>">
        </div>
        <div class="col-md-4">
            <label class="form-label">Segurança</label>
            <select name="email_smtp_seguranca" class="form-select">
                <?php foreach ($segurancas as $v => $r): ?><option value="<?= $v ?>" <?= ($dados['email_smtp_seguranca'] ?: 'ssl') === $v ? 'selected' : '' ?>><?= e($r) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-6">
            <label class="form-label">Limite de anexos por e-mail</label>
            <div class="input-group"><input type="number" name="email_anexo_max_mb" class="form-control" min="1" max="50" value="<?= (int) ($dados['email_anexo_max_mb'] ?: 20) ?>"><span class="input-group-text">MB</span></div>
            <div class="form-text">Quase todo provedor recusa acima de 20–25 MB.</div>
        </div>
        <div class="col-md-6 d-flex align-items-center">
            <div class="form-check form-switch mt-3">
                <input class="form-check-input" type="checkbox" name="email_verificar_certificado" id="verificar" <?= ($dados['email_verificar_certificado'] ?? 1) ? 'checked' : '' ?>>
                <label class="form-check-label" for="verificar">Conferir o certificado do servidor</label>
                <div class="form-text mt-0">Desligue só se der erro de certificado (hospedagem que usa certificado com outro nome).</div>
            </div>
        </div>
    </div>
</div>

<div class="secao" style="max-width: 900px;">
    <div class="secao-titulo"><i class="bi bi-megaphone"></i> Disparo em massa</div>
    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label">E-mails por hora</label>
            <input type="number" name="email_disparo_por_hora" class="form-control" min="1" max="5000" value="<?= (int) ($dados['email_disparo_por_hora'] ?: 150) ?>">
            <div class="form-text">O disparo vai saindo aos poucos dentro deste limite. Hospedagem compartilhada costuma limitar entre 100 e 500 por hora; acima disso o provedor bloqueia ou manda para spam.</div>
        </div>
        <div class="col-md-6">
            <label class="form-label">Sai de qual e-mail</label>
            <select name="email_disparo_remetente" class="form-select">
                <option value="usuario" <?= $dados['email_disparo_remetente'] !== 'sistema' ? 'selected' : '' ?>>Da caixa de quem cria o disparo</option>
                <option value="sistema" <?= $dados['email_disparo_remetente'] === 'sistema' ? 'selected' : '' ?>>Do e-mail do sistema (Configurações › Sistema)</option>
            </select>
            <div class="form-text">Padrão de cada disparo novo (dá para trocar na hora de disparar).</div>
        </div>
    </div>
</div>
</form>

<div class="secao" style="max-width: 900px;">
    <div class="secao-titulo"><i class="bi bi-plug"></i> Testar</div>
    <?php if ($minhaCaixa['configurada']): ?>
        <p class="small text-muted mb-2">Testa com a <strong>sua</strong> caixa (<?= e($minhaCaixa['email']) ?>), com as configurações já salvas.</p>
        <button type="button" class="btn btn-outline-secondary" onclick="testarCaixa(this)"><i class="bi bi-plug"></i> Testar conexão</button>
        <div id="resultado-teste" class="mt-2"></div>
    <?php else: ?>
        <p class="small text-muted mb-0">Para testar, coloque a senha do seu e-mail no seu cadastro de usuário (Usuários › você › Caixa de e-mail) — lá também tem o botão "Testar".</p>
    <?php endif; ?>
</div>

<script>
async function testarCaixa(botao) {
    botao.disabled = true;
    const area = document.getElementById('resultado-teste');
    area.innerHTML = '<span class="small text-muted">Conectando...</span>';
    try {
        const r = await (await fetch('/email/testar.php', { method: 'POST', body: new FormData() })).json();
        area.innerHTML = (r.resultado || [{ ok: false, texto: r.mensagem || 'Falhou.' }]).map((x) =>
            `<div class="small ${x.ok ? 'text-success' : 'text-danger'}"><i class="bi ${x.ok ? 'bi-check-circle' : 'bi-x-circle'}"></i> ${escapeHtml(x.texto)}</div>`).join('');
    } catch (e) {
        area.innerHTML = '<span class="small text-danger">Não foi possível testar agora.</span>';
    }
    botao.disabled = false;
}
</script>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
