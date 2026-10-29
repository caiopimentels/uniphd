<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirPermissao('usar_email', 'Seu perfil não usa o e-mail pelo sistema.');

// Lê um e-mail: cabeçalho, corpo (iframe isolado, corpo.php) e anexos;
// responder, responder a todos, encaminhar, excluir, não lida, mover.
// Abrir marca como lida.
$pdo = getDB();
$pasta = (string) ($_GET['pasta'] ?? 'INBOX');
$uid = (int) ($_GET['uid'] ?? 0);
$erro = null;
$mensagem = null;
$pastas = [];
$contagens = [];
try {
    $pastas = pastasCaixa($pdo);
    if (!in_array($pasta, array_column($pastas, 'nome'), true)) {
        throw new RuntimeException('Pasta não encontrada.');
    }
    $bruto = mensagemBrutaCaixa($pdo, $pasta, $uid);
    if ($bruto === null) {
        throw new RuntimeException('Este e-mail não existe mais (foi movido ou excluído).');
    }
    $mensagem = EmailMime::analisar($bruto);
    $imap = abrirCaixa($pdo);
    $imap->abrirPasta($pasta);
    $imap->marcar([$uid], '\\Seen');
    esquecerNaoLidasEmail();
    $contagens['INBOX'] = $imap->status('INBOX');
} catch (RuntimeException $e) {
    $erro = $e->getMessage();
}
$pastaAtual = null;
foreach ($pastas as $p) {
    if ($p['nome'] === $pasta) {
        $pastaAtual = $p;
    }
}
$voltar = '/email/index.php?pasta=' . rawurlencode($pasta);
$q = fn (array $extra = []) => http_build_query(['pasta' => $pasta, 'uid' => $uid] + $extra);
$cores = ['#1a73e8', '#d93025', '#188038', '#e37400', '#9334e6', '#007b83', '#c5221f', '#3c4043'];
$iniciais = fn ($p) => mb_strtoupper(mb_substr(trim($p['nome'] ?: $p['email']), 0, 1));
$listaEnderecos = fn (array $l) => implode(', ', array_map(fn ($p) => $p['nome'] ? $p['nome'] . ' <' . $p['email'] . '>' : $p['email'], $l));

