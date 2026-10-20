<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirPortal();

// Sem ?modelo: lista dos modelos. Com ?modelo=ID: editor daquele modelo.
$pdo = getDB();
$config = buscarConfiguracoes($pdo);
if (!modeloPadraoCertificado($pdo)) {
    $pdo->exec("INSERT INTO certificado_modelos (nome, padrao) VALUES ('Modelo padrão', 1)");
}
$modeloId = (int) ($_GET['modelo'] ?? $_POST['modelo_id'] ?? 0);
$modelo = $modeloId ? buscarModeloCertificado($pdo, $modeloId) : null;
// Modelo novo (?modelo=novo): só um rascunho na tela — vai para o banco ao
// salvar. Saindo sem salvar, não fica nada (nem os fundos enviados).
$ehNovo = !$modelo && (($_GET['modelo'] ?? '') === 'novo' || ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['modelo_id'] ?? '') === 'novo'));
if ($ehNovo) {
    $modelo = ['id' => 0, 'nome' => 'Novo modelo', 'padrao' => 0, 'orientacao' => 'horizontal', 'fundo' => null,
        'layout' => json_encode(['paginas' => normalizarPaginasCertificado(paginasPadraoCertificado())], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
}
if ($modeloId && !$modelo) {
    flash('danger', 'Modelo de certificado não encontrado.');
    redirect('/configuracoes/certificado.php');
}
$aqui = $modelo ? '/configuracoes/certificado.php?modelo=' . (int) $modelo['id'] : '/configuracoes/certificado.php';
$jsonLayout = fn (array $paginas) => json_encode(['paginas' => $paginas], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = input('acao');

    if ($acao === 'duplicar' && $modelo && !$ehNovo) {
        $nome = mb_substr(trim(input('nome')), 0, 100) ?: ($acao === 'duplicar' ? $modelo['nome'] . ' (cópia)' : 'Novo modelo');
        $paginas = $acao === 'duplicar' ? paginasModeloCertificado($modelo) : normalizarPaginasCertificado(paginasPadraoCertificado());
        $pdo->prepare('INSERT INTO certificado_modelos (nome, fundo, layout, orientacao, marca_dagua) VALUES (?, ?, ?, ?, ?)')
            ->execute([$nome, $paginas[0]['fundo'], $jsonLayout($paginas), $modelo['orientacao'] ?? 'horizontal', $modelo['marca_dagua'] ?? null]);
        $novoId = (int) $pdo->lastInsertId();
        if ($acao === 'duplicar') {
            // A cópia leva os fundos do modelo (mesmos arquivos).
            $pdo->prepare('INSERT INTO certificado_fundos (modelo_id, nome, imagem) SELECT ?, nome, imagem FROM certificado_fundos WHERE modelo_id = ?')
                ->execute([$novoId, $modelo['id']]);
        }
        registrarAtividade('portal', 'criacao', 'Modelo de certificado criado: ' . $nome);
        flash('success', 'Modelo "' . $nome . '" criado. Depois de montar, escolha-o no cadastro dos cursos.');
        redirect('/configuracoes/certificado.php?modelo=' . $novoId);
    }

    if ($acao === 'tornar_padrao' && $modelo && !$ehNovo) {
        $pdo->beginTransaction();
        $pdo->exec('UPDATE certificado_modelos SET padrao = 0');
        $pdo->prepare('UPDATE certificado_modelos SET padrao = 1 WHERE id = ?')->execute([$modelo['id']]);
        $pdo->commit();
        registrarAtividade('portal', 'edicao', 'Modelo de certificado padrão: ' . $modelo['nome']);
        flash('success', '"' . $modelo['nome'] . '" agora é o modelo padrão (vale para os cursos sem modelo escolhido).');
        redirect(input('voltar') === 'lista' ? '/configuracoes/certificado.php' : $aqui);
    }

    if ($acao === 'excluir_modelo' && $modelo && !$ehNovo) {
        if ($modelo['padrao']) {
            flash('danger', 'O modelo padrão não pode ser excluído. Torne outro modelo padrão antes.');
            redirect('/configuracoes/certificado.php');
        }
        // Os cursos que usavam este modelo passam a usar o padrão (ON DELETE SET NULL).
        // Os fundos saem junto; o arquivo só se nenhum outro modelo usa.
        $fundosModelo = array_column(fundosCertificado($pdo, (int) $modelo['id']), 'imagem');
        $pdo->prepare('DELETE FROM certificado_modelos WHERE id = ?')->execute([$modelo['id']]);
        foreach (array_unique($fundosModelo) as $imagem) {
            apagarFundoSemUso($pdo, $imagem);
        }
        registrarAtividade('portal', 'exclusao', 'Modelo de certificado excluído: ' . $modelo['nome']);
        flash('success', 'Modelo "' . $modelo['nome'] . '" excluído. Os cursos que o usavam passaram para o modelo padrão.');
        redirect('/configuracoes/certificado.php');
    }

    // Envio de arquivo pela tela (sem recarregar): fundo novo ou imagem
    // para pôr no certificado (assinatura, selo, logo).
    if ($acao === 'upload') {
        $ehFundo = input('tipo') === 'fundo';
        if ($ehFundo && !$modelo) {
            responderJson(['sucesso' => false, 'mensagem' => 'Abra um modelo para cadastrar o fundo.']);
        }
        // Rascunho: o arquivo sobe, mas o fundo só é gravado ao salvar o modelo.
        if ($ehFundo && $ehNovo) {
            $upload = salvarImagemPortal($_FILES['arquivo'] ?? [], 'certificado', 10);
            if (!$upload['ok']) {
                responderJson(['sucesso' => false, 'mensagem' => $upload['mensagem']]);
            }
            $nome = mb_substr(trim(input('nome')) ?: pathinfo((string) ($_FILES['arquivo']['name'] ?? ''), PATHINFO_FILENAME) ?: 'Fundo', 0, 100);
            responderJson(['sucesso' => true, 'id' => 'novo-' . bin2hex(random_bytes(4)), 'nome' => $nome, 'url' => $upload['url']]);
        }
        $upload = salvarImagemPortal($_FILES['arquivo'] ?? [], $ehFundo ? 'certificado' : 'certimg', $ehFundo ? 10 : 5);
        if (!$upload['ok']) {
            responderJson(['sucesso' => false, 'mensagem' => $upload['mensagem']]);
        }
        if ($ehFundo) {
            $nome = mb_substr(trim(input('nome')) ?: pathinfo((string) ($_FILES['arquivo']['name'] ?? ''), PATHINFO_FILENAME) ?: 'Fundo', 0, 100);
            $pdo->prepare('INSERT INTO certificado_fundos (modelo_id, nome, imagem) VALUES (?, ?, ?)')->execute([$modelo['id'], $nome, $upload['url']]);
            $fundoId = (int) $pdo->lastInsertId();
            registrarAtividade('portal', 'criacao', 'Fundo de certificado cadastrado: ' . $nome);
            responderJson(['sucesso' => true, 'id' => $fundoId, 'nome' => $nome, 'url' => $upload['url']]);
        }
        responderJson(['sucesso' => true, 'url' => $upload['url']]);
    }

    // Rascunho: fundo ainda não gravado — apaga o arquivo se ninguém usa.
    if ($acao === 'excluir_fundo' && $ehNovo) {
        $url = input('url');
        if (imagemUploadValida($url) && str_starts_with(basename($url), 'certificado-')) {
            apagarFundoSemUso($pdo, $url);
        }
        responderJson(['sucesso' => true]);
    }

    if ($acao === 'excluir_fundo') {
        $stmt = $pdo->prepare('SELECT * FROM certificado_fundos WHERE id = ? AND modelo_id = ?');
        $stmt->execute([(int) ($_POST['id'] ?? 0), (int) ($modelo['id'] ?? 0)]);
        $fundo = $stmt->fetch();
        if (!$fundo) {
            responderJson(['sucesso' => false, 'mensagem' => 'Fundo não encontrado neste modelo.']);
        }
        if (in_array($fundo['imagem'], fundosDoModeloCertificado($modelo), true)) {
            responderJson(['sucesso' => false, 'mensagem' => 'Este fundo está em uso numa folha deste modelo. Troque o fundo da folha e salve antes de excluir.']);
        }
        $pdo->prepare('DELETE FROM certificado_fundos WHERE id = ?')->execute([$fundo['id']]);
        apagarFundoSemUso($pdo, $fundo['imagem']);
        registrarAtividade('portal', 'exclusao', 'Fundo de certificado excluído: ' . $fundo['nome']);
        responderJson(['sucesso' => true]);
    }

    if ($acao === 'salvar' && $modelo) {
        $paginas = normalizarPaginasCertificado((array) json_decode((string) ($_POST['paginas'] ?? '[]'), true));
        $nomeModelo = mb_substr(trim(input('nome')), 0, 100);
        $urlVerificacao = trim(input('url_verificacao'));
        $erros = [];
        if ($nomeModelo === '') {
            $erros[] = 'Dê um nome ao modelo.';
        }
        $galeria = array_column(fundosCertificado($pdo, (int) $modelo['id']), 'imagem');
        // Rascunho: os fundos enviados nesta tela (ainda sem registro).
        $fundosNovos = [];
        if ($ehNovo) {
            foreach ((array) json_decode((string) ($_POST['fundos_novos'] ?? '[]'), true) as $fn) {
                if (is_array($fn) && imagemUploadValida($fn['url'] ?? null) && str_starts_with(basename((string) $fn['url']), 'certificado-')) {
                    $fundosNovos[$fn['url']] = mb_substr(trim((string) ($fn['nome'] ?? '')) ?: 'Fundo', 0, 100);
                }
            }
            $galeria = array_keys($fundosNovos);
        }
        foreach ($paginas as $pagina) {
            if ($pagina['fundo'] && !in_array($pagina['fundo'], $galeria, true) && !in_array($pagina['fundo'], fundosDoModeloCertificado($modelo), true)) {
                $erros[] = 'Um dos fundos escolhidos não está mais na galeria.';
                break;
            }
        }
        if ($urlVerificacao !== '' && !preg_match('#^https?://[^\s]+$#i', $urlVerificacao)) {
            $erros[] = 'O endereço de verificação precisa começar com https://';
        }
        if ($erros) {
            responderJson(['sucesso' => false, 'mensagem' => implode(' ', $erros)]);
        }
        $orientacao = input('orientacao') === 'vertical' ? 'vertical' : 'horizontal';
        $marcaDagua = marcaDaguaModelo(['marca_dagua' => (string) ($_POST['marca_dagua'] ?? '')]);
        $marcaDaguaJson = json_encode(['ativa' => $marcaDagua['ativa'] ? 1 : 0] + $marcaDagua);
        if ($ehNovo) {
            $pdo->prepare('INSERT INTO certificado_modelos (nome, orientacao, layout, fundo, marca_dagua) VALUES (?, ?, ?, ?, ?)')
                ->execute([$nomeModelo, $orientacao, $jsonLayout($paginas), $paginas[0]['fundo'], $marcaDaguaJson]);
            $novoId = (int) $pdo->lastInsertId();
            $insereFundo = $pdo->prepare('INSERT INTO certificado_fundos (modelo_id, nome, imagem) VALUES (?, ?, ?)');
            foreach ($fundosNovos as $url => $nomeFundo) {
                $insereFundo->execute([$novoId, $nomeFundo, $url]);
            }
            $pdo->prepare('UPDATE configuracoes SET certificado_url_verificacao = ? WHERE id = 1')->execute([$urlVerificacao ?: null]);
            registrarAtividade('portal', 'criacao', 'Modelo de documento criado: ' . $nomeModelo);
            flash('success', 'Modelo "' . $nomeModelo . '" criado. Escolha-o no cadastro dos cursos.');
            responderJson(['sucesso' => true, 'id' => $novoId]);
        }
        $pdo->prepare('UPDATE certificado_modelos SET nome = ?, orientacao = ?, layout = ?, fundo = ?, marca_dagua = ? WHERE id = ?')
            ->execute([$nomeModelo, $orientacao, $jsonLayout($paginas), $paginas[0]['fundo'], $marcaDaguaJson, $modelo['id']]);
        $pdo->prepare('UPDATE configuracoes SET certificado_url_verificacao = ? WHERE id = 1')->execute([$urlVerificacao ?: null]);
        registrarAtividade('portal', 'edicao', 'Modelo de documento alterado: ' . $nomeModelo);
        responderJson(['sucesso' => true]);
    }
    redirect($aqui);
}

$valores = valoresExemploCertificado($config);
$modulosCertificado = modulosExemploCertificado();

// =====================================================================
// Lista de modelos
// =====================================================================
if (!$modelo):
    $modelos = modelosCertificado($pdo);
    $pageTitle = 'Modelos de documentos';
    $pageBreadcrumb = 'Acadêmico · Portal do aluno';
    $activeMenu = 'certificado';
    $pageAction = '<a href="/configuracoes/certificado.php?modelo=novo" class="btn btn-brand"><i class="bi bi-plus-lg"></i> Novo modelo</a>';
    require __DIR__ . '/../../includes/layout_header.php';
?>
<link href="<?= e(assetUrl('/assets/css/certificado.css')) ?>" rel="stylesheet">
<style>
    .modelo-card { background: var(--surface, #fff); border: 1px solid var(--border, #e6e8eb); border-radius: 12px; overflow: hidden; height: 100%; display: flex; flex-direction: column; }
    .modelo-card .miniatura { display: block; padding: 12px; background: #eef1f4; }
    .modelo-card .miniatura .cert-folha { pointer-events: none; }
    .modelo-card .corpo { padding: 12px 14px; display: flex; flex-direction: column; gap: 6px; flex: 1; }
</style>
<p class="text-muted" style="max-width: 900px;">
    Modelos dos documentos que o aluno recebe: certificado, declarações de módulo... Cada curso escolhe, em "Documentos emitidos" (Acadêmico › Cursos),
    qual modelo usar em cada documento; sem escolha, vale o <strong>padrão</strong>. Cada modelo pode ser horizontal ou vertical.
    Clique num modelo para editar.
</p>
<div class="row g-3">
    <?php foreach ($modelos as $m):
        $paginas = paginasModeloCertificado($m);
        $paginasCertificado = [$paginas[0]];
        $orientacaoCertificado = $m['orientacao'] ?? 'horizontal';
        $marcaDaguaCertificado = marcaDaguaModelo($m); ?>
        <div class="col-md-6 col-xl-4">
            <div class="modelo-card">
                <a class="miniatura" href="/configuracoes/certificado.php?modelo=<?= (int) $m['id'] ?>" title="Editar"><?php require __DIR__ . '/../../includes/certificado_render.php'; ?></a>
                <div class="corpo">
                    <div class="d-flex align-items-center gap-2">
                        <a href="/configuracoes/certificado.php?modelo=<?= (int) $m['id'] ?>" class="fw-semibold fs-6 text-decoration-none"><?= e($m['nome']) ?></a>
                        <?php if ($m['padrao']): ?><span class="badge text-bg-warning"><i class="bi bi-star-fill"></i> Padrão</span><?php endif; ?>
                        <span class="badge text-bg-light border ms-auto"><?= count($paginas) ?> folha(s)</span>
                    </div>
                    <div class="small text-muted">
                        <?= $m['cursos'] ? 'Cursos: ' . e($m['cursos']) : ($m['padrao'] ? 'Cursos sem modelo escolhido' : 'Nenhum curso usa este modelo') ?>
                    </div>
                    <div class="d-flex flex-wrap gap-1 mt-auto pt-2">
                        <a href="/configuracoes/certificado.php?modelo=<?= (int) $m['id'] ?>" class="btn btn-sm btn-brand"><i class="bi bi-pencil"></i> Editar</a>
                        <form method="post"><input type="hidden" name="acao" value="duplicar"><input type="hidden" name="modelo_id" value="<?= (int) $m['id'] ?>">
                            <button class="btn btn-sm btn-outline-secondary" title="Criar um modelo a partir deste"><i class="bi bi-copy"></i> Duplicar</button></form>
                        <?php if (!$m['padrao']): ?>
                            <form method="post"><input type="hidden" name="acao" value="tornar_padrao"><input type="hidden" name="voltar" value="lista"><input type="hidden" name="modelo_id" value="<?= (int) $m['id'] ?>">
                                <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-star"></i> Tornar padrão</button></form>
                            <form method="post" class="ms-auto" onsubmit="return confirm(<?= e(json_encode('Excluir o modelo "' . $m['nome'] . '"?' . ($m['cursos'] ? ' Os cursos ' . $m['cursos'] . ' passarão a usar o modelo padrão.' : ''))) ?>)">
                                <input type="hidden" name="acao" value="excluir_modelo"><input type="hidden" name="modelo_id" value="<?= (int) $m['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger" title="Excluir"><i class="bi bi-trash"></i></button></form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script src="<?= e(assetUrl('/assets/js/certificado.js')) ?>"></script>
<script>(document.fonts ? document.fonts.ready : Promise.resolve()).then(() => ajustarCertificado());</script>
<?php
    require __DIR__ . '/../../includes/layout_footer.php';
    return;
endif;

// =====================================================================
// Editor de um modelo
// =====================================================================
$paginas = paginasModeloCertificado($modelo);
$paginasCertificado = [$paginas[0]];
$orientacaoCertificado = $modelo['orientacao'] ?? 'horizontal';
$marcaDaguaCertificado = marcaDaguaModelo($modelo);
$temLogoSistema = trim((string) ($config['logo_sistema'] ?? '')) !== '';
$fundos = fundosCertificado($pdo, (int) $modelo['id']);
$cursosDoModelo = $ehNovo ? null : ($modelo['padrao']
    ? $pdo->query('SELECT GROUP_CONCAT(nome ORDER BY nome SEPARATOR ", ") FROM cursos WHERE certificado_modelo_id IS NULL OR certificado_modelo_id = ' . (int) $modelo['id'])->fetchColumn()
    : (array_column(modelosCertificado($pdo), 'cursos', 'id')[$modelo['id']] ?? null));

$pageTitle = $modelo['nome'];
$pageBreadcrumb = 'Acadêmico · Documentos';
$activeMenu = 'certificado';
$pageAction = '<a href="/configuracoes/certificado.php" class="btn btn-outline-secondary" onclick="return confirmarSaida()"><i class="bi bi-arrow-left"></i> Modelos</a>'
    . '<span id="aviso-alterado" class="small text-warning mx-2 d-none"><i class="bi bi-dot"></i> Alterações não salvas</span>'
    . '<button type="button" id="btn-salvar" class="btn btn-brand" onclick="salvar()"><i class="bi bi-check-lg"></i> Salvar</button>';
require __DIR__ . '/../../includes/layout_header.php';
?>
<link href="<?= e(assetUrl('/assets/css/certificado.css')) ?>" rel="stylesheet">
<style>
    .coluna-previa { position: sticky; top: calc(var(--topnav-h, 58px) + 12px); }
    .painel-rolavel { max-height: calc(100vh - var(--topnav-h, 58px) - 150px); overflow-y: auto; padding-right: 4px; }
    #previa .cert-campo { cursor: move; outline: 1px dashed rgba(0,0,0,.18); touch-action: none; }
    #previa .cert-campo:hover { outline-color: var(--accent); }
    #previa .cert-campo.selecionado { outline: 2px solid var(--accent); z-index: 5; overflow: visible; }
    #previa .alca { position: absolute; background: var(--accent); border: 2px solid #fff; border-radius: 4px; z-index: 6; }
    #previa .alca.esq, #previa .alca.dir { top: 50%; width: 10px; height: 24px; margin-top: -12px; cursor: ew-resize; }
    #previa .alca.esq { left: -6px; } #previa .alca.dir { right: -6px; }
    #previa .alca.cima, #previa .alca.baixo { left: 50%; width: 24px; height: 10px; margin-left: -12px; cursor: ns-resize; }
    #previa .alca.cima { top: -6px; } #previa .alca.baixo { bottom: -6px; }
    #previa .alca.canto { right: -7px; bottom: -7px; width: 13px; height: 13px; border-radius: 50%; cursor: nwse-resize; }
    .guia { position: absolute; pointer-events: none; z-index: 8; }
    .guia.v { top: 0; bottom: 0; width: 0; border-left: 1px dashed; }
    .guia.h { left: 0; right: 0; height: 0; border-top: 1px dashed; }
    .guia.centro { border-color: rgba(13,110,253,.35); }
    .guia.encaixe { border-color: #e83e8c; border-left-style: solid; border-top-style: solid; }
    .barra-previa { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; margin-top: 10px; }
    .abas-folhas .nav-link { cursor: pointer; }
    .elemento { border: 1px solid var(--border, #e6e8eb); border-radius: 10px; margin-bottom: 8px; background: var(--surface, #fff); }
    .elemento .cabeca { display: flex; align-items: center; gap: 8px; padding: 8px 10px; cursor: pointer; }
    .elemento .cabeca .resumo { flex: 1; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-size: .9rem; }
    .elemento .corpo { padding: 0 10px 10px; display: none; }
    .elemento.selecionado { border-color: var(--accent); box-shadow: 0 0 0 2px var(--accent-soft); }
    .elemento.selecionado .corpo { display: block; }
    .fundos { display: grid; grid-template-columns: repeat(auto-fill, minmax(110px, 1fr)); gap: 8px; }
    .fundo { border: 2px solid var(--border, #e6e8eb); border-radius: 8px; overflow: hidden; cursor: pointer; position: relative; background: #fff; }
    .fundo.escolhido { border-color: var(--accent); box-shadow: 0 0 0 2px var(--accent-soft); }
    .fundo .miniatura { aspect-ratio: 297/210; background: #f3f4f6 center / cover no-repeat; display: flex; align-items: center; justify-content: center; color: #9ca3af; }
    .fundo .nome { font-size: 11px; padding: 3px 6px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .fundo .excluir { position: absolute; top: 4px; right: 4px; padding: 0 5px; line-height: 1.4; }
    .marcador { cursor: pointer; }
</style>

<div class="secao mb-3">
    <div class="d-flex flex-wrap align-items-end gap-2">
        <div style="min-width: 260px;">
            <label class="form-label small mb-1">Nome deste modelo</label>
            <input type="text" id="nome-modelo" class="form-control" maxlength="100" value="<?= e($modelo['nome']) ?>" oninput="marcarAlterado()">
        </div>
        <div>
            <label class="form-label small mb-1">Orientação</label>
            <select id="orientacao-modelo" class="form-select" style="min-width: 240px;" onchange="trocarOrientacao()">
                <option value="horizontal" <?= $orientacaoCertificado === 'horizontal' ? 'selected' : '' ?>>Horizontal (A4 deitado)</option>
                <option value="vertical" <?= $orientacaoCertificado === 'vertical' ? 'selected' : '' ?>>Vertical (A4 em pé)</option>
            </select>
        </div>
        <?php if (!$ehNovo): ?>
        <form method="post" onsubmit="return confirmarSaida()"><input type="hidden" name="modelo_id" value="<?= (int) $modelo['id'] ?>"><input type="hidden" name="acao" value="duplicar">
            <button class="btn btn-outline-secondary" title="Cria um modelo novo a partir deste"><i class="bi bi-copy"></i> Duplicar</button></form>
        <?php endif; ?>
        <?php if (!$modelo['padrao'] && !$ehNovo): ?>
            <form method="post" onsubmit="return confirmarSaida() && confirm('Tornar este o modelo padrão? Ele passa a valer para os cursos sem modelo escolhido.')"><input type="hidden" name="modelo_id" value="<?= (int) $modelo['id'] ?>"><input type="hidden" name="acao" value="tornar_padrao">
                <button class="btn btn-outline-secondary"><i class="bi bi-star"></i> Tornar padrão</button></form>
        <?php endif; ?>
    </div>
    <div class="small text-muted mt-2">
        <?php if ($modelo['padrao']): ?><span class="badge text-bg-warning me-1"><i class="bi bi-star-fill"></i> Padrão</span> vale para os cursos sem modelo escolhido.<?php endif; ?>
        <?= $cursosDoModelo ? 'Usado em: <strong>' . e($cursosDoModelo) . '</strong>.' : 'Nenhum curso usa este modelo ainda — escolha-o no cadastro do curso (Acadêmico › Cursos).' ?>
    </div>
</div>

<div class="row g-4">
    <!-- Prévia: fica parada enquanto a coluna da direita rola. -->
    <div class="col-xl-7">
        <div class="coluna-previa">
            <ul class="nav nav-tabs abas-folhas mb-2" id="abas-folhas"></ul>
            <div id="previa"><?php require __DIR__ . '/../../includes/certificado_render.php'; ?></div>
            <div class="barra-previa">
                <div class="form-check form-switch me-2"><input class="form-check-input" type="checkbox" id="mostrar-centro" checked onchange="desenharPrevia()"><label class="form-check-label small" for="mostrar-centro">Linhas de centro</label></div>
                <div class="form-check form-switch me-2"><input class="form-check-input" type="checkbox" id="encaixar" checked><label class="form-check-label small" for="encaixar">Grudar nas guias</label></div>
                <span class="vr mx-1"></span>
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="alinharSelecionado('x')"><i class="bi bi-distribute-horizontal"></i> Centro horizontal</button>
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="alinharSelecionado('y')"><i class="bi bi-distribute-vertical"></i> Centro vertical</button>
                <span class="small text-muted ms-auto" id="posicao"></span>
            </div>
            <div class="form-text">Arraste para mover · alças dos lados mudam a largura, de cima/baixo a altura, a do canto as duas · setas do teclado ajustam fino (Shift = passo maior).</div>
        </div>
    </div>

    <div class="col-xl-5">
        <div class="painel-rolavel">
            <div class="secao">
                <div class="secao-titulo"><i class="bi bi-image"></i> Fundo <span class="small text-muted fw-normal ms-1" id="fundo-da-folha"></span></div>
                <div class="fundos" id="fundos"></div>
                <label class="btn btn-sm btn-outline-secondary mt-2"><i class="bi bi-upload"></i> Cadastrar fundo
                    <input type="file" accept="image/*" hidden onchange="enviarFundo(this)">
                </label>
                <div class="form-text">Arte sem os dados do aluno. Ideal: 3508 × 2480 px (A4 deitado) ou 2480 × 3508 px (A4 em pé), até 10 MB.</div>
            </div>

            <div class="secao">
                <div class="secao-titulo"><i class="bi bi-droplet-half"></i> Marca d'água <span class="small text-muted fw-normal ms-1">(logo do sistema, em todas as folhas)</span></div>
                <div class="form-check form-switch mb-2">
                    <input class="form-check-input" type="checkbox" id="marca-ativa" <?= $marcaDaguaCertificado['ativa'] ? 'checked' : '' ?> onchange="atualizarMarcaDagua()" <?= $temLogoSistema ? '' : 'disabled' ?>>
                    <label class="form-check-label" for="marca-ativa">Mostrar marca d'água</label>
                </div>
                <div class="row g-2 small" id="marca-ajustes"<?= $marcaDaguaCertificado['ativa'] ? '' : ' style="opacity: .4"' ?>>
                    <div class="col-sm-4"><label class="form-label small mb-0">Tamanho</label><input type="range" class="form-range" id="marca-tamanho" min="3" max="25" step="0.5" value="<?= $marcaDaguaCertificado['tamanho'] ?>" oninput="atualizarMarcaDagua()"></div>
                    <div class="col-sm-4"><label class="form-label small mb-0">Espaço entre as logos</label><input type="range" class="form-range" id="marca-espaco" min="0" max="10" step="0.5" value="<?= $marcaDaguaCertificado['espaco'] ?>" oninput="atualizarMarcaDagua()"></div>
                    <div class="col-sm-4"><label class="form-label small mb-0">Intensidade</label><input type="range" class="form-range" id="marca-opacidade" min="2" max="40" step="1" value="<?= $marcaDaguaCertificado['opacidade'] ?>" oninput="atualizarMarcaDagua()"></div>
                </div>
                <?php if (!$temLogoSistema): ?><div class="form-text text-danger">Cadastre a logo do sistema (Configurações) para usar a marca d'água.</div><?php endif; ?>
            </div>
            <div class="secao">
                <div class="secao-titulo"><i class="bi bi-layers"></i> Elementos <span class="small text-muted fw-normal ms-1" id="elementos-da-folha"></span></div>
                <div class="d-flex flex-wrap gap-2 mb-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="adicionarTexto()"><i class="bi bi-fonts"></i> Texto</button>
                    <label class="btn btn-sm btn-outline-secondary mb-0"><i class="bi bi-image"></i> Imagem<input type="file" accept="image/*" hidden onchange="adicionarImagem(this)"></label>
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="adicionarModulos()"><i class="bi bi-list-ol"></i> Lista de módulos</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="adicionarQr()"><i class="bi bi-qr-code"></i> QR Code</button>
                </div>
                <div id="lista-elementos"></div>
                <div class="small text-muted mt-2 mb-1">Marcadores — clique para inserir no texto selecionado:</div>
                <div class="d-flex flex-wrap gap-1">
                    <?php foreach (marcadoresCertificado() as $marcador => $rotulo): ?>
                        <span class="badge text-bg-light border marcador" title="<?= e($rotulo) ?>" onclick="inserirMarcador('<?= e($marcador) ?>')"><?= e($marcador) ?></span>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="secao">
                <div class="secao-titulo"><i class="bi bi-patch-check"></i> Verificação <span class="small text-muted fw-normal ms-1">(vale para todos os modelos)</span></div>
                <p class="small text-muted mb-2">
                    Cada certificado ganha um <strong>código único</strong> na primeira vez que é aberto, guardado no sistema junto com o aluno,
                    o curso e a data. Quem recebe o certificado digita o código (ou lê o QR Code) na página de verificação.
                </p>
                <label class="form-label small mb-1">Endereço da página de verificação</label>
                <input type="url" id="url_verificacao" class="form-control form-control-sm" value="<?= e($config['certificado_url_verificacao'] ?? '') ?>"
                       placeholder="<?= e(urlAbsoluta('/portal/verificar.php')) ?>" oninput="marcarAlterado(); atualizarExemploLink(); desenharPrevia();">
                <div class="form-text">Em branco, usa a página do portal. No texto: <code>{codigo}</code>, <code>{url_verificacao}</code> ou <code>{link_verificacao}</code> (já com o código, o mesmo do QR Code).</div>
                <div class="small mt-1">Exemplo de link: <a id="exemplo-link" href="#" target="_blank"></a></div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script src="<?= e(assetUrl('/assets/js/certificado.js')) ?>"></script>
<script>
let paginas = <?= json_encode($paginas, JSON_UNESCAPED_UNICODE) ?>;
let fundos = <?= json_encode(array_map(fn ($f) => ['id' => (int) $f['id'], 'nome' => $f['nome'], 'url' => $f['imagem']], $fundos), JSON_UNESCAPED_UNICODE) ?>;
const MODELO_ID = <?= $ehNovo ? "'novo'" : (int) $modelo['id'] ?>;
const EXEMPLO = <?= json_encode($valores, JSON_UNESCAPED_UNICODE) ?>;
const MODULOS = <?= json_encode($modulosCertificado, JSON_UNESCAPED_UNICODE) ?>;
const URL_PADRAO = <?= json_encode(urlAbsoluta('/portal/verificar.php')) ?>;
const ALINHAMENTOS = <?= json_encode(alinhamentosCertificado(), JSON_UNESCAPED_UNICODE) ?>;
const VERSO_PADRAO = <?= json_encode(folhaVersoPadraoCertificado(), JSON_UNESCAPED_UNICODE) ?>;
const MAX_FOLHAS = <?= CERTIFICADO_MAX_FOLHAS ?>;
const ENCAIXE = 0.8; // % de distância em que o elemento "gruda" na guia
let folhaAtual = 0;
let selecionado = paginas[0].elementos.length ? 0 : -1;
let alterado = false;
const folha = document.querySelector('#previa .cert-folha');
// Fonte em cqw (% da largura): folha em pé é mais estreita, a conversão muda.
const fatorFonte = () => (document.getElementById('orientacao-modelo').value === 'vertical' ? 5.953 : 8.418);
// Marca d'água: liga/desliga e ajusta na prévia (salva com o modelo).
function valoresMarcaDagua() {
    return {
        ativa: document.getElementById('marca-ativa').checked ? 1 : 0,
        tamanho: parseFloat(document.getElementById('marca-tamanho').value),
        espaco: parseFloat(document.getElementById('marca-espaco').value),
        opacidade: parseFloat(document.getElementById('marca-opacidade').value),
    };
}
function atualizarMarcaDagua() {
    const v = valoresMarcaDagua();
    document.getElementById('marca-ajustes').style.opacity = v.ativa ? 1 : 0.4;
    const camada = folha.querySelector('.cert-marca-dagua');
    if (camada) {
        camada.hidden = !v.ativa;
        camada.dataset.tamanho = v.tamanho;
        camada.dataset.espaco = v.espaco;
        camada.dataset.opacidade = v.opacidade / 100;
        desenharMarcaDagua(folha);
    }
    marcarAlterado();
}

function trocarOrientacao() {
    folha.classList.toggle('cert-vertical', document.getElementById('orientacao-modelo').value === 'vertical');
    marcarAlterado();
    desenharPrevia();
}
const elementos = () => paginas[folhaAtual].elementos;

// ---------------------------------------------------------------- utilidades
function escapar(t) { const d = document.createElement('div'); d.textContent = t ?? ''; return d.innerHTML; }

function formatarTexto(texto) {
    let html = escapar(texto);

    // *texto* = negrito
    html = html.replace(/\*([^*\r\n]+)\*/g, '<strong>$1</strong>');

    // _texto_ = itálico
    html = html.replace(/_([^_\r\n]+)_/g, '<em>$1</em>');

    // &texto& = sublinhado
    html = html.replace(/&amp;([^&\r\n]+)&amp;/g, '<u>$1</u>');

    // %texto% = tachado
    html = html.replace(/%([^%\r\n]+)%/g, '<s>$1</s>');

    // Quebras de linha
    html = html.replace(/\r?\n/g, '<br>');

    return html;
}

function linkVerificacao() {
    const base = document.getElementById('url_verificacao').value.trim() || URL_PADRAO;
    return base + (base.includes('?') ? '&' : '?') + 'codigo=ABCD-EFGH-JKLM';
}
function preencher(t) {
    const valores = Object.assign({}, EXEMPLO, { '{url_verificacao}': document.getElementById('url_verificacao').value.trim() || URL_PADRAO, '{link_verificacao}': linkVerificacao() });
    return Object.entries(valores).reduce((s, [k, v]) => s.split(k).join(v), t);
}
function marcarAlterado() { alterado = true; document.getElementById('aviso-alterado').classList.remove('d-none'); }
function confirmarSaida() { return !alterado || confirm('Há alterações não salvas no certificado. Sair mesmo assim?'); }
window.addEventListener('beforeunload', (e) => { if (alterado) { e.preventDefault(); e.returnValue = ''; } });
// Rascunho abandonado: apaga os fundos que já tinham subido (o modelo não existe).
let rascunhoSalvo = false;
window.addEventListener('pagehide', () => {
    if (MODELO_ID !== 'novo' || rascunhoSalvo || !navigator.sendBeacon) return;
    const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
    fundos.forEach((f) => {
        const dados = new FormData();
        dados.append('_csrf', token); dados.append('acao', 'excluir_fundo'); dados.append('modelo_id', 'novo'); dados.append('url', f.url);
        navigator.sendBeacon('/configuracoes/certificado.php', dados);
    });
});
function arred(n) { return Math.round(n * 10) / 10; }
function limitar(n, min, max) { return Math.min(max, Math.max(min, n)); }
function atualizarExemploLink() { const a = document.getElementById('exemplo-link'); a.textContent = linkVerificacao(); a.href = linkVerificacao(); }

// QR gerado uma vez por link e reaproveitado: a prévia é redesenhada a cada
// movimento do mouse ao arrastar.
const cacheQr = {};
function imagemQr(link) {
    if (!cacheQr[link]) {
        const tmp = document.createElement('div');
        new QRCode(tmp, { text: link, width: 512, height: 512, correctLevel: QRCode.CorrectLevel.M });
        cacheQr[link] = tmp.querySelector('canvas').toDataURL('image/png');
    }
    return cacheQr[link];
}

// ---------------------------------------------------------------- folhas
function nomeFolha(i) { return i === 0 ? 'Folha 1 · frente' : `Folha ${i + 1}` + (i === 1 ? ' · verso' : ''); }
function desenharAbas() {
    const abas = document.getElementById('abas-folhas');
    abas.innerHTML = '';
    paginas.forEach((p, i) => {
        const li = document.createElement('li');
        li.className = 'nav-item';
        li.innerHTML = `<a class="nav-link ${i === folhaAtual ? 'active' : ''}">${nomeFolha(i)}${i > 0 && i === folhaAtual ? ' <i class="bi bi-x-lg ms-1 text-danger" title="Excluir esta folha" data-excluir></i>' : ''}</a>`;
        li.querySelector('a').addEventListener('click', (e) => {
            if (e.target.closest('[data-excluir]')) return excluirFolha(i);
            irParaFolha(i);
        });
        abas.appendChild(li);
    });
    if (paginas.length < MAX_FOLHAS) {
        const li = document.createElement('li');
        li.className = 'nav-item';
        li.innerHTML = '<a class="nav-link text-muted"><i class="bi bi-plus-lg"></i> Adicionar folha</a>';
        li.querySelector('a').addEventListener('click', adicionarFolha);
        abas.appendChild(li);
    }
    document.getElementById('fundo-da-folha').textContent = '— ' + nomeFolha(folhaAtual).toLowerCase();
    document.getElementById('elementos-da-folha').textContent = '— ' + nomeFolha(folhaAtual).toLowerCase();
}
function irParaFolha(i) { folhaAtual = i; selecionado = elementos().length ? 0 : -1; desenharTudo(); }
function adicionarFolha() {
    // A primeira folha extra já vem com título + lista de módulos (verso).
    const nova = paginas.length === 1 ? JSON.parse(JSON.stringify(VERSO_PADRAO)) : { fundo: null, elementos: [] };
    nova.fundo = paginas[0].fundo;
    paginas.push(nova);
    marcarAlterado();
    irParaFolha(paginas.length - 1);
}
function excluirFolha(i) {
    if (!confirm('Excluir a ' + nomeFolha(i) + ' e tudo o que está nela?')) return;
    paginas.splice(i, 1);
    marcarAlterado();
    irParaFolha(Math.min(i, paginas.length - 1));
}

// ---------------------------------------------------------------- prévia
function htmlModulos(c) {
    return `<div class="cert-conteudo" style="column-count:${c.colunas}">` + MODULOS.map((m, i) =>
        `<div class="cert-modulo">${c.numerar ? (i + 1) + '. ' : ''}${escapar(m.nome)}${c.mostrar_carga && m.carga_horaria ? ' — ' + m.carga_horaria + ' h' : ''}</div>`).join('') + '</div>';
}

function desenharPrevia(guias = []) {
    const pagina = paginas[folhaAtual];
    folha.style.backgroundImage = pagina.fundo ? `url('${pagina.fundo}')` : 'none';
    folha.querySelectorAll('.cert-campo, .guia').forEach((el) => el.remove());
    pagina.elementos.forEach((c, i) => {
        const el = document.createElement('div');
        el.className = 'cert-campo' + (i === selecionado ? ' selecionado' : '') + (c.altura > 0 ? ' cert-caixa' : '');
        Object.assign(el.style, { left: c.x + '%', top: c.y + '%', width: c.largura + '%', height: c.altura > 0 ? c.altura + '%' : '' });
        if (c.tamanho) {
            const fonte = c.tamanho / fatorFonte();
            el.dataset.fonte = fonte;
            Object.assign(el.style, { fontSize: fonte + 'cqw', color: c.cor, fontWeight: c.negrito ? 700 : 400, textAlign: c.alinhamento });
        }
        if (c.tipo === 'imagem') {
            el.classList.add('cert-imagem');
            el.innerHTML = `<img src="${escapar(c.imagem)}" alt="" draggable="false">`;
        } else if (c.tipo === 'qrcode') {
            el.classList.add('cert-qr');
            el.innerHTML = `<img src="${imagemQr(linkVerificacao())}" alt="" draggable="false">`;
        } else if (c.tipo === 'modulos') {
            el.classList.add('cert-modulos');
            el.innerHTML = htmlModulos(c);
        } else {
            let texto = preencher(c.texto);
            if (c.maiusculo) texto = texto.toLocaleUpperCase('pt-BR');
            if (c.uma_linha) { el.classList.add('cert-uma-linha'); texto = texto.replace(/\s*\n\s*/g, ' '); }
            el.innerHTML = '<div class="cert-conteudo">' + formatarTexto(texto) + '</div>';
        }
        if (i === selecionado) {
            (c.tipo === 'qrcode' ? ['esq', 'dir'] : ['esq', 'dir', 'cima', 'baixo', 'canto']).forEach((lado) => {
                const alca = document.createElement('span');
                alca.className = 'alca ' + lado;
                alca.addEventListener('pointerdown', (ev) => redimensionar(ev, i, lado, el));
                el.appendChild(alca);
            });
        }
        el.addEventListener('pointerdown', (ev) => { if (!ev.target.classList.contains('alca')) arrastar(ev, i); });
        folha.appendChild(el);
    });
    if (document.getElementById('mostrar-centro').checked) {
        guias = [{ eixo: 'v', pos: 50, centro: true }, { eixo: 'h', pos: 50, centro: true }, ...guias];
    }
    guias.forEach((g) => {
        const linha = document.createElement('div');
        linha.className = 'guia ' + g.eixo + (g.centro ? ' centro' : ' encaixe');
        linha.style[g.eixo === 'v' ? 'left' : 'top'] = g.pos + '%';
        folha.appendChild(linha);
    });
    ajustarCertificado(folha);
    const c = elementos()[selecionado];
    document.getElementById('posicao').textContent = c ? `x ${c.x}% · y ${c.y}% · largura ${c.largura}% · altura ${c.altura > 0 ? c.altura + '%' : 'automática'}` : '';
}

// Guias candidatas: centro da folha e as linhas (centro/bordas) dos outros elementos.
function encaixar(i, x, y) {
    const guias = [];
    if (!document.getElementById('encaixar').checked) return { x, y, guias };
    const c = elementos()[i];
    const alvosX = [50], alvosY = [50];
    elementos().forEach((o, j) => {
        if (j === i) return;
        alvosX.push(o.x, o.x - o.largura / 2, o.x + o.largura / 2);
        alvosY.push(o.y);
    });
    let melhorX = null;
    [[x, 0], [x - c.largura / 2, c.largura / 2], [x + c.largura / 2, -c.largura / 2]].forEach(([ponto, ajuste]) => {
        alvosX.forEach((alvo) => {
            const d = Math.abs(ponto - alvo);
            if (d < ENCAIXE && (!melhorX || d < melhorX.d)) melhorX = { d, x: alvo + ajuste, guia: alvo };
        });
    });
    let melhorY = null;
    alvosY.forEach((alvo) => { const d = Math.abs(y - alvo); if (d < ENCAIXE && (!melhorY || d < melhorY.d)) melhorY = { d, y: alvo }; });
    if (melhorX) { x = melhorX.x; guias.push({ eixo: 'v', pos: melhorX.guia }); }
    if (melhorY) { y = melhorY.y; guias.push({ eixo: 'h', pos: melhorY.y }); }
    return { x, y, guias };
}

function arrastar(ev, i) {
    ev.preventDefault();
    selecionar(i);
    const caixa = folha.getBoundingClientRect();
    const c = elementos()[i];
    const inicio = { mx: ev.clientX, my: ev.clientY, x: c.x, y: c.y };
    const mover = (e) => {
        const r = encaixar(i, limitar(inicio.x + (e.clientX - inicio.mx) / caixa.width * 100, 0, 100), limitar(inicio.y + (e.clientY - inicio.my) / caixa.height * 100, 0, 100));
        c.x = arred(r.x); c.y = arred(r.y);
        marcarAlterado();
        desenharPrevia(r.guias);
    };
    const soltar = () => { window.removeEventListener('pointermove', mover); window.removeEventListener('pointerup', soltar); desenharPrevia(); desenharLista(); };
    window.addEventListener('pointermove', mover);
    window.addEventListener('pointerup', soltar);
}

// Alças: x/y são o centro, então largura e altura crescem para os dois lados.
// Altura automática vira fixa a partir do tamanho que o elemento tem agora.
function redimensionar(ev, i, lado, el) {
    ev.preventDefault(); ev.stopPropagation();
    const caixa = folha.getBoundingClientRect();
    const c = elementos()[i];
    if (lado !== 'esq' && lado !== 'dir' && !(c.altura > 0)) {
        c.altura = arred(el.getBoundingClientRect().height / caixa.height * 100);
    }
    const mover = (e) => {
        const px = (e.clientX - caixa.left) / caixa.width * 100;
        const py = (e.clientY - caixa.top) / caixa.height * 100;
        if (lado !== 'cima' && lado !== 'baixo') c.largura = arred(limitar(Math.abs(px - c.x) * 2, 2, 100));
        if (lado !== 'esq' && lado !== 'dir') c.altura = arred(limitar(Math.abs(py - c.y) * 2, 2, 100));
        marcarAlterado();
        desenharPrevia();
    };
    const soltar = () => { window.removeEventListener('pointermove', mover); window.removeEventListener('pointerup', soltar); desenharLista(); };
    window.addEventListener('pointermove', mover);
    window.addEventListener('pointerup', soltar);
}

document.addEventListener('keydown', (e) => {
    const c = elementos()[selecionado];
    if (!c || ['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement.tagName)) return;
    const passo = e.shiftKey ? 1 : 0.1;
    const mapa = { ArrowLeft: ['x', -passo], ArrowRight: ['x', passo], ArrowUp: ['y', -passo], ArrowDown: ['y', passo] };
    if (!mapa[e.key]) return;
    e.preventDefault();
    c[mapa[e.key][0]] = arred(limitar(c[mapa[e.key][0]] + mapa[e.key][1], 0, 100));
    marcarAlterado(); desenharPrevia();
});

function alinharSelecionado(eixo) {
    const c = elementos()[selecionado];
    if (!c) return;
    c[eixo] = 50;
    marcarAlterado(); desenharPrevia([{ eixo: eixo === 'x' ? 'v' : 'h', pos: 50 }]);
    setTimeout(() => desenharPrevia(), 700);
    desenharLista();
}

// ---------------------------------------------------------------- lista
function resumo(c) {
    if (c.tipo === 'imagem') return '<i class="bi bi-image"></i> Imagem';
    if (c.tipo === 'qrcode') return '<i class="bi bi-qr-code"></i> QR Code de verificação';
    if (c.tipo === 'modulos') return '<i class="bi bi-list-ol"></i> Lista de módulos do curso';
    return '<i class="bi bi-fonts"></i> ' + escapar(c.texto.replace(/\n/g, ' '));
}

function camposTamanho(c, comAltura = true) {
    return `<div class="col-6"><label class="form-label small mb-0">Largura (%)</label><input type="number" min="2" max="100" step="0.5" class="form-control form-control-sm" data-k="largura" value="${c.largura}"></div>`
        + (comAltura ? `<div class="col-6"><label class="form-label small mb-0">Altura (%) <span class="text-muted">· 0 = automática</span></label><input type="number" min="0" max="100" step="0.5" class="form-control form-control-sm" data-k="altura" value="${c.altura || 0}"></div>` : '');
}

function camposEstilo(c, i) {
    return `
        <div class="col-4"><label class="form-label small mb-0">Tamanho da letra</label><input type="number" min="6" max="120" class="form-control form-control-sm" data-k="tamanho" value="${c.tamanho}"></div>
        <div class="col-4"><label class="form-label small mb-0">Cor</label><input type="color" class="form-control form-control-sm form-control-color w-100" data-k="cor" value="${c.cor}"></div>
        <div class="col-4 form-check mt-4 ps-4"><input type="checkbox" class="form-check-input" id="neg${i}" data-k="negrito" ${c.negrito ? 'checked' : ''}><label class="form-check-label small" for="neg${i}">Negrito</label></div>
        <div class="col-12"><label class="form-label small mb-0">Alinhamento do texto</label>
            <div class="btn-group btn-group-sm w-100">${Object.entries(ALINHAMENTOS).map(([v, r]) =>
                `<button type="button" class="btn ${c.alinhamento === v ? 'btn-brand' : 'btn-outline-secondary'}" data-alinhar="${v}" title="${r}"><i class="bi bi-text-${v === 'justify' ? 'paragraph' : v}"></i> ${r}</button>`).join('')}</div></div>`;
}

function desenharLista() {
    const lista = document.getElementById('lista-elementos');
    lista.innerHTML = '';
    if (!elementos().length) lista.innerHTML = '<div class="small text-muted mb-2">Esta folha está vazia. Use os botões acima para pôr texto, imagem, lista de módulos ou QR Code.</div>';
    elementos().forEach((c, i) => {
        const item = document.createElement('div');
        item.className = 'elemento' + (i === selecionado ? ' selecionado' : '');
        let corpo = '';
        if (c.tipo === 'texto') {
            corpo = `<textarea class="form-control form-control-sm mb-2" rows="2" data-k="texto">${escapar(c.texto)}</textarea>
                <div class="row g-2">${camposEstilo(c, i)}${camposTamanho(c)}
                    <div class="col-12 form-check ms-2"><input type="checkbox" class="form-check-input" id="mai${i}" data-k="maiusculo" ${c.maiusculo ? 'checked' : ''}><label class="form-check-label small" for="mai${i}">TUDO MAIÚSCULO</label></div>
                    <div class="col-12 form-check ms-2"><input type="checkbox" class="form-check-input" id="uma${i}" data-k="uma_linha" ${c.uma_linha ? 'checked' : ''}><label class="form-check-label small" for="uma${i}">Sempre em uma linha só <span class="text-muted">— se não couber, a letra diminui</span></label></div>
                    <div class="col-12 form-text mt-0">Com altura definida, se o texto não couber na caixa, a letra diminui até caber.</div>
                </div>`;
        } else if (c.tipo === 'modulos') {
            corpo = `<div class="row g-2">${camposEstilo(c, i)}${camposTamanho(c)}
                    <div class="col-4"><label class="form-label small mb-0">Colunas</label><select class="form-select form-select-sm" data-k="colunas">${[1, 2, 3].map((n) => `<option ${c.colunas === n ? 'selected' : ''}>${n}</option>`).join('')}</select></div>
                    <div class="col-8 form-check mt-4 ps-4"><input type="checkbox" class="form-check-input" id="num${i}" data-k="numerar" ${c.numerar ? 'checked' : ''}><label class="form-check-label small me-3" for="num${i}">Numerar</label>
                        <input type="checkbox" class="form-check-input" id="car${i}" data-k="mostrar_carga" ${c.mostrar_carga ? 'checked' : ''}><label class="form-check-label small" for="car${i}">Mostrar horas</label></div>
                    <div class="col-12 form-text mt-0">Mostra os módulos cadastrados no curso do aluno (aqui, um exemplo). Com altura definida, a letra diminui se a lista for grande.</div>
                </div>`;
        } else if (c.tipo === 'imagem') {
            corpo = `<div class="d-flex gap-2 align-items-center mb-2"><img src="${escapar(c.imagem)}" alt="" style="height: 48px; max-width: 120px; object-fit: contain; background: #f3f4f6; border-radius: 6px;">
                    <label class="btn btn-sm btn-outline-secondary mb-0">Trocar imagem<input type="file" accept="image/*" hidden data-trocar></label></div>
                <div class="row g-2">${camposTamanho(c)}</div>
                <div class="form-text">Altura 0 = proporcional à imagem. Para assinatura ou selo, use PNG com fundo transparente.</div>`;
        } else {
            corpo = `<div class="row g-2">${camposTamanho(c, false)}</div>
                <div class="form-text">Sempre quadrado. Leva ao link de verificação com o código deste certificado. Abaixo de 6% fica difícil de ler no papel.</div>`;
        }
        item.innerHTML = `<div class="cabeca"><span class="resumo">${resumo(c)}</span>
                <button type="button" class="btn btn-sm btn-link text-danger p-0" data-remover title="Remover"><i class="bi bi-trash"></i></button></div>
            <div class="corpo">${corpo}</div>`;
        item.querySelector('.cabeca').addEventListener('click', (e) => { if (!e.target.closest('[data-remover]')) selecionar(i); });
        item.querySelector('[data-remover]').addEventListener('click', () => {
            if (!confirm('Remover este elemento?')) return;
            elementos().splice(i, 1); selecionado = Math.min(selecionado, elementos().length - 1); marcarAlterado(); desenharTudo();
        });
        item.querySelectorAll('[data-k]').forEach((campo) => campo.addEventListener('input', () => {
            const k = campo.dataset.k;
            c[k] = campo.type === 'checkbox' ? campo.checked : (campo.type === 'number' || campo.tagName === 'SELECT' ? parseFloat(campo.value) || 0 : campo.value);
            if (k === 'texto') item.querySelector('.resumo').innerHTML = resumo(c);
            marcarAlterado(); desenharPrevia();
        }));
        item.querySelectorAll('[data-alinhar]').forEach((b) => b.addEventListener('click', () => { c.alinhamento = b.dataset.alinhar; marcarAlterado(); desenharTudo(); }));
        const trocar = item.querySelector('[data-trocar]');
        if (trocar) trocar.addEventListener('change', () => enviarArquivo(trocar, 'imagem').then((r) => { if (r) { c.imagem = r.url; marcarAlterado(); desenharTudo(); } }));
        lista.appendChild(item);
    });
}

function desenharTudo() { desenharAbas(); desenharFundos(); desenharLista(); desenharPrevia(); }
function selecionar(i) {
    if (selecionado === i) return;
    selecionado = i;
    desenharLista(); desenharPrevia();
    const item = document.querySelectorAll('#lista-elementos .elemento')[i];
    if (item) item.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
}

// ---------------------------------------------------------------- adicionar
function adicionar(elemento) { elementos().push(elemento); selecionado = elementos().length - 1; marcarAlterado(); desenharTudo(); }
function adicionarTexto() {
    adicionar({ tipo: 'texto', texto: 'Novo texto', x: 50, y: 80, largura: 50, altura: 0, tamanho: 16, cor: '#222222', negrito: false, alinhamento: 'center', uma_linha: false, maiusculo: false });
}
function adicionarModulos() {
    adicionar({ tipo: 'modulos', x: 50, y: 55, largura: 80, altura: 60, tamanho: 14, cor: '#222222', negrito: false, alinhamento: 'left', colunas: 1, numerar: true, mostrar_carga: true });
}
function adicionarQr() { adicionar({ tipo: 'qrcode', x: 90, y: 84, largura: 9, altura: 0 }); }
function adicionarImagem(input) {
    enviarArquivo(input, 'imagem').then((r) => { if (r) adicionar({ tipo: 'imagem', imagem: r.url, x: 50, y: 82, largura: 20, altura: 0 }); });
}

function inserirMarcador(marcador) {
    const area = document.querySelectorAll('#lista-elementos .elemento')[selecionado]?.querySelector('textarea');
    if (!area) { alert('Selecione um texto primeiro.'); return; }
    const pos = area.selectionStart ?? area.value.length;
    area.value = area.value.slice(0, pos) + marcador + area.value.slice(area.selectionEnd ?? pos);
    area.dispatchEvent(new Event('input'));
    area.focus();
}

// ---------------------------------------------------------------- arquivos e fundos
function enviarArquivo(input, tipo) {
    const arquivo = input.files[0];
    input.value = '';
    if (!arquivo) return Promise.resolve(null);
    const dados = new FormData();
    dados.append('acao', 'upload'); dados.append('modelo_id', MODELO_ID); dados.append('tipo', tipo); dados.append('arquivo', arquivo);
    return fetch('/configuracoes/certificado.php', { method: 'POST', body: dados, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then((r) => r.json()).then((r) => { if (!r.sucesso) { alert(r.mensagem); return null; } return r; })
        .catch(() => { alert('Não foi possível enviar a imagem.'); return null; });
}

function desenharFundos() {
    const area = document.getElementById('fundos');
    area.innerHTML = '';
    const atual = paginas[folhaAtual].fundo;
    [{ id: 0, nome: 'Sem fundo', url: null }, ...fundos].forEach((f) => {
        const el = document.createElement('div');
        el.className = 'fundo' + (atual === f.url ? ' escolhido' : '');
        el.innerHTML = `<div class="miniatura" ${f.url ? `style="background-image:url('${escapar(f.url)}')"` : ''}>${f.url ? '' : '<i class="bi bi-slash-circle"></i>'}</div>
            <div class="nome">${escapar(f.nome)}</div>${f.id ? '<button type="button" class="btn btn-sm btn-light excluir" title="Excluir este fundo"><i class="bi bi-x"></i></button>' : ''}`;
        el.addEventListener('click', (e) => {
            if (e.target.closest('.excluir')) return excluirFundo(f);
            paginas[folhaAtual].fundo = f.url; marcarAlterado(); desenharFundos(); desenharPrevia();
        });
        area.appendChild(el);
    });
}

function enviarFundo(input) {
    enviarArquivo(input, 'fundo').then((r) => {
        if (!r) return;
        fundos.unshift({ id: r.id, nome: r.nome, url: r.url });
        paginas[folhaAtual].fundo = r.url; marcarAlterado(); desenharFundos(); desenharPrevia();
    });
}

function excluirFundo(f) {
    if (paginas.some((p) => p.fundo === f.url)) { alert('Este fundo está escolhido numa folha deste modelo. Troque antes de excluir.'); return; }
    if (!confirm('Excluir o fundo "' + f.nome + '" deste modelo?')) return;
    fetch('/configuracoes/certificado.php', { method: 'POST', body: new URLSearchParams({ acao: 'excluir_fundo', modelo_id: MODELO_ID, id: f.id, url: f.url || '' }), headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then((r) => r.json()).then((r) => {
            if (!r.sucesso) { alert(r.mensagem); return; }
            fundos = fundos.filter((x) => x.id !== f.id); desenharFundos();
        });
}

// ---------------------------------------------------------------- salvar
function salvar() {
    const botao = document.getElementById('btn-salvar');
    botao.disabled = true;
    const dados = new URLSearchParams({ acao: 'salvar', modelo_id: MODELO_ID, nome: document.getElementById('nome-modelo').value.trim(), orientacao: document.getElementById('orientacao-modelo').value,
        paginas: JSON.stringify(paginas), url_verificacao: document.getElementById('url_verificacao').value.trim(), marca_dagua: JSON.stringify(valoresMarcaDagua()),
        fundos_novos: MODELO_ID === 'novo' ? JSON.stringify(fundos.map((f) => ({ nome: f.nome, url: f.url }))) : '[]' });
    fetch('/configuracoes/certificado.php', { method: 'POST', body: dados, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then((r) => r.json())
        .then((r) => {
            if (!r.sucesso) { alert(r.mensagem); return; }
            alterado = false;
            // Rascunho salvo: agora o modelo existe — abre a página dele.
            if (MODELO_ID === 'novo' && r.id) { rascunhoSalvo = true; window.location.href = '/configuracoes/certificado.php?modelo=' + r.id; return; }
            document.getElementById('aviso-alterado').classList.add('d-none');
            botao.innerHTML = '<i class="bi bi-check2-all"></i> Salvo!';
            setTimeout(() => { botao.innerHTML = '<i class="bi bi-check-lg"></i> Salvar'; }, 2000);
        })
        .catch(() => alert('Não foi possível salvar. Confira a conexão e tente de novo.'))
        .finally(() => { botao.disabled = false; });
}

desenharTudo();
atualizarExemploLink();
(document.fonts ? document.fonts.ready : Promise.resolve()).then(() => desenharPrevia());
</script>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
