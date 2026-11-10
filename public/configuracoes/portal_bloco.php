<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirPortal();

// Criar / editar um bloco do site do portal (qualquer tipo).
$pdo = getDB();
$tipos = tiposBlocoPortal();
$paginas = paginasPortal($pdo);
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

$imagens = [];
if ($id) {
    $stmt = $pdo->prepare('SELECT * FROM portal_blocos WHERE id = ?');
    $stmt->execute([$id]);
    $bloco = $stmt->fetch();
    if (!$bloco) {
        flash('danger', 'Bloco não encontrado.');
        redirect('/configuracoes/portal.php');
    }
    $bloco['config'] = configBloco($bloco);
    $stmt = $pdo->prepare('SELECT * FROM portal_banner_imagens WHERE bloco_id = ? ORDER BY ordem, id');
    $stmt->execute([$id]);
    $imagens = $stmt->fetchAll();
} else {
    $tipo = array_key_exists($_GET['tipo'] ?? $_POST['tipo'] ?? '', $tipos) ? ($_GET['tipo'] ?? $_POST['tipo']) : 'banner';
    $paginaInicial = buscarPaginaPortal($pdo, 'id', (int) ($_GET['pagina'] ?? $_POST['pagina_id'] ?? 0)) ?? buscarPaginaPortal($pdo, 'chave', 'inicio');
    $bloco = [
        'tipo' => $tipo, 'pagina_id' => $paginaInicial['id'], 'regiao' => ($_GET['regiao'] ?? $_POST['regiao'] ?? 'topo') === 'fim' ? 'fim' : 'topo',
        'titulo' => ['turmas' => 'Turmas com inscrições abertas', 'faq' => 'Perguntas frequentes', 'saudacao' => 'Olá'][$tipo] ?? '',
        'texto' => '', 'largura' => 'inteira', 'altura' => 'media',
        'publico' => $tipos[$tipo][3] ? 'alunos' : 'todos', 'exibir_de' => null, 'exibir_ate' => null, 'ativo' => 1,
        'config' => match ($tipo) {
            'imagem_texto' => ['lado' => 'esquerda', 'botao_texto' => 'Saiba mais'],
            'chamada' => ['botao_texto' => 'Quero me inscrever', 'botao_link' => '/portal/turmas.php'],
            'faq' => ['itens' => [['pergunta' => '', 'resposta' => '']]],
            default => [],
        },
    ];
}
$tipo = $bloco['tipo'];
$posicao = isset($_GET['posicao']) || isset($_POST['posicao']) ? (int) ($_GET['posicao'] ?? $_POST['posicao']) : null;
$erros = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $paginaDestino = buscarPaginaPortal($pdo, 'id', (int) input('pagina_id')) ?? buscarPaginaPortal($pdo, 'id', (int) $bloco['pagina_id']);
    $dados = [
        'pagina_id' => (int) $paginaDestino['id'],
        'regiao' => $paginaDestino['tipo'] === 'sistema' && input('regiao') === 'fim' ? 'fim' : 'topo',
        'titulo' => trim(input('titulo')) ?: null,
        'texto' => trim((string) ($_POST['texto'] ?? '')) ?: null,
        'largura' => array_key_exists(input('largura'), largurasBlocoPortal()) ? input('largura') : 'inteira',
        'altura' => array_key_exists(input('altura'), alturasBlocoPortal()) ? input('altura') : 'media',
        'publico' => array_key_exists(input('publico'), publicosBlocoPortal()) ? input('publico') : 'todos',
        'exibir_de' => input('exibir_de') ?: null,
        'exibir_ate' => input('exibir_ate') ?: null,
        'ativo' => isset($_POST['ativo']) ? 1 : 0,
    ];
    $config = $bloco['config'];
    $link = fn (string $campo) => trim(input($campo));

    if ($tipo === 'texto' && !$dados['texto']) {
        $erros[] = 'Escreva o texto do bloco.';
    }
    if ($tipo === 'imagem_texto' || $tipo === 'chamada') {
        $config['texto'] = trim((string) ($_POST['config_texto'] ?? ''));
        $config['botao_texto'] = trim(input('botao_texto'));
        $config['botao_link'] = $link('botao_link');
        $config['nova_aba'] = isset($_POST['nova_aba']);
        if (!linkBannerValido($config['botao_link'])) {
            $erros[] = 'O link do botão precisa começar com https:// (ou ser um caminho do site, como /portal/turmas.php).';
        }
        if ($config['botao_texto'] !== '' xor $config['botao_link'] !== '') {
            $erros[] = 'Para o botão aparecer, preencha o texto e o link dele (ou deixe os dois em branco).';
        }
    }
    if ($tipo === 'imagem_texto') {
        $config['lado'] = input('lado') === 'direita' ? 'direita' : 'esquerda';
        if (!$dados['titulo'] && $config['texto'] === '') {
            $erros[] = 'Escreva um título ou um texto.';
        }
    }
    if ($tipo === 'chamada') {
        $coresSistema = isset($_POST['cores_sistema']);
        $config['cor_fundo'] = !$coresSistema && preg_match('/^#[0-9a-f]{6}$/i', input('cor_fundo')) ? input('cor_fundo') : null;
        $config['cor_texto'] = !$coresSistema && preg_match('/^#[0-9a-f]{6}$/i', input('cor_texto')) ? input('cor_texto') : null;
        if (!$dados['titulo'] && $config['texto'] === '') {
            $erros[] = 'Escreva a frase de destaque.';
        }
    }
    if ($tipo === 'banner') {
        $config['tempo'] = max(2, min(60, (int) ($_POST['tempo'] ?? 6) ?: 6));
        $config['efeito'] = array_key_exists(input('efeito'), efeitosBannerPortal()) ? input('efeito') : 'esmaecer';
        $config['pausar'] = isset($_POST['pausar']);
    }
    if ($tipo === 'turmas') {
        $config['limite'] = max(0, min(48, (int) ($_POST['limite'] ?? 0)));
        $config['curso_id'] = (int) ($_POST['curso_id'] ?? 0) ?: null;
    }
    if ($tipo === 'faq') {
        $config['itens'] = [];
        foreach ((array) ($_POST['pergunta'] ?? []) as $i => $pergunta) {
            $pergunta = trim((string) $pergunta);
            $resposta = trim((string) ($_POST['resposta'][$i] ?? ''));
            if ($pergunta !== '') {
                $config['itens'][] = ['pergunta' => mb_substr($pergunta, 0, 300), 'resposta' => mb_substr($resposta, 0, 3000)];
            }
        }
        if (!$config['itens']) {
            $erros[] = 'Escreva pelo menos uma pergunta.';
        }
    }
    if ($dados['exibir_de'] && $dados['exibir_ate'] && $dados['exibir_ate'] < $dados['exibir_de']) {
        $erros[] = 'A data final de exibição é antes da inicial.';
    }
    $imagensPost = (array) ($_POST['imagens'] ?? []);
    foreach ($imagensPost as $dadosImagem) {
        if (!linkBannerValido(trim((string) ($dadosImagem['link'] ?? '')))) {
            $erros[] = 'Link inválido: "' . trim((string) $dadosImagem['link']) . '". Use o endereço completo (https://...) ou um caminho do site (/portal/...).';
        }
    }
    // Imagens novas: cada uma vem com o próprio link/descrição (mesmo índice do arquivo).
    $linksNovas = array_map(fn ($l) => trim((string) $l), (array) ($_POST['novas_link'] ?? []));
    foreach ($linksNovas as $l) {
        if (!linkBannerValido($l)) {
            $erros[] = 'Link inválido: "' . $l . '". Use o endereço completo (https://...) ou um caminho do site (/portal/...).';
        }
    }

    // Imagem do bloco "imagem + texto".
    $apagar = [];
    if (!$erros && $tipo === 'imagem_texto') {
        if (($_FILES['imagem_bloco']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $upload = salvarImagemPortal($_FILES['imagem_bloco'], 'bloco');
            if ($upload['ok']) {
                $apagar[] = $config['imagem'] ?? null;
                $config['imagem'] = $upload['url'];
            } else {
                $erros[] = 'Imagem: ' . $upload['mensagem'];
            }
        } elseif (isset($_POST['remover_imagem_bloco'])) {
            $apagar[] = $config['imagem'] ?? null;
            $config['imagem'] = null;
        }
    }

    if (!$erros) {
        $pdo->beginTransaction();
        $jsonConfig = $config ? json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        $mudouDeLugar = $id && ((int) $bloco['pagina_id'] !== $dados['pagina_id'] || $bloco['regiao'] !== $dados['regiao']);
        if ($id) {
            $stmt = $pdo->prepare('UPDATE portal_blocos SET pagina_id = ?, regiao = ?, titulo = ?, texto = ?, largura = ?, altura = ?, publico = ?, exibir_de = ?, exibir_ate = ?, ativo = ?, config = ? WHERE id = ?');
            $stmt->execute([...array_values($dados), $jsonConfig, $id]);
            if ($mudouDeLugar) {
                // Foi para outra página: entra no fim dela.
                $stmt = $pdo->prepare('SELECT COALESCE(MAX(ordem), 0) + 10 FROM portal_blocos WHERE pagina_id = ? AND regiao = ? AND id <> ?');
                $stmt->execute([$dados['pagina_id'], $dados['regiao'], $id]);
                $pdo->prepare('UPDATE portal_blocos SET ordem = ? WHERE id = ?')->execute([(int) $stmt->fetchColumn(), $id]);
            }
        } else {
            $ordem = ordemParaInserirBloco($pdo, $dados['pagina_id'], $dados['regiao'], $dados['pagina_id'] === (int) $bloco['pagina_id'] ? $posicao : null);
            $stmt = $pdo->prepare('INSERT INTO portal_blocos (pagina_id, regiao, titulo, texto, largura, altura, publico, exibir_de, exibir_ate, ativo, config, tipo, ordem) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([...array_values($dados), $jsonConfig, $tipo, $ordem]);
            $id = (int) $pdo->lastInsertId();
        }

        if ($tipo === 'banner') {
            // Imagens que já existiam: link, aba, texto, ordem, troca e remoção.
            $existentes = array_column($imagens, null, 'id');
            $atualiza = $pdo->prepare('UPDATE portal_banner_imagens SET link = ?, nova_aba = ?, texto_alternativo = ?, ordem = ?, imagem = ? WHERE id = ?');
            foreach ($imagensPost as $imagemId => $dadosImagem) {
                $atual = $existentes[(int) $imagemId] ?? null;
                if (!$atual) {
                    continue;
                }
                if (!empty($dadosImagem['remover'])) {
                    $pdo->prepare('DELETE FROM portal_banner_imagens WHERE id = ?')->execute([$atual['id']]);
                    $apagar[] = $atual['imagem'];
                    continue;
                }
                $arquivo = $atual['imagem'];
                if (($_FILES['trocar']['error'][$imagemId] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $upload = salvarImagemPortal(array_combine(['name', 'type', 'tmp_name', 'error', 'size'], array_map(fn ($k) => $_FILES['trocar'][$k][$imagemId], ['name', 'type', 'tmp_name', 'error', 'size'])), 'banner');
                    if ($upload['ok']) {
                        $apagar[] = $arquivo;
                        $arquivo = $upload['url'];
                    } else {
                        $erros[] = 'Troca de imagem: ' . $upload['mensagem'];
                    }
                }
                $atualiza->execute([
                    trim((string) ($dadosImagem['link'] ?? '')) ?: null, !empty($dadosImagem['nova_aba']) ? 1 : 0,
                    trim((string) ($dadosImagem['texto_alternativo'] ?? '')) ?: null, (int) ($dadosImagem['ordem'] ?? 0), $arquivo, $atual['id'],
                ]);
            }
            // Imagens novas (pode mandar várias de uma vez).
            $stmt = $pdo->prepare('SELECT COALESCE(MAX(ordem), 0) FROM portal_banner_imagens WHERE bloco_id = ?');
            $stmt->execute([$id]);
            $ordem = (int) $stmt->fetchColumn();
            $insere = $pdo->prepare('INSERT INTO portal_banner_imagens (bloco_id, imagem, link, nova_aba, texto_alternativo, ordem) VALUES (?, ?, ?, ?, ?, ?)');
            foreach (array_keys($_FILES['novas']['name'] ?? []) as $i) {
                if ($_FILES['novas']['error'][$i] === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                $upload = salvarImagemPortal(array_combine(['name', 'type', 'tmp_name', 'error', 'size'], array_map(fn ($k) => $_FILES['novas'][$k][$i], ['name', 'type', 'tmp_name', 'error', 'size'])), 'banner');
                if ($upload['ok']) {
                    $ordem += 10;
                    $insere->execute([$id, $upload['url'], ($linksNovas[$i] ?? '') ?: null, !empty($_POST['novas_aba'][$i]) ? 1 : 0,
                        mb_substr(trim((string) ($_POST['novas_texto'][$i] ?? '')), 0, 150) ?: null, $ordem]);
                } else {
                    $erros[] = ($_FILES['novas']['name'][$i] ?: 'Imagem') . ': ' . $upload['mensagem'];
                }
            }
            // Fotos escolhidas do álbum das turmas (cópia própria do banner).
            $idsAlbum = array_filter(array_map('intval', (array) ($_POST['album_fotos'] ?? [])));
            if ($idsAlbum) {
                $stmt = $pdo->query('SELECT * FROM turma_fotos WHERE id IN (' . implode(',', $idsAlbum) . ') ORDER BY turma_id, criado_em, id');
                foreach ($stmt->fetchAll() as $foto) {
                    $linkFoto = isset($_POST['album_link_turma']) ? '/portal/turma.php?id=' . (int) $foto['turma_id'] : null;
                    $r = fotoParaBanner($pdo, $foto, $id, $linkFoto);
                    if (!$r['ok']) {
                        $erros[] = $foto['arquivo_nome'] . ': ' . $r['mensagem'];
                    }
                }
            }
        }
        $pdo->commit();
        array_map('apagarImagemPortal', array_filter($apagar));

        registrarAtividade('portal', 'edicao', 'Bloco do portal salvo: ' . ($dados['titulo'] ?: $tipos[$tipo][0]));
        flash($erros ? 'warning' : 'success', $erros ? 'Bloco salvo, mas: ' . implode(' ', $erros) : 'Salvo. Já está no site.');
        redirect(isset($_POST['continuar']) ? '/configuracoes/portal_bloco.php?id=' . $id : '/configuracoes/portal.php?pagina=' . $dados['pagina_id']);
    }
    $bloco = array_merge($bloco, $dados, ['config' => $config]);
}

$c = $bloco['config'];
$paginaAtual = buscarPaginaPortal($pdo, 'id', (int) $bloco['pagina_id']);
// Fotos dos álbuns das turmas, para usar no banner sem precisar baixar e reenviar.
$fotosAlbum = $tipo === 'banner' ? $pdo->query('SELECT f.id, f.turma_id, f.legenda, f.arquivo_nome, t.nome AS turma_nome, c.nome AS curso_nome
                                                FROM turma_fotos f JOIN turmas t ON t.id = f.turma_id JOIN cursos c ON c.id = t.curso_id
                                                ORDER BY t.data_inicio IS NULL, t.data_inicio DESC, t.id DESC, f.criado_em, f.id')->fetchAll() : [];
$cursos = $tipo === 'turmas' ? $pdo->query('SELECT id, nome FROM cursos WHERE ativo = 1 ORDER BY nome')->fetchAll() : [];
$config = buscarConfiguracoes($pdo);
$tamanhosSugeridos = [
    'inteira' => ['baixa' => '1800 × 300', 'media' => '1800 × 600', 'alta' => '1800 × 900'],
    'metade' => ['baixa' => '900 × 300', 'media' => '1280 × 720', 'alta' => '800 × 1000'],
    'terco' => ['baixa' => '900 × 300', 'media' => '1280 × 720', 'alta' => '800 × 1000'],
];
$rotuloTitulo = match ($tipo) {
    'saudacao' => 'Cumprimento',
    'chamada' => 'Frase de destaque',
    default => 'Título',
};

$voltar = '/configuracoes/portal.php?pagina=' . (int) $bloco['pagina_id'];
$pageTitle = ($id ? 'Editar: ' : 'Novo bloco: ') . mb_strtolower($tipos[$tipo][0]);
$pageBreadcrumb = 'Portal · Site do portal · ' . $paginaAtual['titulo'];
$activeMenu = 'portal';
$pageAction = '<a href="' . e($voltar) . '" class="btn btn-outline-secondary">Cancelar</a>'
    . '<button type="submit" form="form-bloco" class="btn btn-brand"><i class="bi bi-check-lg"></i> Salvar</button>';
require __DIR__ . '/../../includes/layout_header.php';
?>
<style>
    .opcao-visual { border: 2px solid var(--border, #e6e8eb); border-radius: 10px; padding: 10px; cursor: pointer; text-align: center; display: block; height: 100%; }
    .opcao-visual .desenho { display: grid; grid-template-columns: repeat(6, 1fr); gap: 3px; margin-bottom: 6px; }
    .opcao-visual .desenho span { height: 22px; border-radius: 3px; background: #e4e7eb; }
    .opcao-visual .desenho span.cheio { background: var(--accent); }
    input:checked + .opcao-visual { border-color: var(--accent); background: var(--accent-soft); }
    .img-banner { width: 100%; aspect-ratio: 3/1; object-fit: cover; border-radius: 8px; background: #eef1f4; }
</style>

<?php if ($erros): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($erros as $erro): ?><li><?= e($erro) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" id="form-bloco" enctype="multipart/form-data">
    <input type="hidden" name="id" value="<?= (int) $id ?>">
    <input type="hidden" name="tipo" value="<?= e($tipo) ?>">
    <?php if ($posicao !== null): ?><input type="hidden" name="posicao" value="<?= $posicao ?>"><?php endif; ?>

    <div class="secao" style="max-width: 1000px;">
        <div class="secao-titulo"><i class="bi <?= e($tipos[$tipo][1]) ?>"></i> <?= e($tipos[$tipo][0]) ?></div>
        <p class="small text-muted"><?= e($tipos[$tipo][2]) ?></p>
        <div class="row g-3">
            <div class="col-md-8">
                <label class="form-label"><?= $rotuloTitulo ?> <?= in_array($tipo, ['chamada', 'imagem_texto'], true) ? '' : '<span class="text-muted small">(opcional — aparece em cima do bloco)</span>' ?></label>
                <input type="text" name="titulo" class="form-control" maxlength="150" value="<?= e($bloco['titulo']) ?>"
                       placeholder="<?= e(['saudacao' => 'Olá', 'chamada' => 'Ex.: Matrículas abertas para 2027!', 'imagem_texto' => 'Ex.: Conheça a UniPHD'][$tipo] ?? '') ?>">
                <?php if ($tipo === 'saudacao'): ?>
                    <div class="form-text">O aluno logado vê "<?= e($bloco['titulo'] ?: 'Olá') ?>, Maria!". O visitante vê o nome do portal. A frase de baixo é a "frase de boas-vindas" de <a href="/configuracoes/portal_menu.php">Menu, rodapé e ajustes</a>.</div>
                <?php endif; ?>
            </div>

            <?php if ($tipo === 'texto'): ?>
                <div class="col-12"><label class="form-label">Texto *</label><textarea name="texto" class="form-control" rows="6"><?= e($bloco['texto']) ?></textarea></div>
            <?php endif; ?>

            <?php if ($tipo === 'imagem_texto' || $tipo === 'chamada'): ?>
                <div class="col-12">
                    <label class="form-label"><?= $tipo === 'chamada' ? 'Texto menor (opcional)' : 'Texto' ?></label>
                    <textarea name="config_texto" class="form-control" rows="<?= $tipo === 'chamada' ? 2 : 5 ?>"><?= e($c['texto'] ?? '') ?></textarea>
                </div>
                <div class="col-md-4"><label class="form-label">Texto do botão</label><input type="text" name="botao_texto" class="form-control" maxlength="40" value="<?= e($c['botao_texto'] ?? '') ?>"></div>
                <div class="col-md-6"><label class="form-label">Ao clicar, abrir</label><input type="text" name="botao_link" class="form-control" placeholder="https://... ou /portal/turmas.php" value="<?= e($c['botao_link'] ?? '') ?>">
                    <div class="form-text">WhatsApp: https://wa.me/55DDDNUMERO</div></div>
                <div class="col-md-2 form-check mt-md-5 ms-2"><input type="checkbox" class="form-check-input" name="nova_aba" id="nova_aba" <?= !empty($c['nova_aba']) ? 'checked' : '' ?>><label class="form-check-label small" for="nova_aba">Outra aba</label></div>
            <?php endif; ?>

            <?php if ($tipo === 'imagem_texto'): ?>
                <div class="col-md-6">
                    <label class="form-label">Imagem</label>
                    <?php if (!empty($c['imagem'])): ?>
                        <img src="<?= e($c['imagem']) ?>" alt="" class="w-100 rounded mb-2" style="max-height: 180px; object-fit: cover;">
                        <div class="form-check mb-1"><input type="checkbox" class="form-check-input" name="remover_imagem_bloco" id="remover_imagem_bloco"><label class="form-check-label small" for="remover_imagem_bloco">Remover imagem</label></div>
                    <?php endif; ?>
                    <input type="file" name="imagem_bloco" class="form-control" accept="image/*">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Imagem fica</label>
                    <div class="d-flex gap-3">
                        <div class="form-check"><input type="radio" class="form-check-input" name="lado" value="esquerda" id="lado_e" <?= ($c['lado'] ?? 'esquerda') === 'esquerda' ? 'checked' : '' ?>><label class="form-check-label" for="lado_e">À esquerda do texto</label></div>
                        <div class="form-check"><input type="radio" class="form-check-input" name="lado" value="direita" id="lado_d" <?= ($c['lado'] ?? '') === 'direita' ? 'checked' : '' ?>><label class="form-check-label" for="lado_d">À direita</label></div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($tipo === 'chamada'): ?>
                <div class="col-12 form-check ms-2"><input type="checkbox" class="form-check-input" name="cores_sistema" id="cores_sistema" <?= empty($c['cor_fundo']) ? 'checked' : '' ?> onchange="document.getElementById('cores').style.display = this.checked ? 'none' : ''"><label class="form-check-label" for="cores_sistema">Usar as cores do sistema</label></div>
                <div class="col-12" id="cores" style="<?= empty($c['cor_fundo']) ? 'display:none;' : '' ?>">
                    <div class="row g-3">
                        <div class="col-md-3"><label class="form-label">Cor do fundo</label><input type="color" name="cor_fundo" class="form-control form-control-color w-100" value="<?= e($c['cor_fundo'] ?? $config['cor_primaria']) ?>"></div>
                        <div class="col-md-3"><label class="form-label">Cor do texto</label><input type="color" name="cor_texto" class="form-control form-control-color w-100" value="<?= e($c['cor_texto'] ?? '#ffffff') ?>"></div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($tipo === 'turmas'): ?>
                <div class="col-md-4"><label class="form-label">Quantas turmas mostrar</label><input type="number" name="limite" class="form-control" min="0" max="48" value="<?= (int) ($c['limite'] ?? 0) ?>"><div class="form-text">0 = todas. Com limite, aparece o link "Ver todos os cursos".</div></div>
                <div class="col-md-8"><label class="form-label">Só deste curso</label>
                    <select name="curso_id" class="form-select"><option value="">Todos os cursos</option>
                        <?php foreach ($cursos as $curso): ?><option value="<?= (int) $curso['id'] ?>" <?= (int) ($c['curso_id'] ?? 0) === (int) $curso['id'] ? 'selected' : '' ?>><?= e($curso['nome']) ?></option><?php endforeach; ?>
                    </select></div>
            <?php endif; ?>

            <?php if ($tipo === 'faq'): ?>
                <div class="col-12">
                    <div id="lista-faq"></div>
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="adicionarPergunta()"><i class="bi bi-plus-lg"></i> Pergunta</button>
                    <template id="modelo-faq">
                        <div class="border rounded p-2 mb-2 item-faq">
                            <div class="d-flex gap-2 mb-1"><input type="text" name="pergunta[]" class="form-control form-control-sm" placeholder="Pergunta" maxlength="300">
                                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="moverPergunta(this, -1)"><i class="bi bi-arrow-up"></i></button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="moverPergunta(this, 1)"><i class="bi bi-arrow-down"></i></button>
                                <button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('.item-faq').remove()"><i class="bi bi-x-lg"></i></button></div>
                            <textarea name="resposta[]" class="form-control form-control-sm" rows="2" placeholder="Resposta"></textarea>
                        </div>
                    </template>
                </div>
            <?php endif; ?>

            <?php if (in_array($tipo, ['avisos', 'atalhos'], true)): ?>
                <div class="col-12 small text-muted">Este bloco é montado sozinho com os dados do aluno logado — não precisa preencher nada.</div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($tipo === 'banner'): ?>
    <div class="secao" style="max-width: 1000px;">
        <div class="secao-titulo"><i class="bi bi-images"></i> Imagens <span class="text-muted small fw-normal ms-2">— com mais de uma, elas passam sozinhas (carrossel)</span></div>
        <div id="lista-existentes">
        <?php foreach ($imagens as $img): ?>
            <div class="row g-3 align-items-start border-bottom pb-3 mb-3 img-existente">
                <div class="col-md-4">
                    <div class="d-flex align-items-center gap-1 mb-1">
                        <span class="badge text-bg-light border numero-ordem"></span>
                        <button type="button" class="btn btn-sm btn-link p-0 px-1" onclick="moverExistente(this, -1)" title="Passar antes"><i class="bi bi-arrow-up"></i></button>
                        <button type="button" class="btn btn-sm btn-link p-0 px-1" onclick="moverExistente(this, 1)" title="Passar depois"><i class="bi bi-arrow-down"></i></button>
                    </div>
                    <input type="hidden" name="imagens[<?= (int) $img['id'] ?>][ordem]" class="campo-ordem" value="<?= (int) $img['ordem'] ?>">
                    <a href="<?= e($img['imagem']) ?>" target="_blank"><img src="<?= e($img['imagem']) ?>" alt="" class="img-banner"></a>
                    <label class="form-label small mt-2 mb-1">Trocar a imagem</label>
                    <input type="file" name="trocar[<?= (int) $img['id'] ?>]" class="form-control form-control-sm" accept="image/*">
                </div>
                <div class="col-md-8">
                    <div class="row g-2">
                        <div class="col-12"><label class="form-label small mb-1">Ao clicar, abrir <span class="text-muted">(opcional)</span></label>
                            <input type="text" name="imagens[<?= (int) $img['id'] ?>][link]" class="form-control form-control-sm" placeholder="https://... ou /portal/turma.php?id=..." value="<?= e($img['link']) ?>"></div>
                        <div class="col-12"><label class="form-label small mb-1">Descrição da imagem <span class="text-muted">(para quem usa leitor de tela)</span></label>
                            <input type="text" name="imagens[<?= (int) $img['id'] ?>][texto_alternativo]" class="form-control form-control-sm" maxlength="150" value="<?= e($img['texto_alternativo']) ?>"></div>
                        <div class="col-12 d-flex gap-4">
                            <div class="form-check"><input type="checkbox" class="form-check-input" name="imagens[<?= (int) $img['id'] ?>][nova_aba]" id="aba_<?= (int) $img['id'] ?>" value="1" <?= $img['nova_aba'] ? 'checked' : '' ?>><label class="form-check-label small" for="aba_<?= (int) $img['id'] ?>">Abrir o link em outra aba</label></div>
                            <div class="form-check"><input type="checkbox" class="form-check-input" name="imagens[<?= (int) $img['id'] ?>][remover]" id="rem_<?= (int) $img['id'] ?>" value="1"><label class="form-check-label small text-danger" for="rem_<?= (int) $img['id'] ?>">Remover esta imagem</label></div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
        <div class="p-3 rounded" style="background: var(--accent-soft);">
            <div class="fw-semibold mb-2"><i class="bi bi-plus-circle"></i> <?= $imagens ? 'Acrescentar mais imagens' : 'Escolha as imagens do banner' ?></div>
            <div>
                <div><label class="form-label small mb-1"><i class="bi bi-upload"></i> Enviar do computador (arte, propaganda, foto)</label>
                    <input type="file" name="novas[]" id="campo-novas" class="d-none" accept="image/*" multiple>
                    <div class="zona-novas" id="zona-novas" onclick="escolherNovas()">
                        <i class="bi bi-plus-lg fs-4 d-block"></i>
                        <span class="small">Clique para adicionar imagens — uma de cada vez ou várias juntas. Também dá para arrastar para cá.</span>
                    </div>
                    <div class="lista-novas" id="lista-novas"></div>
                    <div class="form-text">JPG, PNG ou WEBP, até 5 MB cada. O link de cada uma já pode ser preenchido aqui; elas entram no banner ao <strong>Salvar</strong>, depois das que já estão.</div></div>
            </div>
            <div class="d-flex flex-wrap align-items-center gap-2 mt-3 pt-3 border-top">
                <?php if ($fotosAlbum): ?>
                    <span class="small text-muted">ou</span>
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#modal-album"><i class="bi bi-images"></i> Escolher do álbum das turmas</button>
                    <span class="small" id="album-escolhidas"></span>
                <?php else: ?>
                    <span class="small text-muted"><i class="bi bi-images"></i> Também dá para usar fotos do álbum das turmas (Turma › Álbum) — ainda não há nenhuma.</span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="secao" style="max-width: 1000px;">
        <div class="secao-titulo"><i class="bi bi-collection-play"></i> Carrossel <span class="text-muted small fw-normal ms-2">— vale quando o banner tem mais de uma imagem</span></div>
        <div class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label">Tempo de cada imagem</label>
                <div class="input-group">
                    <input type="number" name="tempo" class="form-control" min="2" max="60" value="<?= (int) ($c['tempo'] ?? 6) ?>">
                    <span class="input-group-text">segundos</span>
                </div>
            </div>
            <div class="col-md-5">
                <label class="form-label">Efeito da troca</label>
                <select name="efeito" class="form-select">
                    <?php foreach (efeitosBannerPortal() as $valor => $rotulo): ?>
                        <option value="<?= $valor ?>" <?= ($c['efeito'] ?? 'esmaecer') === $valor ? 'selected' : '' ?>><?= e($rotulo) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <div class="form-check mb-2"><input type="checkbox" class="form-check-input" name="pausar" id="pausar" <?= ($c['pausar'] ?? true) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="pausar">Parar enquanto o mouse estiver em cima</label></div>
            </div>
        </div>
        <div class="form-text">As setas e os pontinhos embaixo do banner deixam a pessoa passar as imagens na hora; no celular, dá para arrastar com o dedo.</div>
    </div>
    <?php endif; ?>

    <div class="secao" style="max-width: 1000px;">
        <div class="secao-titulo"><i class="bi bi-aspect-ratio"></i> Tamanho</div>
        <div class="row g-2 mb-2">
            <?php foreach (['inteira' => 6, 'metade' => 3, 'terco' => 2] as $largura => $colunas): ?>
                <div class="col-4">
                    <input type="radio" class="btn-check" name="largura" id="largura_<?= $largura ?>" value="<?= $largura ?>" <?= $bloco['largura'] === $largura ? 'checked' : '' ?> onchange="atualizarDica()">
                    <label class="opcao-visual" for="largura_<?= $largura ?>">
                        <span class="desenho"><?php for ($i = 0; $i < 6; $i++): ?><span class="<?= $i < $colunas ? 'cheio' : '' ?>"></span><?php endfor; ?></span>
                        <span class="small fw-semibold"><?= e(largurasBlocoPortal()[$largura]) ?></span>
                    </label>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="form-text mb-3">Blocos de metade ou um terço ficam lado a lado com o próximo. No celular, todos ocupam a largura toda.</div>
        <?php if ($tipo === 'banner'): ?>
            <label class="form-label">Altura do banner</label>
            <div class="row g-2">
                <?php foreach (alturasBlocoPortal() as $altura => $rotulo): ?>
                    <div class="col-4">
                        <input type="radio" class="btn-check" name="altura" id="altura_<?= $altura ?>" value="<?= $altura ?>" <?= $bloco['altura'] === $altura ? 'checked' : '' ?> onchange="atualizarDica()">
                        <label class="opcao-visual" for="altura_<?= $altura ?>">
                            <span class="d-block mx-auto mb-1 rounded" style="width: 80%; background: var(--accent); opacity: .8; height: <?= ['baixa' => 12, 'media' => 26, 'alta' => 42][$altura] ?>px;"></span>
                            <span class="small fw-semibold"><?= e($rotulo) ?></span>
                        </label>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="alert alert-info small mt-3 mb-0" id="dica-tamanho"></div>
        <?php endif; ?>
    </div>

    <div class="secao" style="max-width: 1000px;">
        <div class="secao-titulo"><i class="bi bi-geo-alt"></i> Onde e para quem</div>
        <div class="row g-3">
            <div class="col-md-5">
                <label class="form-label">Página</label>
                <select name="pagina_id" class="form-select" id="pagina_id" onchange="atualizarRegiao()">
                    <?php foreach ($paginas as $p): ?>
                        <option value="<?= (int) $p['id'] ?>" data-sistema="<?= $p['tipo'] === 'sistema' ? 1 : 0 ?>" <?= (int) $p['id'] === (int) $bloco['pagina_id'] ? 'selected' : '' ?>><?= e($p['titulo']) ?><?= $p['tipo'] === 'sistema' ? ' (tela)' : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4" id="campo-regiao">
                <label class="form-label">Na tela, aparece</label>
                <select name="regiao" class="form-select">
                    <option value="topo" <?= $bloco['regiao'] === 'topo' ? 'selected' : '' ?>>Antes do conteúdo</option>
                    <option value="fim" <?= $bloco['regiao'] === 'fim' ? 'selected' : '' ?>>Depois do conteúdo</option>
                </select>
            </div>
            <div class="col-md-5">
                <label class="form-label">Mostrar para</label>
                <select name="publico" class="form-select">
                    <?php foreach (publicosBlocoPortal() as $chave => $rotulo): ?>
                        <option value="<?= e($chave) ?>" <?= $bloco['publico'] === $chave ? 'selected' : '' ?>><?= e($rotulo) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3"><label class="form-label">A partir de</label><input type="date" name="exibir_de" class="form-control" value="<?= e($bloco['exibir_de']) ?>"></div>
            <div class="col-md-3"><label class="form-label">Até</label><input type="date" name="exibir_ate" class="form-control" value="<?= e($bloco['exibir_ate']) ?>"></div>
            <div class="col-12 form-text mt-0">Datas em branco: fica no ar sem prazo. Útil para promoções que acabam sozinhas.</div>
            <div class="col-12 form-check form-switch ms-2">
                <input type="checkbox" class="form-check-input" role="switch" name="ativo" id="ativo" <?= $bloco['ativo'] ? 'checked' : '' ?>>
                <label class="form-check-label" for="ativo">Aparece no site</label>
            </div>
        </div>
    </div>

    <div class="d-flex gap-2" style="max-width: 1000px;">
        <button type="submit" class="btn btn-brand"><i class="bi bi-check-lg"></i> Salvar e voltar</button>
        <button type="submit" name="continuar" value="1" class="btn btn-outline-secondary">Salvar e continuar editando</button>
    </div>
<?php if ($fotosAlbum): $turmasAlbum = array_unique(array_map(fn ($f) => $f['turma_id'] . '|' . $f['curso_nome'] . ' — ' . $f['turma_nome'], $fotosAlbum)); ?>
<div class="modal fade" id="modal-album" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title"><i class="bi bi-images"></i> Fotos do álbum das turmas</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <select class="form-select mb-3" style="max-width: 460px" onchange="filtrarAlbum(this.value)">
                    <option value="">Todas as turmas</option>
                    <?php foreach ($turmasAlbum as $t): [$tid, $tnome] = explode('|', $t, 2); ?><option value="<?= (int) $tid ?>"><?= e($tnome) ?></option><?php endforeach; ?>
                </select>
                <div class="grade-album">
                    <?php foreach ($fotosAlbum as $f): ?>
                        <label class="foto-album" data-turma="<?= (int) $f['turma_id'] ?>" title="<?= e($f['curso_nome'] . ' — ' . $f['turma_nome'] . ($f['legenda'] ? ': ' . $f['legenda'] : '')) ?>">
                            <input type="checkbox" class="form-check-input" name="album_fotos[]" value="<?= (int) $f['id'] ?>" form="form-bloco" onchange="contarAlbum()">
                            <img src="/turmas/album_foto.php?id=<?= (int) $f['id'] ?>&amp;tam=mini" alt="" loading="lazy">
                            <span><?= e($f['legenda'] ?: $f['turma_nome']) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="modal-footer justify-content-between">
                <div class="form-check"><input type="checkbox" class="form-check-input" name="album_link_turma" id="album_link_turma" value="1" form="form-bloco" checked>
                    <label class="form-check-label small" for="album_link_turma">Ao clicar, abrir a página de inscrição da turma da foto <span class="text-muted">(depois de salvar dá para trocar o link de cada uma)</span></label></div>
                <button type="button" class="btn btn-brand" data-bs-dismiss="modal">Pronto</button>
            </div>
        </div>
    </div>
</div>
<style>
    .grade-album { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 10px; }
    .foto-album { position: relative; border: 2px solid transparent; border-radius: 8px; overflow: hidden; cursor: pointer; background: var(--bs-tertiary-bg); }
    .foto-album:has(input:checked) { border-color: var(--accent); }
    .foto-album img { width: 100%; aspect-ratio: 3/2; object-fit: cover; display: block; }
    .foto-album input { position: absolute; top: 6px; left: 6px; width: 20px; height: 20px; }
    .foto-album span { display: block; font-size: 12px; padding: 3px 6px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
</style>
<?php endif; ?>
</form>

<?php require __DIR__ . '/../../includes/modal_confirmar.php'; ?>
<style>
    .zona-novas { border: 2px dashed var(--bs-border-color); border-radius: 10px; padding: 14px; text-align: center; cursor: pointer; background: var(--bs-body-bg); }
    .zona-novas.sobre, .zona-novas:hover { border-color: var(--accent); }
    .lista-novas { display: flex; flex-direction: column; gap: 10px; margin-top: 10px; }
    .lista-novas:empty { display: none; }
    .lista-novas .nova { background: var(--bs-body-bg); border-radius: 8px; padding: 10px; }
    .lista-novas img { width: 100%; aspect-ratio: 3/1; object-fit: cover; display: block; border-radius: 6px; }
</style>
<script>
function atualizarRegiao() {
    const opcao = document.getElementById('pagina_id').selectedOptions[0];
    document.getElementById('campo-regiao').style.display = opcao.dataset.sistema === '1' ? '' : 'none';
}
atualizarRegiao();
<?php if ($tipo === 'banner'): ?>
const TAMANHOS = <?= json_encode($tamanhosSugeridos) ?>;
// Imagens novas: cada escolha SOMA à lista (o campo de arquivo comum
// substituiria a anterior). Mostra miniaturas e deixa tirar uma antes de
// salvar. Confere o limite de envio do servidor antes de mandar.
const LIMITE_ENVIO = <?= iniParaBytesPortal(ini_get('post_max_size')) ?>;
const MAX_ARQUIVOS = <?= (int) ini_get('max_file_uploads') ?>;
let novas = []; // { arquivo, link, texto, aba }
function escolherNovas() {
    const temp = document.createElement('input');
    temp.type = 'file';
    temp.accept = 'image/*';
    temp.multiple = true;
    temp.onchange = () => somarNovas(temp.files);
    temp.click();
}
function somarNovas(arquivos) {
    [...arquivos].filter((a) => a.type.startsWith('image/')).forEach((a) => novas.push({ arquivo: a, link: '', texto: '', aba: false }));
    mostrarNovas();
}
function tirarNova(i) {
    novas.splice(i, 1);
    mostrarNovas();
}
function moverNova(i, d) {
    const j = i + d;
    if (j < 0 || j >= novas.length) return;
    [novas[i], novas[j]] = [novas[j], novas[i]];
    mostrarNovas();
}
// Imagens já salvas: as setas trocam a ordem; o número é refeito ao salvar.
function numerarExistentes() {
    document.querySelectorAll('#lista-existentes .img-existente').forEach((el, i) => {
        el.querySelector('.numero-ordem').textContent = (i + 1) + 'ª no carrossel';
        el.querySelector('.campo-ordem').value = (i + 1) * 10;
    });
}
function moverExistente(botao, d) {
    const item = botao.closest('.img-existente');
    const vizinho = d < 0 ? item.previousElementSibling : item.nextElementSibling;
    if (vizinho) d < 0 ? vizinho.before(item) : vizinho.after(item);
    numerarExistentes();
}
numerarExistentes();
function mostrarNovas() {
    const dt = new DataTransfer();
    novas.forEach((n) => dt.items.add(n.arquivo));
    document.getElementById('campo-novas').files = dt.files;
    const lista = document.getElementById('lista-novas');
    lista.querySelectorAll('img').forEach((img) => URL.revokeObjectURL(img.src));
    lista.innerHTML = '';
    const jaSalvas = document.querySelectorAll('#lista-existentes .img-existente').length;
    novas.forEach((n, i) => {
        const item = document.createElement('div');
        item.className = 'nova row g-2 align-items-start';
        item.innerHTML = `
            <div class="col-md-4"><img alt="">
                <div class="d-flex align-items-center gap-1 mt-1">
                    <span class="badge text-bg-light border">${jaSalvas + i + 1}ª · nova</span>
                    <button type="button" class="btn btn-sm btn-link p-0 px-1" onclick="moverNova(${i}, -1)" title="Passar antes"><i class="bi bi-arrow-up"></i></button>
                    <button type="button" class="btn btn-sm btn-link p-0 px-1" onclick="moverNova(${i}, 1)" title="Passar depois"><i class="bi bi-arrow-down"></i></button>
                    <span class="small text-muted text-truncate nome"></span>
                </div></div>
            <div class="col-md-8"><div class="row g-2">
                <div class="col-12"><label class="form-label small mb-1">Ao clicar, abrir <span class="text-muted">(opcional)</span></label>
                    <input type="text" name="novas_link[${i}]" class="form-control form-control-sm" placeholder="https://... ou /portal/turma.php?id=..." data-campo="link"></div>
                <div class="col-12"><label class="form-label small mb-1">Descrição da imagem <span class="text-muted">(para quem usa leitor de tela)</span></label>
                    <input type="text" name="novas_texto[${i}]" class="form-control form-control-sm" maxlength="150" data-campo="texto"></div>
                <div class="col-12 d-flex gap-4">
                    <div class="form-check"><input type="checkbox" class="form-check-input" name="novas_aba[${i}]" value="1" id="nova_aba_${i}" data-campo="aba"><label class="form-check-label small" for="nova_aba_${i}">Abrir o link em outra aba</label></div>
                    <button type="button" class="btn btn-sm btn-link text-danger p-0" onclick="tirarNova(${i})"><i class="bi bi-x-circle"></i> Tirar esta imagem</button>
                </div>
            </div></div>`;
        item.querySelector('img').src = URL.createObjectURL(n.arquivo);
        item.querySelector('.nome').textContent = n.arquivo.name;
        item.querySelectorAll('[data-campo]').forEach((campo) => {
            const chave = campo.dataset.campo;
            if (campo.type === 'checkbox') campo.checked = n[chave]; else campo.value = n[chave];
            campo.addEventListener('input', () => { n[chave] = campo.type === 'checkbox' ? campo.checked : campo.value; });
            campo.addEventListener('change', () => { n[chave] = campo.type === 'checkbox' ? campo.checked : campo.value; });
        });
        lista.appendChild(item);
    });
}
const zonaNovas = document.getElementById('zona-novas');
if (zonaNovas) {
    zonaNovas.addEventListener('dragover', (ev) => { ev.preventDefault(); zonaNovas.classList.add('sobre'); });
    zonaNovas.addEventListener('dragleave', () => zonaNovas.classList.remove('sobre'));
    zonaNovas.addEventListener('drop', (ev) => { ev.preventDefault(); zonaNovas.classList.remove('sobre'); somarNovas(ev.dataTransfer.files); });
    document.getElementById('form-bloco').addEventListener('submit', (ev) => {
        const total = novas.reduce((soma, n) => soma + n.arquivo.size, 0);
        const grande = novas.map((n) => n.arquivo).find((a) => a.size > 5 * 1024 * 1024);
        let problema = '';
        if (grande) problema = `A imagem <strong>${escapeHtml(grande.name)}</strong> tem mais de 5 MB. Diminua e escolha de novo (tire-a da lista no ✕).`;
        else if (LIMITE_ENVIO && total > LIMITE_ENVIO * 0.95) problema = `As imagens juntas passam do que o servidor aceita de uma vez (${Math.floor(LIMITE_ENVIO / 1048576)} MB). Salve com uma parte e depois acrescente o resto.`;
        else if (MAX_ARQUIVOS && novas.length > MAX_ARQUIVOS - 1) problema = `O servidor aceita até ${MAX_ARQUIVOS - 1} imagens por vez. Salve com uma parte e depois acrescente o resto.`;
        if (problema) {
            ev.preventDefault();
            confirmarNoModal({ titulo: 'Muitas imagens de uma vez', html: problema, botao: 'Ok' });
        }
    });
}

function atualizarDica() {
    const largura = document.querySelector('input[name="largura"]:checked').value;
    const altura = document.querySelector('input[name="altura"]:checked').value;
    document.getElementById('dica-tamanho').innerHTML = '<i class="bi bi-lightbulb"></i> Tamanho ideal da imagem: <strong>' + TAMANHOS[largura][altura]
        + ' pixels</strong>. Deixe textos e logos perto do centro: no celular as bordas podem ser cortadas.';
}
atualizarDica();
<?php else: ?>
function atualizarDica() {}
<?php endif; ?>
<?php if ($fotosAlbum): ?>
function filtrarAlbum(turma) {
    document.querySelectorAll('.foto-album').forEach((el) => { el.style.display = !turma || el.dataset.turma === turma ? '' : 'none'; });
}
function contarAlbum() {
    const n = document.querySelectorAll('[name="album_fotos[]"]:checked').length;
    document.getElementById('album-escolhidas').innerHTML = n ? '<i class="bi bi-check2-circle text-success"></i> ' + n + ' foto(s) do álbum escolhida(s) — entram ao <strong>Salvar</strong>.' : '';
}
<?php endif; ?>
<?php if ($tipo === 'faq'): ?>
function adicionarPergunta(pergunta = '', resposta = '') {
    const item = document.getElementById('modelo-faq').content.firstElementChild.cloneNode(true);
    item.querySelector('[name="pergunta[]"]').value = pergunta;
    item.querySelector('[name="resposta[]"]').value = resposta;
    document.getElementById('lista-faq').appendChild(item);
}
function moverPergunta(botao, direcao) {
    const item = botao.closest('.item-faq');
    const vizinho = direcao < 0 ? item.previousElementSibling : item.nextElementSibling;
    if (vizinho) direcao < 0 ? vizinho.before(item) : vizinho.after(item);
}
<?php foreach ((array) ($c['itens'] ?? []) as $item): ?>
adicionarPergunta(<?= json_encode($item['pergunta'] ?? '') ?>, <?= json_encode($item['resposta'] ?? '') ?>);
<?php endforeach; ?>
<?php endif; ?>
</script>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
