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
// e espaço em % da largura da folha (data-tamanho / data-espaco). A
// transparência e o preto já vão desenhados na telha: com opacity/filter na
// camada, o PDF virava uma foto da folha inteira (MBs por página).
var telhasMarcaDagua = {};
window.desenharMarcaDagua = function (raiz) {
    (raiz || document).querySelectorAll('.cert-marca-dagua[data-logo]').forEach(function (el) {
        var tamanho = parseFloat(el.dataset.tamanho) || 8;
        var espaco = parseFloat(el.dataset.espaco) || 0;
        var opacidade = parseFloat(el.dataset.opacidade) || 0.1;
        var chave = el.dataset.logo + '|' + tamanho + '|' + espaco + '|' + opacidade;
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
                // Logo em preto: tudo o que é desenho (qualquer cor, até o
                // amarelo claro) vira preto; branco e transparente somem.
                // Assim a logo aparece inteira e a intensidade é só a
                // transparência escolhida no modelo.
                var silhueta = document.createElement('canvas');
                silhueta.width = Math.round(largura);
                silhueta.height = Math.round(altura);
                var cs = silhueta.getContext('2d');
                cs.drawImage(logo, 0, 0, silhueta.width, silhueta.height);
                var pixels = cs.getImageData(0, 0, silhueta.width, silhueta.height);
                var p = pixels.data;
                for (var i = 0; i < p.length; i += 4) {
                    var luz = (0.299 * p[i] + 0.587 * p[i + 1] + 0.114 * p[i + 2]) / 255;
                    // Quase branco = fundo da logo: fica transparente.
                    var alfa = luz > 0.94 ? 0 : p[i + 3];
                    p[i] = p[i + 1] = p[i + 2] = 0;
                    p[i + 3] = alfa;
                }
                cs.putImageData(pixels, 0, 0);

                var ctx = canvas.getContext('2d');
                ctx.globalAlpha = opacidade;
                ctx.drawImage(silhueta, vao / 2, vao / 2, largura, altura);
                // 2ª fileira deslocada meia logo (fica mais "tecido").
                ctx.drawImage(silhueta, vao / 2 + telhaL / 2, fileira + vao / 2, largura, altura);
                ctx.drawImage(silhueta, vao / 2 - telhaL / 2, fileira + vao / 2, largura, altura);
                telhasMarcaDagua[chave] = canvas.toDataURL('image/png');
            } catch (e) {
                // Logo de outro site (canvas bloqueado): repete a logo sem o espaço.
                telhasMarcaDagua[chave] = el.dataset.logo;
                el.style.opacity = opacidade;
            }
            aplicar(telhasMarcaDagua[chave]);
        };
        logo.src = el.dataset.logo;
    });
};