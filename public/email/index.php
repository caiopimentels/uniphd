<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirPermissao('usar_email', 'Seu perfil não usa o e-mail pelo sistema.');

// E-mail (caixa do próprio usuário): pastas à esquerda, lista no meio,
// busca, seleção para marcar lida/não lida, mover e excluir.
// ?pasta=NOME&pagina=N&busca=TEXTO&nao_lidas=1
$pdo = getDB();
$caixa = caixaUsuario($pdo, (int) usuarioAtualId());
$porPagina = 50;
$pagina = max(1, (int) ($_GET['pagina'] ?? 1));
$busca = trim((string) ($_GET['busca'] ?? ''));
$soNaoLidas = !empty($_GET['nao_lidas']);
$erro = null;
$pastas = [];
$mensagens = [];
$total = 0;
$pasta = (string) ($_GET['pasta'] ?? 'INBOX');
$contagens = [];

if ($caixa['configurada']) {
    try {
        $pastas = pastasCaixa($pdo, isset($_GET['atualizar']));
        if (!in_array($pasta, array_column($pastas, 'nome'), true)) {
            $pasta = 'INBOX';
        }
        $imap = abrirCaixa($pdo);
        foreach ($pastas as $p) {
            if (in_array($p['especial'], ['inbox', 'spam', 'rascunhos'], true)) {
                $contagens[$p['nome']] = $imap->status($p['nome']);
            }
        }
        $imap->abrirPasta($pasta);
        $uids = $imap->uids($busca !== '' ? $busca : null, $soNaoLidas);
        $total = count($uids);
        $mensagens = $imap->resumos(array_slice($uids, ($pagina - 1) * $porPagina, $porPagina));
        if ($pasta === 'INBOX' && isset($contagens['INBOX'])) {
            $_SESSION['email_nao_lidas_' . usuarioAtualId()] = ['ate' => time() + 60, 'n' => $contagens['INBOX']['nao_lidas']];
        }
    } catch (RuntimeException $e) {
        $erro = $e->getMessage();
    }
}
$pastaAtual = null;
foreach ($pastas as $p) {
    if ($p['nome'] === $pasta) {
        $pastaAtual = $p;
    }
}
$ehEnviados = in_array($pastaAtual['especial'] ?? null, ['enviados', 'rascunhos'], true);
$ehLixeira = ($pastaAtual['especial'] ?? null) === 'lixeira';
$pastaArquivo = isset($pastas) ? pastaEspecial($pastas, 'arquivo') : null;
$paginas = max(1, (int) ceil($total / $porPagina));
$link = fn (array $mudar = []) => '/email/index.php?' . http_build_query(array_filter(array_merge(['pasta' => $pasta, 'busca' => $busca, 'nao_lidas' => $soNaoLidas ? 1 : null, 'pagina' => $pagina > 1 ? $pagina : null], $mudar), fn ($v) => $v !== null && $v !== ''));
$icones = ['inbox' => 'bi-inbox', 'enviados' => 'bi-send', 'rascunhos' => 'bi-file-earmark', 'spam' => 'bi-exclamation-octagon', 'lixeira' => 'bi-trash', 'arquivo' => 'bi-archive'];

$pageTitle = $pastaAtual['rotulo'] ?? 'E-mail';
$pageBreadcrumb = 'E-mail' . ($caixa['configurada'] ? ' · ' . $caixa['email'] : '');
$activeMenu = 'email';
require __DIR__ . '/../../includes/layout_header.php';
require __DIR__ . '/../../includes/email_estilo.php';
?>

<?php if (!$caixa['configurada']): ?>
    <div class="secao text-center py-5" style="max-width: 640px; margin: 0 auto;">
        <i class="bi bi-envelope-paper fs-1 text-muted d-block mb-2"></i>
        <h5>Sua caixa de e-mail ainda não está ligada</h5>
        <p class="text-muted">Coloque o e-mail e a senha da sua caixa para ler e enviar e-mails por aqui.</p>
        <a href="/email/minha_caixa.php" class="btn btn-brand"><i class="bi bi-gear"></i> Configurar minha caixa</a>
        <?php if (usuarioPodeDispararEmail()): ?><a href="/email/disparos.php" class="btn btn-outline-secondary ms-1"><i class="bi bi-megaphone"></i> Disparo em massa</a><?php endif; ?>
    </div>
