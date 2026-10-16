<?php
// Modal "Pagar com Pix" das parcelas. Basta incluir na página e chamar
// abrirPix(idDaParcela) — os dados vêm de /financeiro/pix.php.
?>
<div class="modal fade" id="modal-pix" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-qr-code"></i> Pagar com Pix</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <div id="pix-carregando" class="text-muted py-4">Carregando...</div>
                <div id="pix-conteudo" class="d-none">
                    <div class="fw-semibold" id="pix-descricao"></div>
                    <div class="fs-4 fw-bold" id="pix-valor"></div>
                    <div class="small text-muted">Vencimento <span id="pix-vencimento"></span> · <span id="pix-pagador"></span></div>
                    <div class="small text-success fw-semibold d-none" id="pix-desconto"></div>
                    <div class="mb-3"></div>

                    <div class="pix-qr-caixa mx-auto mb-3">
                        <div id="pix-qr"></div>
                        <div id="pix-selo" class="pix-selo d-none">QR Code disponível após o boleto ser registrado no Itaú</div>
                    </div>

                    <div id="pix-aviso" class="alert alert-warning text-start small d-none"></div>

                    <div id="pix-copia-area" class="text-start d-none">
                        <label class="form-label small mb-1">Pix copia e cola</label>
                        <div class="input-group">
                            <input type="text" class="form-control form-control-sm font-monospace" id="pix-copia" readonly>
                            <button type="button" class="btn btn-sm btn-brand" id="pix-copiar" onclick="copiarPix()"><i class="bi bi-clipboard"></i> Copiar</button>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer justify-content-between">
                <a href="#" target="_blank" rel="noopener" class="btn btn-outline-secondary btn-sm" id="pix-boleto"><i class="bi bi-upc-scan"></i> Ver boleto</a>
                <a href="#" target="_blank" rel="noopener" class="btn btn-success btn-sm d-none" id="pix-whatsapp"><i class="bi bi-whatsapp"></i> Enviar ao aluno</a>
            </div>
        </div>
    </div>
</div>

<style>
.pix-qr-caixa { width: 220px; height: 220px; position: relative; }
.pix-qr-caixa #pix-qr img, .pix-qr-caixa #pix-qr canvas { width: 220px !important; height: 220px !important; }
.pix-qr-caixa.borrado #pix-qr { filter: blur(5px); opacity: .5; }
.pix-selo { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; padding: 1.5rem; font-weight: 600; font-size: .85rem; color: #b02a37; }
</style>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
function abrirPix(parcelaId) {
    const modal = document.getElementById('modal-pix');
    document.getElementById('pix-carregando').classList.remove('d-none');
    document.getElementById('pix-conteudo').classList.add('d-none');
    bootstrap.Modal.getOrCreateInstance(modal).show();

    fetch('/financeiro/pix.php?id=' + encodeURIComponent(parcelaId), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then((r) => r.json())
        .then((d) => {
            if (!d.sucesso) {
                document.getElementById('pix-carregando').textContent = d.mensagem || 'Não foi possível carregar o Pix.';
                return;
            }
            document.getElementById('pix-descricao').textContent = d.descricao;
            document.getElementById('pix-valor').textContent = d.valor;
            document.getElementById('pix-vencimento').textContent = d.vencimento;
            document.getElementById('pix-pagador').textContent = d.pagador;
            document.getElementById('pix-boleto').href = d.boleto_url;
            const desconto = document.getElementById('pix-desconto');
            desconto.textContent = d.desconto || '';
            desconto.classList.toggle('d-none', !d.desconto);

            // Sem registro não há Pix: desenha um QR com texto neutro (não leva
            // a pagamento nenhum), borrado, só para mostrar onde ele aparecerá.
            const alvo = document.getElementById('pix-qr');
            alvo.innerHTML = '';
            new QRCode(alvo, { text: d.copia_cola || 'PREVIA - PIX NAO REGISTRADO', width: 256, height: 256, correctLevel: QRCode.CorrectLevel.M });
            document.querySelector('.pix-qr-caixa').classList.toggle('borrado', !d.registrado);
            document.getElementById('pix-selo').classList.toggle('d-none', d.registrado);

            document.getElementById('pix-copia-area').classList.toggle('d-none', !d.registrado);
            document.getElementById('pix-copia').value = d.copia_cola || '';

            const aviso = document.getElementById('pix-aviso');
            if (!d.registrado) {
                let html = 'O Pix desta parcela é <strong>dinâmico</strong>: o Itaú gera o QR Code junto com o boleto, no registro da cobrança.';
                if (d.pendencias.length) {
                    html += '<br>Falta para registrar: <ul class="mb-0">' + d.pendencias.map((p) => '<li>' + escapeHtml(p) + '</li>').join('') + '</ul>';
                }
                aviso.innerHTML = html;
                aviso.classList.remove('d-none');
            } else {
                aviso.classList.add('d-none');
            }

            const whatsapp = document.getElementById('pix-whatsapp');
            whatsapp.classList.toggle('d-none', !d.whatsapp);
            whatsapp.href = d.whatsapp || '#';

            document.getElementById('pix-carregando').classList.add('d-none');
            document.getElementById('pix-conteudo').classList.remove('d-none');
        })
        .catch(() => { document.getElementById('pix-carregando').textContent = 'Erro de rede ao carregar o Pix.'; });
}

function copiarPix() {
    const campo = document.getElementById('pix-copia');
    navigator.clipboard.writeText(campo.value).then(() => {
        const botao = document.getElementById('pix-copiar');
        botao.innerHTML = '<i class="bi bi-check-lg"></i> Copiado';
        setTimeout(() => { botao.innerHTML = '<i class="bi bi-clipboard"></i> Copiar'; }, 2000);
    });
}
</script>
