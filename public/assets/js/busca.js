// Busca com lista suspensa (typeahead) sobre uma lista que já está na
// página: usada na matrícula (aluno / turma) e na turma (curso). A escolha
// vai para um campo oculto com o id.

// Compara sem acento e sem caixa, e CPF só pelos dígitos — "joao" acha
// "João" e "12345" acha "123.45...".
function normalizar(texto) {
    return String(texto || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
}

function montarBusca(config) {
    const campo = document.getElementById(config.campo);
    const lista = document.getElementById(config.lista);
    const oculto = document.getElementById(config.oculto);

    function renderizar() {
        const termo = normalizar(campo.value.trim());
        const digitos = campo.value.replace(/\D/g, '');
        const achados = config.itens()
            .filter((item) => !termo || config.combina(item, termo, digitos))
            .slice(0, 10);

        let html = achados.map((item) => {
            const bloqueado = config.bloqueado ? config.bloqueado(item) : '';
            return `<button type="button" class="typeahead-item" ${bloqueado ? 'disabled style="opacity:.55"' : ''} data-id="${item.id}">
                <span class="typeahead-nome">${escapeHtml(config.titulo(item))}</span>
                <span class="typeahead-meta">${escapeHtml(bloqueado || config.detalhe(item))}</span>
            </button>`;
        }).join('');
        if (!achados.length) {
            html = `<div class="typeahead-vazio">${config.vazio}</div>`;
        }
        lista.innerHTML = html;
        lista.classList.remove('d-none');
    }

    lista.addEventListener('click', function (ev) {
        const botao = ev.target.closest('.typeahead-item');
        if (!botao || botao.disabled) return;
        escolher(botao.dataset.id);
    });

    function escolher(id) {
        oculto.value = id;
        const item = config.itens().find((i) => String(i.id) === String(id));
        campo.value = item ? config.titulo(item) : '';
        lista.classList.add('d-none');
        if (config.aoEscolher) config.aoEscolher();
    }

    campo.addEventListener('input', function () {
        oculto.value = '';
        if (config.aoEscolher) config.aoEscolher();
        renderizar();
    });
    campo.addEventListener('focus', renderizar);
    document.addEventListener('click', function (ev) {
        if (!ev.target.closest('#' + config.campo) && !ev.target.closest('#' + config.lista)) {
            lista.classList.add('d-none');
        }
    });

    // Valor já escolhido (vindo da URL ou de um envio com erro) aparece no campo.
    if (oculto.value) {
        const item = config.itens().find((i) => String(i.id) === String(oculto.value));
        if (item) campo.value = config.titulo(item);
    }

    return { escolher: escolher };
}