<?php else: ?>
<div class="email-app">
    <?php require __DIR__ . '/../../includes/email_lateral.php'; ?>

    <div class="email-principal">
        <form class="email-busca" method="get">
            <input type="hidden" name="pasta" value="<?= e($pasta) ?>">
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="search" name="busca" class="form-control" placeholder="Pesquisar em <?= e(mb_strtolower($pastaAtual['rotulo'] ?? 'e-mails')) ?> (remetente, assunto, texto)" value="<?= e($busca) ?>">
                <?php if ($busca !== ''): ?><a class="btn btn-outline-secondary" href="<?= e($link(['busca' => null, 'pagina' => null])) ?>" title="Limpar busca"><i class="bi bi-x-lg"></i></a><?php endif; ?>
            </div>
        </form>

        <?php if ($erro): ?>
            <div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> <?= e($erro) ?>
                <div class="small mt-1">Confira a senha em <a href="/email/minha_caixa.php">Minha caixa</a> ou o servidor em Configurações › E-mail.</div></div>
        <?php else: ?>
        <div class="email-lista-card">
            <div class="email-barra">
                <input type="checkbox" class="form-check-input" id="marcar-todos" title="Selecionar todos" onchange="document.querySelectorAll('.sel-msg').forEach((c) => c.checked = this.checked); atualizarSelecao()">
                <a class="btn btn-sm btn-link text-secondary" href="<?= e($link(['atualizar' => 1])) ?>" title="Atualizar"><i class="bi bi-arrow-clockwise"></i></a>
                <div class="email-acoes-sel sem-selecao" id="acoes-sel" title="Marque e-mails para usar estas ações">
                    <button type="button" class="btn btn-sm btn-link text-secondary" onclick="acaoEmail('lida')" title="Marcar como lida"><i class="bi bi-envelope-open"></i></button>
                    <button type="button" class="btn btn-sm btn-link text-secondary" onclick="acaoEmail('nao_lida')" title="Marcar como não lida"><i class="bi bi-envelope"></i></button>
                    <button type="button" class="btn btn-sm btn-link text-secondary" onclick="acaoEmail('excluir')" title="<?= $ehLixeira ? 'Excluir para sempre' : 'Excluir' ?>"><i class="bi bi-trash"></i></button>
                    <?php if ($pastaArquivo && $pastaArquivo !== $pasta): ?><button type="button" class="btn btn-sm btn-link text-secondary" onclick="acaoEmail('mover', <?= e(json_encode($pastaArquivo)) ?>)" title="Arquivar"><i class="bi bi-archive"></i></button><?php endif; ?>
                    <div class="dropdown d-inline-block">
                        <button type="button" class="btn btn-sm btn-link text-secondary" data-bs-toggle="dropdown" title="Mover para"><i class="bi bi-folder-symlink"></i></button>
                        <ul class="dropdown-menu">
                            <?php foreach ($pastas as $p): if ($p['nome'] === $pasta) continue; ?>
                                <li><a class="dropdown-item" href="#" onclick="acaoEmail('mover', <?= e(json_encode($p['nome'])) ?>); return false;"><i class="bi <?= $icones[$p['especial']] ?? 'bi-folder' ?>"></i> <?= e($p['rotulo']) ?></a></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
                <a class="btn btn-sm <?= $soNaoLidas ? 'btn-dark' : 'btn-outline-secondary' ?> ms-2" href="<?= e($link(['nao_lidas' => $soNaoLidas ? null : 1, 'pagina' => null])) ?>">Não lidas</a>
                <span class="ms-auto small text-muted text-nowrap">
                    <?= $total ? (($pagina - 1) * $porPagina + 1) . '–' . min($total, $pagina * $porPagina) . ' de ' . $total : '' ?>
                    <a class="btn btn-sm btn-link text-secondary <?= $pagina <= 1 ? 'disabled' : '' ?>" href="<?= e($link(['pagina' => $pagina - 1])) ?>"><i class="bi bi-chevron-left"></i></a>
                    <a class="btn btn-sm btn-link text-secondary <?= $pagina >= $paginas ? 'disabled' : '' ?>" href="<?= e($link(['pagina' => $pagina + 1])) ?>"><i class="bi bi-chevron-right"></i></a>
                </span>
            </div>

            <?php if (!$mensagens): ?>
                <div class="text-center text-muted py-5"><i class="bi bi-inbox fs-2 d-block mb-2"></i><?= $busca !== '' ? 'Nada encontrado para "' . e($busca) . '".' : ($soNaoLidas ? 'Nenhuma não lida.' : 'Nenhum e-mail nesta pasta.') ?></div>
            <?php endif; ?>
            <?php foreach ($mensagens as $m):
                $quem = $ehEnviados ? 'Para: ' . implode(', ', array_map(fn ($p) => $p['nome'] ?: $p['email'], $m['para'])) : ($m['de']['nome'] ?: $m['de']['email']);
                $hoje = date('Y-m-d', strtotime($m['data'])) === date('Y-m-d');
                // Rascunho abre na tela de escrever, para continuar.
                $url = ($pastaAtual['especial'] ?? null) === 'rascunhos'
                    ? '/email/escrever.php?rascunho=' . (int) $m['uid']
                    : '/email/ler.php?' . http_build_query(['pasta' => $pasta, 'uid' => $m['uid']]);
            ?>
                <div class="email-linha <?= $m['lida'] ? '' : 'nao-lida' ?>" data-uid="<?= (int) $m['uid'] ?>">
                    <input type="checkbox" class="form-check-input sel-msg" value="<?= (int) $m['uid'] ?>" onchange="atualizarSelecao()">
                    <button type="button" class="estrela <?= $m['importante'] ? 'ligada' : '' ?>" title="Importante" onclick="estrela(this, <?= (int) $m['uid'] ?>)"><i class="bi <?= $m['importante'] ? 'bi-star-fill' : 'bi-star' ?>"></i></button>
                    <a class="email-link" href="<?= e($url) ?>">
                        <span class="email-quem" title="<?= e($ehEnviados ? $quem : $m['de']['email']) ?>"><?= e($quem) ?></span>
                        <span class="email-assunto"><?= e($m['assunto'] ?: '(sem assunto)') ?></span>
                        <?php if ($m['tem_anexo']): ?><i class="bi bi-paperclip text-muted"></i><?php endif; ?>
                        <?php if ($m['respondida']): ?><i class="bi bi-reply text-muted" title="Respondido"></i><?php endif; ?>
                        <span class="email-data" title="<?= e(formatDateTime($m['data'])) ?>"><?= $hoje ? date('H:i', strtotime($m['data'])) : date('d/m/y', strtotime($m['data'])) ?></span>
                    </a>
                    <span class="email-acoes-linha">
                        <?php if ($pastaArquivo && $pastaArquivo !== $pasta): ?><button type="button" title="Arquivar" onclick="acaoLinha(<?= (int) $m['uid'] ?>, 'mover', <?= e(json_encode($pastaArquivo)) ?>)"><i class="bi bi-archive"></i></button><?php endif; ?>
                        <button type="button" title="<?= $ehLixeira ? 'Excluir para sempre' : 'Excluir' ?>" onclick="acaoLinha(<?= (int) $m['uid'] ?>, 'excluir')"><i class="bi bi-trash"></i></button>
                        <button type="button" title="<?= $m['lida'] ? 'Marcar como não lida' : 'Marcar como lida' ?>" onclick="acaoLinha(<?= (int) $m['uid'] ?>, '<?= $m['lida'] ? 'nao_lida' : 'lida' ?>')"><i class="bi <?= $m['lida'] ? 'bi-envelope' : 'bi-envelope-open' ?>"></i></button>
                    </span>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<script>
