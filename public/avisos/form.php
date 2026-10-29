<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();
exigirPermissao('gerenciar_avisos', 'Seu perfil não pode enviar avisos aos alunos.');

// Novo aviso / editar aviso: título, conteúdo — arte livre tipo Canva
// (aviso-arte.js) ou texto com imagens e links —, formato (mural, janela ao
// entrar, faixa no topo), para quem, se repete em toda visita, período e se
// pede "Li e entendi". ?aluno=ID / ?turma=ID já abrem para eles.
$pdo = getDB();

// Imagem pelo botão do editor (JSON).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'upload_imagem') {
    $r = salvarImagemPortal($_FILES['arquivo'] ?? [], 'aviso', 5);
    responderJson(['sucesso' => $r['ok'], 'mensagem' => $r['mensagem'], 'url' => $r['url']]);
}

$id = (int) ($_GET['id'] ?? 0);
$aviso = ['titulo' => '', 'montagem' => 'arte', 'arte' => '', 'conteudo' => '', 'formato' => 'janela', 'cor' => 'azul', 'publico' => 'todos', 'quem_site' => 'todos',
    'repeticao' => 'uma_vez', 'paginas' => '', 'largura' => null, 'inicio' => date('Y-m-d H:i'), 'fim' => '', 'exige_confirmacao' => 0, 'ativo' => 1];
$destinos = [];
if ($id) {
    $stmt = $pdo->prepare('SELECT * FROM avisos WHERE id = ?');
    $stmt->execute([$id]);
    $aviso = $stmt->fetch();
    if (!$aviso) {
        flash('danger', 'Aviso não encontrado.');
        redirect('/avisos/listar.php');
    }
    $destinos = array_map(fn ($d) => (int) $d['id'], nomesDestinosAviso($pdo, $aviso));
} elseif (!empty($_GET['aluno'])) {
    $aviso['publico'] = 'aluno';
    $destinos = [(int) $_GET['aluno']];
} elseif (!empty($_GET['turma'])) {
    $aviso['publico'] = 'turma';
    $destinos = [(int) $_GET['turma']];
}

