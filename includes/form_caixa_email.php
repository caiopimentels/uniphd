<?php
// Campos da caixa de e-mail de um usuário (dentro de um <form>). Espera
// $caixaUsuario (linha de usuarios ou []) e $caixaUsuarioId (null = novo).
$caixaTemSenha = !empty($caixaUsuario['email_caixa_senha_cripto']);
?>
<div class="secao" style="max-width: 760px;">
    <div class="secao-titulo"><i class="bi bi-envelope"></i> Caixa de e-mail <span class="small text-muted fw-normal ms-1">(menu E-mail)</span></div>
    <p class="small text-muted">O e-mail e a senha da caixa dele no provedor (o servidor fica em Configurações › E-mail). Com isso ele lê, responde e envia e-mails pelo sistema — cada um só vê a própria caixa.</p>
    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label">E-mail da caixa</label>
            <input type="email" name="email_caixa" id="caixa-email" class="form-control" value="<?= e($caixaUsuario['email_caixa'] ?? '') ?>" placeholder="<?= e(($caixaUsuario['email'] ?? '') ?: 'nome@seudominio.com.br') ?>" autocomplete="off">
            <div class="form-text">Em branco: o mesmo e-mail do login.</div>
        </div>
        <div class="col-md-6">
            <label class="form-label">Senha do e-mail</label>
            <input type="password" name="email_caixa_senha" id="caixa-senha" class="form-control" autocomplete="new-password" placeholder="<?= $caixaTemSenha ? 'Guardada — em branco para manter' : 'Senha da caixa no provedor' ?>">
            <?php if ($caixaTemSenha): ?>
                <div class="form-check mt-1"><input class="form-check-input" type="checkbox" name="email_caixa_remover_senha" id="caixa-remover" value="1"><label class="form-check-label small" for="caixa-remover">Tirar a senha (desliga a caixa)</label></div>
            <?php endif; ?>
            <div class="form-text">Guardada criptografada; ninguém consegue ver depois.</div>
        </div>
        <div class="col-md-6">
            <label class="form-label">Nome que aparece para quem recebe</label>
            <input type="text" name="email_remetente_nome" class="form-control" maxlength="150" value="<?= e($caixaUsuario['email_remetente_nome'] ?? '') ?>" placeholder="<?= e(($caixaUsuario['nome'] ?? '') ?: 'Ex.: Keyla — Secretaria UniPHD') ?>">
        </div>
        <div class="col-md-6 d-flex align-items-end">
            <button type="button" class="btn btn-outline-secondary" onclick="testarCaixaUsuario(this)"><i class="bi bi-plug"></i> Testar conexão</button>
        </div>
        <div class="col-12" id="caixa-teste"></div>
        <div class="col-12">
            <label class="form-label">Assinatura <span class="small text-muted">(entra no fim de cada e-mail novo)</span></label>
            <div id="editor-assinatura" style="background: #fff;"><?= limparHtmlContrato((string) ($caixaUsuario['email_assinatura'] ?? ''), true) ?></div>
            <input type="hidden" name="email_assinatura" id="campo-assinatura">
        </div>
    </div>
</div>
<link href="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.snow.css" rel="stylesheet">
<style>#editor-assinatura.ql-container { height: auto; } #editor-assinatura .ql-editor { min-height: 110px; }</style>
<script src="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.js"></script>
<script>
(() => {
    // Estilo embutido (o que os leitores de e-mail entendem), como no Escrever.
    ['attributors/style/size', 'attributors/style/align', 'attributors/style/color', 'attributors/style/font'].forEach((a) => Quill.register(Quill.import(a), true));
    const tamanho = Quill.import('attributors/style/size');
    tamanho.whitelist = ['11px', '13px', '16px'];
    Quill.register(tamanho, true);
    const quill = new Quill('#editor-assinatura', { theme: 'snow', placeholder: 'Ex.: Keyla Lane — Secretaria UniPHD · (77) 98111-8760',
        modules: { toolbar: [['bold', 'italic', 'underline'], [{ color: [] }], [{ size: [false, '11px', '16px'] }], ['link', 'image', 'clean']] } });
    const campo = document.getElementById('campo-assinatura');
    const sincronizar = () => { campo.value = quill.getLength() > 1 || quill.root.querySelector('img') ? quill.getSemanticHTML() : ''; };
    sincronizar();
    quill.on('text-change', sincronizar);
})();
async function testarCaixaUsuario(botao) {
    botao.disabled = true;
    const area = document.getElementById('caixa-teste');
    area.innerHTML = '<span class="small text-muted">Conectando ao servidor de e-mail...</span>';
    const dados = new FormData();
    dados.append('usuario_id', <?= json_encode($caixaUsuarioId) ?> ?? '');
    dados.append('email', document.getElementById('caixa-email').value.trim() || document.getElementById('caixa-email').placeholder);
    dados.append('senha', document.getElementById('caixa-senha').value);
    try {
        const r = await (await fetch('/email/testar.php', { method: 'POST', body: dados })).json();
        area.innerHTML = (r.resultado || [{ ok: false, texto: r.mensagem || 'Falhou.' }]).map((x) =>
            `<div class="small ${x.ok ? 'text-success' : 'text-danger'}"><i class="bi ${x.ok ? 'bi-check-circle' : 'bi-x-circle'}"></i> ${escapeHtml(x.texto)}</div>`).join('');
    } catch (e) {
        area.innerHTML = '<span class="small text-danger">Não foi possível testar agora.</span>';
    }
    botao.disabled = false;
}
</script>
