// Seleção de linhas para "Alterar em massa" (painel lateral das listas):
// .selecao-todos marca/desmarca as .selecao-item; os botões
// [data-acao-massa] mandam os ids marcados por POST para data-url.
(function () {
    const itens = () => [...document.querySelectorAll('.selecao-item')];
    const todos = document.querySelector('.selecao-todos');

    function atualizar() {
        const marcados = itens().filter((c) => c.checked).length;
        const info = document.getElementById('info-selecionadas');
        if (info) info.textContent = marcados;
        if (todos) {
            todos.checked = marcados > 0 && marcados === itens().length;
            todos.indeterminate = marcados > 0 && marcados < itens().length;
        }
    }

    todos?.addEventListener('change', () => {
        itens().forEach((c) => { c.checked = todos.checked; });
        atualizar();
    });
    itens().forEach((c) => {
        c.addEventListener('change', atualizar);
        // A linha da tabela abre a ficha ao clicar; a caixinha não.
        c.addEventListener('click', (ev) => ev.stopPropagation());
    });

    document.querySelectorAll('[data-acao-massa]').forEach((botao) => {
        botao.addEventListener('click', () => {
            const ids = itens().filter((c) => c.checked).map((c) => c.value);
            if (!ids.length) {
                alert('Marque ao menos um item na lista.');
                return;
            }
            if (botao.dataset.confirmar && !confirm(botao.dataset.confirmar.replace('{n}', ids.length))) {
                return;
            }
            const form = document.createElement('form');
            form.method = 'post';
            form.action = botao.dataset.url;
            const campos = [['acao', botao.dataset.acaoMassa], ['voltar', window.location.pathname + window.location.search], ...ids.map((id) => ['ids[]', id])];
            campos.forEach(([nome, valor]) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = nome;
                input.value = valor;
                form.appendChild(input);
            });
            document.body.appendChild(form);
            form.submit();
        });
    });

    atualizar();
})();

function alternarLateral() {
    document.querySelector('.pedidos-lateral')?.classList.toggle('aberta');
}
