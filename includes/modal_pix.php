<?php
// Modal "Pix e boleto" das parcelas. Basta incluir na página e chamar
// abrirPix(idDaParcela) ou abrirBoleto(idDaParcela): os dois pedem a
// /financeiro/pix.php para gerar (registrar no Itaú) a cobrança, se ainda não
// houver. O Pix mostra o QR Code, o copia e cola e a linha digitável; o
// boleto abre direto numa aba nova (o modal só aparece se der erro).
?>
<div class="modal fade" id="modal-pix" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-qr-code"></i> Pix e boleto</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <div id="pix-carregando" class="py-4">
                    <div class="spinner-border text-secondary mb-3" role="status"></div>
                    <div class="fw-semibold" id="pix-carregando-texto">Gerando o boleto e o Pix no Itaú...</div>
                    <div class="small text-muted">Isso leva alguns segundos.</div>
                </div>
                <div id="pix-falha" class="alert alert-danger text-start small d-none"></div>
                <div id="pix-consulta" class="alert text-start small d-none"></div>
                <div id="pix-conteudo" class="d-none">
                    <div class="fw-semibold" id="pix-descricao"></div>
                    <div class="fs-4 fw-bold" id="pix-valor"></div>
                    <div class="small text-muted">Vencimento <span id="pix-vencimento"></span> · <span id="pix-pagador"></span></div>
                    <div class="small text-success fw-semibold d-none" id="pix-desconto"></div>
                    <div id="pix-registrado" class="badge text-bg-success my-2 d-none"><i class="bi bi-check-circle"></i> Registrado no Itaú</div>
                    <div class="mb-2"></div>

                    <div class="pix-qr-caixa mx-auto mb-3">
                        <div id="pix-qr"></div>
                        <div id="pix-selo" class="pix-selo d-none">QR Code disponível após o boleto ser registrado no Itaú</div>
                    </div>

                    <div id="pix-aviso" class="alert alert-warning text-start small d-none"></div>

                    <div id="pix-copia-area" class="text-start mb-3 d-none">
                        <label class="form-label small mb-1 text-success fw-semibold"><i class="bi bi-check-circle-fill"></i> Pix copia e cola</label>
                        <div class="input-group">
                            <input type="text" class="form-control form-control-sm font-monospace campo-gerado" id="pix-copia" readonly>
                            <button type="button" class="btn btn-sm btn-success" onclick="copiarCampoPix('pix-copia', this)"><i class="bi bi-clipboard"></i> Copiar</button>
                        </div>
                    </div>
                    <div id="pix-linha-area" class="text-start d-none">
                        <label class="form-label small mb-1 text-success fw-semibold"><i class="bi bi-check-circle-fill"></i> Linha digitável do boleto</label>
                        <div class="input-group">
                            <input type="text" class="form-control form-control-sm font-monospace campo-gerado" id="pix-linha" readonly>
                            <button type="button" class="btn btn-sm btn-success" onclick="copiarCampoPix('pix-linha', this)"><i class="bi bi-clipboard"></i> Copiar</button>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer justify-content-between">
                <div class="d-flex gap-2">
                    <a href="#" target="_blank" rel="noopener" class="btn btn-outline-secondary btn-sm" id="pix-boleto"><i class="bi bi-printer"></i> Abrir boleto</a>
                    <button type="button" class="btn btn-outline-primary btn-sm d-none" id="pix-consultar" onclick="consultarPagamentoPix()"><i class="bi bi-search"></i> Consultar pagamento no banco</button>
                    <button type="button" class="btn btn-outline-danger btn-sm d-none" id="pix-tentar" onclick="(modoPixAtual === 'boleto' ? abrirBoleto : abrirPix)(parcelaPixAtual, true)"><i class="bi bi-arrow-repeat"></i> Tentar de novo</button>
                </div>
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
.campo-gerado { border-color: var(--bs-success) !important; background: rgba(25, 135, 84, .08) !important; }
</style>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
let parcelaPixAtual = null;
let modoPixAtual = 'pix';

