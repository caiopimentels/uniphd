<?php
// Confirmação em modal (no lugar do confirm() do navegador): inclua na
// página e chame confirmarNoModal({ titulo, html, botao, classeBotao }),
// que devolve uma Promise com true (confirmou) ou false.
?>
<div class="modal fade" id="modal-confirmar" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="confirmar-titulo"></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="confirmar-corpo"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Voltar</button>
                <button type="button" class="btn" id="confirmar-ok"></button>
            </div>
        </div>
    </div>
</div>
<script>
function confirmarNoModal({ titulo = 'Confirmar', html = '', botao = 'Confirmar', classeBotao = 'btn-brand' } = {}) {
    const modal = document.getElementById('modal-confirmar');
    document.getElementById('confirmar-titulo').textContent = titulo;
    document.getElementById('confirmar-corpo').innerHTML = html;
    const ok = document.getElementById('confirmar-ok');
    ok.className = 'btn ' + classeBotao;
    ok.textContent = botao;
    return new Promise((resolver) => {
        let confirmou = false;
        ok.onclick = () => { confirmou = true; bootstrap.Modal.getInstance(modal).hide(); };
        modal.addEventListener('hidden.bs.modal', () => resolver(confirmou), { once: true });
        bootstrap.Modal.getOrCreateInstance(modal).show();
    });
}
</script>
