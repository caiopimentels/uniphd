<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirPortal();

// Menu do topo, links do rodapé, rodapé e ajustes gerais do portal.
$pdo = getDB();
$paginas = array_values(array_filter(paginasPortal($pdo), fn ($p) => urlPaginaPortal($p)));
$idsPaginas = array_map('intval', array_column($paginas, 'id'));
$erros = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $itens = ['topo' => [], 'rodape' => []];
    foreach (['topo', 'rodape'] as $local) {
        foreach ((array) ($_POST[$local] ?? []) as $item) {
            $rotulo = mb_substr(trim((string) ($item['rotulo'] ?? '')), 0, 60);
            $destino = (string) ($item['destino'] ?? '');
            if ($rotulo === '') {
                continue;
            }
            $paginaId = str_starts_with($destino, 'pagina:') ? (int) substr($destino, 7) : null;
            $url = $paginaId ? null : trim((string) ($item['url'] ?? ''));
            if ($paginaId && !in_array($paginaId, $idsPaginas, true)) {
                continue;
            }
            if (!$paginaId && ($url === '' || !linkBannerValido($url))) {
                $erros[] = 'O item "' . $rotulo . '" precisa de um link válido (https://... ou /portal/...).';
                continue;
            }
            $itens[$local][] = [
                'rotulo' => $rotulo, 'pagina_id' => $paginaId, 'url' => $url,
                'publico' => array_key_exists($item['publico'] ?? '', publicosBlocoPortal()) ? $item['publico'] : 'todos',
                'nova_aba' => !empty($item['nova_aba']) ? 1 : 0, 'ativo' => !empty($item['ativo']) ? 1 : 0,
            ];
        }
    }
    $redes = [];
    foreach (redesSociaisPortal() as $rede => $dadosRede) {
        $url = trim((string) ($_POST['redes'][$rede] ?? ''));
        if ($url !== '' && !preg_match('#^https?://#i', $url)) {
            $erros[] = $dadosRede[0] . ': use o endereço completo, começando com https://';
        } elseif ($url !== '') {
            $redes[$rede] = $url;
        }
    }

    if (!$erros) {
        $pdo->beginTransaction();
        $pdo->exec('DELETE FROM portal_menu');
        $insere = $pdo->prepare('INSERT INTO portal_menu (local, rotulo, pagina_id, url, publico, nova_aba, ativo, ordem) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($itens as $local => $lista) {
            foreach ($lista as $i => $item) {
                $insere->execute([$local, $item['rotulo'], $item['pagina_id'], $item['url'], $item['publico'], $item['nova_aba'], $item['ativo'], ($i + 1) * 10]);
            }
        }
        // Cores próprias do portal; desligado (ou cor inválida) = usa as do sistema.
        $corValida = fn ($c) => preg_match('/^#[0-9a-fA-F]{6}$/', (string) $c) ? strtolower($c) : null;
        $coresProprias = isset($_POST['portal_cores_proprias']);
        $itensRodape = array_map(fn ($chave) => !empty($_POST['rodape_itens'][$chave]), array_combine(array_keys(opcoesRodapePortal()), array_keys(opcoesRodapePortal())));
        $pdo->prepare('UPDATE configuracoes SET portal_titulo = ?, portal_subtitulo = ?, portal_cadastro_aberto = ?, portal_rodape_texto = ?, portal_rodape_contatos = ?, portal_rodape_itens = ?, portal_redes = ?,
                portal_cor_primaria = ?, portal_cor_secundaria = ?, whatsapp_numero = ? WHERE id = 1')
            ->execute([
                trim(input('portal_titulo')) ?: null, trim(input('portal_subtitulo')) ?: null, isset($_POST['portal_cadastro_aberto']) ? 1 : 0,
                trim((string) ($_POST['portal_rodape_texto'] ?? '')) ?: null, $itensRodape['endereco'] || $itensRodape['telefone'] || $itensRodape['email'] ? 1 : 0,
                json_encode(array_map('intval', $itensRodape)),
                $redes ? json_encode($redes, JSON_UNESCAPED_SLASHES) : null,
                $coresProprias ? $corValida($_POST['portal_cor_primaria'] ?? '') : null,
                $coresProprias ? $corValida($_POST['portal_cor_secundaria'] ?? '') : null,
                // wa.me precisa do código do país: só DDD + número ganha o 55.
                ($zap = apenasDigitos((string) ($_POST['whatsapp_numero'] ?? ''))) !== '' ? (strlen($zap) <= 11 ? '55' . $zap : $zap) : null,
            ]);
        $pdo->commit();
        registrarAtividade('portal', 'edicao', 'Menu, rodapé e ajustes do portal alterados');
        flash('success', 'Salvo. Já está no site.');
        redirect('/configuracoes/portal_menu.php');
    }
}