// 47 dígitos -> "34191.09008 01000.027951 09943.630005 3 16010000000100"
function formatarLinhaDigitavel(d) {
    d = String(d || '').replace(/\D/g, '');
    if (d.length !== 47) return d;
    return d.slice(0, 5) + '.' + d.slice(5, 10) + ' ' + d.slice(10, 15) + '.' + d.slice(15, 21) + ' '
        + d.slice(21, 26) + '.' + d.slice(26, 32) + ' ' + d.slice(32, 33) + ' ' + d.slice(33);
}

function prepararModalPix(parcelaId, modo) {
    parcelaPixAtual = parcelaId;
    modoPixAtual = modo;
    document.getElementById('pix-carregando-texto').textContent = modo === 'boleto' ? 'Gerando o boleto no Itaú...' : 'Gerando o Pix no Itaú...';
    document.getElementById('pix-carregando').classList.remove('d-none');
    document.getElementById('pix-conteudo').classList.add('d-none');
    document.getElementById('pix-falha').classList.add('d-none');
    document.getElementById('pix-tentar').classList.add('d-none');
    document.getElementById('pix-consultar').classList.add('d-none');
    document.getElementById('pix-consulta').classList.add('d-none');
    bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-pix')).show();
}

function gerarCobrancaPix(parcelaId, forcar) {
    const dados = new URLSearchParams({ id: parcelaId, gerar: 1 });
    if (forcar) dados.append('forcar', 1);
    return fetch('/financeiro/pix.php', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: dados }).then((r) => r.json());
}

function falhaRedePix() {
    document.getElementById('pix-carregando').classList.add('d-none');
    const falha = document.getElementById('pix-falha');
    falha.textContent = 'Erro de rede ao falar com o sistema. Tente de novo.';
    falha.classList.remove('d-none');
    document.getElementById('pix-tentar').classList.remove('d-none');
}

// Boleto: a aba é aberta já no clique (depois de esperar o banco o navegador
// a bloquearia como pop-up) mostrando "Gerando..."; quando o banco responde,
// ela vai para o boleto. Deu erro: fecha a aba e o modal mostra o motivo.
function abrirBoleto(parcelaId, forcar) {
    const aba = window.open('', '_blank');
    if (aba) {
        aba.document.write('<!doctype html><meta charset="utf-8"><title>Gerando boleto...</title>'
            + '<body style="font-family:system-ui,sans-serif;display:flex;align-items:center;justify-content:center;height:90vh;color:#555">'
            + '<div style="text-align:center"><div style="width:40px;height:40px;margin:0 auto 16px;border:4px solid #ddd;border-top-color:#666;border-radius:50%;animation:g 1s linear infinite"></div>'
            + '<b>Gerando o boleto no Itaú...</b><br><small>Isso leva alguns segundos.</small></div>'
            + '<style>@keyframes g{to{transform:rotate(360deg)}}</style></body>');
        aba.document.close();
    }
    prepararModalPix(parcelaId, 'boleto');
    gerarCobrancaPix(parcelaId, forcar)
        .then((d) => {
            const pronto = d.sucesso && !d.erro_geracao && (d.boleto_registrado || !d.pendencias.length);
            if (pronto && aba) {
                aba.location.href = d.boleto_url;
                bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-pix')).hide();
                return;
            }
            if (aba) aba.close();
            mostrarCobrancaPix(d);
        })
        .catch(() => {
            if (aba) aba.close();
            falhaRedePix();
        });
}

function abrirPix(parcelaId, forcar) {
    prepararModalPix(parcelaId, 'pix');
    gerarCobrancaPix(parcelaId, forcar).then(mostrarCobrancaPix).catch(falhaRedePix);
}

