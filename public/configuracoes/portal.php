<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirPortal();

// Construtor do site do portal: escolhe a página à esquerda e monta os
// blocos dela (ordem, inserir, esconder, duplicar, excluir).
$pdo = getDB();
$paginas = paginasPortal($pdo);
$pagina = buscarPaginaPortal($pdo, 'id', (int) ($_GET['pagina'] ?? $_POST['pagina'] ?? 0)) ?? buscarPaginaPortal($pdo, 'chave', 'inicio');
$aqui = '/configuracoes/portal.php?pagina=' . (int) $pagina['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = input('acao');
    $blocoId = (int) ($_POST['bloco_id'] ?? 0);
    $stmt = $pdo->prepare('SELECT * FROM portal_blocos WHERE id = ? AND pagina_id = ?');
    $stmt->execute([$blocoId, $pagina['id']]);
    $bloco = $stmt->fetch();

    // Ocultar/mostrar a página (o Início não: é a capa do portal).
    if ($acao === 'alternar_pagina' && $pagina['tipo'] !== 'inicio') {
        $pdo->prepare('UPDATE portal_paginas SET ativo = 1 - ativo WHERE id = ?')->execute([$pagina['id']]);
        registrarAtividade('portal', 'edicao', ($pagina['ativo'] ? 'Página ocultada do portal: ' : 'Página mostrada no portal: ') . $pagina['titulo']);
        flash('success', $pagina['ativo'] ? '"' . $pagina['titulo'] . '" está oculta: saiu do menu e não abre mais no portal.' : '"' . $pagina['titulo'] . '" voltou a aparecer no portal.');
        redirect($aqui);
    }
    if ($acao === 'ordenar') {
        // Arrastar e soltar (fetch): a ordem nova de cada região.
        $atualiza = $pdo->prepare('UPDATE portal_blocos SET regiao = ?, ordem = ? WHERE id = ? AND pagina_id = ?');
        foreach (['topo', 'fim'] as $regiao) {
            foreach (array_map('intval', (array) ($_POST[$regiao] ?? [])) as $posicao => $id) {
                $atualiza->execute([$regiao, ($posicao + 1) * 10, $id, $pagina['id']]);
            }
        }
        registrarAtividade('portal', 'edicao', 'Ordem dos blocos alterada: ' . $pagina['titulo']);
        responderJson(['sucesso' => true]);
    }
    if ($bloco && $acao === 'mover') {
        $stmt = $pdo->prepare('SELECT id FROM portal_blocos WHERE pagina_id = ? AND regiao = ? ORDER BY ordem, id');
        $stmt->execute([$pagina['id'], $bloco['regiao']]);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $posicao = array_search((int) $bloco['id'], $ids, true);
        $destino = $posicao + (input('direcao') === 'cima' ? -1 : 1);
        if (isset($ids[$destino])) {
            [$ids[$posicao], $ids[$destino]] = [$ids[$destino], $ids[$posicao]];
            $atualiza = $pdo->prepare('UPDATE portal_blocos SET ordem = ? WHERE id = ?');
            foreach ($ids as $i => $id) {
                $atualiza->execute([($i + 1) * 10, $id]);
            }
        }
    } elseif ($bloco && $acao === 'alternar') {
        $pdo->prepare('UPDATE portal_blocos SET ativo = 1 - ativo WHERE id = ?')->execute([$bloco['id']]);
        registrarAtividade('portal', 'edicao', 'Bloco ' . ($bloco['ativo'] ? 'escondido' : 'mostrado') . ' no portal: ' . ($bloco['titulo'] ?: $bloco['tipo']));
    } elseif ($bloco && $acao === 'duplicar') {
        $pdo->beginTransaction();
        $ordem = ordemParaInserirBloco($pdo, (int) $pagina['id'], $bloco['regiao'], null);
        $copia = $bloco;
        unset($copia['id'], $copia['criado_em']);
        $copia['ordem'] = $ordem;
        $copia['titulo'] = $bloco['titulo'] ? $bloco['titulo'] . ' (cópia)' : null;
        $pdo->prepare('INSERT INTO portal_blocos (' . implode(', ', array_keys($copia)) . ') VALUES (' . implode(',', array_fill(0, count($copia), '?')) . ')')
            ->execute(array_values($copia));
        $novoId = (int) $pdo->lastInsertId();
        // As imagens são copiadas de verdade: excluir um dos blocos não pode
        // apagar a imagem do outro.
        $stmt = $pdo->prepare('SELECT * FROM portal_banner_imagens WHERE bloco_id = ?');
        $stmt->execute([$bloco['id']]);
        $insere = $pdo->prepare('INSERT INTO portal_banner_imagens (bloco_id, imagem, link, nova_aba, texto_alternativo, ordem) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($stmt->fetchAll() as $img) {
            $insere->execute([$novoId, copiarImagemPortal($img['imagem']), $img['link'], $img['nova_aba'], $img['texto_alternativo'], $img['ordem']]);
        }
        $config = configBloco($bloco);
        if (!empty($config['imagem'])) {
            $config['imagem'] = copiarImagemPortal($config['imagem']);
            $pdo->prepare('UPDATE portal_blocos SET config = ? WHERE id = ?')->execute([json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $novoId]);
        }
        $pdo->commit();
        registrarAtividade('portal', 'criacao', 'Bloco duplicado no portal: ' . ($bloco['titulo'] ?: $bloco['tipo']));
        flash('success', 'Bloco duplicado — a cópia está no fim da página.');
    } elseif ($bloco && $acao === 'excluir') {
        $stmt = $pdo->prepare('SELECT imagem FROM portal_banner_imagens WHERE bloco_id = ?');
        $stmt->execute([$bloco['id']]);
        $imagens = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $imagens[] = configBloco($bloco)['imagem'] ?? null;
        $pdo->prepare('DELETE FROM portal_blocos WHERE id = ?')->execute([$bloco['id']]);
        array_map('apagarImagemPortal', $imagens);
        registrarAtividade('portal', 'exclusao', 'Bloco excluído do portal: ' . ($bloco['titulo'] ?: $bloco['tipo']));
        flash('success', 'Bloco excluído.');
    }
    redirect($aqui);
}

