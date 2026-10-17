    <div class="pagina-rodape">
        <?= e(nomeSistema()) ?> · <?= date('Y') ?>
    </div>
</main>
<style>
.topnav-sino { position: relative; }

.sino-badge {
    position: absolute;
    top: -2px;
    right: -4px;
    min-width: 18px;
    height: 18px;
    padding: 0 5px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 999px;
    background: #dc3545;
    color: #fff;
    font-size: 10.5px;
    font-weight: 700;
    line-height: 1;
    box-shadow: 0 0 0 2px #fff;
}

.painel-alertas {
    width: 370px;
    max-height: 440px;
    overflow-y: auto;
    padding: 0;
}

.alertas-cabecalho {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: .5rem;
    padding: .7rem .9rem;
    border-bottom: 1px solid var(--border);
    font-weight: 700;
    font-size: .85rem;
    position: sticky;
    top: 0;
    background: #fff;
    z-index: 1;
}

.alertas-vazio {
    padding: 2rem 1rem;
    text-align: center;
    color: var(--text-muted);
    font-size: .87rem;
}

.alertas-vazio i { font-size: 1.6rem; display: block; margin-bottom: .4rem; }

.alerta-item {
    display: flex;
    align-items: flex-start;
    gap: .65rem;
    padding: .7rem .9rem;
    border-bottom: 1px solid var(--border);
    text-decoration: none;
    color: inherit;
}

.alerta-item:hover { background: var(--bg); }
.alerta-item.nao-lida { background: var(--accent-soft); }
.alerta-item.nao-lida:hover { filter: brightness(.98); }

.alerta-icone {
    width: 32px;
    height: 32px;
    flex: none;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    background: var(--bg);
    color: var(--accent);
    font-size: .95rem;
}


.alerta-corpo { display: flex; flex-direction: column; gap: 2px; min-width: 0; flex: 1; }
.alerta-titulo { font-size: .85rem; font-weight: 600; line-height: 1.25; }
.alerta-descricao { font-size: .78rem; color: var(--text-muted); }
.alerta-tempo { font-size: .72rem; color: var(--text-muted); }

.alerta-ponto {
    width: 8px;
    height: 8px;
    flex: none;
    margin-top: .35rem;
    border-radius: 50%;
    background: var(--accent);
}
</style>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function atualizarBadgeAlertas(naoLidas) {
    const badge = document.getElementById('sino-badge');
    if (!badge) return;

    if (naoLidas > 0) {
        badge.textContent = naoLidas > 99 ? '99+' : naoLidas;
    } else {
        badge.remove();
    }
}

function marcarAlertaLido(id) {
    // O link segue navegando normalmente; a marcação vai junto.
    const corpo = new URLSearchParams({ id: id });
    fetch('/notificacoes/marcar_lida.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
        body: corpo.toString(),
        keepalive: true,
    }).catch(() => {});
}

function marcarTodosAlertas() {
    fetch('/notificacoes/marcar_lida.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
        body: 'todas=1',
    })
        .then((r) => r.json())
        .then((data) => {
            if (!data.sucesso) return;
            atualizarBadgeAlertas(data.nao_lidas);
            document.querySelectorAll('.alerta-item.nao-lida').forEach(function (item) {
                item.classList.remove('nao-lida');
                const ponto = item.querySelector('.alerta-ponto');
                if (ponto) ponto.remove();
            });
            document.querySelectorAll('.alertas-cabecalho .btn-link').forEach((b) => b.remove());
        })
        .catch(() => {});
}
</script>
<script src="/assets/js/filtro-periodo.js"></script>
<script src="/assets/js/validar-documento.js"></script>
<script src="/assets/js/escapar-html.js"></script>
<script src="/assets/js/csrf.js"></script>
<script>
(function () {
    let restante = <?= (int) sessaoSegundosRestantes() ?>;
    const elValor = document.getElementById('sessao-timer-valor');
    const elBadge = document.getElementById('topnav-sessao-timer');

    function formatar(segundos) {
        const m = Math.floor(segundos / 60);
        const s = segundos % 60;
        return String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
    }

    function renderizar() {
        if (!elValor) return;
        elValor.textContent = formatar(restante);
        if (elBadge) elBadge.classList.toggle('topnav-sessao-alerta', restante <= 60);
    }

    setInterval(function () {
        restante = Math.max(0, restante - 1);
        renderizar();

        if (restante === 0) {
            // Não decide sozinho que a sessão expirou (uma requisição AJAX
            // recente pode já ter renovado no servidor) — só recarrega, e
            // quem confirma de verdade é o exigirLogin() do PHP.
            location.reload();
        }
    }, 1000);

    renderizar();

    // Exposto para outras telas: qualquer ação que renove a sessão no
    // servidor (ex.: adicionar item num pedido longo) chama isto para o
    // contador visual não zerar e recarregar a página à toa, derrubando
    // o que ainda não foi salvo.
    window.renovarContadorSessao = function (segundos) {
        if (typeof segundos === 'number') {
            restante = segundos;
            renderizar();
        }
    };
})();

// Faz uma chamada leve ao servidor só para renovar a sessão (atualiza
// "última atividade") sem recarregar a página — usada em telas onde o
// usuário pode ficar digitando por muito tempo sem navegar (ex.: montando
// um pedido longo em Vendas/Compras) e perderia tudo se a sessão expirasse.
// Troca o tema (claro/escuro) e salva no usuário — cada um escolhe o dele,
// vale em qualquer aparelho que ele logar depois.
function alternarTema() {
    const atual = document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'dark' : 'light';
    const novo = atual === 'dark' ? 'claro' : 'escuro';

    fetch('/usuarios/tema.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
        body: 'tema=' + novo,
    })
        .then((r) => r.json())
        .then((data) => {
            if (data && data.sucesso) {
                location.reload();
            }
        })
        .catch(() => {});
}

function manterSessaoViva() {
    fetch('/sessao/manter.php', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then((r) => r.json())
        .then((data) => {
            if (data && data.sucesso && window.renovarContadorSessao) {
                window.renovarContadorSessao(data.restante);
            }
        })
        .catch(() => {});
}
</script>
</body>
</html>
