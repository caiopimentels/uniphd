<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();
exigirPermissao('gerenciar_avisos', 'Seu perfil não pode enviar avisos aos alunos.');

// Um aviso: como o aluno vê, para quem foi e quem já viu / confirmou.
$pdo = getDB();
$stmt = $pdo->prepare('SELECT * FROM avisos WHERE id = ?');
$stmt->execute([(int) ($_GET['id'] ?? 0)]);
$aviso = $stmt->fetch();
if (!$aviso) {
    flash('danger', 'Aviso não encontrado.');
    redirect('/avisos/listar.php');
}
$alunos = destinatariosAviso($pdo, $aviso);
$viram = count(array_filter($alunos, fn ($a) => $a['visto_em']));
$confirmaram = count(array_filter($alunos, fn ($a) => $a['confirmado_em']));
$destinos = nomesDestinosAviso($pdo, $aviso);
[$situacaoTexto, $situacaoCor] = situacaoAviso($aviso);
[$formatoNome, , $formatoIcone] = formatosAviso()[$aviso['formato']];
[, $corForte] = coresAviso()[$aviso['cor']];

$pageTitle = $aviso['titulo'];
$pageBreadcrumb = 'Portal · Avisos';
$activeMenu = 'avisos';
$pageAction = '<a href="/avisos/listar.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Voltar</a>'
    . '<button type="button" class="btn btn-outline-danger" onclick="excluirAviso()"><i class="bi bi-trash"></i> Excluir</button>'
    . '<form action="/avisos/acao.php" method="post" class="d-inline"><input type="hidden" name="id" value="' . (int) $aviso['id'] . '"><input type="hidden" name="acao" value="' . ($aviso['ativo'] ? 'desligar' : 'ligar') . '">'
    . '<button type="submit" class="btn ' . ($aviso['ativo'] ? 'btn-outline-secondary' : 'btn-outline-success') . '"><i class="bi ' . ($aviso['ativo'] ? 'bi-toggle-off' : 'bi-toggle-on') . '"></i> ' . ($aviso['ativo'] ? 'Desligar' : 'Ligar') . '</button></form>'
    . '<a href="/avisos/form.php?id=' . (int) $aviso['id'] . '" class="btn btn-brand"><i class="bi bi-pencil"></i> Editar</a>';