$erros = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dataHora = fn ($v) => preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', (string) $v) ? str_replace('T', ' ', $v) . ':00' : '';
    $arteEnviada = json_decode((string) ($_POST['arte'] ?? ''), true);
    $aviso = [
        'titulo' => mb_substr(input('titulo'), 0, 150),
        'montagem' => input('montagem') === 'texto' ? 'texto' : 'arte',
        'arte' => json_encode(normalizarArteAviso(is_array($arteEnviada) ? $arteEnviada : []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'conteudo' => limparHtmlContrato(salvarImagensColadasAviso((string) ($_POST['conteudo'] ?? '')), true),
        'formato' => array_key_exists(input('formato'), formatosAviso()) ? input('formato') : 'mural',
        'cor' => array_key_exists(input('cor'), coresAviso()) ? input('cor') : 'azul',
        'publico' => array_key_exists(input('publico'), publicosAviso()) ? input('publico') : 'todos',
        'quem_site' => array_key_exists(input('quem_site'), quemSiteAviso()) ? input('quem_site') : 'todos',
        'repeticao' => array_key_exists(input('repeticao'), repeticoesAviso()) ? input('repeticao') : 'uma_vez',
        'paginas' => implode(',', array_intersect(array_keys(paginasPortalAviso($pdo)), (array) ($_POST['paginas'] ?? []))),
        'largura' => isset($_POST['largura_auto']) ? null : max(280, min(1200, (int) ($_POST['largura'] ?? 540))),
        'inicio' => $dataHora($_POST['inicio'] ?? ''),
        'fim' => $dataHora($_POST['fim'] ?? ''),
        'exige_confirmacao' => isset($_POST['exige_confirmacao']) ? 1 : 0,
        'ativo' => isset($_POST['ativo']) ? 1 : 0,
    ];
    $destinos = in_array($aviso['publico'], ['site', 'todos'], true) ? [] : array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['destinos'] ?? [])))));

    if ($aviso['titulo'] === '') {
        $erros[] = 'Informe o título do aviso.';
    }
    if (!avisoTemConteudo($aviso) && $aviso['formato'] !== 'faixa') {
        $erros[] = $aviso['montagem'] === 'arte' ? 'Monte a arte do aviso (ou escolha um modelo pronto).' : 'Escreva o conteúdo do aviso.';
    }
    if ($aviso['inicio'] === '') {
        $erros[] = 'Informe quando o aviso começa a aparecer.';
    }
    if ($aviso['fim'] !== '' && $aviso['fim'] <= $aviso['inicio']) {
        $erros[] = 'O fim precisa ser depois do início.';
    }
    if (!in_array($aviso['publico'], ['site', 'todos'], true) && !$destinos) {
        $erros[] = match ($aviso['publico']) {
            'curso' => 'Escolha pelo menos um curso.',
            'turma' => 'Escolha pelo menos uma turma.',
            default => 'Escolha pelo menos um aluno.',
        };
    }

    if (!$erros) {
        $campos = [$aviso['titulo'], $aviso['montagem'], $aviso['arte'], $aviso['conteudo'] ?: null, $aviso['formato'], $aviso['cor'], $aviso['publico'],
            $aviso['quem_site'], $aviso['repeticao'], $aviso['paginas'] ?: null, $aviso['largura'], $aviso['inicio'], $aviso['fim'] ?: null,
            $aviso['exige_confirmacao'], $aviso['ativo']];
        $pdo->beginTransaction();
        if ($id) {
            $pdo->prepare('UPDATE avisos SET titulo=?, montagem=?, arte=?, conteudo=?, formato=?, cor=?, publico=?, quem_site=?, repeticao=?, paginas=?, largura=?, inicio=?, fim=?, exige_confirmacao=?, ativo=? WHERE id=?')
                ->execute([...$campos, $id]);
        } else {
            $pdo->prepare('INSERT INTO avisos (titulo, montagem, arte, conteudo, formato, cor, publico, quem_site, repeticao, paginas, largura, inicio, fim, exige_confirmacao, ativo, criado_por) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([...$campos, usuarioAtualNome()]);
            $id = (int) $pdo->lastInsertId();
        }
        $pdo->prepare('DELETE FROM aviso_destinos WHERE aviso_id = ?')->execute([$id]);
        $stmt = $pdo->prepare('INSERT INTO aviso_destinos (aviso_id, ref_id) VALUES (?, ?)');
        foreach ($destinos as $ref) {
            $stmt->execute([$id, $ref]);
        }
        $pdo->commit();
        registrarAtividade('avisos', isset($_GET['id']) ? 'edicao' : 'criacao', 'Aviso "' . $aviso['titulo'] . '" (' . formatosAviso()[$aviso['formato']][0] . ')');
        flash('success', 'Aviso salvo.');
        redirect('/avisos/ver.php?id=' . $id);
    }
}