$tipos = tiposBlocoPortal();
$blocos = blocosPagina($pdo, (int) $pagina['id'], null, false);
$regioes = $pagina['tipo'] === 'sistema'
    ? ['topo' => 'Antes do conteúdo da tela', 'fim' => 'Depois do conteúdo da tela']
    : ['topo' => 'Blocos da página'];
$hoje = date('Y-m-d');

// Prévia: as telas só de aluno exigem login de aluno de verdade.
$urlPrevia = urlPaginaPortal($pagina);
if ($pagina['chave'] === 'turma') {
    $primeira = turmasAbertasPortal($pdo)[0] ?? null;
    $urlPrevia = $primeira ? '/portal/turma.php?id=' . (int) $primeira['id'] : null;
}
$semPrevia = in_array($pagina['chave'], ['meus_cursos', 'financeiro', 'calendario', 'certificados'], true);
$conteudoTela = [
    'cursos' => 'a lista de turmas com inscrição aberta',
    'turma' => 'os detalhes do curso e o botão de inscrição',
    'meus_cursos' => 'as matrículas do aluno',
    'financeiro' => 'as parcelas do aluno',
    'calendario' => 'as aulas do aluno',
    'certificados' => 'os certificados do aluno',
][$pagina['chave']] ?? '';

$pageTitle = 'Portal do aluno';
$pageBreadcrumb = 'Secretaria · Montar o site';
$activeMenu = 'portal';
$pageAction = '<a href="/portal/index.php" target="_blank" class="btn btn-outline-secondary"><i class="bi bi-box-arrow-up-right"></i> Abrir o portal</a>';
require __DIR__ . '/../../includes/layout_header.php';