$config = buscarConfiguracoes($pdo);
$redes = redesConfiguradasPortal($config);
$itensMenu = ['topo' => itensMenuPortal($pdo, 'topo', false), 'rodape' => itensMenuPortal($pdo, 'rodape', false)];

$pageTitle = 'Menu, rodapé e ajustes';
$pageBreadcrumb = 'Acadêmico · Portal do aluno';
$activeMenu = 'portal';
$pageAction = '<a href="/configuracoes/portal.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Páginas</a>'
    . '<button type="submit" form="form-menu" class="btn btn-brand"><i class="bi bi-check-lg"></i> Salvar</button>';
require __DIR__ . '/../../includes/layout_header.php';
?>
<style>
    .linha-menu { display: grid; grid-template-columns: 28px 1.2fr 1.6fr 1.1fr auto; gap: 6px; align-items: center; padding: 6px; border: 1px solid var(--border, #e6e8eb); border-radius: 8px; margin-bottom: 6px; background: var(--surface, #fff); }
    .linha-menu .arrastar { cursor: grab; color: #888; text-align: center; }
    @media (max-width: 900px) { .linha-menu { grid-template-columns: 28px 1fr; } }
</style>
<?php if ($erros): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($erros as $erro): ?><li><?= e($erro) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<form method="post" id="form-menu" style="max-width: 1100px;">
    <div class="secao">
        <div class="secao-titulo"><i class="bi bi-sliders"></i> Ajustes gerais</div>
        <div class="row g-3">
            <div class="col-md-5"><label class="form-label">Nome do portal</label><input type="text" name="portal_titulo" class="form-control" maxlength="150" placeholder="<?= e($config['nome_sistema']) ?>" value="<?= e($config['portal_titulo'] ?? '') ?>"></div>
            <div class="col-md-7"><label class="form-label">Frase de boas-vindas</label><input type="text" name="portal_subtitulo" class="form-control" maxlength="255" placeholder="Ex.: Sua jornada de aprendizado começa aqui." value="<?= e($config['portal_subtitulo'] ?? '') ?>"><div class="form-text">Aparece no bloco "Saudação".</div></div>
            <div class="col-12 form-check form-switch ms-2"><input type="checkbox" class="form-check-input" role="switch" name="portal_cadastro_aberto" id="cadastro" <?= $config['portal_cadastro_aberto'] ? 'checked' : '' ?>><label class="form-check-label" for="cadastro">Qualquer pessoa pode criar conta de aluno pelo portal</label></div>
            <div class="col-12"><label class="form-label">Endereço do portal para divulgar</label><input type="text" class="form-control" readonly value="<?= e(urlAbsoluta('/portal/')) ?>"></div>
        </div>
    </div>

    <?php $temCoresProprias = !empty($config['portal_cor_primaria']); ?>
    <div class="secao">
        <div class="secao-titulo"><i class="bi bi-palette"></i> Cores do site</div>
        <div class="form-check form-switch mb-3">
            <input type="checkbox" class="form-check-input" role="switch" name="portal_cores_proprias" id="cores-proprias" <?= $temCoresProprias ? 'checked' : '' ?> onchange="atualizarCores()">
            <label class="form-check-label" for="cores-proprias">Usar cores próprias no portal <span class="text-muted small">(desligado: as mesmas cores do sistema, de Configurações)</span></label>
        </div>
        <div class="row g-3 align-items-end" id="campos-cores">
            <div class="col-md-3">
                <label class="form-label">Cor principal</label>
                <input type="color" name="portal_cor_primaria" id="cor-primaria" class="form-control form-control-color w-100" value="<?= e($config['portal_cor_primaria'] ?: ($config['cor_primaria'] ?: '#16a34a')) ?>" oninput="atualizarCores()">
                <div class="form-text">Botões, links, menu ativo, ícones.</div>
            </div>
            <div class="col-md-3">
                <label class="form-label">Cor de destaque escura</label>
                <input type="color" name="portal_cor_secundaria" id="cor-secundaria" class="form-control form-control-color w-100" value="<?= e($config['portal_cor_secundaria'] ?: ($config['cor_secundaria'] ?: '#15803d')) ?>" oninput="atualizarCores()">
                <div class="form-text">Botão ao passar o mouse, títulos.</div>
            </div>
            <div class="col-md-6">
                <label class="form-label">Como fica</label>
                <div class="border rounded p-3 d-flex align-items-center gap-3 flex-wrap" id="previa-cores">
                    <span class="btn btn-sm text-white" id="previa-botao">Inscrever-se</span>
                    <span class="fw-semibold" id="previa-titulo">Título de seção</span>
                    <span class="badge rounded-pill" id="previa-selo">🔥 3 vagas restantes</span>
                </div>
            </div>
        </div>
    </div>

    <?php foreach (['topo' => ['Menu do topo', 'bi-menu-button-wide', 'À direita do menu ficam sempre "Entrar / Criar conta" (visitante) ou o nome do aluno com "Meus dados" e "Sair" (aluno logado).'],
                    'rodape' => ['Links do rodapé', 'bi-link-45deg', 'Links pequenos no rodapé (política de privacidade, conferir certificado, regulamento...).']] as $local => [$tituloSecao, $icone, $ajuda]): ?>
        <div class="secao">
            <div class="secao-titulo"><i class="bi <?= $icone ?>"></i> <?= $tituloSecao ?></div>
            <p class="small text-muted"><?= e($ajuda) ?> Arraste pelo <i class="bi bi-grip-vertical"></i> para mudar a ordem.</p>
            <div class="lista-menu" id="menu-<?= $local ?>" data-local="<?= $local ?>">
                <?php foreach ($itensMenu[$local] as $item): ?>
                    <div class="linha-menu" data-item='<?= e(json_encode(['rotulo' => $item['rotulo'], 'destino' => $item['pagina_id'] ? 'pagina:' . $item['pagina_id'] : 'link', 'url' => $item['url'], 'publico' => $item['publico'], 'nova_aba' => (bool) $item['nova_aba'], 'ativo' => (bool) $item['ativo']])) ?>'></div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="adicionarItem('<?= $local ?>')"><i class="bi bi-plus-lg"></i> Item</button>
        </div>
    <?php endforeach; ?>

    <div class="secao">
        <div class="secao-titulo"><i class="bi bi-layout-text-window-reverse"></i> Rodapé</div>
        <div class="row g-3">
            <div class="col-12">
                <div class="form-label mb-1">O que aparece no rodapé <span class="text-muted small">(dados de Configurações › Empresa)</span></div>
                <div class="d-flex flex-wrap gap-3">
                    <?php $itensRodapeAtuais = itensRodapePortal($config); ?>
                    <?php foreach (opcoesRodapePortal() as $chave => [$rotuloItem, $iconeItem]): ?>
                        <div class="form-check form-switch"><input type="checkbox" class="form-check-input" role="switch" name="rodape_itens[<?= $chave ?>]" id="rodape-<?= $chave ?>" <?= $itensRodapeAtuais[$chave] ? 'checked' : '' ?>><label class="form-check-label" for="rodape-<?= $chave ?>"><i class="bi <?= $iconeItem ?>"></i> <?= $rotuloItem ?></label></div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="col-md-5">
                <label class="form-label"><i class="bi bi-whatsapp"></i> WhatsApp da escola</label>
                <input type="text" name="whatsapp_numero" class="form-control" inputmode="numeric" placeholder="Ex.: (77) 99999-9999" value="<?= e($config['whatsapp_numero'] ?? '') ?>">
                <div class="form-text">Com DDD. Usado no botão verde do canto da tela e/ou no link do rodapé (escolha acima). Vazio = não mostra.</div>
            </div>
            <div class="col-12"><label class="form-label">Texto do rodapé</label><textarea name="portal_rodape_texto" class="form-control" rows="2" placeholder="Ex.: Credenciada pelo MEC — Portaria nº ..."><?= e($config['portal_rodape_texto'] ?? '') ?></textarea></div>
            <?php foreach (redesSociaisPortal() as $rede => [$nomeRede, $iconeRede]): ?>
                <div class="col-md-4"><label class="form-label"><i class="bi <?= $iconeRede ?>"></i> <?= $nomeRede ?></label><input type="url" name="redes[<?= $rede ?>]" class="form-control" placeholder="https://..." value="<?= e($redes[$rede] ?? '') ?>"></div>
            <?php endforeach; ?>
        </div>
    </div>
</form>

<script>
function atualizarCores() {
    const ligado = document.getElementById('cores-proprias').checked;
    document.getElementById('campos-cores').style.opacity = ligado ? 1 : .45;
    document.querySelectorAll('#campos-cores input').forEach((el) => { el.disabled = !ligado; });
    const p = document.getElementById('cor-primaria').value, s = document.getElementById('cor-secundaria').value;
    document.getElementById('previa-botao').style.background = p;
    document.getElementById('previa-titulo').style.color = s;
    const selo = document.getElementById('previa-selo');
    selo.style.background = 'color-mix(in srgb, ' + p + ' 12%, white)';
    selo.style.color = s;
}
atualizarCores();
</script>

<template id="modelo-item">
    <div class="linha-menu">
        <span class="arrastar"><i class="bi bi-grip-vertical"></i></span>
        <input type="text" class="form-control form-control-sm" data-campo="rotulo" maxlength="60" placeholder="Nome no menu">
        <div class="d-flex gap-1">
            <select class="form-select form-select-sm" data-campo="destino">
                <optgroup label="Páginas do portal">
                    <?php foreach ($paginas as $p): ?><option value="pagina:<?= (int) $p['id'] ?>"><?= e($p['titulo']) ?></option><?php endforeach; ?>
                </optgroup>
                <option value="link">Outro endereço (link)…</option>
            </select>
            <input type="text" class="form-control form-control-sm" data-campo="url" placeholder="https://...">
        </div>
        <select class="form-select form-select-sm" data-campo="publico">
            <?php foreach (publicosBlocoPortal() as $chave => $rotulo): ?><option value="<?= e($chave) ?>"><?= e($rotulo) ?></option><?php endforeach; ?>
        </select>
        <div class="d-flex gap-2 align-items-center text-nowrap">
            <label class="small"><input type="checkbox" class="form-check-input" data-campo="nova_aba"> outra aba</label>
            <label class="small"><input type="checkbox" class="form-check-input" data-campo="ativo"> ligado</label>
            <button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('.linha-menu').remove(); renumerar();"><i class="bi bi-x-lg"></i></button>
        </div>
    </div>
</template>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.2/Sortable.min.js"></script>
<script>
// Cada linha vira campos <local>[i][campo]; a ordem das linhas é a do menu.
function montarLinha(linha, dados) {
    const nova = document.getElementById('modelo-item').content.firstElementChild.cloneNode(true);
    linha.replaceWith(nova);
    const d = Object.assign({ rotulo: '', destino: 'link', url: '', publico: 'todos', nova_aba: false, ativo: true }, dados);
    nova.querySelectorAll('[data-campo]').forEach((c) => { c.type === 'checkbox' ? c.checked = !!d[c.dataset.campo] : c.value = d[c.dataset.campo] ?? ''; });
    const destino = nova.querySelector('[data-campo="destino"]'), url = nova.querySelector('[data-campo="url"]');
    const atualizar = () => { url.style.display = destino.value === 'link' ? '' : 'none'; };
    destino.addEventListener('change', atualizar); atualizar();
    return nova;
}
function renumerar() {
    document.querySelectorAll('.lista-menu').forEach((lista) => lista.querySelectorAll('.linha-menu').forEach((linha, i) =>
        linha.querySelectorAll('[data-campo]').forEach((c) => { c.name = `${lista.dataset.local}[${i}][${c.dataset.campo}]`; if (c.type === 'checkbox') c.value = '1'; })));
}
function adicionarItem(local) {
    const vazio = document.createElement('div');
    document.getElementById('menu-' + local).appendChild(vazio);
    montarLinha(vazio, {}).querySelector('[data-campo="rotulo"]').focus();
    renumerar();
}
document.querySelectorAll('.linha-menu[data-item]').forEach((l) => montarLinha(l, JSON.parse(l.dataset.item)));
document.querySelectorAll('.lista-menu').forEach((lista) => Sortable.create(lista, { handle: '.arrastar', animation: 150, onEnd: renumerar }));
renumerar();
</script>
<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
