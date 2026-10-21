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
<script src="<?= e(assetUrl('/assets/js/mascaras.js')) ?>"></script>
<script src="<?= e(assetUrl('/assets/js/imprimir-lista.js')) ?>"></script>
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
<?php
// Administrador: avisa uma vez por sessão se a renovação automática do
// certificado do Itaú falhou ou se o certificado venceu.
if (usuarioLogado() && usuarioEhAdmin() && empty($_SESSION['aviso_certificado_mostrado'])):
    $problemasCertificado = getDB()->query(
        "SELECT id, apelido, api_certificado_validade, api_certificado_renovacao_erro FROM contas_bancarias
         WHERE integracao = 'itau' AND ativo = 1 AND api_ambiente = 'producao' AND api_certificado_validade IS NOT NULL
           AND (api_certificado_validade < CURDATE()
                OR (api_certificado_renovacao_erro IS NOT NULL AND api_certificado_validade <= CURDATE() + INTERVAL 30 DAY))"
    )->fetchAll();
    $_SESSION['aviso_certificado_mostrado'] = true;
    if ($problemasCertificado): ?>
<div class="modal fade" id="modal-aviso-certificado" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-danger">
            <div class="modal-header bg-danger-subtle">
                <h5 class="modal-title"><i class="bi bi-shield-exclamation"></i> Certificado do Itaú</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <?php foreach ($problemasCertificado as $pc): $vencido = $pc['api_certificado_validade'] < date('Y-m-d'); ?>
                    <div class="mb-3">
                        <div class="fw-semibold"><?= e($pc['apelido']) ?> — <?= $vencido ? 'certificado VENCIDO em ' : 'vence em ' ?><?= formatDate($pc['api_certificado_validade']) ?></div>
                        <?php if ($pc['api_certificado_renovacao_erro']): ?>
                            <div class="small">Houve uma <strong>falha na renovação automática</strong>: <?= e($pc['api_certificado_renovacao_erro']) ?></div>
                        <?php endif; ?>
                        <div class="small text-muted mt-1">
                            <?= $vencido ? 'Enquanto não instalar um novo, boletos e Pix não são gerados.' : 'O sistema tenta de novo todo dia.' ?>
                            Para resolver: peça ao Itaú (implantação) o <strong>token temporário</strong> e use "Gerar certificado novo" na conta bancária.
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Depois</button>
                <a href="/contas_bancarias/form.php?id=<?= (int) $problemasCertificado[0]['id'] ?>#certificado" class="btn btn-danger"><i class="bi bi-key"></i> Abrir a conta bancária</a>
            </div>
        </div>
    </div>
</div>
<script>bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-aviso-certificado')).show();</script>
<?php endif; endif; ?>
</body>
</html>