const PASTA_EMAIL = <?= json_encode($pasta) ?>;
function selecionados() { return [...document.querySelectorAll('.sel-msg:checked')].map((c) => c.value); }
function atualizarSelecao() {
    const n = selecionados().length;
    const acoes = document.getElementById('acoes-sel');
    acoes?.classList.toggle('sem-selecao', !n);
    acoes?.setAttribute('title', n ? n + ' selecionado(s)' : 'Marque e-mails para usar estas ações');
}
// Ações da própria linha (aparecem ao passar o mouse).
async function acaoLinha(uid, acao, destino) {
    if (acao === 'excluir' && <?= $ehLixeira ? 'true' : 'false' ?> && !(await confirmarNoModal({ titulo: 'Excluir para sempre', html: 'Este e-mail será apagado de vez.', botao: 'Excluir', classeBotao: 'btn-danger' }))) return;
    try {
        await postarEmail(acao, [String(uid)], destino);
        const linha = document.querySelector(`.email-linha[data-uid="${uid}"]`);
        if (acao === 'lida' || acao === 'nao_lida') { location.reload(); return; }
        linha?.remove();
    } catch (e) { mostrarErroEmail(e.message); }
}
async function postarEmail(acao, uids, destino) {
    const dados = new FormData();
    dados.append('acao', acao);
    dados.append('pasta', PASTA_EMAIL);
    uids.forEach((u) => dados.append('uids[]', u));
    if (destino) dados.append('destino', destino);
    const r = await (await fetch('/email/acao.php', { method: 'POST', body: dados })).json();
    if (!r.sucesso) throw new Error(r.mensagem || 'Não foi possível.');
    return r;
}
async function acaoEmail(acao, destino) {
    const uids = selecionados();
    if (!uids.length) return;
    try {
        await postarEmail(acao, uids, destino);
        location.reload();
    } catch (e) {
        mostrarErroEmail(e.message);
    }
}
async function estrela(botao, uid) {
    const ligar = !botao.classList.contains('ligada');
    try {
        await postarEmail(ligar ? 'importante' : 'nao_importante', [uid]);
        botao.classList.toggle('ligada', ligar);
        botao.innerHTML = `<i class="bi ${ligar ? 'bi-star-fill' : 'bi-star'}"></i>`;
    } catch (e) { mostrarErroEmail(e.message); }
}
function mostrarErroEmail(texto) {
    const div = document.createElement('div');
    div.className = 'alert alert-danger position-fixed bottom-0 end-0 m-3 shadow';
    div.style.zIndex = 2000;
    div.textContent = texto;
    document.body.appendChild(div);
    setTimeout(() => div.remove(), 6000);
}
</script>

<?php require __DIR__ . '/../../includes/modal_confirmar.php'; ?>
<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
