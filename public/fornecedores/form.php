<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();
exigirFornecedores();

$pdo = getDB();
$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
$fornecedor = null;
$tabAtiva = $_GET['tab'] ?? 'dados';

if ($id) {
    $stmt = $pdo->prepare('SELECT * FROM fornecedores WHERE id = ?');
    $stmt->execute([$id]);
    $fornecedor = $stmt->fetch();
    if (!$fornecedor) {
        flash('danger', 'Fornecedor não encontrado.');
        redirect('/fornecedores/listar.php');
    }
}

$erros = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome = input('nome');
    $documento = input('documento');
    $telefone = input('telefone');
    $email = input('email');
    $endereco = input('endereco');
    $ativo = isset($_POST['ativo']) ? 1 : 0;

    if ($nome === '') {
        $erros[] = 'O nome é obrigatório.';
    }

    if (!$erros) {
        if ($id) {
            $stmt = $pdo->prepare('UPDATE fornecedores SET nome=?, documento=?, telefone=?, email=?, endereco=?, ativo=? WHERE id=?');
            $stmt->execute([$nome, $documento ?: null, $telefone ?: null, $email ?: null, $endereco ?: null, $ativo, $id]);
            registrarAtividade('fornecedores', 'edicao', 'Fornecedor editado: ' . $nome);
        } else {
            $stmt = $pdo->prepare('INSERT INTO fornecedores (nome, documento, telefone, email, endereco, ativo) VALUES (?,?,?,?,?,?)');
            $stmt->execute([$nome, $documento ?: null, $telefone ?: null, $email ?: null, $endereco ?: null, $ativo]);
            $id = (int) $pdo->lastInsertId();
            registrarAtividade('fornecedores', 'criacao', 'Fornecedor criado: ' . $nome);
        }
        flash('success', 'Fornecedor salvo com sucesso.');
        redirect('/fornecedores/form.php?id=' . $id);
    }

    $fornecedor = ['nome' => $nome, 'documento' => $documento, 'telefone' => $telefone, 'email' => $email, 'endereco' => $endereco, 'ativo' => $ativo];
}

$historico = [];
if ($id) {
    $stmt = $pdo->prepare(
        "SELECT cp.*, c.nome AS categoria_nome
         FROM contas_pagar cp LEFT JOIN categorias_financeiras c ON c.id = cp.categoria_id
         WHERE cp.fornecedor_id = ? ORDER BY cp.data_vencimento DESC"
    );
    $stmt->execute([$id]);
    $historico = $stmt->fetchAll();
}

$pageTitle = $id ? e($fornecedor['nome']) : 'Novo fornecedor';
$pageBreadcrumb = 'Financeiro · Fornecedores';
$activeMenu = 'fornecedores';
$pageAction = '<a href="/fornecedores/listar.php" class="btn btn-outline-secondary">Cancelar</a>'
    . '<button type="submit" form="form-fornecedor" class="btn btn-brand"><i class="bi bi-check-lg"></i> Salvar</button>';
require __DIR__ . '/../../includes/layout_header.php';
?>

<?php if ($erros): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($erros as $erro): ?><li><?= e($erro) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<ul class="nav nav-tabs mb-3">
    <li class="nav-item"><a class="nav-link <?= $tabAtiva === 'dados' ? 'active' : '' ?>" href="#" data-tab="dados">Dados gerais</a></li>
    <?php if ($id): ?>
    <li class="nav-item"><a class="nav-link <?= $tabAtiva === 'historico' ? 'active' : '' ?>" href="#" data-tab="historico">Lançamentos</a></li>
    <?php endif; ?>
</ul>

<div class="tab-pane" id="pane-dados" style="<?= $tabAtiva === 'dados' ? '' : 'display:none;' ?>">
        <form method="post" id="form-fornecedor">
            <div class="secao">
            <div class="secao-titulo"><i class="bi bi-truck"></i> Dados do fornecedor</div>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Nome *</label>
                    <input type="text" name="nome" class="form-control" required value="<?= e($fornecedor['nome'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">CPF/CNPJ</label>
                    <input type="text" name="documento" class="form-control" value="<?= e($fornecedor['documento'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Telefone</label>
                    <input type="text" name="telefone" class="form-control" value="<?= e($fornecedor['telefone'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">E-mail</label>
                    <input type="email" name="email" class="form-control" value="<?= e($fornecedor['email'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Endereço</label>
                    <input type="text" name="endereco" class="form-control" value="<?= e($fornecedor['endereco'] ?? '') ?>">
                </div>
                <div class="col-12 form-check ps-4">
                    <input type="checkbox" name="ativo" class="form-check-input" id="ativo" <?= ($fornecedor['ativo'] ?? 1) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="ativo">Fornecedor ativo</label>
                </div>
            </div>
            </div>
        </form>
</div>

<?php if ($id): ?>
<div class="tab-pane" id="pane-historico" style="<?= $tabAtiva === 'historico' ? '' : 'display:none;' ?>">
    <div class="secao">
        <div class="secao-titulo"><i class="bi bi-clock-history"></i> Contas a pagar deste fornecedor</div>
    </div>
    <div class="card">
        <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle">
                <thead><tr><th>Descrição</th><th>Categoria</th><th>Vencimento</th><th class="text-end">Valor</th><th>Status</th></tr></thead>
                <tbody>
                <?php if (!$historico): ?>
                    <tr><td colspan="5" class="text-center text-muted py-3">Nenhum lançamento para este fornecedor.</td></tr>
                <?php endif; ?>
                <?php foreach ($historico as $c): ?>
                    <tr>
                        <td><?= e($c['descricao']) ?></td>
                        <td><?= e($c['categoria_nome'] ?: '-') ?></td>
                        <td><?= formatDate($c['data_vencimento']) ?></td>
                        <td class="text-end"><?= formatMoney($c['valor']) ?></td>
                        <td><span class="badge <?= contaStatusBadgeClass($c['status']) ?>"><?= contaStatusLabel($c['status']) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
document.querySelectorAll('[data-tab]').forEach(function (link) {
    link.addEventListener('click', function (ev) {
        ev.preventDefault();
        document.querySelectorAll('[data-tab]').forEach(l => l.classList.remove('active'));
        document.querySelectorAll('.tab-pane').forEach(p => p.style.display = 'none');
        this.classList.add('active');
        document.getElementById('pane-' + this.dataset.tab).style.display = '';
        history.replaceState(null, '', '?id=<?= (int) ($id ?? 0) ?>&tab=' + this.dataset.tab);
    });
});
</script>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