$cartaoBloco = function (array $bloco, int $posicao) use ($tipos, $hoje) {
    $foraDoPeriodo = ($bloco['exibir_de'] && $bloco['exibir_de'] > $hoje) || ($bloco['exibir_ate'] && $bloco['exibir_ate'] < $hoje);
    $capa = $bloco['imagens'][0]['imagem'] ?? ($bloco['config']['imagem'] ?? null);
    $tipo = $tipos[$bloco['tipo']] ?? [$bloco['tipo'], 'bi-square', '', false];
    $form = fn (string $acao, string $conteudo, string $extra = '', string $confirmar = '') =>
        '<form method="post" class="d-inline"' . ($confirmar ? ' onsubmit="return confirm(' . e(json_encode($confirmar)) . ')"' : '') . '>'
        . '<input type="hidden" name="acao" value="' . $acao . '"><input type="hidden" name="bloco_id" value="' . (int) $bloco['id'] . '">' . $extra . $conteudo . '</form>';
    ob_start(); ?>
    <div class="editor-bloco <?= e($bloco['largura']) ?> <?= !$bloco['ativo'] || $foraDoPeriodo ? 'desligado' : '' ?>" data-id="<?= (int) $bloco['id'] ?>">
        <?php // Mesma proporção do banner no site (portal.css), para a miniatura não cortar.
        $proporcaoBanner = $bloco['tipo'] === 'banner' ? (($bloco['largura'] === 'inteira'
            ? ['baixa' => '6 / 1', 'media' => '3 / 1', 'alta' => '2 / 1']
            : ['baixa' => '3 / 1', 'media' => '16 / 9', 'alta' => '4 / 5'])[$bloco['altura'] ?? ''] ?? null) : null; ?>
        <div class="miniatura<?= $capa ? '' : ' sem-imagem' ?><?= $capa && $proporcaoBanner ? ' inteira' : '' ?>" <?= $capa ? 'style="background-image:url(\'' . e($capa) . '\');' . ($proporcaoBanner ? ' --proporcao: ' . $proporcaoBanner : '') . '"' : '' ?>>
            <?php if (!$capa): ?><i class="bi <?= e($tipo[1]) ?>"></i><?php endif; ?>
            <?php if (count($bloco['imagens']) > 1): ?><span class="qtd"><i class="bi bi-images"></i> <?= count($bloco['imagens']) ?></span><?php endif; ?>
            <span class="arrastar" title="Arraste para mudar de lugar"><i class="bi bi-grip-vertical"></i></span>
        </div>
        <div class="corpo">
            <div class="fw-semibold text-truncate"><?= e($bloco['titulo'] ?: $tipo[0]) ?></div>
            <div class="d-flex flex-wrap gap-1">
                <span class="badge text-bg-light border"><?= e($tipo[0]) ?></span>
                <?php if ($bloco['publico'] !== 'todos'): ?><span class="badge text-bg-info"><?= e(publicosBlocoPortal()[$bloco['publico']]) ?></span><?php endif; ?>
                <?php if (!$bloco['ativo']): ?><span class="badge text-bg-secondary">Escondido</span><?php endif; ?>
                <?php if ($bloco['exibir_de'] || $bloco['exibir_ate']): ?>
                    <span class="badge <?= $foraDoPeriodo ? 'text-bg-warning' : 'text-bg-light border' ?>"><i class="bi bi-calendar-range"></i> <?= $bloco['exibir_de'] ? formatDate($bloco['exibir_de']) : '…' ?> a <?= $bloco['exibir_ate'] ? formatDate($bloco['exibir_ate']) : '…' ?></span>
                <?php endif; ?>
                <?php if ($bloco['tipo'] === 'banner' && !$bloco['imagens']): ?><span class="badge text-bg-danger">Sem imagem — não aparece</span><?php endif; ?>
            </div>
            <div class="acoes">
                <a href="/configuracoes/portal_bloco.php?id=<?= (int) $bloco['id'] ?>" class="btn btn-sm btn-brand"><i class="bi bi-pencil"></i> Editar</a>
                <?= $form('mover', '<button class="btn btn-sm btn-outline-secondary" title="Subir"><i class="bi bi-arrow-up"></i></button>', '<input type="hidden" name="direcao" value="cima">') ?>
                <?= $form('mover', '<button class="btn btn-sm btn-outline-secondary" title="Descer"><i class="bi bi-arrow-down"></i></button>', '<input type="hidden" name="direcao" value="baixo">') ?>
                <?= $form('excluir', '<button class="btn btn-sm btn-outline-danger" title="Excluir este bloco"><i class="bi bi-trash"></i></button>', '', 'Excluir este bloco? As imagens dele também serão apagadas.') ?>
                <div class="dropdown d-inline">
                    <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="dropdown" data-bs-popper-config='{"strategy":"fixed"}' title="Mais"><i class="bi bi-three-dots"></i></button>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="#" onclick="abrirNovoBloco('<?= e($bloco['regiao']) ?>', <?= $posicao ?>); return false;"><i class="bi bi-arrow-bar-up"></i> Inserir bloco antes deste</a></li>
                        <li><a class="dropdown-item" href="#" onclick="abrirNovoBloco('<?= e($bloco['regiao']) ?>', <?= $posicao + 1 ?>); return false;"><i class="bi bi-arrow-bar-down"></i> Inserir bloco depois deste</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><?= $form('alternar', '<button class="dropdown-item"><i class="bi bi-' . ($bloco['ativo'] ? 'eye-slash' : 'eye') . '"></i> ' . ($bloco['ativo'] ? 'Esconder do site' : 'Mostrar no site') . '</button>') ?></li>
                        <li><?= $form('duplicar', '<button class="dropdown-item"><i class="bi bi-copy"></i> Duplicar</button>') ?></li>
                        <li><?= $form('excluir', '<button class="dropdown-item text-danger"><i class="bi bi-trash"></i> Excluir</button>', '', 'Excluir este bloco? As imagens dele também serão apagadas.') ?></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
    <?php return ob_get_clean();
};
?>
<style>
    .lista-paginas .list-group-item { display: flex; align-items: center; gap: 8px; }
    .lista-paginas .list-group-item.active { background: var(--accent); border-color: var(--accent); }
    .regiao { border: 2px dashed var(--border-strong, #d5d8dc); border-radius: 12px; padding: 12px; margin-bottom: 12px; }
    .regiao-titulo { font-size: .8rem; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; color: var(--text-subtle, #9ca3af); margin-bottom: 8px; }
    .conteudo-tela { background: repeating-linear-gradient(45deg, #f3f4f6, #f3f4f6 10px, #eceef1 10px, #eceef1 20px); border-radius: 12px; padding: 22px; text-align: center; color: #6b7280; margin-bottom: 12px; }
    .editor-grade { display: grid; grid-template-columns: repeat(6, 1fr); gap: 12px; min-height: 40px; }
    .editor-bloco { background: var(--surface, #fff); border: 1px solid var(--border, #e6e8eb); border-radius: 10px; overflow: hidden; display: flex; flex-direction: column; }
    .editor-bloco.inteira { grid-column: span 6; } .editor-bloco.metade { grid-column: span 3; } .editor-bloco.terco { grid-column: span 2; }
    .editor-bloco.desligado { opacity: .5; }
    .editor-bloco .miniatura { height: 90px; background: #eef1f4 center / cover no-repeat; display: flex; align-items: center; justify-content: center; color: #8a94a0; font-size: 1.8rem; position: relative; }
    .editor-bloco .miniatura.sem-imagem { height: 34px; font-size: 1.1rem; }
    /* Banner: a miniatura mostra a imagem inteira, na proporção dela. */
    .editor-bloco .miniatura.inteira { height: auto; aspect-ratio: var(--proporcao, 6 / 1); background-size: contain; }
    .editor-bloco .miniatura .qtd { position: absolute; right: 8px; bottom: 8px; background: rgba(0,0,0,.6); color: #fff; font-size: 11px; padding: 2px 7px; border-radius: 999px; }
    .editor-bloco .arrastar { position: absolute; left: 6px; top: 6px; background: rgba(255,255,255,.9); border-radius: 6px; padding: 0 4px; font-size: 1rem; color: #555; cursor: grab; }
    .editor-bloco .corpo { padding: 10px 12px; display: flex; flex-direction: column; gap: 6px; flex: 1; }
    .editor-bloco .acoes { display: flex; gap: 4px; flex-wrap: wrap; margin-top: auto; }
    .editor-bloco.sortable-ghost { opacity: .3; }
    .btn-adicionar { border: 2px dashed var(--border-strong, #d5d8dc); background: transparent; border-radius: 10px; width: 100%; padding: 10px; color: var(--accent-dark); font-weight: 600; margin-top: 10px; }
    .btn-adicionar:hover { border-color: var(--accent); background: var(--accent-soft); }
    .tipo-bloco { border: 1px solid var(--border, #e6e8eb); border-radius: 10px; padding: 12px; display: flex; gap: 12px; align-items: flex-start; text-decoration: none; color: inherit; height: 100%; }
    .tipo-bloco:hover { border-color: var(--accent); background: var(--accent-soft); }
    .tipo-bloco i { font-size: 1.5rem; color: var(--accent); }
    .previa-portal { width: 100%; height: 760px; border: 1px solid var(--border, #e6e8eb); border-radius: 10px; background: #fff; }
    @media (max-width: 768px) { .editor-bloco.metade, .editor-bloco.terco { grid-column: span 6; } }
</style>

<div class="row g-4">
    <div class="col-lg-3">
        <div class="fw-semibold small text-uppercase text-muted mb-2">Páginas do site</div>
        <div class="list-group lista-paginas mb-2">
            <?php foreach ($paginas as $p): if ($p['tipo'] === 'sistema') continue; ?>
                <a href="/configuracoes/portal.php?pagina=<?= (int) $p['id'] ?>" class="list-group-item list-group-item-action <?= (int) $p['id'] === (int) $pagina['id'] ? 'active' : '' ?>">
                    <i class="bi <?= $p['tipo'] === 'inicio' ? 'bi-house' : 'bi-file-earmark-text' ?>"></i>
                    <span class="flex-grow-1 text-truncate"><?= e($p['titulo']) ?></span>
                    <?php if (!$p['ativo']): ?><span class="badge text-bg-secondary">oculta</span><?php endif; ?>
                    <?php if ($p['publico'] === 'alunos'): ?><i class="bi bi-lock" title="Só alunos logados"></i><?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
        <a href="/configuracoes/portal_pagina.php" class="btn btn-sm btn-outline-secondary w-100 mb-4"><i class="bi bi-plus-lg"></i> Nova página</a>

        <div class="fw-semibold small text-uppercase text-muted mb-2">Telas do portal</div>
        <div class="list-group lista-paginas mb-4">
            <?php foreach ($paginas as $p): if ($p['tipo'] !== 'sistema') continue; ?>
                <a href="/configuracoes/portal.php?pagina=<?= (int) $p['id'] ?>" class="list-group-item list-group-item-action <?= (int) $p['id'] === (int) $pagina['id'] ? 'active' : '' ?>">
                    <i class="bi bi-window"></i><span class="flex-grow-1 text-truncate"><?= e($p['titulo']) ?></span>
                    <?php if (!$p['ativo']): ?><span class="badge text-bg-secondary">oculta</span><?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>

        <a href="/configuracoes/portal_menu.php" class="btn btn-outline-secondary w-100"><i class="bi bi-menu-button-wide"></i> Menu, rodapé e ajustes</a>
    </div>

    <div class="col-lg-9">
        <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
            <h2 class="h5 mb-0"><?= e($pagina['titulo']) ?></h2>
            <?php if ($pagina['tipo'] === 'personalizada'): ?>
                <span class="small text-muted"><?= e(urlPaginaPortal($pagina)) ?></span>
                <a href="/configuracoes/portal_pagina.php?id=<?= (int) $pagina['id'] ?>" class="btn btn-sm btn-outline-secondary ms-auto"><i class="bi bi-gear"></i> Nome, endereço e menu</a>
            <?php endif; ?>
            <?php if ($urlPrevia): ?><a href="<?= e($urlPrevia) ?>" target="_blank" class="btn btn-sm btn-outline-secondary <?= $pagina['tipo'] === 'personalizada' ? '' : 'ms-auto' ?>"><i class="bi bi-box-arrow-up-right"></i> Abrir</a><?php endif; ?>
            <?php if ($pagina['tipo'] !== 'inicio'): ?>
                <form method="post" class="d-inline <?= $urlPrevia || $pagina['tipo'] === 'personalizada' ? '' : 'ms-auto' ?>">
                    <input type="hidden" name="acao" value="alternar_pagina">
                    <button type="submit" class="btn btn-sm <?= $pagina['ativo'] ? 'btn-outline-secondary' : 'btn-warning' ?>" title="<?= $pagina['ativo'] ? 'Tira do menu e não deixa abrir no portal' : 'Volta a aparecer no portal' ?>">
                        <i class="bi bi-<?= $pagina['ativo'] ? 'eye-slash' : 'eye' ?>"></i> <?= $pagina['ativo'] ? 'Ocultar do portal' : 'Mostrar no portal' ?>
                    </button>
                </form>
            <?php endif; ?>
            <?php if ($pagina['tipo'] === 'personalizada'): ?>
                <form method="post" action="/configuracoes/portal_pagina.php" class="d-inline" onsubmit="return confirm(<?= e(json_encode('Excluir a página "' . $pagina['titulo'] . '"? Os blocos e as imagens dela também serão apagados.')) ?>);">
                    <input type="hidden" name="id" value="<?= (int) $pagina['id'] ?>"><input type="hidden" name="acao" value="excluir">
                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Excluir esta página"><i class="bi bi-trash"></i></button>
                </form>
            <?php endif; ?>
        </div>
        <?php if (!$pagina['ativo'] && $pagina['tipo'] !== 'inicio'): ?>
            <div class="alert alert-warning py-2 small"><i class="bi bi-eye-slash"></i> Esta página está <strong>oculta</strong>: não aparece no menu nem abre no portal. Só você vê pela prévia.</div>
        <?php endif; ?>
        <p class="small text-muted">Cada caixa é um bloco, na mesma largura e ordem em que aparece no site. <strong>Arraste</strong> pelo <i class="bi bi-grip-vertical"></i> para mudar de lugar. As mudanças entram no ar na hora.</p>

        <?php foreach ($regioes as $regiao => $rotuloRegiao):
            $blocosRegiao = array_values(array_filter($blocos, fn ($b) => $b['regiao'] === $regiao)); ?>
            <?php if ($regiao === 'fim'): ?>
                <div class="conteudo-tela"><i class="bi bi-window-stack"></i> Aqui fica o conteúdo da tela: <?= e($conteudoTela) ?></div>
            <?php endif; ?>
            <div class="regiao">
                <div class="regiao-titulo"><?= e($rotuloRegiao) ?></div>
                <div class="editor-grade lista-blocos" data-regiao="<?= $regiao ?>">
                    <?php foreach ($blocosRegiao as $i => $bloco): ?><?= $cartaoBloco($bloco, $i) ?><?php endforeach; ?>
                </div>
                <?php if (!$blocosRegiao): ?><div class="small text-muted">Nenhum bloco aqui.</div><?php endif; ?>
                <button type="button" class="btn-adicionar" onclick="abrirNovoBloco('<?= $regiao ?>', <?= count($blocosRegiao) ?>)"><i class="bi bi-plus-lg"></i> Adicionar bloco</button>
            </div>
        <?php endforeach; ?>

        <div class="secao mt-4">
            <div class="secao-titulo">
                <i class="bi bi-eye"></i> Prévia
                <?php if ($urlPrevia && !$semPrevia): ?>
                    <div class="btn-group btn-group-sm ms-auto" role="group">
                        <input type="radio" class="btn-check" name="previa" id="previa-visitante" checked onchange="trocarPrevia('visitantes')"><label class="btn btn-outline-secondary" for="previa-visitante"><i class="bi bi-person"></i> Como visitante</label>
                        <input type="radio" class="btn-check" name="previa" id="previa-aluno" onchange="trocarPrevia('alunos')"><label class="btn btn-outline-secondary" for="previa-aluno"><i class="bi bi-person-check"></i> Como aluno logado</label>
                    </div>
                <?php endif; ?>
            </div>
            <?php if ($semPrevia): ?>
                <p class="text-muted small mb-0">Esta tela só abre com um aluno logado, então não tem prévia aqui. Entre no portal com um aluno de teste para ver os blocos no lugar.</p>
            <?php elseif (!$urlPrevia): ?>
                <p class="text-muted small mb-0">A prévia aparece quando houver uma turma publicada no portal.</p>
            <?php else: ?>
                <iframe id="previa" class="previa-portal" src="<?= e($urlPrevia . (str_contains($urlPrevia, '?') ? '&' : '?') . 'previa=visitantes') ?>" title="Prévia do portal"></iframe>
                <div class="form-text">"Como aluno logado" mostra os blocos do aluno com um aluno de exemplo (Maria).</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Escolher o tipo do bloco novo -->
<div class="modal fade" id="modal-novo-bloco" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title">Que bloco você quer adicionar?</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="row g-2">
                    <?php foreach ($tipos as $chave => [$rotulo, $icone, $explicacao, $soAluno]): ?>
                        <div class="col-md-6">
                            <a class="tipo-bloco" href="#" data-tipo="<?= e($chave) ?>">
                                <i class="bi <?= e($icone) ?>"></i>
                                <span><strong><?= e($rotulo) ?></strong><?= $soAluno ? ' <span class="badge text-bg-info">aluno logado</span>' : '' ?><br><span class="small text-muted"><?= e($explicacao) ?></span></span>
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.2/Sortable.min.js"></script>
<script>
const PAGINA_ID = <?= (int) $pagina['id'] ?>;
let regiaoNova = 'topo', posicaoNova = 0;
function abrirNovoBloco(regiao, posicao) {
    regiaoNova = regiao; posicaoNova = posicao;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-novo-bloco')).show();
}
document.querySelectorAll('.tipo-bloco').forEach((el) => el.addEventListener('click', (e) => {
    e.preventDefault();
    location = `/configuracoes/portal_bloco.php?tipo=${el.dataset.tipo}&pagina=${PAGINA_ID}&regiao=${regiaoNova}&posicao=${posicaoNova}`;
}));

function recarregarPrevia() { const f = document.getElementById('previa'); if (f) f.contentWindow.location.reload(); }
function trocarPrevia(publico) {
    const f = document.getElementById('previa');
    const url = new URL(f.src, location.origin);
    url.searchParams.set('previa', publico);
    f.src = url.pathname + url.search;
}

// Arrastar: entre as duas regiões das telas do sistema, inclusive.
document.querySelectorAll('.lista-blocos').forEach((lista) => Sortable.create(lista, {
    group: 'blocos', animation: 150, handle: '.arrastar',
    onEnd: () => {
        const dados = new URLSearchParams({ acao: 'ordenar', pagina: PAGINA_ID });
        document.querySelectorAll('.lista-blocos').forEach((l) => l.querySelectorAll('.editor-bloco').forEach((b) => dados.append(l.dataset.regiao + '[]', b.dataset.id)));
        fetch('/configuracoes/portal.php', { method: 'POST', body: dados, headers: { 'X-Requested-With': 'XMLHttpRequest' } }).then(recarregarPrevia);
    },
}));
</script>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