$pageTitle = $mensagem['assunto'] ?? 'E-mail';
$pageBreadcrumb = 'E-mail · ' . ($pastaAtual['rotulo'] ?? '');
$activeMenu = 'email';
require __DIR__ . '/../../includes/layout_header.php';
require __DIR__ . '/../../includes/email_estilo.php';
?>
<div class="email-app">
    <?php require __DIR__ . '/../../includes/email_lateral.php'; ?>
    <div class="email-principal">
        <?php if ($erro): ?>
            <div class="alert alert-danger"><?= e($erro) ?> <a href="<?= e($voltar) ?>">Voltar</a></div>
        <?php else: ?>
        <div class="email-barra mb-2 border rounded-3 bg-body">
            <a class="btn btn-sm btn-link text-secondary" href="<?= e($voltar) ?>" title="Voltar"><i class="bi bi-arrow-left"></i></a>
            <button type="button" class="btn btn-sm btn-link text-secondary" onclick="acaoLer('excluir')" title="Excluir"><i class="bi bi-trash"></i></button>
            <button type="button" class="btn btn-sm btn-link text-secondary" onclick="acaoLer('nao_lida')" title="Marcar como não lida"><i class="bi bi-envelope"></i></button>
            <div class="dropdown d-inline-block">
                <button type="button" class="btn btn-sm btn-link text-secondary" data-bs-toggle="dropdown" title="Mover para"><i class="bi bi-folder-symlink"></i></button>
                <ul class="dropdown-menu">
                    <?php foreach ($pastas as $p): if ($p['nome'] === $pasta) continue; ?>
                        <li><a class="dropdown-item" href="#" onclick="acaoLer('mover', <?= e(json_encode($p['nome'])) ?>); return false;"><?= e($p['rotulo']) ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <button type="button" class="btn btn-sm btn-link text-secondary" onclick="imprimirEmail()" title="Imprimir"><i class="bi bi-printer"></i></button>
        </div>

        <div class="email-leitura">
            <h4><?= e($mensagem['assunto'] ?: '(sem assunto)') ?></h4>
            <div class="email-remetente">
                <span class="email-avatar" style="background: <?= $cores[crc32($mensagem['de']['email']) % count($cores)] ?>"><?= e($iniciais($mensagem['de'])) ?></span>
                <div class="flex-grow-1 small">
                    <div><strong class="fs-6"><?= e($mensagem['de']['nome'] ?: $mensagem['de']['email']) ?></strong> <span class="text-muted">&lt;<?= e($mensagem['de']['email']) ?>&gt;</span></div>
                    <?php if ($mensagem['para']): ?><div class="text-muted">para <?= e($listaEnderecos($mensagem['para'])) ?></div><?php endif; ?>
                    <?php if ($mensagem['cc']): ?><div class="text-muted">cc <?= e($listaEnderecos($mensagem['cc'])) ?></div><?php endif; ?>
                </div>
                <div class="text-end small text-muted text-nowrap"><?= $mensagem['data'] ? formatDateTime($mensagem['data']) : '' ?>
                    <div class="mt-1">
                        <a class="btn btn-sm btn-outline-secondary" href="/email/escrever.php?<?= e($q(['responder' => 1])) ?>" title="Responder"><i class="bi bi-reply"></i></a>
                    </div>
                </div>
            </div>

            <?php if (temImagensExternas($mensagem['html'])): ?>
                <div class="alert alert-light border small py-2 mt-3 mb-0" id="aviso-imagens"><i class="bi bi-image"></i> As imagens deste e-mail estão escondidas (protege contra rastreio de leitura).
                    <a href="#" onclick="mostrarImagens(); return false;">Mostrar imagens</a></div>
            <?php endif; ?>
            <iframe class="email-corpo-frame" id="corpo-email" sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox" src="/email/corpo.php?<?= e($q()) ?>" onload="ajustarAltura(this)"></iframe>

            <?php $anexos = array_filter($mensagem['anexos'], fn ($a) => !$a['inline']); if ($anexos): ?>
                <div class="email-anexos">
                    <?php foreach ($anexos as $a):
                        // Barreiras rápidas já na lista (o antivírus roda na hora de baixar).
                        $conteudoAnexo = EmailMime::parte($bruto, $a['id'])['conteudo'] ?? '';
                        $bloqueio = motivoArquivoPerigoso($a['nome'], $conteudoAnexo, false);
                        $tamanhoAnexo = $a['tamanho'] >= 1048576 ? number_format($a['tamanho'] / 1048576, 1, ',', '') . ' MB' : max(1, (int) round($a['tamanho'] / 1024)) . ' KB';
                    ?>
                        <?php if ($bloqueio): ?>
                            <span class="email-anexo border-danger text-danger" title="Bloqueado por segurança: <?= e($bloqueio) ?>">
                                <i class="bi bi-shield-x fs-3"></i>
                                <span class="d-flex flex-column" style="min-width: 0"><span class="nome"><?= e($a['nome']) ?></span><span class="small">Bloqueado: <?= e($bloqueio) ?></span></span>
                            </span>
                        <?php else: ?>
                            <a class="email-anexo" href="/email/anexo.php?<?= e($q(['parte' => $a['id']])) ?>" title="Baixar <?= e($a['nome']) ?> (só baixa; não abre no sistema)">
                                <i class="bi <?= iconeArquivo($a['tipo'], $a['nome']) ?> fs-3"></i>
                                <span class="d-flex flex-column" style="min-width: 0"><span class="nome"><?= e($a['nome']) ?></span><span class="small text-muted"><?= $tamanhoAnexo ?></span></span>
                                <i class="bi bi-download text-muted ms-1"></i>
                            </a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
                <div class="small text-muted mt-1"><i class="bi bi-shield-check"></i> Anexos só baixam (o sistema não abre nenhum arquivo)<?= programaAntivirus() ? ' e passam pelo antivírus antes' : '' ?>. Programas, scripts e documentos com macro ficam bloqueados.</div>
            <?php endif; ?>

            <div class="d-flex flex-wrap gap-2 mt-4 nao-imprimir">
                <a class="btn btn-outline-secondary rounded-pill" href="/email/escrever.php?<?= e($q(['responder' => 1])) ?>"><i class="bi bi-reply"></i> Responder</a>
                <?php if (count($mensagem['para']) + count($mensagem['cc']) > 1): ?>
                    <a class="btn btn-outline-secondary rounded-pill" href="/email/escrever.php?<?= e($q(['responder' => 1, 'todos' => 1])) ?>"><i class="bi bi-reply-all"></i> Responder a todos</a>
                <?php endif; ?>
                <a class="btn btn-outline-secondary rounded-pill" href="/email/escrever.php?<?= e($q(['encaminhar' => 1])) ?>"><i class="bi bi-forward"></i> Encaminhar</a>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<style>
    @media print {
        .email-lateral, .email-barra, #aviso-imagens, .email-remetente .btn, .pagina-cabecalho, .page-header, .pagina-titulo, .breadcrumb { display: none !important; }
        .email-app { display: block !important; }
        .email-principal, .email-leitura { border: 0 !important; box-shadow: none !important; padding: 0 !important; margin: 0 !important; }
        .email-corpo-frame { width: 100% !important; border: 0 !important; }
    }
</style>
<script>
// O corpo vem num iframe isolado; a altura acompanha o conteúdo.
function ajustarAltura(frame) {
    try {
        const doc = frame.contentDocument;
        const medir = () => { frame.style.height = (doc.documentElement.scrollHeight + 8) + 'px'; };
        medir();
        doc.querySelectorAll('img').forEach((img) => img.addEventListener('load', medir));
        setTimeout(medir, 600);
    } catch (e) {}
}
function mostrarImagens() {
    document.getElementById('corpo-email').src = '/email/corpo.php?<?= $q(['imagens' => 1]) ?>';
    document.getElementById('aviso-imagens')?.remove();
}
// O corpo fica num iframe isolado (sem permissão de imprimir sozinho):
// imprime a página, que no papel mostra só o e-mail (ver @media print).
function imprimirEmail() {
    ajustarAltura(document.getElementById('corpo-email'));
    window.print();
}
async function acaoLer(acao, destino) {
    const dados = new FormData();
    dados.append('acao', acao);
    dados.append('pasta', <?= json_encode($pasta) ?>);
    dados.append('uids[]', <?= (int) $uid ?>);
    if (destino) dados.append('destino', destino);
    const r = await (await fetch('/email/acao.php', { method: 'POST', body: dados })).json();
    if (r.sucesso) location.href = <?= json_encode($voltar) ?>;
}
</script>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
