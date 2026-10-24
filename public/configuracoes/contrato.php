<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirEditarDocumentos();

// Editor de um modelo de contrato (?modelo=ID ou ?modelo=novo). A lista
// dos modelos fica em Documentos (/configuracoes/certificado.php).
$pdo = getDB();
garantirContratoPadrao($pdo);
$param = (string) ($_GET['modelo'] ?? $_POST['modelo_id'] ?? '');
$ehNovo = $param === 'novo';
$modelo = $ehNovo ? ['id' => 0, 'nome' => 'Novo contrato', 'conteudo' => textoContratoPadrao(), 'pagina' => null, 'padrao' => 0] : buscarModeloContrato($pdo, (int) $param);
if (!$modelo) {
    flash('danger', 'Modelo de contrato não encontrado.');
    redirect('/configuracoes/certificado.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = input('acao');

    // Imagem solta na folha (logo, assinatura): sobe pela tela, sem recarregar.
    if ($acao === 'upload_imagem') {
        $upload = salvarImagemPortal($_FILES['arquivo'] ?? [], 'contrato', 5);
        responderJson($upload['ok'] ? ['sucesso' => true, 'url' => $upload['url']] : ['sucesso' => false, 'mensagem' => $upload['mensagem']]);
    }
    $nome = mb_substr(trim(input('nome')), 0, 100) ?: 'Contrato';
    $conteudo = limparHtmlContrato((string) ($_POST['conteudo'] ?? ''));

    // Pré-visualização com dados de exemplo (o que está na tela, sem salvar).
    $pagina = paginaContratoDoPost();
    if ($acao === 'previa') {
        // Em PDF, já dividido em páginas como vai sair; sem o PDF (servidor sem
        // Chrome), a página para imprimir.
        $assinaturasExemplo = '<div class="assinaturas"><div>Maria Aparecida da Silva Souza<br>Contratante</div><div>' . e(documentoDados($pdo)['nome']) . '<br>Contratada</div></div>';
        $voltar = '/configuracoes/contrato.php?modelo=' . ($ehNovo ? 'novo' : (int) $modelo['id']);
        ob_start();
        renderizarPaginaContrato($pdo, ['conteudo' => $conteudo, 'pagina' => $pagina], valoresExemploContrato($pdo), 'Pré-visualização · ' . $nome, '#', $assinaturasExemplo);
        $arquivo = pdfDocumentoEmitido('previa-contrato-' . (int) usuarioAtualId(), (string) ob_get_clean());
        if ($arquivo) {
            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="previa-contrato.pdf"');
            header('Content-Length: ' . filesize($arquivo));
            header('Cache-Control: private, no-store');
            readfile($arquivo);
        } else {
            renderizarPaginaContrato($pdo, ['conteudo' => $conteudo, 'pagina' => $pagina], valoresExemploContrato($pdo), 'Pré-visualização · ' . $nome, $voltar,
                $assinaturasExemplo, 'Pré-visualização com dados de exemplo (não foi possível gerar o PDF).');
        }
        exit;
    }

    if ($acao === 'salvar') {
        if ($ehNovo) {
            $pdo->prepare('INSERT INTO contrato_modelos (nome, conteudo, pagina) VALUES (?, ?, ?)')->execute([$nome, $conteudo, $pagina]);
            $id = (int) $pdo->lastInsertId();
            registrarAtividade('portal', 'criacao', 'Modelo de contrato criado: ' . $nome);
        } else {
            $id = (int) $modelo['id'];
            $pdo->prepare('UPDATE contrato_modelos SET nome = ?, conteudo = ?, pagina = ? WHERE id = ?')->execute([$nome, $conteudo, $pagina, $id]);
            registrarAtividade('portal', 'edicao', 'Modelo de contrato editado: ' . $nome);
        }
        flash('success', 'Contrato "' . $nome . '" salvo.');
        redirect('/configuracoes/contrato.php?modelo=' . $id);
    }

    if ($acao === 'duplicar' && !$ehNovo) {
        $pdo->prepare('INSERT INTO contrato_modelos (nome, conteudo, pagina) VALUES (?, ?, ?)')->execute([$modelo['nome'] . ' (cópia)', $modelo['conteudo'], $modelo['pagina']]);
        $id = (int) $pdo->lastInsertId();
        registrarAtividade('portal', 'criacao', 'Modelo de contrato criado: ' . $modelo['nome'] . ' (cópia)');
        flash('success', 'Cópia criada.');
        redirect('/configuracoes/contrato.php?modelo=' . $id);
    }

    if ($acao === 'tornar_padrao' && !$ehNovo) {
        $pdo->beginTransaction();
        $pdo->exec('UPDATE contrato_modelos SET padrao = 0');
        $pdo->prepare('UPDATE contrato_modelos SET padrao = 1 WHERE id = ?')->execute([$modelo['id']]);
        $pdo->commit();
        registrarAtividade('portal', 'edicao', 'Modelo de contrato padrão: ' . $modelo['nome']);
        flash('success', '"' . $modelo['nome'] . '" agora é o contrato padrão (vale para os cursos sem contrato escolhido).');
        redirect(input('voltar') === 'lista' ? '/configuracoes/certificado.php' : '/configuracoes/contrato.php?modelo=' . (int) $modelo['id']);
    }

    if ($acao === 'excluir' && !$ehNovo) {
        if ($modelo['padrao']) {
            flash('danger', 'O contrato padrão não pode ser excluído. Torne outro contrato padrão antes.');
        } else {
            // Os cursos que usavam este contrato passam ao padrão (ON DELETE SET NULL).
            $pdo->prepare('DELETE FROM contrato_modelos WHERE id = ?')->execute([$modelo['id']]);
            registrarAtividade('portal', 'exclusao', 'Modelo de contrato excluído: ' . $modelo['nome']);
            flash('success', 'Contrato "' . $modelo['nome'] . '" excluído. Os cursos que o usavam passaram para o contrato padrão.');
        }
        redirect('/configuracoes/certificado.php');
    }
}

$cursosDoModelo = '';
if (!$ehNovo) {
    $stmt = $pdo->prepare($modelo['padrao']
        ? 'SELECT GROUP_CONCAT(nome ORDER BY nome SEPARATOR ", ") FROM cursos WHERE contrato_modelo_id IS NULL OR contrato_modelo_id = ?'
        : 'SELECT GROUP_CONCAT(nome ORDER BY nome SEPARATOR ", ") FROM cursos WHERE contrato_modelo_id = ?');
    $stmt->execute([$modelo['id']]);
    $cursosDoModelo = (string) $stmt->fetchColumn();
}

$pageTitle = $ehNovo ? 'Novo contrato' : 'Contrato · ' . e($modelo['nome']);
$pageBreadcrumb = 'Secretaria · Documentos';
$activeMenu = 'certificado';
$pageAction = '<a href="/configuracoes/certificado.php" class="btn btn-outline-secondary" onclick="return confirmarSaidaContrato()"><i class="bi bi-arrow-left"></i> Modelos</a>'
    . '<button type="button" class="btn btn-outline-secondary" onclick="enviarContrato(\'previa\')"><i class="bi bi-eye"></i> Pré-visualizar</button>'
    . '<button type="button" class="btn btn-brand" onclick="enviarContrato(\'salvar\')"><i class="bi bi-check-lg"></i> Salvar</button>';
$pagina = paginaContrato($modelo['pagina'] ?? null);
$doc = documentoDados($pdo);
// O cabeçalho é sempre o texto do modelo; quem estava com "dados da
// instituição" já abre com esse texto pronto para ajustar.
$cabecalhoHtml = match ($pagina['cabecalho']) {
    'instituicao' => $pagina['cabecalho_html'] ?: textoCabecalhoInstituicao($doc),
    'nenhum' => '',
    default => $pagina['cabecalho_html'],
};
require __DIR__ . '/../../includes/layout_header.php';
?>
<link href="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.snow.css" rel="stylesheet">
<style>
    <?= cssContrato() ?>
    :root { --cor-doc: <?= e($doc['cor']) ?>; }
    /* Mesa cinza com a folha A4 em tamanho real (1 cm = 37,8 px). */
    .mesa-contrato { background: #dfe3e8; padding: 0 24px 24px 56px; border-radius: 10px; overflow-x: auto; }
    .topo-mesa { position: sticky; top: 0; z-index: 8; background: #dfe3e8; padding: 14px 0 10px; }
    .topo-mesa .ql-toolbar { background: #fff; border-radius: 8px; border: 1px solid #ccd; }
    .rotulo-barra { font-size: .75rem; color: var(--text-muted); margin: 0 0 3px 4px; }
    /* Régua como a do Word: cinza = margens; clique põe uma marca de tabulação. */
    .regua { position: relative; width: 794px; height: 26px; margin: 10px auto 0; background: #fff; border: 1px solid #c3cad3; user-select: none; font-family: Arial, sans-serif; }
    .regua-margem { position: absolute; top: 0; bottom: 0; background: #c9d0d8; }
    .regua-escala { position: absolute; inset: 0; cursor: copy; }
    .regua-risco { position: absolute; bottom: 0; width: 1px; background: #8a939e; pointer-events: none; }
    .regua-numero { position: absolute; top: 1px; font-size: 9px; color: #555; transform: translateX(-50%); pointer-events: none; }
    .alca-margem { position: absolute; top: 0; bottom: 0; width: 8px; margin-left: -4px; cursor: ew-resize; z-index: 3; }
    .alca-margem::after { content: ''; position: absolute; left: 3px; top: 0; bottom: 0; width: 2px; background: #6c7682; }
    .marca-tab { position: absolute; bottom: 1px; width: 10px; height: 10px; margin-left: -1px; border-left: 2px solid #111; border-bottom: 2px solid #111; cursor: ew-resize; z-index: 4; }
    .marca-tab.saindo { opacity: .3; }
    /* Páginas como no Word: uma folha branca por página, com o cabeçalho e o
       rodapé em cada uma; o texto pula para a folha seguinte quando enche
       (contrato-editor.js calcula, a mesma conta da impressão). */
    .folha-editor { position: relative; width: 794px; margin: 0 auto; font-family: Arial, Helvetica, sans-serif; }
    .fundo-pagina { position: absolute; left: 0; width: 794px; background: #fff; box-shadow: 0 2px 12px rgba(0,0,0,.15); z-index: 0; }
    .fundo-pagina .limite-margens { position: absolute; outline: 1px dashed #c3cad3; pointer-events: none; }
    .rotulo-pagina { position: absolute; right: -2px; top: -18px; font-size: 10px; color: #6c7682; }
    /* Régua vertical (margens de cima e de baixo), uma por página. */
    .regua-v { position: absolute; left: -32px; top: 0; width: 22px; background: #fff; border: 1px solid #c3cad3; user-select: none; }
    .regua-v .margem-v { position: absolute; left: 0; right: 0; background: #c9d0d8; }
    .regua-v .risco-v { position: absolute; right: 0; height: 1px; background: #8a939e; }
    .regua-v .numero-v { position: absolute; left: 2px; font-size: 8px; color: #555; transform: translateY(-50%); }
    .regua-v .alca-v { position: absolute; left: 0; right: 0; height: 8px; margin-top: -4px; cursor: ns-resize; z-index: 2; }
    .regua-v .alca-v::after { content: ''; position: absolute; left: 0; right: 0; top: 3px; height: 2px; background: #6c7682; }
    .area-folha { position: absolute; z-index: 2; }
    .area-folha .etiqueta-area { position: absolute; top: -18px; left: -2px; font-size: 10px; background: var(--accent); color: #fff; padding: 1px 6px; border-radius: 4px 4px 0 0; display: none; z-index: 2; white-space: nowrap; }
    /* Cabeçalho e rodapé: dois cliques para editar; o resto apaga. */
    .area-cabecalho, .copia-cabecalho { border-bottom: 1.5px solid var(--cor-doc); padding-bottom: 3mm; font-size: 11px; line-height: 1.35; min-height: 24px; }
    .area-rodape, .copia-rodape { border-top: 1px solid #ccc; padding-top: 2mm; font-size: 9.5px; line-height: 1.35; color: #555; }
    .area-cabecalho, .area-rodape, .copia-area { cursor: default; transition: opacity .15s; }
    .copia-area { position: absolute; z-index: 2; opacity: .55; }
    .copia-area p { margin: 0; }
    .folha-editor:not(.modo-cabecalho) .area-cabecalho, .folha-editor:not(.modo-rodape) .area-rodape { opacity: .55; }
    .folha-editor:not(.modo-cabecalho) .area-cabecalho:hover, .folha-editor:not(.modo-rodape) .area-rodape:hover, .copia-area:hover { opacity: .8; outline: 1px dashed var(--accent); outline-offset: 2px; }
    .folha-editor:not(.modo-cabecalho) .area-cabecalho:hover .etiqueta-area, .folha-editor:not(.modo-rodape) .area-rodape:hover .etiqueta-area,
    .folha-editor.modo-cabecalho .area-cabecalho .etiqueta-area, .folha-editor.modo-rodape .area-rodape .etiqueta-area { display: block; }
    .folha-editor.modo-cabecalho .area-cabecalho, .folha-editor.modo-rodape .area-rodape { outline: 1px dashed var(--accent); outline-offset: 2px; }
    .folha-editor.modo-cabecalho .area-corpo, .folha-editor.modo-rodape .area-corpo { opacity: .45; }
    .area-vazia { background: repeating-linear-gradient(45deg, transparent 0 6px, rgba(0,0,0,.025) 6px 12px); }
    .folha-editor .ql-container.ql-snow { border: 0; font-family: inherit; font-size: inherit; }
    .folha-editor .ql-editor { padding: 0; font-size: 12.5px; line-height: 1.35; overflow: visible; }
    .area-corpo .ql-editor { min-height: 60px; }
    .area-cabecalho .ql-editor, .area-rodape .ql-editor { min-height: 18px; font-size: inherit; line-height: inherit; }
    .folha-editor .ql-editor p { margin: 0 0 6pt; } .area-cabecalho .ql-editor p, .area-rodape .ql-editor p { margin: 0; }
    .folha-editor .ql-editor h2 { margin: .8em 0 .4em; font-weight: 700; } .folha-editor .ql-editor h1 { margin: .4em 0 .6em; font-weight: 700; }
    .folha-editor .ql-editor h1 { font-size: 17px; color: var(--cor-doc); } .folha-editor .ql-editor h2 { font-size: 14.5px; } .folha-editor .ql-editor h3 { font-size: 13px; }
    .folha-editor .ql-editor.ql-blank::before { left: 0; font-style: normal; color: #aab; }
    .numero-pagina-editor { float: right; color: #888; }
    /* Imagens soltas por cima do texto: arrastar e redimensionar pelo canto. */
    .camada-imagens { position: absolute; inset: 0; z-index: 5; pointer-events: none; }
    .img-solta { position: absolute; pointer-events: auto; cursor: move; outline: 1px dashed transparent; user-select: none; }
    .img-solta img { width: 100%; display: block; pointer-events: none; }
    .img-solta:hover, .img-solta.selecionada { outline-color: var(--accent); }
    .img-solta .alca-tamanho { position: absolute; right: -6px; bottom: -6px; width: 12px; height: 12px; background: var(--accent); border: 2px solid #fff; border-radius: 3px; cursor: nwse-resize; display: none; }
    .img-solta .opcoes-img { position: absolute; left: 0; top: -30px; display: none; gap: 8px; align-items: center; background: #fff; border: 1px solid #ccd; border-radius: 6px; padding: 3px 8px; font-size: 11px; white-space: nowrap; box-shadow: 0 2px 6px rgba(0,0,0,.12); cursor: default; }
    .img-solta.selecionada .alca-tamanho { display: block; } .img-solta.selecionada .opcoes-img { display: flex; }
    .variaveis-contrato { overflow: auto; min-height: 0; background: var(--surface, #fff); }
    .coluna-variaveis { position: sticky; top: 12px; max-height: calc(100vh - 24px); display: flex; flex-direction: column; background: var(--bg, #f5f6f8); z-index: 4; }
    .variaveis-contrato .grupo { font-size: .72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: var(--text-muted); margin: 12px 0 4px; }
    .variaveis-contrato button { display: block; width: 100%; text-align: left; border: 0; background: none; padding: 4px 6px; border-radius: 6px; font-size: .85rem; }
    .variaveis-contrato button:hover { background: var(--accent-soft); }
    .variaveis-contrato code { font-size: .78rem; }
    .campo-margem { width: 90px; }
    /* Nomes das fontes e da entrelinha nas listas da barra. */
    .topo-mesa .ql-picker.ql-font { width: 118px; }
    <?php foreach (fontesContrato() as $chaveFonte => [$nomeFonte, $familiaFonte]): ?>
    .topo-mesa .ql-picker.ql-font .ql-picker-label[data-value="<?= $chaveFonte ?>"]::before,
    .topo-mesa .ql-picker.ql-font .ql-picker-item[data-value="<?= $chaveFonte ?>"]::before { content: '<?= $nomeFonte ?>'; font-family: <?= $familiaFonte ?>; }
    <?php endforeach; ?>
    .topo-mesa .ql-picker.ql-font .ql-picker-label:not([data-value])::before,
    .topo-mesa .ql-picker.ql-font .ql-picker-item:not([data-value])::before { content: 'Arial (padrão)'; }
    .topo-mesa .ql-picker.ql-entrelinha { width: 100px; }
    .topo-mesa .ql-picker.ql-entrelinha .ql-picker-label::before { content: 'Entrelinha'; }
    <?php foreach (entrelinhasContrato() as $chaveLinha => [$rotuloLinha]): ?>
    .topo-mesa .ql-picker.ql-entrelinha .ql-picker-label[data-value="<?= $chaveLinha ?>"]::before,
    .topo-mesa .ql-picker.ql-entrelinha .ql-picker-item[data-value="<?= $chaveLinha ?>"]::before { content: '<?= $rotuloLinha ?>'; }
    <?php endforeach; ?>
    .topo-mesa .ql-picker.ql-entrelinha .ql-picker-item:not([data-value])::before { content: 'Normal (1,15)'; }
    .topo-mesa .ql-picker.ql-espaco { width: 128px; }
    .topo-mesa .ql-picker.ql-espaco .ql-picker-label::before { content: 'Espaço depois'; }
    <?php foreach (espacosParagrafoContrato() as $chaveEsp => [$rotuloEsp]): ?>
    .topo-mesa .ql-picker.ql-espaco .ql-picker-label[data-value="<?= $chaveEsp ?>"]::before,
    .topo-mesa .ql-picker.ql-espaco .ql-picker-item[data-value="<?= $chaveEsp ?>"]::before { content: 'Depois: <?= $rotuloEsp ?>'; }
    <?php endforeach; ?>
    .topo-mesa .ql-picker.ql-espaco .ql-picker-item:not([data-value])::before { content: 'Depois: Médio (6 pt)'; }
    .img-solta.copia { pointer-events: none; }
</style>

<form method="post" id="form-contrato" class="row g-3">
    <input type="hidden" name="modelo_id" value="<?= $ehNovo ? 'novo' : (int) $modelo['id'] ?>">
    <input type="hidden" name="acao" id="contrato-acao" value="salvar">
    <input type="hidden" name="conteudo" id="contrato-conteudo">
    <input type="hidden" name="cabecalho" value="personalizado">
    <input type="hidden" name="cabecalho_html" id="contrato-cabecalho">
    <input type="hidden" name="rodape_html" id="contrato-rodape">
    <input type="hidden" name="imagens_json" id="contrato-imagens">
    <input type="hidden" name="tabulacoes_json" id="contrato-tabulacoes">
    <div class="col-lg-9">
        <div class="d-flex flex-wrap align-items-end gap-2 mb-2">
            <div style="min-width: 280px; flex: 1;">
                <label class="form-label">Nome do contrato</label>
                <input type="text" name="nome" class="form-control" maxlength="100" required value="<?= e($modelo['nome']) ?>">
            </div>
            <?php if (!$ehNovo && $modelo['padrao']): ?><span class="badge text-bg-warning mb-2"><i class="bi bi-star-fill"></i> Padrão</span><?php endif; ?>
        </div>
        <?php if (!$ehNovo): ?>
            <div class="small text-muted mb-2"><?= $cursosDoModelo ? 'Usado nos cursos: ' . e($cursosDoModelo) : ($modelo['padrao'] ? 'Vale para os cursos sem contrato escolhido.' : 'Nenhum curso usa este contrato ainda — escolha-o no cadastro do curso.') ?></div>
        <?php endif; ?>

        <div class="secao mb-3">
            <div class="secao-titulo"><i class="bi bi-file-earmark-ruled"></i> Configurar página <span class="small text-muted fw-normal ms-1">(folha A4)</span></div>
            <div class="row g-3 align-items-end">
                <div class="col-auto">
                    <label class="form-label small mb-1">Margens (cm)</label>
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach (['superior' => 'Superior', 'inferior' => 'Inferior', 'esquerda' => 'Esquerda', 'direita' => 'Direita'] as $lado => $rotuloLado): ?>
                            <div><input type="number" name="margem_<?= $lado ?>" data-margem="<?= $lado ?>" class="form-control form-control-sm campo-margem" min="0.5" max="5" step="0.1"
                                        value="<?= e((string) $pagina['margens'][$lado]) ?>" oninput="atualizarMargens()"><div class="form-text mt-0"><?= $rotuloLado ?></div></div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="col-auto">
                    <label class="form-label small mb-1 d-block">Imagens</label>
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="document.getElementById('arquivo-imagem').click()"><i class="bi bi-image"></i> Imagem (logo, assinatura...)</button>
                    <input type="file" id="arquivo-imagem" accept="image/png,image/jpeg,image/webp,image/gif" class="d-none" onchange="enviarImagem(this)">
                </div>
                <div class="col-auto">
                    <div class="form-check form-switch mb-3">
                        <input type="checkbox" class="form-check-input" role="switch" name="numero_pagina" id="numero-pagina" <?= $pagina['numero_pagina'] ? 'checked' : '' ?> onchange="atualizarNumeroPagina()">
                        <label class="form-check-label small" for="numero-pagina">Número da página no rodapé</label>
                    </div>
                </div>
            </div>
        </div>

        <div class="mesa-contrato">
            <div class="topo-mesa">
                <div class="rotulo-barra" id="rotulo-barra">Editando: texto do contrato</div>
                <div id="barras"></div>
                <div class="regua" id="regua">
                    <div class="regua-margem" id="regua-margem-esq"></div>
                    <div class="regua-margem" id="regua-margem-dir"></div>
                    <div class="regua-escala" id="regua-escala"></div>
                    <div class="alca-margem" id="alca-esq" title="Margem esquerda"></div>
                    <div class="alca-margem" id="alca-dir" title="Margem direita"></div>
                </div>
            </div>
            <div class="folha-editor" id="folha">
                <div id="fundos"></div>
                <style id="estilo-paginacao"></style>
                <div class="camada-imagens" id="camada-imagens"></div>
                <div class="area-folha area-cabecalho" id="area-cabecalho" title="Dois cliques para editar o cabeçalho">
                    <span class="etiqueta-area">Cabeçalho — repete em todas as páginas</span>
                    <div id="editor-cabecalho"><?= $cabecalhoHtml ?></div>
                </div>
                <div class="area-folha area-corpo" id="area-corpo">
                    <div id="editor"><?= limparHtmlContrato((string) $modelo['conteudo']) ?></div>
                </div>
                <div class="area-folha area-rodape" id="area-rodape" title="Dois cliques para editar o rodapé">
                    <span class="etiqueta-area">Rodapé — repete em todas as páginas</span>
                    <span class="numero-pagina-editor" id="numero-pagina-editor">Página 1 de 1</span>
                    <div id="editor-rodape"><?= $pagina['rodape_html'] ?></div>
                </div>
            </div>
        </div>
        <div class="small text-muted mt-2">A linha tracejada mostra as margens. As páginas aqui são como vão sair na impressão (a divisão exata, confira no Pré-visualizar).</div>
    </div>
    <div class="col-lg-3">
        <div class="coluna-variaveis">
            <?php if (!$ehNovo): ?>
            <div class="d-flex flex-wrap gap-2 mb-3">
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="enviarContrato('duplicar')"><i class="bi bi-copy"></i> Duplicar</button>
                <?php if (!$modelo['padrao']): ?>
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="enviarContrato('tornar_padrao')"><i class="bi bi-star"></i> Tornar padrão</button>
                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="if (confirm('Excluir este contrato? Os cursos que o usam passam para o contrato padrão.')) enviarContrato('excluir')"><i class="bi bi-trash"></i> Excluir</button>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <div class="secao variaveis-contrato">
                <div class="secao-titulo"><i class="bi bi-braces"></i> Variáveis</div>
                <p class="small text-muted mb-1">Clique para pôr no texto, onde está o cursor.</p>
                <?php foreach (variaveisContrato() as $grupo => $variaveis): ?>
                    <div class="grupo"><?= e($grupo) ?></div>
                    <?php foreach ($variaveis as $variavel => $descricao): ?>
                        <button type="button" onclick="inserirVariavel(<?= e(json_encode($variavel)) ?>)" title="<?= e($variavel) ?>"><?= e($descricao) ?> <code><?= e($variavel) ?></code></button>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</form>

<script>
window.CONTRATO = {
    modeloId: <?= json_encode($ehNovo ? 'novo' : (string) (int) $modelo['id']) ?>,
    imagens: <?= json_encode($pagina['imagens'], JSON_UNESCAPED_SLASHES) ?>,
    tabulacoes: <?= json_encode($pagina['tabulacoes']) ?>,
    fontes: <?= json_encode(array_values(array_diff(array_keys(fontesContrato()), ['arial']))) ?>,
    entrelinhas: <?= json_encode(array_map('strval', array_keys(entrelinhasContrato()))) ?>,
    espacos: <?= json_encode(array_map('strval', array_keys(espacosParagrafoContrato()))) ?>,
};
</script>
<script src="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.js"></script>
<script src="<?= e(assetUrl('/assets/js/contrato-tabs.js')) ?>"></script>
<script src="<?= e(assetUrl('/assets/js/contrato-editor.js')) ?>"></script>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