require __DIR__ . '/../../includes/layout_header.php';
?>
<link href="<?= e(urlFontesArteAviso()) ?>" rel="stylesheet">
<link href="<?= e(assetUrl('/assets/css/aviso-arte.css')) ?>" rel="stylesheet">
<style>
    .aviso-conteudo img { max-width: 100%; height: auto; }
    .aviso-conteudo .ql-align-center { text-align: center; } .aviso-conteudo .ql-align-right { text-align: right; } .aviso-conteudo .ql-align-justify { text-align: justify; }
    .aviso-conteudo p:last-child { margin-bottom: 0; }
    .aviso-conteudo a { color: #2563eb; text-decoration: underline; }
    .aviso-conteudo .ql-size-small { font-size: .8em; } .aviso-conteudo .ql-size-large { font-size: 1.4em; } .aviso-conteudo .ql-size-huge { font-size: 2em; }
</style>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm mb-3" style="border-left: 5px solid <?= $corForte ?> !important;">
            <div class="card-body">
                <h5 style="color: <?= $corForte ?>"><?= e($aviso['titulo']) ?></h5>
                <?php if ($aviso['montagem'] === 'arte'): ?>
                    <?= avisoTemConteudo($aviso) ? '<div style="max-width: ' . larguraJanelaAviso($aviso) . 'px">' . htmlArteAviso(arteAviso($aviso)) . '</div>' : '<span class="text-muted">Sem arte (só a faixa com o título).</span>' ?>
                <?php else: ?>
                    <div class="aviso-conteudo"><?= htmlAviso($aviso['conteudo']) ?: '<span class="text-muted">Sem texto (só a faixa com o título).</span>' ?></div>
                <?php endif; ?>
            </div>
        </div>
        <div class="secao">
            <div class="secao-titulo"><i class="bi bi-info-circle"></i> Detalhes</div>
            <dl class="row mb-0 small">
                <dt class="col-sm-4">Situação</dt><dd class="col-sm-8"><span class="badge text-bg-<?= $situacaoCor ?> <?= $situacaoCor === 'light' ? 'border' : '' ?>"><?= $situacaoTexto ?></span></dd>
                <dt class="col-sm-4">Formato</dt><dd class="col-sm-8"><i class="bi <?= $formatoIcone ?>"></i> <?= e($formatoNome) ?> · <?= $aviso['montagem'] === 'arte' ? 'arte' : 'texto' ?><?= $aviso['exige_confirmacao'] ? ' · pede "Li e entendi"' : '' ?></dd>
                <dt class="col-sm-4">Aparece</dt><dd class="col-sm-8"><?= e(repeticoesAviso()[$aviso['repeticao']][0]) ?> <span class="text-muted">— <?= e(repeticoesAviso()[$aviso['repeticao']][1]) ?></span></dd>
                <dt class="col-sm-4">Para</dt><dd class="col-sm-8"><?= e(publicosAviso()[$aviso['publico']]) ?><?= $aviso['publico'] === 'site' ? ' — ' . e(mb_strtolower(quemSiteAviso()[$aviso['quem_site']])) : '' ?><?php if ($destinos): ?>:
                    <?= e(implode(', ', array_column($destinos, 'nome'))) ?><?php endif; ?></dd>
                <dt class="col-sm-4">Páginas</dt><dd class="col-sm-8"><?php $pgs = array_intersect_key(paginasPortalAviso($pdo), array_flip(paginasDoAviso($aviso))); ?><?= $pgs ? e(implode(', ', $pgs)) : 'Todas' ?></dd>
                <dt class="col-sm-4">Tamanho</dt><dd class="col-sm-8"><?= $aviso['largura'] ? (int) $aviso['largura'] . ' px' : 'Automático' ?></dd>
                <dt class="col-sm-4">Período</dt><dd class="col-sm-8"><?= formatDateTime($aviso['inicio']) ?> até <?= $aviso['fim'] ? formatDateTime($aviso['fim']) : 'desligar' ?></dd>
                <dt class="col-sm-4">Criado</dt><dd class="col-sm-8"><?= formatDateTime($aviso['criado_em']) ?><?= $aviso['criado_por'] ? ' por ' . e($aviso['criado_por']) : '' ?></dd>
            </dl>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="secao">
            <div class="secao-titulo d-flex align-items-center"><i class="bi bi-eye me-1"></i> Quem viu
                <span class="ms-auto small fw-normal text-muted"><?= $viram ?> de <?= count($alunos) ?> viram<?= $aviso['exige_confirmacao'] ? ' · ' . $confirmaram . ' confirmaram' : '' ?></span></div>
            <?php if ($aviso['publico'] === 'site'): ?><div class="small text-muted mb-2"><?= $aviso['quem_site'] === 'visitantes' ? 'Aviso só para visitantes sem login: não há lista de alunos.' : 'Contam os alunos com cadastro; visitantes sem login não entram nesta conta.' ?></div><?php endif; ?>
            <div class="d-flex gap-2 mb-2">
                <select id="filtro-leitura" class="form-select form-select-sm" style="max-width: 190px" onchange="filtrarLeituras()">
                    <option value="">Todos (<?= count($alunos) ?>)</option>
                    <option value="viu">Viram (<?= $viram ?>)</option>
                    <option value="nao">Não viram (<?= count($alunos) - $viram ?>)</option>
                    <?php if ($aviso['exige_confirmacao']): ?>
                        <option value="confirmou">Confirmaram (<?= $confirmaram ?>)</option>
                        <option value="falta">Falta confirmar (<?= count($alunos) - $confirmaram ?>)</option>
                    <?php endif; ?>
                </select>
                <input type="search" id="busca-leitura" class="form-control form-control-sm" placeholder="Procurar aluno" oninput="filtrarLeituras()">
            </div>
            <div style="max-height: 520px; overflow-y: auto;">
                <table class="table table-sm mb-0 align-middle">
                    <tbody>
                    <?php if (!$alunos): ?>
                        <tr><td class="text-muted text-center py-3">Nenhum aluno recebe este aviso hoje.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($alunos as $a): ?>
                        <tr class="linha-leitura" data-nome="<?= e(mb_strtolower($a['nome'])) ?>" data-viu="<?= $a['visto_em'] ? 1 : 0 ?>" data-confirmou="<?= $a['confirmado_em'] ? 1 : 0 ?>">
                            <td><a href="/alunos/form.php?id=<?= (int) $a['id'] ?>" class="text-decoration-none"><?= e($a['nome']) ?></a></td>
                            <td class="small text-end text-nowrap">
                                <?php if ($a['confirmado_em']): ?><span class="text-success"><i class="bi bi-check2-all"></i> confirmou <?= formatDateTime($a['confirmado_em']) ?></span>
                                <?php elseif ($a['visto_em']): ?><span class="text-primary"><i class="bi bi-check2"></i> viu <?= formatDateTime($a['visto_em']) ?></span>
                                <?php else: ?><span class="text-muted">ainda não viu</span><?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<form id="form-excluir" action="/avisos/acao.php" method="post" class="d-none"><input type="hidden" name="id" value="<?= (int) $aviso['id'] ?>"><input type="hidden" name="acao" value="excluir"></form>
<?php require __DIR__ . '/../../includes/modal_confirmar.php'; ?>
<script>
function filtrarLeituras() {
    const filtro = document.getElementById('filtro-leitura').value;
    const termo = document.getElementById('busca-leitura').value.trim().toLowerCase();
    document.querySelectorAll('.linha-leitura').forEach((tr) => {
        const d = tr.dataset;
        const passa = (!filtro || (filtro === 'viu' && d.viu === '1') || (filtro === 'nao' && d.viu === '0')
            || (filtro === 'confirmou' && d.confirmou === '1') || (filtro === 'falta' && d.confirmou === '0'))
            && (!termo || d.nome.includes(termo));
        tr.style.display = passa ? '' : 'none';
    });
}
async function excluirAviso() {
    const ok = await confirmarNoModal({ titulo: 'Excluir aviso', html: 'O aviso sai do portal e o registro de quem viu é apagado. <strong>Não dá para desfazer.</strong><br><span class="small text-muted">Para só tirar do ar e manter o registro, use "Desligar".</span>', botao: 'Excluir', classeBotao: 'btn-danger' });
    if (ok) document.getElementById('form-excluir').submit();
}
</script>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
