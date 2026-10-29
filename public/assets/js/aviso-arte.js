// Arte dos avisos, tipo Canva (Portal › Avisos aos alunos › aviso). Uma folha com
// proporção e fundo, e elementos soltos — texto, imagem, botão e forma —
// que se arrastam, aumentam, giram e ficam uns por cima dos outros.
// Posição e tamanho em % da folha; letra e cantos em "px numa folha de
// 1000 px" (cqw na tela). htmlArteAviso() desenha igual ao PHP
// (includes/avisos.php › htmlArteAviso) e precisa de aviso-arte.css.
(() => {
    const PROPORCOES = { '1:1': [1, 1], '4:5': [4, 5], '9:16': [9, 16], '16:9': [16, 9], '3:1': [3, 1] };
    const FONTES = {
        'Inter': "'Inter', sans-serif", 'Montserrat': "'Montserrat', sans-serif", 'Poppins': "'Poppins', sans-serif",
        'Oswald': "'Oswald', sans-serif", 'Anton': "'Anton', sans-serif", 'Bebas Neue': "'Bebas Neue', sans-serif",
        'Playfair Display': "'Playfair Display', serif", 'Lora': "'Lora', serif", 'Pacifico': "'Pacifico', cursive", 'Dancing Script': "'Dancing Script', cursive",
    };
    const esc = (t) => String(t ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const cqw = (px) => Math.round(px / 10 * 1000) / 1000 + 'cqw';
    const rgba = (hex, op) => {
        const n = parseInt(hex.slice(1), 16);
        return `rgba(${n >> 16}, ${(n >> 8) & 255}, ${n & 255}, ${Math.round(op) / 100})`;
    };
    const arred = (n) => Math.round(n * 100) / 100;

    function estiloLetra(e) {
        return `font-family: ${FONTES[e.fonte] || FONTES.Inter}; font-size: ${cqw(e.tamanho)}; color: ${e.cor}; font-weight: ${e.negrito ? 700 : 400};`
            + (e.italico ? ' font-style: italic;' : '') + (e.maiusculo ? ' text-transform: uppercase;' : '');
    }

    function estiloDentro(e) {
        if (e.tipo === 'texto') {
            return estiloLetra(e) + ` text-align: ${e.alinhamento}; line-height: ${e.entrelinha};`
                + (e.fundo ? ` background: ${rgba(e.fundo, e.fundo_opacidade ?? 100)}; padding: 0 ${cqw(16)};` : '')
                + (e.raio ? ` border-radius: ${cqw(e.raio)};` : '')
                + (e.sombra ? ' text-shadow: 0 0.3cqw 1cqw rgba(0,0,0,.45);' : '');
        }
        if (e.tipo === 'botao') {
            return estiloLetra(e) + ` background: ${e.fundo}; border-radius: ${cqw(e.raio)};` + (e.sombra ? ' box-shadow: 0 0.6cqw 2cqw rgba(0,0,0,.3);' : '');
        }
        if (e.tipo === 'imagem') {
            return `border-radius: ${cqw(e.raio)};` + (e.sombra ? ' box-shadow: 0 0.6cqw 2cqw rgba(0,0,0,.3);' : '');
        }
        return `background: ${e.cor}; border-radius: ${e.circulo ? '50%' : cqw(e.raio)};` + (e.sombra ? ' box-shadow: 0 0.6cqw 2cqw rgba(0,0,0,.3);' : '');
    }

    // opcoes.editor: desenha para editar (sem links, com alças no selecionado).
    function htmlArteAviso(arte, opcoes = {}) {
        const [pw, ph] = PROPORCOES[arte.proporcao] || [1, 1];
        let html = `<div class="arte-aviso" style="aspect-ratio: ${pw} / ${ph}; background-color: ${arte.fundo_cor};`
            + (arte.fundo_imagem ? ` background-image: url('${esc(arte.fundo_imagem)}');` : '') + '">';
        arte.elementos.forEach((e, i) => {
            const autoAltura = opcoes.editor && e.tipo === 'texto';
            const fora = `left: ${e.x}%; top: ${e.y}%; width: ${e.w}%; height: ${autoAltura ? 'auto' : e.h + '%'};`
                + (e.giro ? ` transform: rotate(${e.giro}deg);` : '') + (e.opacidade < 100 ? ` opacity: ${e.opacidade / 100};` : '');
            let miolo = '';
            if (e.tipo === 'texto' || e.tipo === 'botao') miolo = esc(e.texto);
            if (e.tipo === 'imagem') miolo = `<img src="${esc(e.imagem)}" alt="" draggable="false" style="object-fit: ${e.ajuste}">`;
            const dentro = `<div class="arte-in" style="${esc(estiloDentro(e))}${autoAltura ? ' height: auto;' : ''}">${miolo}</div>`;
            if (opcoes.editor) {
                const sel = i === opcoes.sel;
                const alcas = !sel ? '' : (e.tipo === 'texto' ? ['nw', 'ne', 'sw', 'se', 'e', 'w'] : ['nw', 'ne', 'sw', 'se', 'n', 's', 'e', 'w'])
                    .map((a) => `<span class="arte-alca a-${a}" data-alca="${a}"></span>`).join('') + '<span class="arte-alca a-giro" data-alca="giro" title="Girar"><i class="bi bi-arrow-clockwise"></i></span>';
                html += `<div class="arte-el arte-${e.tipo}${sel ? ' sel' : ''}" data-i="${i}" style="${fora}">${dentro}${alcas}</div>`;
            } else if (e.link) {
                html += `<a class="arte-el arte-${e.tipo}" href="${esc(e.link)}"${/^https?:/.test(e.link) ? ' target="_blank" rel="noopener"' : ''} style="${fora}">${dentro}</a>`;
            } else {
                html += `<div class="arte-el arte-${e.tipo}" style="${fora}">${dentro}</div>`;
            }
        });
        return html + '</div>';
    }
    window.htmlArteAviso = htmlArteAviso;

    // ------------------------------------------------------------------
    // Modelos prontos
    // ------------------------------------------------------------------
    const T = (texto, x, y, w, tamanho, cor, extra = {}) => ({ tipo: 'texto', texto, x, y, w, h: 10, giro: 0, opacidade: 100, raio: 0, sombra: false, link: '',
        fonte: 'Montserrat', tamanho, cor, negrito: false, italico: false, maiusculo: false, alinhamento: 'center', entrelinha: 1.15, fundo: '', fundo_opacidade: 100, ...extra });
    const F = (x, y, w, h, cor, extra = {}) => ({ tipo: 'forma', x, y, w, h, giro: 0, opacidade: 100, raio: 0, sombra: false, link: '', cor, circulo: false, ...extra });
    const B = (texto, x, y, w, h, fundo, cor, extra = {}) => ({ tipo: 'botao', texto, x, y, w, h, giro: 0, opacidade: 100, raio: 60, sombra: true, link: '/portal/turmas.php',
        fonte: 'Montserrat', tamanho: 42, cor, negrito: true, italico: false, maiusculo: false, fundo, ...extra });
    const MODELOS = {
        promocao: ['Promoção (Black Friday)', {
            proporcao: '1:1', fundo_cor: '#0b0b0f', fundo_imagem: null, elementos: [
                F(-10, 62, 120, 60, '#facc15', { giro: -8 }),
                T('BLACK', 8, 12, 84, 190, '#ffffff', { fonte: 'Anton', entrelinha: 1 }),
                T('FRIDAY', 8, 32, 84, 190, '#facc15', { fonte: 'Anton', entrelinha: 1 }),
                T('Até 50% OFF nos cursos', 10, 54, 80, 52, '#ffffff', { negrito: true }),
                B('QUERO APROVEITAR', 22, 74, 56, 10, '#0b0b0f', '#facc15', { tamanho: 36 }),
                T('Só até domingo', 10, 88, 80, 30, '#0b0b0f', { negrito: true }),
            ],
        }],
        comunicado: ['Comunicado', {
            proporcao: '4:5', fundo_cor: '#dbeafe', fundo_imagem: null, elementos: [
                F(7, 7, 86, 86, '#ffffff', { raio: 40, sombra: true }),
                F(7, 7, 86, 4, '#2563eb', { raio: 0 }),
                T('COMUNICADO', 12, 18, 76, 34, '#2563eb', { negrito: true, maiusculo: true }),
                T('Título do aviso', 12, 27, 76, 78, '#0f172a', { fonte: 'Poppins', negrito: true }),
                T('Escreva aqui o recado para os alunos. Dois cliques no texto para editar.', 14, 50, 72, 38, '#334155', { fonte: 'Inter', entrelinha: 1.4 }),
                T('Secretaria', 12, 82, 76, 30, '#64748b', { fonte: 'Inter', italico: true }),
            ],
        }],
        atencao: ['Atenção (aula remarcada)', {
            proporcao: '16:9', fundo_cor: '#fef3c7', fundo_imagem: null, elementos: [
                F(0, 0, 4, 100, '#f59e0b'),
                T('⚠ ATENÇÃO', 8, 12, 60, 50, '#b45309', { negrito: true, alinhamento: 'left' }),
                T('Aula remarcada', 8, 28, 84, 110, '#1f2937', { fonte: 'Poppins', negrito: true, alinhamento: 'left' }),
                T('A aula do dia 10 passou para o dia 17, no mesmo horário.', 8, 62, 80, 44, '#374151', { fonte: 'Inter', alinhamento: 'left', entrelinha: 1.35 }),
            ],
        }],
        boas_vindas: ['Boas-vindas', {
            proporcao: '16:9', fundo_cor: '#064e3b', fundo_imagem: null, elementos: [
                F(62, -20, 60, 106, '#10b981', { circulo: true, opacidade: 35 }),
                F(78, 50, 40, 71, '#34d399', { circulo: true, opacidade: 30 }),
                T('Seja bem-vindo(a)!', 7, 22, 70, 100, '#ffffff', { fonte: 'Pacifico', alinhamento: 'left' }),
                T('Que bom ter você com a gente. Acesse Meus cursos para ver o calendário.', 7, 50, 56, 40, '#d1fae5', { fonte: 'Inter', alinhamento: 'left', entrelinha: 1.35 }),
                B('Meus cursos', 7, 74, 28, 12, '#ffffff', '#064e3b', { link: '/portal/meus_cursos.php', tamanho: 34 }),
            ],
        }],
    };

    // ------------------------------------------------------------------
    // Editor
    // ------------------------------------------------------------------
    window.iniciarEditorArte = function (config) {
        const raiz = config.raiz;
        let arte = JSON.parse(JSON.stringify(config.arte));
        let sel = -1;
        let editandoTexto = false;
        const historico = [JSON.stringify(arte)];
        let posHistorico = 0;

        raiz.innerHTML = `
            <div class="arte-barra">
                <div class="btn-group btn-group-sm">
                    <button type="button" class="btn btn-outline-secondary" data-acao="titulo" title="Título grande"><i class="bi bi-type-h1"></i> Título</button>
                    <button type="button" class="btn btn-outline-secondary" data-acao="texto"><i class="bi bi-fonts"></i> Texto</button>
                    <label class="btn btn-outline-secondary mb-0" title="Imagem (ou cole / arraste uma imagem na folha)"><i class="bi bi-image"></i> Imagem<input type="file" accept="image/*" hidden data-arquivo="imagem"></label>
                    <button type="button" class="btn btn-outline-secondary" data-acao="botao" title="Botão com link"><i class="bi bi-hand-index"></i> Botão</button>
                    <button type="button" class="btn btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown"><i class="bi bi-square"></i> Forma</button>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="#" data-acao="retangulo"><i class="bi bi-square-fill"></i> Retângulo</a></li>
                        <li><a class="dropdown-item" href="#" data-acao="arredondado"><i class="bi bi-app"></i> Cartão arredondado</a></li>
                        <li><a class="dropdown-item" href="#" data-acao="circulo"><i class="bi bi-circle-fill"></i> Círculo</a></li>
                        <li><a class="dropdown-item" href="#" data-acao="linha"><i class="bi bi-dash-lg"></i> Linha</a></li>
                    </ul>
                </div>
                <div class="btn-group btn-group-sm">
                    <button type="button" class="btn btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown"><i class="bi bi-magic"></i> Modelos prontos</button>
                    <ul class="dropdown-menu">${Object.entries(MODELOS).map(([k, [nome]]) => `<li><a class="dropdown-item" href="#" data-modelo="${k}">${nome}</a></li>`).join('')}</ul>
                </div>
                <div class="btn-group btn-group-sm">
                    <button type="button" class="btn btn-outline-secondary" data-acao="desfazer" title="Desfazer (Ctrl+Z)"><i class="bi bi-arrow-counterclockwise"></i></button>
                    <button type="button" class="btn btn-outline-secondary" data-acao="refazer" title="Refazer (Ctrl+Shift+Z)"><i class="bi bi-arrow-clockwise"></i></button>
                </div>
                <span class="arte-sep"></span>
                <select class="form-select form-select-sm" data-folha="proporcao" style="width: auto; min-width: 215px" title="Formato da folha">
                    ${Object.entries(config.proporcoes).map(([k, r]) => `<option value="${k}">${r} · ${k}</option>`).join('')}
                </select>
                <label class="arte-cor" title="Cor do fundo"><input type="color" data-folha="fundo_cor"><span>Fundo</span></label>
                <label class="btn btn-sm btn-outline-secondary mb-0" title="Imagem de fundo (cobre a folha toda)"><i class="bi bi-card-image"></i> Imagem de fundo<input type="file" accept="image/*" hidden data-arquivo="fundo"></label>
                <button type="button" class="btn btn-sm btn-link text-danger px-1 d-none" data-acao="tirar-fundo" title="Tirar a imagem de fundo"><i class="bi bi-x-circle"></i></button>
            </div>
            <div class="arte-contexto"></div>
            <div class="arte-palco"><div class="arte-folha-editor"></div></div>
            <div class="form-text">Arraste para mover · alças dos cantos aumentam/diminuem (o texto acompanha) · alça <i class="bi bi-arrow-clockwise"></i> gira ·
                <strong>dois cliques no texto para escrever</strong> · setas movem · Delete apaga · Ctrl+D duplica · Ctrl+Z desfaz · cole (Ctrl+V) ou arraste uma imagem para a folha.</div>`;
        const folhaEl = raiz.querySelector('.arte-folha-editor');
        const palco = raiz.querySelector('.arte-palco');
        const contexto = raiz.querySelector('.arte-contexto');

        // ---- histórico ----
        function registrar() {
            const atual = JSON.stringify(arte);
            if (atual === historico[posHistorico]) return;
            historico.splice(posHistorico + 1);
            historico.push(atual);
            if (historico.length > 80) historico.shift();
            posHistorico = historico.length - 1;
            if (config.aoMudar) config.aoMudar();
        }
        function voltarPara(pos) {
            if (pos < 0 || pos >= historico.length) return;
            posHistorico = pos;
            arte = JSON.parse(historico[pos]);
            if (sel >= arte.elementos.length) sel = -1;
            desenhar();
            if (config.aoMudar) config.aoMudar();
        }

        // ---- desenho ----
        function medidas() { return folhaEl.querySelector('.arte-aviso').getBoundingClientRect(); }
        function dimensionarFolha() {
            const [pw, ph] = PROPORCOES[arte.proporcao];
            const largura = Math.min(palco.clientWidth - 48, (window.innerHeight * 0.68) * pw / ph, 900);
            folhaEl.style.width = Math.max(240, largura) + 'px';
        }
        function desenhar(guias = []) {
            if (editandoTexto) return;
            dimensionarFolha();
            folhaEl.innerHTML = htmlArteAviso(arte, { editor: true, sel });
            const folha = folhaEl.querySelector('.arte-aviso');
            guias.forEach((g) => {
                const l = document.createElement('div');
                l.className = 'arte-guia ' + g.eixo;
                l.style[g.eixo === 'v' ? 'left' : 'top'] = g.pos + '%';
                folha.appendChild(l);
            });
            sincronizarAlturas();
            raiz.querySelector('[data-folha="proporcao"]').value = arte.proporcao;
            raiz.querySelector('[data-folha="fundo_cor"]').value = arte.fundo_cor;
            raiz.querySelector('[data-acao="tirar-fundo"]').classList.toggle('d-none', !arte.fundo_imagem);
            desenharContexto();
        }
        // Texto: a caixa tem a altura do que está escrito (como no Canva).
        function sincronizarAlturas() {
            const r = medidas();
            folhaEl.querySelectorAll('.arte-texto[data-i]').forEach((el) => {
                arte.elementos[el.dataset.i].h = arred(el.offsetHeight / r.height * 100);
            });
        }

        // ---- barra do elemento selecionado ----
        function cor(k, valor, titulo) { return `<label class="arte-cor" title="${titulo}"><input type="color" data-k="${k}" value="${valor}"><span>${titulo}</span></label>`; }
        function liga(k, ativo, icone, titulo) { return `<button type="button" class="btn btn-sm ${ativo ? 'btn-dark' : 'btn-outline-secondary'}" data-liga="${k}" title="${titulo}">${icone}</button>`; }
        function faixa(k, valor, min, max, titulo, passo = 1) { return `<label class="arte-faixa" title="${titulo}"><span>${titulo}</span><input type="range" class="form-range" data-k="${k}" min="${min}" max="${max}" step="${passo}" value="${valor}"></label>`; }
        function campoFonte(e) {
            return `<select class="form-select form-select-sm" data-k="fonte" style="width: 150px">${Object.keys(FONTES).map((f) => `<option ${e.fonte === f ? 'selected' : ''} style="font-family: ${FONTES[f]}">${f}</option>`).join('')}</select>`
                + `<div class="input-group input-group-sm" style="width: 118px" title="Tamanho da letra"><button type="button" class="btn btn-outline-secondary" data-tamanho="-1">−</button>`
                + `<input type="number" class="form-control text-center" data-k="tamanho" min="6" max="600" value="${Math.round(e.tamanho)}"><button type="button" class="btn btn-outline-secondary" data-tamanho="1">+</button></div>`;
        }
        function comuns(e) {
            return `<span class="arte-sep"></span>`
                + `<div class="input-group input-group-sm" style="width: 230px" title="Link ao clicar (opcional)"><span class="input-group-text"><i class="bi bi-link-45deg"></i></span><input type="text" class="form-control" data-k="link" placeholder="${e.tipo === 'botao' ? 'Link do botão' : 'Link ao clicar (opcional)'}" value="${esc(e.link || '')}"></div>`
                + faixa('opacidade', e.opacidade, 5, 100, 'Transparência')
                + `<div class="input-group input-group-sm" style="width: 92px" title="Giro (graus)"><span class="input-group-text"><i class="bi bi-arrow-repeat"></i></span><input type="number" class="form-control" data-k="giro" min="-180" max="180" value="${Math.round(e.giro)}"></div>`
                + `<span class="arte-sep"></span><div class="btn-group btn-group-sm">`
                + `<button type="button" class="btn btn-outline-secondary" data-ordem="frente" title="Trazer para a frente"><i class="bi bi-front"></i></button>`
                + `<button type="button" class="btn btn-outline-secondary" data-ordem="tras" title="Mandar para trás"><i class="bi bi-back"></i></button>`
                + `<button type="button" class="btn btn-outline-secondary" data-acao="centralizar" title="Centralizar na folha"><i class="bi bi-align-center"></i></button>`
                + `<button type="button" class="btn btn-outline-secondary" data-acao="duplicar" title="Duplicar (Ctrl+D)"><i class="bi bi-copy"></i></button>`
                + `<button type="button" class="btn btn-outline-danger" data-acao="apagar" title="Apagar (Delete)"><i class="bi bi-trash"></i></button></div>`;
        }
        function desenharContexto() {
            const e = arte.elementos[sel];
            if (!e) {
                contexto.innerHTML = '<span class="small text-muted"><i class="bi bi-hand-index-thumb"></i> Clique num elemento da arte para mudar cor, letra, tamanho, link... ou comece por um <strong>modelo pronto</strong>.</span>';
                return;
            }
            let h = '';
            if (e.tipo === 'texto') {
                h = campoFonte(e) + cor('cor', e.cor, 'Cor')
                    + `<div class="btn-group btn-group-sm">${liga('negrito', e.negrito, '<i class="bi bi-type-bold"></i>', 'Negrito')}${liga('italico', e.italico, '<i class="bi bi-type-italic"></i>', 'Itálico')}${liga('maiusculo', e.maiusculo, 'AA', 'Tudo maiúsculo')}</div>`
                    + `<div class="btn-group btn-group-sm">${['left', 'center', 'right'].map((a) => `<button type="button" class="btn ${e.alinhamento === a ? 'btn-dark' : 'btn-outline-secondary'}" data-alinhar="${a}"><i class="bi bi-text-${a}"></i></button>`).join('')}</div>`
                    + `<select class="form-select form-select-sm" data-k="entrelinha" style="width: 92px" title="Espaço entre linhas">${[0.9, 1, 1.15, 1.3, 1.5, 1.8, 2].map((v) => `<option value="${v}" ${Number(e.entrelinha) === v ? 'selected' : ''}>↕ ${v}</option>`).join('')}</select>`
                    + liga('sombra', e.sombra, '<i class="bi bi-shadows"></i>', 'Sombra no texto')
                    + `<span class="arte-sep"></span>` + liga('com_fundo', !!e.fundo, '<i class="bi bi-paint-bucket"></i> Fundo', 'Faixa de cor atrás do texto')
                    + (e.fundo ? cor('fundo', e.fundo, 'Cor do fundo') + faixa('fundo_opacidade', e.fundo_opacidade ?? 100, 0, 100, 'Fundo') + faixa('raio', e.raio, 0, 200, 'Cantos') : '')
                    + comuns(e);
            } else if (e.tipo === 'botao') {
                h = `<input type="text" class="form-control form-control-sm" style="width: 170px" data-k="texto" value="${esc(e.texto)}" title="Texto do botão">`
                    + campoFonte(e) + cor('cor', e.cor, 'Letra') + cor('fundo', e.fundo, 'Botão')
                    + `<div class="btn-group btn-group-sm">${liga('negrito', e.negrito, '<i class="bi bi-type-bold"></i>', 'Negrito')}${liga('maiusculo', e.maiusculo, 'AA', 'Tudo maiúsculo')}</div>`
                    + faixa('raio', e.raio, 0, 300, 'Cantos') + liga('sombra', e.sombra, '<i class="bi bi-shadows"></i>', 'Sombra') + comuns(e);
            } else if (e.tipo === 'imagem') {
                h = `<label class="btn btn-sm btn-outline-secondary mb-0"><i class="bi bi-arrow-repeat"></i> Trocar imagem<input type="file" accept="image/*" hidden data-arquivo="trocar"></label>`
                    + `<div class="btn-group btn-group-sm">${[['cover', 'Preencher'], ['contain', 'Inteira']].map(([v, r]) => `<button type="button" class="btn ${e.ajuste === v ? 'btn-dark' : 'btn-outline-secondary'}" data-ajuste="${v}">${r}</button>`).join('')}</div>`
                    + faixa('raio', e.raio, 0, 500, 'Cantos') + liga('sombra', e.sombra, '<i class="bi bi-shadows"></i>', 'Sombra') + comuns(e);
            } else {
                h = cor('cor', e.cor, 'Cor') + liga('circulo', e.circulo, '<i class="bi bi-circle"></i> Círculo', 'Forma redonda')
                    + (e.circulo ? '' : faixa('raio', e.raio, 0, 300, 'Cantos')) + liga('sombra', e.sombra, '<i class="bi bi-shadows"></i>', 'Sombra') + comuns(e);
            }
            contexto.innerHTML = h;
        }

        // Campos da barra do elemento.
        contexto.addEventListener('input', (ev) => {
            const e = arte.elementos[sel];
            const k = ev.target.dataset.k;
            if (!e || !k) return;
            const v = ev.target.value;
            e[k] = ['tamanho', 'opacidade', 'giro', 'raio', 'fundo_opacidade', 'entrelinha'].includes(k) ? (parseFloat(v) || 0) : v;
            const foco = ev.target;
            folhaEl.innerHTML = htmlArteAviso(arte, { editor: true, sel });
            sincronizarAlturas();
            foco.focus();
        });
        contexto.addEventListener('change', () => registrar());
        contexto.addEventListener('click', (ev) => {
            const e = arte.elementos[sel];
            const b = ev.target.closest('button');
            if (!e || !b) return;
            if (b.dataset.liga) {
                const k = b.dataset.liga;
                if (k === 'com_fundo') e.fundo = e.fundo ? '' : '#000000';
                else e[k] = !e[k];
            } else if (b.dataset.alinhar) e.alinhamento = b.dataset.alinhar;
            else if (b.dataset.ajuste) e.ajuste = b.dataset.ajuste;
            else if (b.dataset.tamanho) e.tamanho = Math.max(6, Math.round(e.tamanho * (b.dataset.tamanho > 0 ? 1.1 : 0.9)));
            else if (b.dataset.ordem) {
                const [item] = arte.elementos.splice(sel, 1);
                if (b.dataset.ordem === 'frente') { arte.elementos.push(item); sel = arte.elementos.length - 1; } else { arte.elementos.unshift(item); sel = 0; }
            } else return;
            desenhar(); registrar();
        });

        // ---- botões da barra de cima ----
        function adicionar(e) {
            arte.elementos.push(e);
            sel = arte.elementos.length - 1;
            desenhar(); registrar();
        }
        const acoes = {
            titulo: () => adicionar(T('Seu título aqui', 10, 40, 80, 90, '#111111', { fonte: 'Poppins', negrito: true })),
            texto: () => adicionar(T('Dois cliques para escrever', 15, 45, 70, 40, '#333333', { fonte: 'Inter' })),
            botao: () => adicionar(B('Saiba mais', 30, 75, 40, 10, '#16a34a', '#ffffff')),
            retangulo: () => adicionar(F(20, 20, 60, 30, '#2563eb')),
            arredondado: () => adicionar(F(10, 15, 80, 70, '#ffffff', { raio: 40, sombra: true })),
            circulo: () => adicionar(F(35, 35, 30, 30 * folhaAspecto(), '#f59e0b', { circulo: true })),
            linha: () => adicionar(F(20, 50, 60, 0.8, '#111111')),
            desfazer: () => voltarPara(posHistorico - 1),
            refazer: () => voltarPara(posHistorico + 1),
            'tirar-fundo': () => { arte.fundo_imagem = null; desenhar(); registrar(); },
            duplicar: () => duplicar(),
            apagar: () => apagar(),
            centralizar: () => { const e = arte.elementos[sel]; if (e) { e.x = arred(50 - e.w / 2); desenhar([{ eixo: 'v', pos: 50 }]); registrar(); setTimeout(() => desenhar(), 600); } },
        };
        function folhaAspecto() { const [pw, ph] = PROPORCOES[arte.proporcao]; return pw / ph; }
        raiz.addEventListener('click', async (ev) => {
            const a = ev.target.closest('[data-acao]');
            if (a && raiz.contains(a) && !contexto.contains(a) || (a && contexto.contains(a) && ['duplicar', 'apagar', 'centralizar'].includes(a.dataset.acao))) {
                ev.preventDefault();
                acoes[a.dataset.acao]?.();
                return;
            }
            const m = ev.target.closest('[data-modelo]');
            if (m) {
                ev.preventDefault();
                if (arte.elementos.length && config.confirmar && !(await config.confirmar('Trocar pela arte do modelo? O que está na folha agora é substituído (dá para desfazer com Ctrl+Z).'))) return;
                arte = JSON.parse(JSON.stringify(MODELOS[m.dataset.modelo][1]));
                sel = -1;
                desenhar(); registrar();
            }
        });
        raiz.querySelector('[data-folha="proporcao"]').addEventListener('change', (ev) => { arte.proporcao = ev.target.value; desenhar(); registrar(); });
        raiz.querySelector('[data-folha="fundo_cor"]').addEventListener('input', (ev) => { arte.fundo_cor = ev.target.value; folhaEl.querySelector('.arte-aviso').style.backgroundColor = ev.target.value; });
        raiz.querySelector('[data-folha="fundo_cor"]').addEventListener('change', () => registrar());

        // ---- imagens: botão, trocar, fundo, colar e arrastar ----
        async function enviar(arquivo) {
            const dados = new FormData();
            dados.append('acao', 'upload_imagem');
            dados.append('arquivo', arquivo);
            try {
                const r = await (await fetch(config.urlUpload, { method: 'POST', body: dados })).json();
                if (!r.sucesso) { config.erro?.(r.mensagem || 'Não foi possível enviar a imagem.'); return null; }
                return r.url;
            } catch (e) { config.erro?.('Não foi possível enviar a imagem.'); return null; }
        }
        function medirImagem(url) {
            return new Promise((ok) => { const img = new Image(); img.onload = () => ok(img.naturalHeight / img.naturalWidth); img.onerror = () => ok(1); img.src = url; });
        }
        async function adicionarImagem(arquivo, centroX = 50, centroY = 50) {
            const url = await enviar(arquivo);
            if (!url) return;
            const razao = await medirImagem(url);
            const w = 60;
            const h = arred(w * razao * folhaAspecto());
            adicionar({ tipo: 'imagem', imagem: url, x: arred(centroX - w / 2), y: arred(centroY - h / 2), w, h, giro: 0, opacidade: 100, raio: 0, sombra: false, link: '', ajuste: 'cover' });
        }
        raiz.addEventListener('change', async (ev) => {
            const campo = ev.target.closest('[data-arquivo]');
            if (!campo || !campo.files.length) return;
            const arquivo = campo.files[0];
            campo.value = '';
            if (campo.dataset.arquivo === 'imagem') return adicionarImagem(arquivo);
            const url = await enviar(arquivo);
            if (!url) return;
            if (campo.dataset.arquivo === 'fundo') arte.fundo_imagem = url;
            else if (arte.elementos[sel]) arte.elementos[sel].imagem = url;
            desenhar(); registrar();
        });
        document.addEventListener('paste', (ev) => {
            if (editandoTexto || ['INPUT', 'TEXTAREA'].includes(document.activeElement?.tagName) || document.activeElement?.isContentEditable) return;
            const item = [...(ev.clipboardData?.items || [])].find((i) => i.type.startsWith('image/'));
            if (!item || !raiz.offsetParent) return;
            ev.preventDefault();
            adicionarImagem(item.getAsFile());
        });
        palco.addEventListener('dragover', (ev) => { ev.preventDefault(); palco.classList.add('soltando'); });
        palco.addEventListener('dragleave', () => palco.classList.remove('soltando'));
        palco.addEventListener('drop', (ev) => {
            ev.preventDefault();
            palco.classList.remove('soltando');
            const arquivo = [...(ev.dataTransfer?.files || [])].find((f) => f.type.startsWith('image/'));
            if (!arquivo) return;
            const r = medidas();
            adicionarImagem(arquivo, (ev.clientX - r.left) / r.width * 100, (ev.clientY - r.top) / r.height * 100);
        });

        // ---- mover, redimensionar, girar ----
        const ENCAIXE = 1.2; // % da folha
        function encaixar(i, x, y) {
            const e = arte.elementos[i];
            const guias = [];
            const alvosX = [0, 50, 100];
            const alvosY = [0, 50, 100];
            arte.elementos.forEach((o, j) => {
                if (j === i) return;
                alvosX.push(o.x, o.x + o.w / 2, o.x + o.w);
                alvosY.push(o.y, o.y + o.h / 2, o.y + o.h);
            });
            let melhor = null;
            [0, e.w / 2, e.w].forEach((d) => alvosX.forEach((alvo) => {
                const dist = Math.abs(x + d - alvo);
                if (dist < ENCAIXE && (!melhor || dist < melhor.dist)) melhor = { dist, x: alvo - d, pos: alvo };
            }));
            if (melhor) { x = melhor.x; guias.push({ eixo: 'v', pos: melhor.pos }); }
            melhor = null;
            [0, e.h / 2, e.h].forEach((d) => alvosY.forEach((alvo) => {
                const dist = Math.abs(y + d - alvo);
                if (dist < ENCAIXE && (!melhor || dist < melhor.dist)) melhor = { dist, y: alvo - d, pos: alvo };
            }));
            if (melhor) { y = melhor.y; guias.push({ eixo: 'h', pos: melhor.pos }); }
            return { x, y, guias };
        }
        function selecionar(i) {
            if (sel === i) return;
            sel = i;
            desenhar();
        }
        folhaEl.addEventListener('pointerdown', (ev) => {
            if (editandoTexto) {
                if (ev.target.closest('[contenteditable]')) return;
                terminarEdicao();
            }
            const el = ev.target.closest('[data-i]');
            if (!el) { selecionar(-1); return; }
            ev.preventDefault();
            const i = Number(el.dataset.i);
            const alca = ev.target.closest('[data-alca]')?.dataset.alca;
            selecionar(i);
            const e = arte.elementos[i];
            const r = medidas();
            const ini = { mx: ev.clientX, my: ev.clientY, x: e.x, y: e.y, w: e.w, h: e.h, tamanho: e.tamanho, raio: e.raio };
            let mexeu = false;
            const mover = (m) => {
                const dx = (m.clientX - ini.mx) / r.width * 100;
                const dy = (m.clientY - ini.my) / r.height * 100;
                if (!mexeu && Math.abs(m.clientX - ini.mx) + Math.abs(m.clientY - ini.my) < 3) return;
                mexeu = true;
                let guias = [];
                if (!alca) {
                    const s = m.altKey ? { x: ini.x + dx, y: ini.y + dy, guias: [] } : encaixar(i, ini.x + dx, ini.y + dy);
                    e.x = arred(s.x); e.y = arred(s.y); guias = s.guias;
                } else if (alca === 'giro') {
                    const cx = r.left + (e.x + e.w / 2) / 100 * r.width;
                    const cy = r.top + (e.y + e.h / 2) / 100 * r.height;
                    let ang = Math.atan2(m.clientY - cy, m.clientX - cx) * 180 / Math.PI + 90;
                    if (ang > 180) ang -= 360;
                    const passo = m.shiftKey ? 15 : 0;
                    if (passo) ang = Math.round(ang / passo) * passo;
                    else if (Math.abs(ang % 45) < 3 || Math.abs(ang % 45) > 42) ang = Math.round(ang / 45) * 45; // gruda em 0, 45, 90...
                    e.giro = Math.round(ang);
                } else if (alca.length === 2) {
                    // Canto: aumenta/diminui mantendo a proporção; texto e botão acompanham a letra.
                    const sx = alca[1] === 'e' ? 1 : -1;
                    const sy = alca[0] === 's' ? 1 : -1;
                    const fx = (ini.w + dx * sx) / ini.w;
                    const fy = (ini.h + dy * sy) / ini.h;
                    const f = Math.max(0.05, Math.abs(fx - 1) > Math.abs(fy - 1) ? fx : fy);
                    e.w = arred(Math.max(1, ini.w * f));
                    e.h = arred(Math.max(0.5, ini.h * f));
                    e.x = arred(sx > 0 ? ini.x : ini.x + ini.w - e.w);
                    e.y = arred(sy > 0 ? ini.y : ini.y + ini.h - e.h);
                    if (e.tipo === 'texto' || e.tipo === 'botao') e.tamanho = Math.max(6, arred(ini.tamanho * f));
                    if (ini.raio) e.raio = arred(ini.raio * f);
                } else {
                    if (alca === 'e') e.w = arred(Math.max(1, ini.w + dx));
                    if (alca === 'w') { e.w = arred(Math.max(1, ini.w - dx)); e.x = arred(ini.x + ini.w - e.w); }
                    if (alca === 's') e.h = arred(Math.max(0.5, ini.h + dy));
                    if (alca === 'n') { e.h = arred(Math.max(0.5, ini.h - dy)); e.y = arred(ini.y + ini.h - e.h); }
                }
                desenhar(guias);
            };
            const soltar = () => {
                window.removeEventListener('pointermove', mover);
                window.removeEventListener('pointerup', soltar);
                if (mexeu) { desenhar(); registrar(); }
            };
            window.addEventListener('pointermove', mover);
            window.addEventListener('pointerup', soltar);
        });

        // ---- escrever direto na arte (dois cliques) ----
        folhaEl.addEventListener('dblclick', (ev) => {
            const el = ev.target.closest('.arte-texto[data-i], .arte-botao[data-i]');
            if (!el) return;
            const i = Number(el.dataset.i);
            const dentro = el.querySelector('.arte-in');
            editandoTexto = true;
            el.querySelectorAll('.arte-alca').forEach((a) => a.remove());
            el.classList.add('editando');
            try { dentro.contentEditable = 'plaintext-only'; } catch (e) { dentro.contentEditable = 'true'; }
            if (dentro.contentEditable !== 'plaintext-only') dentro.contentEditable = 'true';
            dentro.focus();
            const faixaSel = document.createRange();
            faixaSel.selectNodeContents(dentro);
            const s = window.getSelection();
            s.removeAllRanges();
            s.addRange(faixaSel);
            dentro.addEventListener('input', () => {
                arte.elementos[i].texto = dentro.innerText.replace(/\n$/, '');
                if (config.aoMudar) config.aoMudar();
            });
            dentro.addEventListener('blur', terminarEdicao, { once: true });
        });
        function terminarEdicao() {
            if (!editandoTexto) return;
            editandoTexto = false;
            // Texto apagado inteiro: o elemento sai.
            const e = arte.elementos[sel];
            if (e && (e.tipo === 'texto') && !e.texto.trim()) { arte.elementos.splice(sel, 1); sel = -1; }
            desenhar(); registrar();
        }

        // ---- teclado ----
        function duplicar() {
            const e = arte.elementos[sel];
            if (!e) return;
            const copia = JSON.parse(JSON.stringify(e));
            copia.x = arred(copia.x + 3); copia.y = arred(copia.y + 3);
            adicionar(copia);
        }
        function apagar() {
            if (!arte.elementos[sel]) return;
            arte.elementos.splice(sel, 1);
            sel = -1;
            desenhar(); registrar();
        }
        document.addEventListener('keydown', (ev) => {
            if (editandoTexto) { if (ev.key === 'Escape') document.activeElement.blur(); return; }
            const ativo = document.activeElement;
            if (ativo && (['INPUT', 'TEXTAREA', 'SELECT'].includes(ativo.tagName) || ativo.isContentEditable)) return;
            if (!raiz.offsetParent) return; // editor escondido (aviso só com texto)
            const ctrl = ev.ctrlKey || ev.metaKey;
            if (ctrl && ev.key.toLowerCase() === 'z') { ev.preventDefault(); voltarPara(posHistorico + (ev.shiftKey ? 1 : -1)); return; }
            if (ctrl && ev.key.toLowerCase() === 'y') { ev.preventDefault(); voltarPara(posHistorico + 1); return; }
            const e = arte.elementos[sel];
            if (!e) return;
            if (ctrl && ev.key.toLowerCase() === 'd') { ev.preventDefault(); duplicar(); return; }
            if (ev.key === 'Delete' || ev.key === 'Backspace') { ev.preventDefault(); apagar(); return; }
            if (ev.key === 'Escape') { selecionar(-1); return; }
            const passo = ev.shiftKey ? 2 : 0.3;
            const mapa = { ArrowLeft: ['x', -passo], ArrowRight: ['x', passo], ArrowUp: ['y', -passo], ArrowDown: ['y', passo] };
            if (!mapa[ev.key]) return;
            ev.preventDefault();
            e[mapa[ev.key][0]] = arred(e[mapa[ev.key][0]] + mapa[ev.key][1]);
            desenhar();
            clearTimeout(acoes._t);
            acoes._t = setTimeout(registrar, 400);
        });

        window.addEventListener('resize', () => desenhar());
        if (document.fonts) document.fonts.ready.then(() => desenhar());
        desenhar();
        return {
            obter: () => { if (editandoTexto) terminarEdicao(); return arte; },
            redesenhar: () => desenhar(),
        };
    };
})();
