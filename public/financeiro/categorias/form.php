<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
exigirPermissaoAcao('gerenciar_categorias', 'Seu perfil não pode cadastrar categorias financeiras.');

$pdo = getDB();
$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
$categoria = null;

if ($id) {
    $stmt = $pdo->prepare('SELECT * FROM categorias_financeiras WHERE id = ?');
    $stmt->execute([$id]);
    $categoria = $stmt->fetch();
    if (!$categoria) {
        flash('danger', 'Categoria não encontrada.');
        redirect('/financeiro/categorias/listar.php');
    }
}

$erros = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome = input('nome');
    $tipo = input('tipo') === 'receita' ? 'receita' : 'despesa';
    $ativo = isset($_POST['ativo']) ? 1 : 0;

    if ($nome === '') {
        $erros[] = 'O nome é obrigatório.';
    }

    // Categoria usada pelo sistema (mensalidade, taxa de matrícula) pode ser
    // renomeada, mas não trocar de tipo nem ser desativada — as matrículas
    // continuam gerando lançamentos nela.
    if (!empty($categoria['chave'])) {
        $tipo = $categoria['tipo'];
        $ativo = 1;
    }

    if (!$erros) {
        if ($id) {
            $stmt = $pdo->prepare('UPDATE categorias_financeiras SET nome=?, tipo=?, ativo=? WHERE id=?');
            $stmt->execute([$nome, $tipo, $ativo, $id]);
            registrarAtividade('categorias_financeiras', 'edicao', 'Categoria financeira editada: ' . $nome);
        } else {
            $stmt = $pdo->prepare('INSERT INTO categorias_financeiras (nome, tipo, ativo) VALUES (?,?,?)');
            $stmt->execute([$nome, $tipo, $ativo]);
            registrarAtividade('categorias_financeiras', 'criacao', 'Categoria financeira criada: ' . $nome);
        }
        flash('success', 'Categoria salva com sucesso.');
        redirect('/financeiro/categorias/listar.php');
    }

    $categoria = ['nome' => $nome, 'tipo' => $tipo, 'ativo' => $ativo, 'chave' => $categoria['chave'] ?? null];
}

$pageTitle = $id ? 'Editar categoria' : 'Nova categoria';
$pageBreadcrumb = 'Financeiro · Categorias';
$pageAction = '<a href="/financeiro/categorias/listar.php" class="btn btn-outline-secondary">Cancelar</a>'
    . '<button type="submit" form="form-principal" class="btn btn-brand"><i class="bi bi-check-lg"></i> Salvar</button>';
$activeMenu = 'categorias-financeiras';
require __DIR__ . '/../../../includes/layout_header.php';
?>

<?php if ($erros): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($erros as $erro): ?><li><?= e($erro) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="secao" style="max-width: 480px;">
    <div class="secao-titulo"><i class="bi bi-tags"></i> Dados da categoria</div>
        <form method="post" id="form-principal">
            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label">Nome *</label>
                    <input type="text" name="nome" class="form-control" required value="<?= e($categoria['nome'] ?? '') ?>">
                </div>
                <div class="col-12">
                    <label class="form-label">Tipo</label>
                    <select name="tipo" class="form-select" <?= !empty($categoria['chave']) ? 'disabled' : '' ?>>
                        <option value="despesa" <?= ($categoria['tipo'] ?? 'despesa') === 'despesa' ? 'selected' : '' ?>>Despesa</option>
                        <option value="receita" <?= ($categoria['tipo'] ?? '') === 'receita' ? 'selected' : '' ?>>Receita</option>
                    </select>
                    <?php if (!empty($categoria['chave'])): ?>
                        <div class="form-text">Categoria usada pelas matrículas: pode ser renomeada, mas não muda de tipo nem pode ser desativada.</div>
                    <?php endif; ?>
                </div>
                <div class="col-12 form-check ps-4">
                    <input type="checkbox" name="ativo" class="form-check-input" id="ativo" <?= ($categoria['ativo'] ?? 1) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="ativo">Ativa</label>
                </div>
            </div>
        </form>
</div>

<?php require __DIR__ . '/../../../includes/layout_footer.php'; ?>
