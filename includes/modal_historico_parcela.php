<?php
// Modal "Histórico da parcela": inclua na página e chame
// abrirHistoricoParcela(id). O conteúdo vem de /financeiro/contas_receber/historico.php.
?>
<div class="modal fade" id="modal-historico-parcela" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-clock-history"></i> Histórico da parcela</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="historico-parcela-corpo"></div>
        </div>
    </div>
</div>
<script>
function abrirHistoricoParcela(id) {
    const corpo = document.getElementById('historico-parcela-corpo');
    corpo.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-secondary"></div></div>';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-historico-parcela')).show();
    fetch('/financeiro/contas_receber/historico.php?id=' + encodeURIComponent(id), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then((r) => r.text())
        .then((html) => { corpo.innerHTML = html; })
        .catch(() => { corpo.innerHTML = '<div class="alert alert-danger mb-0">Não foi possível carregar o histórico.</div>'; });
}

// Mostra o que o Itaú tem registrado do(s) boleto(s) da parcela.
function consultarBoletoNoItau(id, botao) {
    const alvo = document.getElementById('consulta-itau-' + id);
    botao.disabled = true;
    alvo.innerHTML = '<div class="text-center py-3"><div class="spinner-border spinner-border-sm text-secondary"></div> Consultando o Itaú...</div>';
    fetch('/financeiro/contas_receber/consultar_itau.php?id=' + encodeURIComponent(id), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then((r) => r.text())
        .then((html) => { alvo.innerHTML = html; })
        .catch(() => { alvo.innerHTML = '<div class="alert alert-danger mb-0">Não foi possível consultar o Itaú.</div>'; })
        .finally(() => { botao.disabled = false; });
}
</script>
