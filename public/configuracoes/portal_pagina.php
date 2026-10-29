<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirPortal();

// Página nova do site (Sobre nós, Contato, Promoções...): nome, endereço,
// quem pode ver e se entra no menu. Os blocos dela são montados no editor.
$pdo = getDB();
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$pagina = $id ? buscarPaginaPortal($pdo, 'id', $id) : null;
if ($id && (!$pagina || $pagina['tipo'] !== 'personalizada')) {
    flash('danger', 'Página não encontrada.');
    redirect('/configuracoes/portal.php');
}
$stmt = $pdo->prepare("SELECT COUNT(*) FROM portal_menu WHERE local = 'topo' AND pagina_id = ?");
$stmt->execute([$id]);
$noMenu = $pagina ? (bool) $stmt->fetchColumn() : true;
$erros = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && input('acao') === 'excluir' && $pagina) {
    $stmt = $pdo->prepare("SELECT b.config, i.imagem FROM portal_blocos b LEFT JOIN portal_banner_imagens i ON i.bloco_id = b.id WHERE b.pagina_id = ?");
    $stmt->execute([$pagina['id']]);
    $arquivos = [];
    foreach ($stmt->fetchAll() as $linha) {
        $arquivos[] = $linha['imagem'];
        $arquivos[] = configBloco($linha)['imagem'] ?? null;
    }
    // Blocos e itens de menu da página vão junto (ON DELETE CASCADE).
    $pdo->prepare('DELETE FROM portal_paginas WHERE id = ?')->execute([$pagina['id']]);
    array_map('apagarImagemPortal', array_filter(array_unique($arquivos)));
    registrarAtividade('portal', 'exclusao', 'Página do portal excluída: ' . $pagina['titulo']);
    flash('success', 'Página "' . $pagina['titulo'] . '" excluída.');
    redirect('/configuracoes/portal.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $titulo = mb_substr(trim(input('titulo')), 0, 150);
    $slug = slugPortal(input('slug') ?: $titulo);
    $publico = input('publico') === 'alunos' ? 'alunos' : 'todos';
    $ativo = isset($_POST['ativo']) ? 1 : 0;
    $noMenu = isset($_POST['no_menu']);
    if ($titulo === '') {
        $erros[] = 'Dê um nome à página.';
    }
    $outra = buscarPaginaPortal($pdo, 'slug', $slug);
    if ($outra && (int) $outra['id'] !== $id) {
        $erros[] = 'Já existe uma página com o endereço "' . $slug . '". Escolha outro.';
    }
    if (!$erros) {
        $pdo->beginTransaction();
        if ($pagina) {
            $pdo->prepare('UPDATE portal_paginas SET titulo = ?, slug = ?, publico = ?, ativo = ? WHERE id = ?')->execute([$titulo, $slug, $publico, $ativo, $id]);
        } else {
            $pdo->prepare("INSERT INTO portal_paginas (tipo, titulo, slug, publico, ativo, ordem) VALUES ('personalizada', ?, ?, ?, ?, 100)")->execute([$titulo, $slug, $publico, $ativo]);
            $id = (int) $pdo->lastInsertId();
        }
        // Item do menu do topo: cria no fim ou tira, conforme a escolha.
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM portal_menu WHERE local = 'topo' AND pagina_id = ?");
        $stmt->execute([$id]);
        $temItem = (bool) $stmt->fetchColumn();
        if ($noMenu && !$temItem) {
            $ordem = (int) $pdo->query("SELECT COALESCE(MAX(ordem), 0) + 10 FROM portal_menu WHERE local = 'topo'")->fetchColumn();
            $pdo->prepare("INSERT INTO portal_menu (local, rotulo, pagina_id, publico, ordem) VALUES ('topo', ?, ?, ?, ?)")
                ->execute([mb_substr($titulo, 0, 60), $id, $publico === 'alunos' ? 'alunos' : 'todos', $ordem]);
        } elseif (!$noMenu && $temItem) {
            $pdo->prepare("DELETE FROM portal_menu WHERE local = 'topo' AND pagina_id = ?")->execute([$id]);
        }
        $pdo->commit();
        registrarAtividade('portal', $pagina ? 'edicao' : 'criacao', 'Página do portal salva: ' . $titulo);
        flash('success', $pagina ? 'Página salva.' : 'Página criada. Agora adicione os blocos dela.');
        redirect('/configuracoes/portal.php?pagina=' . $id);
    }
    $pagina = ['titulo' => $titulo, 'slug' => $slug, 'publico' => $publico, 'ativo' => $ativo] + ($pagina ?? []);
}

$pageTitle = $id ? 'Página: ' . $pagina['titulo'] : 'Nova página';
$pageBreadcrumb = 'Portal · Site do portal';
$activeMenu = 'portal';
$pageAction = '<a href="/configuracoes/portal.php' . ($id ? '?pagina=' . $id : '') . '" class="btn btn-outline-secondary">Cancelar</a>'
    . '<button type="submit" form="form-pagina" class="btn btn-brand"><i class="bi bi-check-lg"></i> Salvar</button>';
require __DIR__ . '/../../includes/layout_header.php';
?>
<?php if ($erros): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($erros as $erro): ?><li><?= e($erro) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<form method="post" id="form-pagina" class="secao" style="max-width: 760px;">
    <input type="hidden" name="id" value="<?= (int) $id ?>">
    <div class="row g-3">
        <div class="col-md-7">
            <label class="form-label">Nome da página *</label>
            <input type="text" name="titulo" id="titulo" class="form-control" maxlength="150" required placeholder="Ex.: Sobre nós" value="<?= e($pagina['titulo'] ?? '') ?>" oninput="sugerirEndereco()">
        </div>
        <div class="col-md-5">
            <label class="form-label">Endereço</label>
            <div class="input-group"><span class="input-group-text small">/portal/pagina.php?p=</span>
                <input type="text" name="slug" id="slug" class="form-control" maxlength="80" value="<?= e($pagina['slug'] ?? '') ?>" oninput="this.dataset.editado = 1"></div>
            <div class="form-text">Só letras minúsculas, números e hífen. É o link para divulgar.</div>
        </div>
        <div class="col-md-7">
            <label class="form-label">Quem pode ver</label>
            <select name="publico" class="form-select">
                <option value="todos" <?= ($pagina['publico'] ?? 'todos') === 'todos' ? 'selected' : '' ?>>Todo mundo (página aberta, para divulgar)</option>
                <option value="alunos" <?= ($pagina['publico'] ?? '') === 'alunos' ? 'selected' : '' ?>>Só alunos logados (pede o login)</option>
            </select>
        </div>
        <div class="col-12 form-check form-switch ms-2">
            <input type="checkbox" class="form-check-input" role="switch" name="no_menu" id="no_menu" <?= $noMenu ? 'checked' : '' ?>>
            <label class="form-check-label" for="no_menu">Colocar no menu do topo</label>
        </div>
        <div class="col-12 form-check form-switch ms-2">
            <input type="checkbox" class="form-check-input" role="switch" name="ativo" id="ativo" <?= ($pagina['ativo'] ?? 1) ? 'checked' : '' ?>>
            <label class="form-check-label" for="ativo">Publicada (desligada, só você vê na prévia do editor)</label>
        </div>
    </div>
</form>

<?php if ($id): ?>
<form method="post" style="max-width: 760px;" onsubmit="return confirm('Excluir esta página e todos os blocos dela? Esta ação não pode ser desfeita.');">
    <input type="hidden" name="id" value="<?= (int) $id ?>"><input type="hidden" name="acao" value="excluir">
    <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i> Excluir página</button>
</form>
<?php endif; ?>

<script>
// Sugere o endereço a partir do nome, enquanto a pessoa não mexer nele.
function sugerirEndereco() {
    const slug = document.getElementById('slug');
    if (slug.dataset.editado || <?= $id ? 'true' : 'false' ?>) return;
    slug.value = document.getElementById('titulo').value.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
}
</script>
<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
