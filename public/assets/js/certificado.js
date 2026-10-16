// Acabamento da folha do certificado, igual na tela, na impressão e no
// editor: a letra diminui até o texto caber (texto "em uma linha" cabe na
// largura; caixa com altura definida cabe na altura), e o QR Code é
// desenhado com o link de verificação. Precisa do qrcodejs.
window.ajustarCertificado = function (raiz) {
    raiz = raiz || document;
    raiz.querySelectorAll('.cert-uma-linha, .cert-caixa[data-fonte]').forEach(function (el) {
        var fonte = parseFloat(el.dataset.fonte);
        var conteudo = el.querySelector('.cert-conteudo') || el;
        var naoCabe = function () {
            return el.classList.contains('cert-uma-linha')
                ? conteudo.scrollWidth > el.clientWidth + 1
                : conteudo.scrollHeight > el.clientHeight + 1 || conteudo.scrollWidth > el.clientWidth + 1;
        };
        el.style.fontSize = fonte + 'cqw';
        // Encolhe aos poucos (3% por vez) até caber.
        while (naoCabe() && fonte > 0.3) {
            fonte *= 0.97;
            el.style.fontSize = fonte + 'cqw';
        }
    });
    raiz.querySelectorAll('.cert-qr[data-qr]').forEach(function (el) {
        if (!window.QRCode || el.dataset.desenhado === el.dataset.qr) return;
        el.innerHTML = '';
        new QRCode(el, { text: el.dataset.qr || ' ', width: 512, height: 512, correctLevel: QRCode.CorrectLevel.M });
        el.dataset.desenhado = el.dataset.qr;
    });
};
