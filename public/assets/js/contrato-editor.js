// Editor do contrato (Acadêmico › Documentos › contrato), parecido com o Word:
// uma folha A4 por página, cada uma com o cabeçalho e o rodapé; réguas de
// cima (margens dos lados e tabulações) e do lado (margens de cima e de
// baixo); cabeçalho e rodapé abrem com dois cliques; imagens soltas por cima
// do texto. Os dados iniciais vêm em window.CONTRATO (configuracoes/contrato.php).
//
// O texto é um editor só; as folhas são desenhadas por trás e cada parágrafo
// que não cabe numa folha ganha um espaço em cima que o leva para a folha
// seguinte (a mesma conta da impressão: folha − margens − cabeçalho − rodapé).
(() => {
    const C = window.CONTRATO;
    const PX_POR_MM = 794 / 210;
    const PX_POR_CM = PX_POR_MM * 10;
    const ALT_PAG = 297 * PX_POR_MM;
    const VAO = 28; // espaço cinza entre as folhas
    const topoPagina = (k) => k * (ALT_PAG + VAO);
    const folha = document.getElementById('folha');
    const estiloPaginacao = document.getElementById('estilo-paginacao');
    let alterado = false;

    // ---- Formatos extras do Quill: fonte, entrelinha, espaço e TAB ----
    const Fonte = Quill.import('formats/font');
    Fonte.whitelist = C.fontes;
    Quill.register(Fonte, true);
    const Parchment = Quill.import('parchment');
    Quill.register(new Parchment.ClassAttributor('entrelinha', 'ql-entrelinha', { scope: Parchment.Scope.BLOCK, whitelist: C.entrelinhas }), true);
    // Espaço depois do parágrafo (sem escolha = 6 pt, o padrão).
    Quill.register(new Parchment.ClassAttributor('espaco', 'ql-espaco', { scope: Parchment.Scope.BLOCK, whitelist: C.espacos }), true);

    // TAB vira um espaço que estica até a próxima marca da régua.
    const Embed = Quill.import('blots/embed');
    class Tabulacao extends Embed {
        static value() { return true; }
        html() { return '<span class="ql-tab">\t</span>'; }
    }
    Tabulacao.blotName = 'tab';
    Tabulacao.tagName = 'span';
    Tabulacao.className = 'ql-tab';
    Quill.register(Tabulacao, true);

    const teclado = {
        bindings: {
            tab: {
                key: 'Tab',
                handler(faixa, contexto) {
                    if (contexto.format.list) return true; // em lista, Tab recua o item
                    this.quill.insertEmbed(faixa.index, 'tab', true, 'user');
                    this.quill.setSelection(faixa.index + 1, 0, 'user');
                    return false;
                },
            },
        },
    };
    const fontes = [false, ...C.fontes];
    const barraCompleta = [
        [{ font: fontes }, { header: [1, 2, 3, false] }, { size: ['small', false, 'large', 'huge'] }],
        ['bold', 'italic', 'underline', 'strike'],
        [{ color: [] }, { background: [] }],
        [{ align: [] }, { entrelinha: [false, ...C.entrelinhas] }, { espaco: [false, ...C.espacos] }],
        [{ list: 'ordered' }, { list: 'bullet' }, { indent: '-1' }, { indent: '+1' }],
        ['link', 'clean'],
    ];
    const barraSimples = [[{ font: fontes }, { size: ['small', false, 'large', 'huge'] }], ['bold', 'italic', 'underline'], [{ color: [] }], [{ align: [] }], ['clean']];
    const editores = {
        corpo: { quill: new Quill('#editor', { theme: 'snow', modules: { toolbar: barraCompleta, keyboard: teclado } }), nome: 'texto do contrato', area: document.getElementById('area-corpo') },
        cabecalho: { quill: new Quill('#editor-cabecalho', { theme: 'snow', placeholder: 'Dois cliques para escrever o cabeçalho...', modules: { toolbar: barraSimples, keyboard: teclado } }), nome: 'cabeçalho', area: document.getElementById('area-cabecalho'), modo: 'modo-cabecalho' },
        rodape: { quill: new Quill('#editor-rodape', { theme: 'snow', placeholder: 'Dois cliques para escrever o rodapé...', modules: { toolbar: barraSimples, keyboard: teclado } }), nome: 'rodapé', area: document.getElementById('area-rodape'), modo: 'modo-rodape' },
    };
    let ativo = editores.corpo;
    Object.values(editores).forEach((ed) => {
        ed.barra = ed.quill.getModule('toolbar').container;
        document.getElementById('barras').appendChild(ed.barra);
        ed.quill.on('text-change', () => { alterado = true; agendar(); });
    });

    // ---- Cabeçalho e rodapé: dois cliques para editar (como no Word) ----
    function ativar(ed) {
        ativo = ed;
        Object.values(editores).forEach((outro) => {
            outro.barra.style.display = outro === ed ? '' : 'none';
            if (outro !== editores.corpo) outro.quill.enable(outro === ed);
        });
        folha.classList.remove('modo-cabecalho', 'modo-rodape');
        if (ed.modo) folha.classList.add(ed.modo);
        document.getElementById('rotulo-barra').textContent = 'Editando: ' + ed.nome + (ed.modo ? ' (um clique no texto do contrato volta para ele)' : '');
    }
    function editarArea(ed) {
        if (ativo === ed) return;
        ativar(ed);
        ed.quill.focus();
        ed.quill.setSelection(ed.quill.getLength(), 0);
    }
    editores.cabecalho.area.addEventListener('dblclick', () => editarArea(editores.cabecalho));
    editores.rodape.area.addEventListener('dblclick', () => editarArea(editores.rodape));
    editores.corpo.area.addEventListener('mousedown', () => { if (ativo !== editores.corpo) ativar(editores.corpo); });
    document.addEventListener('keydown', (ev) => { if (ev.key === 'Escape' && ativo !== editores.corpo) { ativar(editores.corpo); editores.corpo.quill.focus(); } });
    ativar(editores.corpo);

    window.inserirVariavel = (variavel) => {
        const quill = ativo.quill;
        const posicao = (quill.getSelection(true) || { index: quill.getLength() }).index;
        quill.insertText(posicao, variavel, 'user');
        quill.setSelection(posicao + variavel.length, 0, 'user');
    };

    // ---- Medidas da página ----
    let marcas = (C.tabulacoes || []).slice();
    const margem = (lado) => Math.min(5, Math.max(0.5, parseFloat(String(document.querySelector(`[data-margem="${lado}"]`).value).replace(',', '.')) || 2));
    function geometria() {
        const sup = margem('superior') * PX_POR_CM;
        const inf = margem('inferior') * PX_POR_CM;
        const esq = margem('esquerda') * PX_POR_CM;
        const dir = margem('direita') * PX_POR_CM;
        const temCab = editores.cabecalho.quill.getLength() > 1;
        const temRod = editores.rodape.quill.getLength() > 1 || document.getElementById('numero-pagina').checked;
        const altCab = editores.cabecalho.area.offsetHeight;
        const altRod = editores.rodape.area.offsetHeight;
        // Iguais aos espaços reservados na impressão (contratos.php).
        const espacoCab = temCab ? altCab + 18 : 0;
        const espacoRod = temRod ? altRod + 14 : 0;
        return {
            sup, inf, esq, dir, temCab, temRod, altCab, altRod, espacoCab, espacoRod,
            largura: 794 - esq - dir,
            corpoTopo: (k) => topoPagina(k) + sup + espacoCab,
            corpoFim: (k) => topoPagina(k) + ALT_PAG - inf - espacoRod,
        };
    }

    // ---- Paginação: folhas, cabeçalho/rodapé em cada uma, réguas do lado ----
    let paginas = 1;
    let agendado = false;
    function agendar() {
        if (agendado) return;
        agendado = true;
        requestAnimationFrame(() => { agendado = false; paginar(); });
    }
    function posicionarAreas(g) {
        const cab = editores.cabecalho.area;
        const rod = editores.rodape.area;
        [cab, rod, editores.corpo.area].forEach((area) => { area.style.left = g.esq + 'px'; area.style.width = g.largura + 'px'; });
        // Vazios, ficam na faixa da margem (como no Word), só para dar os dois cliques.
        cab.classList.toggle('area-vazia', !g.temCab);
        cab.style.top = (g.temCab ? g.sup : Math.max(4, g.sup - cab.offsetHeight - 4)) + 'px';
        rod.classList.toggle('area-vazia', !g.temRod);
        rod.style.top = (g.temRod ? ALT_PAG - g.inf - g.altRod : ALT_PAG - g.inf + 4) + 'px';
        editores.corpo.area.style.top = g.corpoTopo(0) + 'px';
    }
    function paginar() {
        Object.values(editores).forEach((ed) => aplicarTabulacoes(ed.quill.root, marcas));
        estiloPaginacao.textContent = '';
        let g = geometria();
        posicionarAreas(g);
        g = geometria();
        const base = folha.getBoundingClientRect().top;
        const blocos = Array.from(editores.corpo.quill.root.children);
        const regras = [];
        let pagina = 0;
        let desloc = 0;
        let fimAnterior = g.corpoTopo(0);
        blocos.forEach((bloco, i) => {
            const r = bloco.getBoundingClientRect();
            const vao = (r.top - base) - fimAnterior; // espaço original acima do parágrafo
            fimAnterior = r.bottom - base;
            let topo = r.top - base + desloc;
            const altura = r.height;
            // Já está numa folha seguinte (depois de um parágrafo maior que a folha).
            while (topo >= g.corpoTopo(pagina + 1)) pagina++;
            // Não cabe (ou começa na margem de baixo) e não é o primeiro da
            // folha: vai para a próxima.
            let empurrar = 0;
            while (topo + altura > g.corpoFim(pagina) + 0.5 && topo > g.corpoTopo(pagina) + 0.5) {
                pagina++;
                const d = g.corpoTopo(pagina) - topo;
                empurrar += d;
                topo += d;
            }
            if (empurrar > 0) {
                regras.push(`#editor .ql-editor > :nth-child(${i + 1}) { margin-top: ${vao + empurrar}px !important; }`);
                desloc += empurrar;
            }
            while (topo + altura > g.corpoFim(pagina) + 0.5) pagina++; // parágrafo maior que a folha
        });
        estiloPaginacao.textContent = regras.join('\n');
        // As assinaturas vêm depois do texto (uns 3 cm).
        const fimTexto = blocos.length ? blocos[blocos.length - 1].getBoundingClientRect().bottom - base : g.corpoTopo(0);
        let ultima = 0;
        while (fimTexto >= g.corpoFim(ultima)) ultima++;
        if (fimTexto + 32 * PX_POR_MM > g.corpoFim(ultima)) ultima++;
        paginas = ultima + 1;
        desenharPaginas(g);
        desenharImagens();
    }
    function desenharPaginas(g) {
        const fundos = document.getElementById('fundos');
        fundos.innerHTML = '';
        folha.style.height = (topoPagina(paginas - 1) + ALT_PAG) + 'px';
        const htmlCab = editores.cabecalho.quill.root.innerHTML;
        const htmlRod = editores.rodape.quill.root.innerHTML;
        const comNumero = document.getElementById('numero-pagina').checked;
        for (let k = 0; k < paginas; k++) {
            const topo = topoPagina(k);
            const pag = document.createElement('div');
            pag.className = 'fundo-pagina';
            pag.style.cssText = `top: ${topo}px; height: ${ALT_PAG}px`;
            pag.innerHTML = `<div class="limite-margens" style="top: ${g.sup}px; bottom: ${g.inf}px; left: ${g.esq}px; right: ${g.dir}px"></div>`
                + `<div class="rotulo-pagina">Página ${k + 1} de ${paginas}</div>`;
            pag.appendChild(reguaVertical(g));
            fundos.appendChild(pag);
            if (k === 0) continue;
            // Cópias do cabeçalho e do rodapé nas outras folhas (dois cliques editam o original).
            if (g.temCab) fundos.appendChild(copiaArea('copia-cabecalho', htmlCab, topo + g.sup, g, editores.cabecalho));
            if (g.temRod) {
                const numero = comNumero ? `<span class="numero-pagina-editor">Página ${k + 1} de ${paginas}</span>` : '';
                fundos.appendChild(copiaArea('copia-rodape', numero + htmlRod, topo + ALT_PAG - g.inf - g.altRod, g, editores.rodape));
            }
        }
        document.getElementById('numero-pagina-editor').textContent = `Página 1 de ${paginas}`;
    }
    function copiaArea(classe, html, topo, g, ed) {
        const copia = document.createElement('div');
        copia.className = 'copia-area ' + classe;
        copia.style.cssText = `top: ${topo}px; left: ${g.esq}px; width: ${g.largura}px`;
        copia.innerHTML = html;
        copia.title = 'Dois cliques para editar (vale para todas as páginas)';
        copia.addEventListener('dblclick', () => {
            ed.area.scrollIntoView({ behavior: 'smooth', block: 'center' });
            editarArea(ed);
        });
        return copia;
    }
    // Régua do lado: cinza = margens de cima e de baixo (arraste para mudar).
    function reguaVertical(g) {
        const regua = document.createElement('div');
        regua.className = 'regua-v';
        regua.style.height = ALT_PAG + 'px';
        let html = `<div class="margem-v" style="top: 0; height: ${g.sup}px"></div><div class="margem-v" style="bottom: 0; height: ${g.inf}px"></div>`;
        for (let cm = 0.5; cm < 29.7; cm += 0.5) {
            const y = cm * PX_POR_CM;
            const inteiro = Math.abs(cm - Math.round(cm)) < 0.01;
            html += `<div class="risco-v" style="top: ${y}px; width: ${inteiro ? 8 : 4}px"></div>`;
            if (inteiro && Math.round(cm) % 2 === 0) html += `<div class="numero-v" style="top: ${y}px">${Math.round(cm)}</div>`;
        }
        html += `<div class="alca-v" data-lado="superior" style="top: ${g.sup}px" title="Margem de cima"></div>`
            + `<div class="alca-v" data-lado="inferior" style="top: ${ALT_PAG - g.inf}px" title="Margem de baixo"></div>`;
        regua.innerHTML = html;
        regua.querySelectorAll('.alca-v').forEach((alca) => alca.addEventListener('pointerdown', (ev) => {
            ev.preventDefault();
            const lado = alca.dataset.lado;
            const campo = document.querySelector(`[data-margem="${lado}"]`);
            const topoRegua = regua.getBoundingClientRect().top;
            const mover = (e) => {
                const cm = (e.clientY - topoRegua) / PX_POR_CM;
                campo.value = String(Math.min(5, Math.max(0.5, Math.round((lado === 'superior' ? cm : 29.7 - cm) * 10) / 10)));
                window.atualizarMargens();
            };
            const soltar = () => { document.removeEventListener('pointermove', mover); document.removeEventListener('pointerup', soltar); };
            document.addEventListener('pointermove', mover);
            document.addEventListener('pointerup', soltar);
        }));
        return regua;
    }

    // ---- Régua de cima: margens dos lados e tabulações ----
    window.atualizarMargens = () => {
        desenharRegua();
        agendar();
        alterado = true;
    };
    function desenharRegua() {
        const esq = margem('esquerda');
        const dir = margem('direita');
        const largura = 21 - esq - dir;
        document.getElementById('regua-margem-esq').style.cssText = `left: 0; width: ${esq * PX_POR_CM}px`;
        document.getElementById('regua-margem-dir').style.cssText = `right: 0; width: ${dir * PX_POR_CM}px`;
        document.getElementById('alca-esq').style.left = (esq * PX_POR_CM) + 'px';
        document.getElementById('alca-dir').style.left = ((21 - dir) * PX_POR_CM) + 'px';
        const escala = document.getElementById('regua-escala');
        escala.innerHTML = '';
        for (let cm = 0; cm <= largura + 0.001; cm += 0.25) {
            const x = (esq + cm) * PX_POR_CM;
            const inteiro = Math.abs(cm - Math.round(cm)) < 0.01;
            const meio = Math.abs(cm * 2 - Math.round(cm * 2)) < 0.01;
            const risco = document.createElement('div');
            risco.className = 'regua-risco';
            risco.style.left = x + 'px';
            risco.style.height = inteiro ? '8px' : (meio ? '6px' : '3px');
            escala.appendChild(risco);
            if (inteiro && cm > 0) {
                const numero = document.createElement('div');
                numero.className = 'regua-numero';
                numero.style.left = x + 'px';
                numero.textContent = Math.round(cm);
                escala.appendChild(numero);
            }
        }
        marcas.filter((m) => m <= largura).forEach((m) => {
            const marca = document.createElement('div');
            marca.className = 'marca-tab';
            marca.title = 'Tabulação em ' + m.toFixed(2).replace('.', ',') + ' cm — arraste para mudar ou para fora da régua para tirar';
            marca.style.left = ((esq + m) * PX_POR_CM) + 'px';
            marca.addEventListener('pointerdown', (ev) => arrastarMarca(ev, marca, m));
            escala.appendChild(marca);
        });
    }
    const arredondar = (cm) => Math.round(cm * 4) / 4; // de 0,25 em 0,25 cm
    document.getElementById('regua-escala').addEventListener('pointerdown', (ev) => {
        if (ev.target.classList.contains('marca-tab')) return;
        const caixa = ev.currentTarget.getBoundingClientRect();
        const cm = arredondar((ev.clientX - caixa.left) / PX_POR_CM - margem('esquerda'));
        if (cm <= 0 || cm >= 21 - margem('esquerda') - margem('direita')) return;
        if (!marcas.some((m) => Math.abs(m - cm) < 0.01)) {
            marcas.push(cm);
            marcas.sort((a, b) => a - b);
            alterado = true;
            desenharRegua();
            agendar();
        }
    });
    function arrastarMarca(ev, marca, valor) {
        ev.preventDefault();
        ev.stopPropagation();
        const caixa = document.getElementById('regua').getBoundingClientRect();
        let atual = valor;
        let fora = false;
        const mover = (e) => {
            atual = arredondar((e.clientX - caixa.left) / PX_POR_CM - margem('esquerda'));
            fora = Math.abs(e.clientY - (caixa.top + caixa.height / 2)) > 30;
            marca.style.left = ((margem('esquerda') + atual) * PX_POR_CM) + 'px';
            marca.classList.toggle('saindo', fora);
        };
        const soltar = () => {
            document.removeEventListener('pointermove', mover);
            document.removeEventListener('pointerup', soltar);
            marcas = marcas.filter((m) => Math.abs(m - valor) > 0.001);
            if (!fora && atual > 0 && atual < 21 - margem('esquerda') - margem('direita') && !marcas.some((m) => Math.abs(m - atual) < 0.01)) {
                marcas.push(atual);
            }
            marcas.sort((a, b) => a - b);
            alterado = true;
            desenharRegua();
            agendar();
        };
        document.addEventListener('pointermove', mover);
        document.addEventListener('pointerup', soltar);
    }
    [['alca-esq', 'esquerda'], ['alca-dir', 'direita']].forEach(([id, lado]) => {
        document.getElementById(id).addEventListener('pointerdown', (ev) => {
            ev.preventDefault();
            const caixa = document.getElementById('regua').getBoundingClientRect();
            const campo = document.querySelector(`[data-margem="${lado}"]`);
            const mover = (e) => {
                const cm = (e.clientX - caixa.left) / PX_POR_CM;
                campo.value = String(Math.min(5, Math.max(0.5, Math.round((lado === 'esquerda' ? cm : 21 - cm) * 10) / 10)));
                window.atualizarMargens();
            };
            const soltar = () => { document.removeEventListener('pointermove', mover); document.removeEventListener('pointerup', soltar); };
            document.addEventListener('pointermove', mover);
            document.addEventListener('pointerup', soltar);
        });
    });

    window.atualizarNumeroPagina = () => {
        document.getElementById('numero-pagina-editor').style.display = document.getElementById('numero-pagina').checked ? '' : 'none';
        alterado = true;
        agendar();
    };

    // ---- Imagens soltas (posição e largura em mm, a partir do canto da 1ª folha) ----
    // Na impressão as folhas vêm coladas; aqui há o vão cinza entre elas.
    // Imagem no cabeçalho ou no rodapé repete em todas as páginas.
    let imagens = C.imagens || [];
    const camada = document.getElementById('camada-imagens');
    const yNaTela = (img) => (img.todas ? img.y * PX_POR_MM : img.y * PX_POR_MM + Math.floor(img.y / 297) * VAO);
    function soltarImagem(img, topoPx, alturaPx) {
        const g = geometria();
        const k = Math.max(0, Math.floor((topoPx + alturaPx / 2) / (ALT_PAG + VAO)));
        const local = (topoPx - topoPagina(k)) / PX_POR_MM;
        const centro = topoPx + alturaPx / 2 - topoPagina(k);
        const noCabecalho = centro <= g.sup + Math.max(g.espacoCab, 20);
        const noRodape = centro >= ALT_PAG - g.inf - Math.max(g.espacoRod, 20);
        img.todas = noCabecalho || noRodape ? 1 : 0;
        img.y = Math.round((img.todas ? local : k * 297 + local) * 10) / 10;
    }
    function desenharImagens() {
        camada.innerHTML = '';
        imagens.forEach((img, i) => {
            const caixa = document.createElement('div');
            caixa.className = 'img-solta';
            caixa.style.left = (img.x * PX_POR_MM) + 'px';
            caixa.style.top = yNaTela(img) + 'px';
            caixa.style.width = (img.largura * PX_POR_MM) + 'px';
            caixa.innerHTML = '<img alt="">'
                + `<div class="opcoes-img"><span>${img.todas ? 'No cabeçalho/rodapé: repete em todas as páginas' : 'Só nesta página'}</span>`
                + '<button type="button" class="btn btn-sm btn-link text-danger p-0" title="Remover"><i class="bi bi-trash"></i></button></div>'
                + '<span class="alca-tamanho" title="Puxe para mudar o tamanho"></span>';
            caixa.querySelector('img').src = img.url;
            caixa.querySelector('button').addEventListener('click', () => { imagens.splice(i, 1); alterado = true; desenharImagens(); });
            caixa.querySelector('.opcoes-img').addEventListener('pointerdown', (ev) => ev.stopPropagation());
            // Arrastar a imagem; pela alça do canto, mudar a largura (a altura acompanha).
            caixa.addEventListener('pointerdown', (ev) => {
                ev.preventDefault();
                camada.querySelectorAll('.img-solta').forEach((c) => c.classList.toggle('selecionada', c === caixa));
                const redimensionar = ev.target.classList.contains('alca-tamanho');
                const inicio = { mx: ev.clientX, my: ev.clientY, left: parseFloat(caixa.style.left), top: parseFloat(caixa.style.top), largura: img.largura };
                const mover = (e) => {
                    if (redimensionar) {
                        img.largura = Math.max(3, Math.min(210, Math.round((inicio.largura + (e.clientX - inicio.mx) / PX_POR_MM) * 10) / 10));
                        caixa.style.width = (img.largura * PX_POR_MM) + 'px';
                    } else {
                        caixa.style.left = (inicio.left + e.clientX - inicio.mx) + 'px';
                        caixa.style.top = (inicio.top + e.clientY - inicio.my) + 'px';
                    }
                    alterado = true;
                };
                const soltar = () => {
                    document.removeEventListener('pointermove', mover);
                    document.removeEventListener('pointerup', soltar);
                    img.x = Math.round(parseFloat(caixa.style.left) / PX_POR_MM * 10) / 10;
                    soltarImagem(img, parseFloat(caixa.style.top), caixa.offsetHeight);
                    desenharImagens();
                    caixa.classList.add('selecionada');
                };
                document.addEventListener('pointermove', mover);
                document.addEventListener('pointerup', soltar);
            });
            camada.appendChild(caixa);
            // As que repetem aparecem também nas outras folhas.
            if (img.todas) {
                for (let k = 1; k < paginas; k++) {
                    const copia = document.createElement('div');
                    copia.className = 'img-solta copia';
                    copia.style.cssText = `left: ${img.x * PX_POR_MM}px; top: ${topoPagina(k) + img.y * PX_POR_MM}px; width: ${img.largura * PX_POR_MM}px`;
                    copia.innerHTML = '<img alt="">';
                    copia.querySelector('img').src = img.url;
                    camada.appendChild(copia);
                }
            }
        });
    }
    document.addEventListener('pointerdown', (ev) => {
        if (!ev.target.closest('.img-solta')) camada.querySelectorAll('.img-solta').forEach((c) => c.classList.remove('selecionada'));
    });
    window.enviarImagem = async (campo) => {
        if (!campo.files.length) return;
        const dados = new FormData();
        dados.append('acao', 'upload_imagem');
        dados.append('modelo_id', C.modeloId);
        dados.append('arquivo', campo.files[0]);
        campo.value = '';
        const r = await (await fetch('/configuracoes/contrato.php', { method: 'POST', body: dados })).json();
        if (!r.sucesso) { alert(r.mensagem || 'Não foi possível enviar a imagem.'); return; }
        // Entra no canto de cima, dentro das margens (no cabeçalho), com 4 cm de largura.
        imagens.push({ url: r.url, x: margem('esquerda') * 10, y: margem('superior') * 10, largura: 40, todas: 1 });
        alterado = true;
        desenharImagens();
    };

    // ---- Salvar / pré-visualizar ----
    // Espaços repetidos viram espaço fixo (&nbsp;): sem isso, ao reabrir o
    // contrato, o editor juntaria "A     B" em "A B".
    const fixarEspacos = (html) => html.replace(/>([^<]*)</g, (m, texto) => '>' + texto.replace(/ {2,}/g, (s) => ' '.repeat(s.length)).replace(/^ /, ' ') + '<');
    const htmlDe = (quill) => (quill.getLength() > 1 ? fixarEspacos(quill.getSemanticHTML()) : '');
    window.enviarContrato = (acao) => {
        const form = document.getElementById('form-contrato');
        document.getElementById('contrato-acao').value = acao;
        document.getElementById('contrato-conteudo').value = htmlDe(editores.corpo.quill);
        document.getElementById('contrato-cabecalho').value = htmlDe(editores.cabecalho.quill);
        document.getElementById('contrato-rodape').value = htmlDe(editores.rodape.quill);
        document.getElementById('contrato-imagens').value = JSON.stringify(imagens);
        document.getElementById('contrato-tabulacoes').value = JSON.stringify(marcas);
        // A pré-visualização abre em outra aba; o resto fica nesta.
        form.target = acao === 'previa' ? '_blank' : '';
        if (acao !== 'previa') alterado = false;
        form.submit();
    };
    window.confirmarSaidaContrato = () => !alterado || confirm('Sair sem salvar as alterações do contrato?');
    window.addEventListener('beforeunload', (ev) => { if (alterado) { ev.preventDefault(); ev.returnValue = ''; } });

    desenharRegua();
    window.atualizarNumeroPagina();
    paginar();
    // Fontes e imagens carregadas mudam as medidas.
    window.addEventListener('load', agendar);
    if (document.fonts) document.fonts.ready.then(agendar);
    alterado = false;
})();
