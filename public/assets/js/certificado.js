// Acabamento da folha do certificado, igual na tela, na impressão e no
// editor: a letra diminui até o texto caber (texto "em uma linha" cabe na
// largura; caixa com altura definida cabe na altura), e o QR Code é
// desenhado com o link de verificação. Precisa do qrcodejs.
window.ajustarCertificado = function (raiz) {
    raiz = raiz || document;
    window.desenharMarcaDagua(raiz);
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

// Marca d'água: monta uma vez a "telha" (logo + espaço, em duas fileiras
// desencontradas) numa imagem e a repete na camada .cert-marca-dagua. Tamanho
// e espaço em % da largura da folha (data-tamanho / data-espaco).
var telhasMarcaDagua = {};
window.desenharMarcaDagua = function (raiz) {
    (raiz || document).querySelectorAll('.cert-marca-dagua[data-logo]').forEach(function (el) {
        var tamanho = parseFloat(el.dataset.tamanho) || 8;
        var espaco = parseFloat(el.dataset.espaco) || 0;
        var chave = el.dataset.logo + '|' + tamanho + '|' + espaco;
        var aplicar = function (url) {
            el.style.backgroundImage = 'url("' + url + '")';
            el.style.backgroundSize = (tamanho + espaco) + 'cqw auto';
        };
        if (telhasMarcaDagua[chave]) return aplicar(telhasMarcaDagua[chave]);
        var logo = new Image();
        logo.onload = function () {
            try {
                var largura = 300;
                var altura = largura * logo.naturalHeight / logo.naturalWidth;
                var vao = largura * espaco / tamanho;
                var telhaL = largura + vao;
                var fileira = altura + vao;
                var canvas = document.createElement('canvas');
                canvas.width = Math.round(telhaL);
                canvas.height = Math.round(fileira * 2);
                var ctx = canvas.getContext('2d');
                ctx.drawImage(logo, vao / 2, vao / 2, largura, altura);
                // 2ª fileira deslocada meia logo (fica mais "tecido").
                ctx.drawImage(logo, vao / 2 + telhaL / 2, fileira + vao / 2, largura, altura);
                ctx.drawImage(logo, vao / 2 - telhaL / 2, fileira + vao / 2, largura, altura);
                telhasMarcaDagua[chave] = canvas.toDataURL('image/png');
            } catch (e) {
                // Logo de outro site (canvas bloqueado): repete a logo sem o espaço.
                telhasMarcaDagua[chave] = el.dataset.logo;
            }
            aplicar(telhasMarcaDagua[chave]);
        };
        logo.src = el.dataset.logo;
    });
};