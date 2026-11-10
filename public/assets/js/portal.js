// Portal do aluno: máscaras, busca de CEP, carrossel dos banners e botão
// de copiar. Sem dependências além do Bootstrap.
(function () {
    function mascarar(campo, formatar) {
        campo.addEventListener('input', function () { campo.value = formatar(campo.value.replace(/\D/g, '')); });
        campo.value = formatar(campo.value.replace(/\D/g, ''));
    }
    document.querySelectorAll('[data-mascara="cpf"]').forEach(function (c) {
        mascarar(c, function (d) {
            d = d.slice(0, 11);
            return d.replace(/^(\d{3})(\d)/, '$1.$2').replace(/^(\d{3})\.(\d{3})(\d)/, '$1.$2.$3').replace(/\.(\d{3})(\d{1,2})$/, '.$1-$2');
        });
    });
    document.querySelectorAll('[data-mascara="telefone"]').forEach(function (c) {
        mascarar(c, function (d) {
            d = d.slice(0, 11);
            if (d.length <= 2) return d;
            if (d.length <= 6) return '(' + d.slice(0, 2) + ') ' + d.slice(2);
            if (d.length <= 10) return '(' + d.slice(0, 2) + ') ' + d.slice(2, 6) + '-' + d.slice(6);
            return '(' + d.slice(0, 2) + ') ' + d.slice(2, 7) + '-' + d.slice(7);
        });
    });
    document.querySelectorAll('[data-mascara="cep"]').forEach(function (c) {
        mascarar(c, function (d) { d = d.slice(0, 8); return d.length > 5 ? d.slice(0, 5) + '-' + d.slice(5) : d; });
        // Preenche o endereço pelo CEP (ViaCEP); o aluno só completa o número.
        c.addEventListener('input', function () {
            var cep = c.value.replace(/\D/g, '');
            if (cep.length !== 8) return;
            var form = c.form;
            fetch('https://viacep.com.br/ws/' + cep + '/json/').then(function (r) { return r.json(); }).then(function (d) {
                if (d.erro) return;
                [['logradouro', d.logradouro], ['bairro', d.bairro], ['cidade', d.localidade], ['uf', d.uf]].forEach(function (par) {
                    var campo = form.querySelector('[name="' + par[0] + '"]');
                    if (campo && par[1]) campo.value = par[1];
                });
                var numero = form.querySelector('[name="numero"]');
                if (numero) numero.focus();
            }).catch(function () {});
        });
    });

    // Carrossel: tempo e efeito vêm do bloco (data-tempo, classe banner-<efeito>);
    // setas, pontinhos e arrastar com o dedo trocam na hora; pode parar com o
    // mouse em cima.
    document.querySelectorAll('.banner[data-slides]').forEach(function (banner) {
        var slides = banner.querySelectorAll('.banner-slide');
        var pontos = banner.querySelectorAll('.banner-pontos button');
        if (slides.length < 2) return;
        var atual = 0, timer, parado = false;
        var tempo = Math.max(2, parseInt(banner.getAttribute('data-tempo'), 10) || 6) * 1000;
        var deslizar = banner.classList.contains('banner-deslizar');
        function mostrar(i, voltando) {
            var novo = (i + slides.length) % slides.length;
            if (novo === atual) return;
            var antigo = slides[atual];
            if (deslizar) {
                // Põe a nova imagem do lado certo antes de deslizar.
                banner.classList.toggle('voltando', !!voltando);
                slides[novo].classList.add('sem-transicao');
                void slides[novo].offsetWidth;
                slides[novo].classList.remove('sem-transicao');
                antigo.classList.add('saindo');
                setTimeout(function () {
                    antigo.classList.add('sem-transicao');
                    antigo.classList.remove('saindo');
                    void antigo.offsetWidth;
                    antigo.classList.remove('sem-transicao');
                }, 750);
            }
            antigo.classList.remove('ativo'); pontos[atual].classList.remove('ativo');
            atual = novo;
            slides[atual].classList.add('ativo'); pontos[atual].classList.add('ativo');
        }
        function iniciar() { clearInterval(timer); timer = setInterval(function () { if (!parado) mostrar(atual + 1); }, tempo); }
        pontos.forEach(function (p, i) { p.addEventListener('click', function () { mostrar(i, i < atual); iniciar(); }); });
        var anterior = banner.querySelector('.banner-seta.anterior'), proxima = banner.querySelector('.banner-seta.proxima');
        if (anterior) anterior.addEventListener('click', function () { mostrar(atual - 1, true); iniciar(); });
        if (proxima) proxima.addEventListener('click', function () { mostrar(atual + 1); iniciar(); });
        if (banner.getAttribute('data-pausar') === '1') {
            banner.addEventListener('mouseenter', function () { parado = true; });
            banner.addEventListener('mouseleave', function () { parado = false; });
        }
        var inicioX = null;
        banner.addEventListener('touchstart', function (ev) { inicioX = ev.touches[0].clientX; }, { passive: true });
        banner.addEventListener('touchend', function (ev) {
            if (inicioX === null) return;
            var dx = ev.changedTouches[0].clientX - inicioX;
            inicioX = null;
            if (Math.abs(dx) > 40) { dx < 0 ? mostrar(atual + 1) : mostrar(atual - 1, true); iniciar(); }
        });
        iniciar();
    });

    document.querySelectorAll('[data-copiar]').forEach(function (botao) {
        botao.addEventListener('click', function () {
            var alvo = document.getElementById(botao.getAttribute('data-copiar'));
            var texto = alvo.value || alvo.textContent;
            var original = botao.innerHTML;
            (navigator.clipboard ? navigator.clipboard.writeText(texto) : Promise.reject()).catch(function () {
                alvo.select && alvo.select(); document.execCommand('copy');
            }).finally(function () {
                botao.innerHTML = '<i class="bi bi-check2"></i> Copiado!';
                setTimeout(function () { botao.innerHTML = original; }, 2000);
            });
        });
    });
})();