// Opções da escolha de destinatários (busca na própria página).
$opcoes = [
    'curso' => array_map(fn ($c) => ['id' => (int) $c['id'], 'nome' => $c['nome'], 'detalhe' => $c['ativo'] ? 'Curso' : 'Curso inativo'],
        $pdo->query('SELECT id, nome, ativo FROM cursos ORDER BY ativo DESC, nome')->fetchAll()),
    'turma' => array_map(fn ($t) => ['id' => (int) $t['id'], 'nome' => $t['curso_nome'] . ' · ' . $t['nome'], 'detalhe' => (int) $t['alunos'] . ' aluno(s) em andamento'],
        $pdo->query("SELECT t.id, t.nome, c.nome AS curso_nome, (SELECT COUNT(*) FROM matriculas m WHERE m.turma_id = t.id AND m.status IN ('ativa', 'pendente')) AS alunos
                     FROM turmas t JOIN cursos c ON c.id = t.curso_id ORDER BY t.data_inicio IS NULL, t.data_inicio DESC, c.nome, t.nome")->fetchAll()),
    'aluno' => array_map(fn ($a) => ['id' => (int) $a['id'], 'nome' => $a['nome'], 'detalhe' => $a['cpf'] ? 'CPF ' . cpfFormatado($a['cpf']) : 'Sem CPF', 'cpf' => (string) $a['cpf']],
        $pdo->query('SELECT id, nome, cpf FROM alunos ORDER BY nome')->fetchAll()),
];
$paraInput = fn ($v) => $v ? str_replace(' ', 'T', substr((string) $v, 0, 16)) : '';

$pageTitle = $id ? 'Editar aviso' : 'Novo aviso';
$pageBreadcrumb = 'Portal · Avisos';
$activeMenu = 'avisos';
$pageAction = '<a href="' . ($id ? '/avisos/ver.php?id=' . $id : '/avisos/listar.php') . '" class="btn btn-outline-secondary">Cancelar</a>'
    . '<button type="button" class="btn btn-outline-secondary" onclick="previaAviso()"><i class="bi bi-eye"></i> Pré-visualizar</button>'
    . '<button type="submit" form="form-aviso" class="btn btn-brand"><i class="bi bi-check-lg"></i> Salvar</button>';
require __DIR__ . '/../../includes/layout_header.php';
?>
<link href="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.snow.css" rel="stylesheet">
<link href="<?= e(urlFontesArteAviso()) ?>" rel="stylesheet">
<link href="<?= e(assetUrl('/assets/css/aviso-arte.css')) ?>" rel="stylesheet">
<style>
    /* Editor da arte */
    .arte-barra, .arte-contexto { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; }
    .arte-barra { margin-bottom: 8px; }
    .arte-contexto { min-height: 46px; padding: 6px 8px; border: 1px solid var(--bs-border-color); border-radius: 10px; background: var(--bs-body-bg); margin-bottom: 8px; }
    .arte-sep { width: 1px; align-self: stretch; background: var(--bs-border-color); margin: 0 2px; }
    .arte-cor { display: inline-flex; align-items: center; gap: 4px; font-size: 12px; margin: 0; cursor: pointer; border: 1px solid var(--bs-border-color); border-radius: 6px; padding: 2px 6px 2px 2px; }
    .arte-cor input { width: 26px; height: 24px; border: 0; padding: 0; background: none; cursor: pointer; }
    .arte-faixa { display: inline-flex; align-items: center; gap: 4px; font-size: 12px; margin: 0; color: var(--bs-secondary-color); }
    .arte-faixa input { width: 80px; }
    .arte-palco { position: relative; z-index: 0; overflow: hidden; background: repeating-conic-gradient(#e5e7eb 0% 25%, #f3f4f6 0% 50%) 50% / 20px 20px; border-radius: 12px; padding: 44px 24px 24px; display: flex; justify-content: center; align-items: flex-start; min-height: 300px; }
    .arte-palco.soltando { outline: 3px dashed var(--accent); outline-offset: -8px; }
    .arte-folha-editor { box-shadow: 0 6px 30px rgba(0,0,0,.18); }
    .arte-folha-editor .arte-aviso { overflow: visible; }
    .arte-folha-editor .arte-aviso::after { content: ''; position: absolute; inset: 0; pointer-events: none; box-shadow: 0 0 0 9999px rgba(243,244,246,.72); z-index: 30; }
    .arte-folha-editor .arte-el { cursor: move; touch-action: none; user-select: none; }
    .arte-folha-editor .arte-el:hover { outline: 1px solid rgba(37, 99, 235, .6); }
    .arte-folha-editor .arte-el.sel { outline: 2px solid #2563eb; z-index: 40; }
    .arte-folha-editor .arte-el.editando { cursor: text; user-select: text; }
    .arte-folha-editor .arte-el.editando .arte-in { outline: none; cursor: text; }
    .arte-alca { position: absolute; width: 12px; height: 12px; background: #fff; border: 2px solid #2563eb; border-radius: 50%; z-index: 41; }
    .a-nw { left: -7px; top: -7px; cursor: nwse-resize; } .a-se { right: -7px; bottom: -7px; cursor: nwse-resize; }
    .a-ne { right: -7px; top: -7px; cursor: nesw-resize; } .a-sw { left: -7px; bottom: -7px; cursor: nesw-resize; }
    .a-n, .a-s { left: 50%; margin-left: -9px; width: 18px; height: 8px; border-radius: 4px; cursor: ns-resize; } .a-n { top: -5px; } .a-s { bottom: -5px; }
    .a-e, .a-w { top: 50%; margin-top: -9px; width: 8px; height: 18px; border-radius: 4px; cursor: ew-resize; } .a-e { right: -5px; } .a-w { left: -5px; }
    .a-giro { left: 50%; top: -34px; margin-left: -12px; width: 24px; height: 24px; display: flex; align-items: center; justify-content: center; font-size: 12px; color: #2563eb; cursor: grab; }
    .arte-guia { position: absolute; z-index: 45; pointer-events: none; border-color: #e83e8c; }
    .arte-guia.v { top: -100%; bottom: -100%; border-left: 1px solid #e83e8c; }
    .arte-guia.h { left: -100%; right: -100%; border-top: 1px solid #e83e8c; }
    .montagem-abas .nav-link { cursor: pointer; }
    .paginas-aviso { display: grid; grid-template-columns: 1fr 1fr; gap: 2px 10px; max-height: 220px; overflow-y: auto; }
    #editor-aviso { min-height: 260px; font-size: 15px; background: #fff; }
    #editor-aviso .ql-editor { min-height: 260px; }
    #editor-aviso img { max-width: 100%; height: auto; }
    .formato-opcao { border: 1px solid var(--bs-border-color); border-radius: 10px; padding: 10px 12px; cursor: pointer; height: 100%; display: block; }
    .formato-opcao:has(input:checked) { border-color: var(--accent); background: var(--accent-soft, #f0fdf4); box-shadow: 0 0 0 1px var(--accent); }
    .formato-opcao input { display: none; }
    .cor-opcao { display: inline-flex; align-items: center; gap: 6px; border: 1px solid var(--bs-border-color); border-radius: 20px; padding: 4px 12px 4px 6px; cursor: pointer; font-size: 13px; }
    .cor-opcao input { display: none; }
    .cor-opcao .bolinha { width: 18px; height: 18px; border-radius: 50%; }
    .cor-opcao:has(input:checked) { border-color: #333; font-weight: 600; }
    .destino-chip { display: inline-flex; align-items: center; gap: 6px; background: var(--accent-soft, #eef2ff); border: 1px solid var(--bs-border-color); border-radius: 16px; padding: 3px 6px 3px 12px; font-size: 13px; margin: 0 6px 6px 0; }
    .destino-chip button { border: 0; background: none; padding: 0 4px; line-height: 1; color: #666; }
    .previa-arte { margin: 0 auto; }
    .previa-faixa { border-radius: 8px; padding: 10px 14px; color: #fff; display: flex; gap: 10px; align-items: center; }
    .previa-conteudo img { max-width: 100%; height: auto; }
    .previa-conteudo .ql-size-small { font-size: .8em; } .previa-conteudo .ql-size-large { font-size: 1.4em; } .previa-conteudo .ql-size-huge { font-size: 2em; }
    .previa-conteudo .ql-align-center { text-align: center; } .previa-conteudo .ql-align-right { text-align: right; } .previa-conteudo .ql-align-justify { text-align: justify; }
</style>

<?php if ($erros): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($erros as $erro): ?><li><?= e($erro) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" id="form-aviso" onsubmit="return prepararEnvio()">
<input type="hidden" name="conteudo" id="aviso-conteudo">
<input type="hidden" name="arte" id="aviso-arte">
<input type="hidden" name="montagem" id="aviso-montagem" value="<?= e($aviso['montagem']) ?>">
<div class="row g-3">
    <div class="col-xl-8">
        <div class="secao">
            <div class="secao-titulo"><i class="bi bi-megaphone"></i> Aviso</div>
            <div class="mb-3">
                <label class="form-label">Título *</label>
                <input type="text" name="titulo" id="aviso-titulo" class="form-control" maxlength="150" required value="<?= e($aviso['titulo']) ?>" placeholder="Ex.: Black Friday — até 50% off">
                <div class="form-text">Aparece na faixa, no mural e na lista. Na janela com arte, só a arte aparece.</div>
            </div>
            <ul class="nav nav-tabs montagem-abas mb-3">
                <li class="nav-item"><a class="nav-link" data-montagem="arte"><i class="bi bi-palette"></i> Arte livre (tipo Canva)</a></li>
                <li class="nav-item"><a class="nav-link" data-montagem="texto"><i class="bi bi-text-paragraph"></i> Só texto</a></li>
            </ul>
            <div id="montagem-arte"><div id="editor-arte"></div></div>
            <div id="montagem-texto">
                <div id="editor-aviso"><?= htmlAviso($aviso['conteudo']) ?></div>
                <div class="form-text">Escreva, mude a cor e o tamanho, coloque links (<i class="bi bi-link-45deg"></i>) e imagens (<i class="bi bi-image"></i>, até 5 MB; também dá para colar uma imagem).</div>
            </div>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="secao">
            <div class="secao-titulo"><i class="bi bi-people"></i> Para quem</div>
            <select name="publico" id="aviso-publico" class="form-select mb-2" onchange="trocarPublico()">
                <?php foreach (publicosAviso() as $chave => $rotulo): ?>
                    <option value="<?= $chave ?>" <?= $aviso['publico'] === $chave ? 'selected' : '' ?>><?= e($rotulo) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="quem_site" id="aviso-quem-site" class="form-select mb-2">
                <?php foreach (quemSiteAviso() as $chave => $rotulo): ?>
                    <option value="<?= $chave ?>" <?= $aviso['quem_site'] === $chave ? 'selected' : '' ?>><?= e($rotulo) ?></option>
                <?php endforeach; ?>
            </select>
            <div id="destinos-area">
                <div class="typeahead mb-2">
                    <input type="search" id="destino-busca" class="form-control" autocomplete="off">
                    <div class="typeahead-lista d-none" id="destino-lista"></div>
                </div>
                <div id="destino-chips"></div>
            </div>
            <div class="form-text" id="publico-ajuda"></div>
        </div>

        <div class="secao">
            <div class="secao-titulo"><i class="bi bi-window"></i> Formato</div>
            <div class="d-grid gap-2 mb-3">
                <?php foreach (formatosAviso() as $chave => [$rotulo, $ajuda, $icone]): ?>
                    <label class="formato-opcao">
                        <input type="radio" name="formato" value="<?= $chave ?>" <?= $aviso['formato'] === $chave ? 'checked' : '' ?>>
                        <div class="fw-semibold"><i class="bi <?= $icone ?>"></i> <?= e($rotulo) ?></div>
                        <div class="small text-muted"><?= e($ajuda) ?></div>
                    </label>
                <?php endforeach; ?>
            </div>
            <label class="form-label">Tamanho na tela <span class="text-muted small">(janela e mural)</span></label>
            <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                <div class="btn-group btn-group-sm">
                    <?php foreach (['Pequeno' => 380, 'Médio' => 540, 'Grande' => 800] as $rotulo => $px): ?>
                        <button type="button" class="btn btn-outline-secondary" onclick="definirLargura(<?= $px ?>)"><?= $rotulo ?></button>
                    <?php endforeach; ?>
                </div>
                <div class="form-check mb-0"><input class="form-check-input" type="checkbox" name="largura_auto" id="largura-auto" <?= $aviso['largura'] ? '' : 'checked' ?> onchange="mostrarLargura()"><label class="form-check-label small" for="largura-auto">Automático</label></div>
            </div>
            <div class="d-flex align-items-center gap-2 mb-3" id="largura-faixa">
                <input type="range" class="form-range" name="largura" id="largura" min="280" max="1200" step="10" value="<?= (int) ($aviso['largura'] ?: 540) ?>" oninput="mostrarLargura()">
                <span class="small text-nowrap" id="largura-valor"></span>
            </div>
            <label class="form-label">Cor <span class="text-muted small">(da faixa e da moldura)</span></label>
            <div class="d-flex flex-wrap gap-2 mb-3">
                <?php foreach (coresAviso() as $chave => [$rotulo, $cor]): ?>
                    <label class="cor-opcao"><input type="radio" name="cor" value="<?= $chave ?>" <?= $aviso['cor'] === $chave ? 'checked' : '' ?>><span class="bolinha" style="background: <?= $cor ?>"></span><?= e($rotulo) ?></label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="secao">
            <div class="secao-titulo"><i class="bi bi-arrow-repeat"></i> Quantas vezes aparece</div>
            <div class="d-grid gap-2 mb-3">
                <?php foreach (repeticoesAviso() as $chave => [$rotulo, $ajuda]): ?>
                    <label class="formato-opcao">
                        <input type="radio" name="repeticao" value="<?= $chave ?>" <?= $aviso['repeticao'] === $chave ? 'checked' : '' ?>>
                        <div class="fw-semibold"><?= e($rotulo) ?></div>
                        <div class="small text-muted"><?= e($ajuda) ?></div>
                    </label>
                <?php endforeach; ?>
            </div>
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="exige_confirmacao" id="aviso-confirmacao" <?= $aviso['exige_confirmacao'] ? 'checked' : '' ?>>
                <label class="form-check-label" for="aviso-confirmacao">Pedir "Li e entendi"</label>
                <div class="form-text mt-0">Para o aluno logado: o aviso continua aparecendo até ele confirmar, e você vê quem confirmou. Visitantes sem login não confirmam.</div>
            </div>
        </div>

        <div class="secao">
            <div class="secao-titulo"><i class="bi bi-files"></i> Em quais páginas aparece</div>
            <div class="paginas-aviso">
                <?php $paginasMarcadas = paginasDoAviso($aviso); foreach (paginasPortalAviso($pdo) as $chave => $rotulo): ?>
                    <div class="form-check"><input class="form-check-input" type="checkbox" name="paginas[]" value="<?= e($chave) ?>" id="pg-<?= e($chave) ?>" <?= in_array($chave, $paginasMarcadas, true) ? 'checked' : '' ?>>
                        <label class="form-check-label small" for="pg-<?= e($chave) ?>"><?= e($rotulo) ?></label></div>
                <?php endforeach; ?>
            </div>
            <div class="form-text">Nenhuma marcada = <strong>todas as páginas</strong>. Vale para a janela e a faixa; o aviso também fica sempre em "Meus avisos" do aluno.</div>
        </div>

        <div class="secao">
            <div class="secao-titulo"><i class="bi bi-calendar-range"></i> Período</div>
            <div class="mb-2">
                <label class="form-label">Aparece a partir de *</label>
                <input type="datetime-local" name="inicio" class="form-control" required value="<?= e($paraInput($aviso['inicio'])) ?>">
            </div>
            <div class="mb-3">
                <label class="form-label">Até (opcional)</label>
                <input type="datetime-local" name="fim" class="form-control" value="<?= e($paraInput($aviso['fim'])) ?>">
                <div class="form-text">Em branco: fica no ar até você desligar.</div>
            </div>
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="ativo" id="aviso-ativo" <?= $aviso['ativo'] ? 'checked' : '' ?>>
                <label class="form-check-label" for="aviso-ativo">Ligado</label>
            </div>
        </div>
    </div>
</div>
</form>

<div class="modal fade" id="modal-previa" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title">Como o aluno vê</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body bg-light" id="previa-corpo"></div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../../includes/modal_confirmar.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.js"></script>
<script src="<?= e(assetUrl('/assets/js/aviso-arte.js')) ?>"></script>
<script>
const CORES_AVISO = <?= json_encode(array_map(fn ($c) => ['forte' => $c[1], 'fundo' => $c[2]], coresAviso())) ?>;
const OPCOES_DESTINO = <?= json_encode($opcoes, JSON_UNESCAPED_UNICODE) ?>;
const AJUDA_PUBLICO = {
    site: 'Qualquer pessoa que abrir o portal. Escolha acima se vale para todos, só para visitantes sem login ou só para alunos logados (inclusive quem tem cadastro mas não está em nenhuma turma).',
    todos: 'Todos os alunos com matrícula ativa ou pendente. Quem entrar numa turma depois também passa a ver.',
    curso: 'Alunos com matrícula ativa ou pendente em qualquer turma do(s) curso(s).',
    turma: 'Alunos com matrícula ativa ou pendente na(s) turma(s).',
    aluno: 'Só os alunos escolhidos.',
};
const ROTULO_BUSCA = { curso: 'Procurar curso...', turma: 'Procurar turma ou curso...', aluno: 'Procurar aluno pelo nome ou CPF...' };
let escolhidos = { site: [], todos: [], curso: [], turma: [], aluno: [] };
escolhidos[<?= json_encode($aviso['publico']) ?>] = <?= json_encode(array_values($destinos)) ?>;

// ---- Editor ----
const quill = new Quill('#editor-aviso', {
    theme: 'snow',
    placeholder: 'Escreva o aviso...',
    modules: {
        toolbar: {
            container: [
                [{ header: [1, 2, 3, false] }, { size: ['small', false, 'large', 'huge'] }],
                ['bold', 'italic', 'underline', 'strike'],
                [{ color: [] }, { background: [] }],
                [{ align: [] }, { list: 'ordered' }, { list: 'bullet' }],
                ['link', 'image', 'clean'],
            ],
            handlers: { image: escolherImagem },
        },
    },
});
function escolherImagem() {
    const campo = document.createElement('input');
    campo.type = 'file';
    campo.accept = 'image/png,image/jpeg,image/webp,image/gif';
    campo.onchange = async () => {
        if (!campo.files.length) return;
        const dados = new FormData();
        dados.append('acao', 'upload_imagem');
        dados.append('arquivo', campo.files[0]);
        const posicao = (quill.getSelection(true) || { index: quill.getLength() }).index;
        try {
            const r = await (await fetch('/avisos/form.php', { method: 'POST', body: dados })).json();
            if (!r.sucesso) { mostrarErroEditor(r.mensagem || 'Não foi possível enviar a imagem.'); return; }
            quill.insertEmbed(posicao, 'image', r.url, 'user');
            quill.setSelection(posicao + 1, 0, 'user');
        } catch (e) {
            mostrarErroEditor('Não foi possível enviar a imagem.');
        }
    };
    campo.click();
}
function mostrarErroEditor(texto, depoisDe) {
    const aviso = document.createElement('div');
    aviso.className = 'alert alert-danger py-2 mt-2';
    aviso.textContent = texto;
    (depoisDe || document.getElementById('editor-aviso')).after(aviso);
    setTimeout(() => aviso.remove(), 6000);
}
const htmlEditor = () => (quill.getLength() > 1 || quill.root.querySelector('img') ? quill.getSemanticHTML() : '');

// ---- Para quem: busca + etiquetas ----
const campoBusca = document.getElementById('destino-busca');
const listaBusca = document.getElementById('destino-lista');
const publico = () => document.getElementById('aviso-publico').value;
const semAcento = (t) => String(t || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
const escapar = (t) => String(t ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

function trocarPublico() {
    const p = publico();
    document.getElementById('destinos-area').style.display = p === 'todos' || p === 'site' ? 'none' : '';
    document.getElementById('aviso-quem-site').style.display = p === 'site' ? '' : 'none';
    document.getElementById('publico-ajuda').textContent = AJUDA_PUBLICO[p];
    campoBusca.placeholder = ROTULO_BUSCA[p] || '';
    campoBusca.value = '';
    listaBusca.classList.add('d-none');
    desenharChips();
}
function desenharChips() {
    const p = publico();
    const area = document.getElementById('destino-chips');
    if (p === 'todos' || p === 'site') { area.innerHTML = ''; return; }
    area.innerHTML = escolhidos[p].map((id) => {
        const item = OPCOES_DESTINO[p].find((o) => o.id === id);
        return `<span class="destino-chip">${escapar(item ? item.nome : '#' + id)}<input type="hidden" name="destinos[]" value="${id}">`
            + `<button type="button" title="Tirar" onclick="tirarDestino(${id})"><i class="bi bi-x-lg"></i></button></span>`;
    }).join('') || '<div class="small text-muted">Nenhum escolhido ainda.</div>';
}
window.tirarDestino = (id) => { const p = publico(); escolhidos[p] = escolhidos[p].filter((x) => x !== id); desenharChips(); };
function listarBusca() {
    const p = publico();
    const termo = semAcento(campoBusca.value.trim());
    const digitos = campoBusca.value.replace(/\D/g, '');
    const achados = OPCOES_DESTINO[p].filter((o) => !escolhidos[p].includes(o.id)
        && (!termo || semAcento(o.nome).includes(termo) || (digitos.length >= 3 && String(o.cpf || '').includes(digitos)))).slice(0, 12);
    listaBusca.innerHTML = achados.map((o) => `<button type="button" class="typeahead-item" data-id="${o.id}"><span class="typeahead-nome">${escapar(o.nome)}</span><span class="typeahead-meta">${escapar(o.detalhe)}</span></button>`).join('')
        || '<div class="typeahead-vazio">Nada encontrado.</div>';
    listaBusca.classList.remove('d-none');
}
campoBusca.addEventListener('input', listarBusca);
campoBusca.addEventListener('focus', listarBusca);
listaBusca.addEventListener('click', (ev) => {
    const botao = ev.target.closest('.typeahead-item');
    if (!botao) return;
    escolhidos[publico()].push(Number(botao.dataset.id));
    campoBusca.value = '';
    desenharChips();
    listarBusca();
    campoBusca.focus();
});
document.addEventListener('click', (ev) => { if (!ev.target.closest('.typeahead')) listaBusca.classList.add('d-none'); });
trocarPublico();

// ---- Arte (tipo Canva) ou só texto ----
let alterado = false;
const editorArte = iniciarEditorArte({
    raiz: document.getElementById('editor-arte'),
    arte: <?= json_encode(arteAviso($aviso), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    proporcoes: <?= json_encode(array_map(fn ($p) => $p[0], proporcoesArteAviso()), JSON_UNESCAPED_UNICODE) ?>,
    urlUpload: '/avisos/form.php',
    aoMudar: () => { alterado = true; },
    erro: (texto) => mostrarErroEditor(texto, document.getElementById('editor-arte')),
    confirmar: (texto) => confirmarNoModal({ titulo: 'Usar modelo pronto', html: texto, botao: 'Usar o modelo' }),
});
function trocarMontagem(m) {
    document.getElementById('aviso-montagem').value = m;
    document.querySelectorAll('[data-montagem]').forEach((a) => a.classList.toggle('active', a.dataset.montagem === m));
    document.getElementById('montagem-arte').style.display = m === 'arte' ? '' : 'none';
    document.getElementById('montagem-texto').style.display = m === 'texto' ? '' : 'none';
    if (m === 'arte') editorArte.redesenhar();
}
document.querySelectorAll('[data-montagem]').forEach((a) => a.addEventListener('click', () => trocarMontagem(a.dataset.montagem)));
trocarMontagem(<?= json_encode($aviso['montagem']) ?>);
const montagem = () => document.getElementById('aviso-montagem').value;

// ---- Tamanho na tela ----
function mostrarLargura() {
    const auto = document.getElementById('largura-auto').checked;
    document.getElementById('largura-faixa').style.opacity = auto ? 0.4 : 1;
    document.getElementById('largura-valor').textContent = auto ? 'automático' : document.getElementById('largura').value + ' px';
}
function definirLargura(px) {
    document.getElementById('largura-auto').checked = false;
    document.getElementById('largura').value = px;
    mostrarLargura();
    alterado = true;
}
mostrarLargura();
const larguraEscolhida = (padrao) => (document.getElementById('largura-auto').checked ? padrao : Number(document.getElementById('largura').value));

// ---- Salvar e pré-visualizar ----
quill.on('text-change', () => { alterado = true; });
document.getElementById('form-aviso').addEventListener('change', () => { alterado = true; });
window.addEventListener('beforeunload', (ev) => { if (alterado) { ev.preventDefault(); ev.returnValue = ''; } });
function prepararEnvio() {
    document.getElementById('aviso-conteudo').value = htmlEditor();
    document.getElementById('aviso-arte').value = JSON.stringify(editorArte.obter());
    alterado = false;
    return true;
}
function previaAviso() {
    const formato = document.querySelector('[name=formato]:checked').value;
    const cor = CORES_AVISO[document.querySelector('[name=cor]:checked').value];
    const titulo = escapar(document.getElementById('aviso-titulo').value || 'Sem título');
    const confirmar = document.getElementById('aviso-confirmacao').checked
        ? `<div class="text-end mt-3"><button type="button" class="btn btn-sm text-white" style="background:${cor.forte}">Li e entendi</button></div>` : '';
    const ehArte = montagem() === 'arte';
    const arte = editorArte.obter();
    const largura = larguraEscolhida(ehArte ? { '1:1': 540, '4:5': 500, '9:16': 400, '16:9': 800, '3:1': 960 }[arte.proporcao] : 800);
    const conteudo = ehArte ? `<div class="previa-arte" style="max-width: ${largura}px">${htmlArteAviso(arte)}</div>` : `<div class="previa-conteudo">${htmlEditor()}</div>`;
    // Como abre (janela, ao clicar na faixa ou no item do mural), no tamanho escolhido.
    const cartao = ehArte
        ? `<div class="bg-white rounded overflow-hidden mx-auto shadow" style="max-width: ${largura}px">${htmlArteAviso(arte)}${confirmar ? '<div class="p-2">' + confirmar + '</div>' : ''}</div>`
        : `<div class="card border-0 shadow mx-auto" style="max-width: ${largura}px; border-top: 5px solid ${cor.forte} !important;"><div class="card-body">`
          + `<h5 style="color:${cor.forte}">${titulo}</h5>${conteudo}${confirmar}</div></div>`;
    const fundoEscuro = (h) => `<div class="p-3 rounded" style="background: rgba(0,0,0,.45)">${h}</div>`;
    let html = '';
    if (formato === 'faixa') {
        html = `<div class="small text-muted mb-1">No alto das páginas:</div>`
            + `<div class="previa-faixa mb-4" style="background:${cor.forte}"><i class="bi bi-megaphone"></i><strong>${titulo}</strong><a class="ms-auto text-white small" href="#">ver aviso</a><i class="bi bi-x-lg"></i></div>`
            + `<div class="small text-muted mb-1">Ao clicar em "ver aviso":</div>${fundoEscuro(cartao)}`;
    } else if (formato === 'janela') {
        html = `<div class="small text-muted mb-1">Abre por cima da tela:</div>${fundoEscuro(cartao)}`;
    } else {
        html = `<div class="small text-muted mb-1">Em Meus avisos (lista):</div>`
            + `<div class="list-group mb-4"><a class="list-group-item d-flex align-items-center gap-2" href="#"><span class="rounded-circle" style="width:10px;height:10px;background:${cor.forte}"></span><strong>${titulo}</strong><span class="badge text-bg-danger">Novo</span><span class="ms-auto small text-muted">hoje</span></a></div>`
            + `<div class="small text-muted mb-1">Ao clicar, abre:</div>${fundoEscuro(cartao)}`;
    }
    document.getElementById('previa-corpo').innerHTML = html;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-previa')).show();
}
</script>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