function mostrarCobrancaPix(d) {
    document.getElementById('pix-carregando').classList.add('d-none');
    if (!d.sucesso) {
        const falha = document.getElementById('pix-falha');
        falha.textContent = d.mensagem || 'Não foi possível carregar.';
        falha.classList.remove('d-none');
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
    document.getElementById('pix-registrado').classList.toggle('d-none', !d.boleto_registrado);
    document.getElementById('pix-consultar').classList.toggle('d-none', !d.pode_consultar);

    // Sem registro não há Pix: desenha um QR com texto neutro (não leva
    // a pagamento nenhum), borrado, só para mostrar onde ele aparecerá.
    const alvo = document.getElementById('pix-qr');
    alvo.innerHTML = '';
    new QRCode(alvo, { text: d.copia_cola || 'PREVIA - PIX NAO REGISTRADO', width: 256, height: 256, correctLevel: QRCode.CorrectLevel.M });
    document.querySelector('.pix-qr-caixa').classList.toggle('borrado', !d.registrado);
    document.getElementById('pix-selo').classList.toggle('d-none', d.registrado);

    document.getElementById('pix-copia-area').classList.toggle('d-none', !d.copia_cola);
    document.getElementById('pix-copia').value = d.copia_cola || '';
    document.getElementById('pix-linha-area').classList.toggle('d-none', !d.linha_digitavel);
    document.getElementById('pix-linha').value = formatarLinhaDigitavel(d.linha_digitavel);

    const aviso = document.getElementById('pix-aviso');
    if (d.erro_geracao) {
        aviso.className = 'alert alert-danger text-start small';
        aviso.innerHTML = '<strong>O banco não gerou a cobrança.</strong><br>' + escapeHtml(d.erro_geracao);
        document.getElementById('pix-tentar').classList.remove('d-none');
    } else if (!d.boleto_registrado) {
        let html = 'Esta parcela ainda não tem boleto registrado no Itaú.';
        if (d.pendencias.length) {
            html += '<br>Falta para registrar: <ul class="mb-0">' + d.pendencias.map((p) => '<li>' + escapeHtml(p) + '</li>').join('') + '</ul>';
        }
        aviso.className = 'alert alert-warning text-start small';
        aviso.innerHTML = html;
    } else {
        aviso.className = 'alert d-none';
    }

    const whatsapp = document.getElementById('pix-whatsapp');
    whatsapp.classList.toggle('d-none', !d.whatsapp);
    whatsapp.href = d.whatsapp || '#';

    document.getElementById('pix-conteudo').classList.remove('d-none');
}

// Pergunta ao Itaú se o boleto já foi pago (não espera o aviso do webhook).
// Pago: a parcela é baixada e a página recarrega para mostrar.
function consultarPagamentoPix() {
    const botao = document.getElementById('pix-consultar');
    const caixa = document.getElementById('pix-consulta');
    botao.disabled = true;
    botao.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Consultando o banco...';
    fetch('/financeiro/consultar_pagamento.php', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: new URLSearchParams({ id: parcelaPixAtual }) })
        .then((r) => r.json())
        .then((d) => {
            caixa.className = 'alert text-start small ' + (d.pago ? 'alert-success' : (d.ok ? 'alert-warning' : 'alert-danger'));
            caixa.textContent = d.mensagem;
            if (d.pago) setTimeout(() => window.location.reload(), 2500);
        })
        .catch(() => {
            caixa.className = 'alert alert-danger text-start small';
            caixa.textContent = 'Erro de rede ao falar com o sistema. Tente de novo.';
        })
        .finally(() => {
            botao.disabled = false;
            botao.innerHTML = '<i class="bi bi-search"></i> Consultar pagamento no banco';
        });
}

function copiarCampoPix(idCampo, botao) {
    const valor = document.getElementById(idCampo).value;
    navigator.clipboard.writeText(idCampo === 'pix-linha' ? valor.replace(/\D/g, '') : valor.trim()).then(() => {
        botao.innerHTML = '<i class="bi bi-check-lg"></i> Copiado';
        setTimeout(() => { botao.innerHTML = '<i class="bi bi-clipboard"></i> Copiar'; }, 2000);
    });
}
</script>
