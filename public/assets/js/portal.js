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

    // Carrossel: troca de imagem a cada 6 s; os pontinhos trocam na hora.
    document.querySelectorAll('.banner[data-slides]').forEach(function (banner) {
        var slides = banner.querySelectorAll('.banner-slide');
        var pontos = banner.querySelectorAll('.banner-pontos button');
        if (slides.length < 2) return;
        var atual = 0, timer;
        function mostrar(i) {
            slides[atual].classList.remove('ativo'); pontos[atual].classList.remove('ativo');
            atual = (i + slides.length) % slides.length;
            slides[atual].classList.add('ativo'); pontos[atual].classList.add('ativo');
        }
        function iniciar() { clearInterval(timer); timer = setInterval(function () { mostrar(atual + 1); }, 6000); }
        pontos.forEach(function (p, i) { p.addEventListener('click', function () { mostrar(i); iniciar(); }); });
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
