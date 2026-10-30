<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirPermissao('atender_whatsapp', 'Seu perfil não pode atender o WhatsApp.');

// Atendimento do WhatsApp: as conversas (à esquerda) e a conversa aberta,
// com o campo para responder e os botões "Assumir" (a IA para de responder
// nela) e "Devolver para a IA". A tela se atualiza sozinha a cada poucos
// segundos (?parcial=1 devolve só a lista e as mensagens).
$pdo = getDB();
$cfg = whatsappConfig($pdo);
$id = (int) ($_GET['id'] ?? 0);
$filtro = in_array($_GET['filtro'] ?? '', ['aguardando', 'humano', 'ia'], true) ? $_GET['filtro'] : '';
$busca = trim((string) ($_GET['busca'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['conversa_id'] ?? 0);
    $stmt = $pdo->prepare('SELECT * FROM whatsapp_conversas WHERE id = ?');
    $stmt->execute([$id]);
    $conversa = $stmt->fetch();
    $voltar = '/whatsapp/conversas.php?id=' . $id;
    if (!$conversa) {
        flash('danger', 'Conversa não encontrada.');
        redirect('/whatsapp/conversas.php');
    }
    $acao = (string) ($_POST['acao'] ?? '');
    if ($acao === 'responder') {
        $texto = trim((string) ($_POST['texto'] ?? ''));
        if ($texto !== '') {
            $envio = enviarTextoWhatsapp($cfg, $conversa['numero'], mb_substr($texto, 0, 4000));
            gravarMensagemWhatsapp($pdo, $id, 'atendente', $texto, 'texto', $envio['id'], $envio['ok'] ? null : $envio['erro']);
            // Quem responde assume a conversa: a IA para de falar nela.
            $pdo->prepare("UPDATE whatsapp_conversas SET modo = 'humano', aguardando = 0 WHERE id = ?")->execute([$id]);
            if (!$envio['ok']) {
                flash('danger', 'A mensagem não foi enviada: ' . $envio['erro']);
            }
        }
    } elseif ($acao === 'assumir') {
        $pdo->prepare("UPDATE whatsapp_conversas SET modo = 'humano', aguardando = 0 WHERE id = ?")->execute([$id]);
        gravarMensagemWhatsapp($pdo, $id, 'sistema', usuarioAtualNome() . ' assumiu a conversa (a IA parou de responder)');
    } elseif ($acao === 'devolver') {
        $pdo->prepare("UPDATE whatsapp_conversas SET modo = 'ia', aguardando = 0 WHERE id = ?")->execute([$id]);
        gravarMensagemWhatsapp($pdo, $id, 'sistema', usuarioAtualNome() . ' devolveu a conversa para a IA');
        // A última mensagem é do cliente (ficou sem resposta enquanto estava
        // com a equipe)? A IA responde agora, depois de a tela já ter voltado.
        $stmt = $pdo->prepare("SELECT id, autor, texto, criado_em >= NOW() - INTERVAL 24 HOUR AS recente FROM whatsapp_mensagens WHERE conversa_id = ? AND autor <> 'sistema' ORDER BY id DESC LIMIT 1");
        $stmt->execute([$id]);
        $ultima = $stmt->fetch();
        if ($ultima && $ultima['autor'] === 'cliente' && $ultima['texto'] !== null && $ultima['recente']) {
            header('Location: ' . $voltar);
            session_write_close();
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            set_time_limit(180);
            try {
                responderConversaWhatsapp($pdo, $id, (int) $ultima['id']);
            } catch (Throwable $e) {
                error_log('[WhatsApp] ' . $e->getMessage());
            }
            exit;
        }
    } elseif ($acao === 'resolvido') {
        $pdo->prepare('UPDATE whatsapp_conversas SET aguardando = 0 WHERE id = ?')->execute([$id]);
    }
    redirect($voltar);
}

// Lista de conversas.
$sql = "SELECT c.*, a.nome AS aluno_nome,
               (SELECT texto FROM whatsapp_mensagens m WHERE m.conversa_id = c.id AND m.autor <> 'sistema' ORDER BY m.id DESC LIMIT 1) AS ultima
        FROM whatsapp_conversas c LEFT JOIN alunos a ON a.id = c.aluno_id WHERE 1 = 1";
$params = [];
if ($filtro === 'aguardando') {
    $sql .= ' AND c.aguardando = 1';
} elseif ($filtro !== '') {
    $sql .= ' AND c.modo = ?';
    $params[] = $filtro;
}
if ($busca !== '') {
    $sql .= ' AND (c.nome LIKE ? OR a.nome LIKE ? OR c.numero LIKE ?)';
    array_push($params, '%' . $busca . '%', '%' . $busca . '%', '%' . preg_replace('/\D/', '', $busca) . '%');
}
$stmt = $pdo->prepare($sql . ' ORDER BY c.aguardando DESC, c.ultima_mensagem_em DESC LIMIT 200');
$stmt->execute($params);
$conversas = $stmt->fetchAll();

$conversa = null;
$mensagens = [];
if ($id) {
    $stmt = $pdo->prepare('SELECT c.*, a.nome AS aluno_nome FROM whatsapp_conversas c LEFT JOIN alunos a ON a.id = c.aluno_id WHERE c.id = ?');
    $stmt->execute([$id]);
    $conversa = $stmt->fetch() ?: null;
    if ($conversa) {
        $pdo->prepare('UPDATE whatsapp_conversas SET nao_lidas = 0 WHERE id = ?')->execute([$id]);
        $stmt = $pdo->prepare('SELECT * FROM (SELECT * FROM whatsapp_mensagens WHERE conversa_id = ? ORDER BY id DESC LIMIT 300) x ORDER BY id');
        $stmt->execute([$id]);
        $mensagens = $stmt->fetchAll();
    }
}
$nomeConversa = fn (array $c) => $c['aluno_nome'] ?: ($c['nome'] ?: numeroWhatsappFormatado($c['numero']));

ob_start();
?>
<div id="wa-lista">
    <?php if (!$conversas): ?><div class="text-muted small p-3">Nenhuma conversa.</div><?php endif; ?>
    <?php foreach ($conversas as $c): ?>
        <a href="?id=<?= (int) $c['id'] ?>&filtro=<?= e($filtro) ?>&busca=<?= e(urlencode($busca)) ?>" class="wa-item <?= (int) $c['id'] === $id ? 'ativo' : '' ?>">
            <div class="d-flex justify-content-between gap-2">
                <strong class="text-truncate"><?= e($nomeConversa($c)) ?></strong>
                <span class="small text-muted text-nowrap"><?= $c['ultima_mensagem_em'] ? (date('Y-m-d', strtotime($c['ultima_mensagem_em'])) === date('Y-m-d') ? date('H:i', strtotime($c['ultima_mensagem_em'])) : formatDate($c['ultima_mensagem_em'])) : '' ?></span>
            </div>
            <div class="d-flex justify-content-between gap-2 align-items-center">
                <span class="small text-muted text-truncate"><?= e(mb_strimwidth((string) $c['ultima'], 0, 60, '…')) ?></span>
                <span class="text-nowrap">
                    <?php if ($c['aguardando']): ?><span class="badge text-bg-warning">Atender</span><?php elseif ($c['modo'] === 'ia'): ?><span class="badge text-bg-light border" title="A IA está respondendo"><i class="bi bi-robot"></i></span><?php endif; ?>
                    <?php if ($c['nao_lidas'] && (int) $c['id'] !== $id): ?><span class="badge rounded-pill text-bg-success"><?= (int) $c['nao_lidas'] ?></span><?php endif; ?>
                </span>
            </div>
        </a>
    <?php endforeach; ?>
</div>
<?php
$htmlLista = ob_get_clean();

ob_start();
?>
<div id="wa-mensagens" data-ultimo="<?= $mensagens ? (int) end($mensagens)['id'] : 0 ?>">
    <?php foreach ($mensagens as $m): ?>
        <?php if ($m['autor'] === 'sistema'): ?>
            <div class="text-center small text-muted my-2"><i class="bi bi-info-circle"></i> <?= e((string) $m['texto']) ?> · <?= formatDateTime($m['criado_em']) ?></div>
        <?php else: $nosso = $m['autor'] !== 'cliente'; ?>
            <div class="d-flex <?= $nosso ? 'justify-content-end' : '' ?> mb-2">
                <div class="wa-balao <?= $nosso ? ($m['autor'] === 'ia' ? 'ia' : 'nosso') : '' ?>">
                    <div class="small text-muted mb-1">
                        <?= match ($m['autor']) { 'cliente' => e($conversa['nome'] ?: 'Cliente'), 'ia' => '<i class="bi bi-robot"></i> IA', default => '<i class="bi bi-person"></i> ' . e($m['autor_nome'] ?: 'Atendente (celular)') } ?>
                        · <?= formatDateTime($m['criado_em']) ?>
                    </div>
                    <div style="white-space: pre-line;"><?= e((string) $m['texto']) ?></div>
                    <?php if ($m['erro']): ?><div class="small text-danger mt-1"><i class="bi bi-exclamation-triangle"></i> Não enviada: <?= e($m['erro']) ?></div><?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    <?php endforeach; ?>
</div>
<?php
$htmlMensagens = ob_get_clean();

if (isset($_GET['parcial'])) {
    responderJson(['lista' => $htmlLista, 'mensagens' => $htmlMensagens]);
}

$pageTitle = 'WhatsApp';
$pageBreadcrumb = 'Secretaria';
$activeMenu = 'whatsapp';
$pageAction = usuarioTemPermissao('configurar_whatsapp') ? '<a href="/whatsapp/configuracao.php" class="btn btn-outline-secondary"><i class="bi bi-gear"></i> Configurar</a>' : '';
require __DIR__ . '/../../includes/layout_header.php';
?>
<style>
    .wa-tela { display: grid; grid-template-columns: 340px 1fr; gap: 16px; }
    @media (max-width: 900px) { .wa-tela { grid-template-columns: 1fr; } }
    .wa-coluna { background: var(--bs-body-bg, #fff); border: 1px solid var(--bs-border-color, #dee2e6); border-radius: 10px; }
    #wa-lista { max-height: 65vh; overflow-y: auto; }
    .wa-item { display: block; padding: 10px 12px; border-bottom: 1px solid var(--bs-border-color, #eee); color: inherit; text-decoration: none; }
    .wa-item:hover, .wa-item.ativo { background: rgba(0, 0, 0, .04); }
    #wa-mensagens { height: 55vh; overflow-y: auto; padding: 12px; background: rgba(0, 0, 0, .015); }
    .wa-balao { max-width: 78%; padding: 8px 12px; border-radius: 10px; background: #fff; border: 1px solid #e5e7eb; }
    .wa-balao.nosso { background: #e8f5e9; }
    .wa-balao.ia { background: #eef2ff; }
</style>

<?php if (!whatsappConfigurado($cfg)): ?>
    <div class="alert alert-warning">O WhatsApp ainda não está configurado. <?= usuarioTemPermissao('configurar_whatsapp') ? '<a href="/whatsapp/configuracao.php">Configurar agora</a>.' : 'Peça a um administrador para configurar.' ?></div>
<?php elseif (!$cfg['ia_ativa']): ?>
    <div class="alert alert-info small"><i class="bi bi-robot"></i> A IA está desligada: as mensagens chegam aqui e só a equipe responde.</div>
<?php endif; ?>

<div class="wa-tela">
    <div class="wa-coluna">
        <form method="get" class="p-2 border-bottom d-flex gap-2">
            <select name="filtro" class="form-select form-select-sm" style="width: 130px;" onchange="this.form.submit()">
                <option value="">Todas</option>
                <option value="aguardando" <?= $filtro === 'aguardando' ? 'selected' : '' ?>>Para atender</option>
                <option value="ia" <?= $filtro === 'ia' ? 'selected' : '' ?>>Com a IA</option>
                <option value="humano" <?= $filtro === 'humano' ? 'selected' : '' ?>>Com a equipe</option>
            </select>
            <input type="search" name="busca" class="form-control form-control-sm" placeholder="Nome ou número" value="<?= e($busca) ?>" onsearch="this.form.submit()">
        </form>
        <div id="wa-lista-caixa"><?= $htmlLista ?></div>
    </div>

    <div class="wa-coluna">
        <?php if (!$conversa): ?>
            <div class="text-muted text-center p-5"><i class="bi bi-whatsapp fs-1 d-block mb-2"></i>Escolha uma conversa.</div>
        <?php else: ?>
            <div class="d-flex flex-wrap align-items-center gap-2 p-3 border-bottom">
                <div class="flex-grow-1 lh-sm">
                    <div class="fw-semibold"><?= e($nomeConversa($conversa)) ?></div>
                    <div class="small text-muted"><?= e(numeroWhatsappFormatado($conversa['numero'])) ?>
                        <?php if ($conversa['aluno_id']): ?> · <a href="/alunos/form.php?id=<?= (int) $conversa['aluno_id'] ?>">ver aluno</a><?php else: ?> · não é aluno cadastrado<?php endif; ?></div>
                </div>
                <?php
                // Por que a IA não responde a esta pessoa (quando não responde).
                $semIa = match (true) {
                    !$cfg['ia_ativa'] => 'IA desligada na configuração',
                    ehNumeroAvisoWhatsapp($cfg, $conversa['numero']) => 'é um número de aviso da equipe',
                    !numeroLiberadoWhatsapp($cfg, $conversa['numero']) => 'modo teste: este número não está na lista',
                    default => null,
                };
                ?>
                <?php if ($conversa['modo'] === 'ia' && $semIa): ?>
                    <span class="badge text-bg-warning" title="A IA não responde a esta conversa"><i class="bi bi-robot"></i> IA não responde: <?= e($semIa) ?></span>
                    <form method="post"><input type="hidden" name="conversa_id" value="<?= (int) $conversa['id'] ?>"><input type="hidden" name="acao" value="assumir">
                        <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-person-raised-hand"></i> Assumir</button></form>
                <?php elseif ($conversa['modo'] === 'ia'): ?>
                    <span class="badge text-bg-light border"><i class="bi bi-robot"></i> A IA está respondendo</span>
                    <form method="post"><input type="hidden" name="conversa_id" value="<?= (int) $conversa['id'] ?>"><input type="hidden" name="acao" value="assumir">
                        <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-person-raised-hand"></i> Assumir</button></form>
                <?php else: ?>
                    <span class="badge text-bg-success"><i class="bi bi-person"></i> Com a equipe</span>
                    <?php if ($conversa['aguardando']): ?>
                        <form method="post"><input type="hidden" name="conversa_id" value="<?= (int) $conversa['id'] ?>"><input type="hidden" name="acao" value="resolvido">
                            <button class="btn btn-sm btn-outline-secondary" title="Tirar o aviso de &quot;para atender&quot;"><i class="bi bi-check2"></i> Já atendido</button></form>
                    <?php endif; ?>
                    <?php if ($cfg['ia_ativa']): ?>
                        <form method="post"><input type="hidden" name="conversa_id" value="<?= (int) $conversa['id'] ?>"><input type="hidden" name="acao" value="devolver">
                            <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-robot"></i> Devolver para a IA</button></form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <div id="wa-mensagens-caixa"><?= $htmlMensagens ?></div>
            <form method="post" class="p-3 border-top" onsubmit="this.querySelector('button').disabled = true;">
                <input type="hidden" name="conversa_id" value="<?= (int) $conversa['id'] ?>">
                <input type="hidden" name="acao" value="responder">
                <div class="d-flex gap-2">
                    <textarea name="texto" class="form-control" rows="2" placeholder="Escreva a resposta (Enter envia, Shift+Enter quebra a linha)" required id="wa-texto"></textarea>
                    <button class="btn btn-brand"><i class="bi bi-send"></i></button>
                </div>
                <?php if ($conversa['modo'] === 'ia'): ?><div class="form-text">Ao responder, você assume a conversa e a IA para de falar nela.</div><?php endif; ?>
            </form>
        <?php endif; ?>
    </div>
</div>

<script>
(() => {
    const rolarFim = () => { const m = document.getElementById('wa-mensagens'); if (m) m.scrollTop = m.scrollHeight; };
    rolarFim();
    const texto = document.getElementById('wa-texto');
    if (texto) {
        texto.focus();
        texto.addEventListener('keydown', (ev) => { if (ev.key === 'Enter' && !ev.shiftKey) { ev.preventDefault(); if (texto.value.trim()) texto.form.requestSubmit(); } });
    }
    // Atualiza a lista e a conversa a cada 6 s (sem recarregar a página).
    setInterval(async () => {
        try {
            const r = await (await fetch(location.pathname + location.search + (location.search ? '&' : '?') + 'parcial=1')).json();
            document.getElementById('wa-lista-caixa').innerHTML = r.lista;
            const caixa = document.getElementById('wa-mensagens-caixa');
            const atual = document.getElementById('wa-mensagens');
            if (caixa && atual) {
                const novo = new DOMParser().parseFromString(r.mensagens, 'text/html').getElementById('wa-mensagens');
                if (novo && novo.dataset.ultimo !== atual.dataset.ultimo) {
                    const noFim = atual.scrollHeight - atual.scrollTop - atual.clientHeight < 40;
                    caixa.innerHTML = r.mensagens;
                    if (noFim) rolarFim();
                }
            }
        } catch (e) { /* tenta de novo no próximo ciclo */ }
    }, 6000);
})();
</script>
<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
