<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();
exigirFormasPagamento();

$pdo = getDB();
$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
$forma = null;

if ($id) {
    $stmt = $pdo->prepare('SELECT * FROM formas_pagamento WHERE id = ?');
    $stmt->execute([$id]);
    $forma = $stmt->fetch();
    if (!$forma) {
        flash('danger', 'Forma de pagamento não encontrada.');
        redirect('/formas_pagamento/listar.php');
    }
}

$erros = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome = input('nome');
    $instrumento = array_key_exists(input('instrumento'), instrumentosCobranca()) ? input('instrumento') : 'manual';
    $contaBancariaId = (int) ($_POST['conta_bancaria_id'] ?? 0) ?: null;
    $ativo = isset($_POST['ativo']) ? 1 : 0;

    if ($nome === '') {
        $erros[] = 'O nome é obrigatório.';
    }
    // Boleto e Pix precisam saber em qual conta cair; forma manual pode ter
    // conta (ex.: transferência) só para informação, sem gerar cobrança.
    if ($instrumento !== 'manual' && !$contaBancariaId) {
        $erros[] = 'Boleto e Pix precisam de uma conta bancária vinculada.';
    }

    if (!$erros) {
        if ($id) {
            $stmt = $pdo->prepare('UPDATE formas_pagamento SET nome=?, instrumento=?, conta_bancaria_id=?, ativo=? WHERE id=?');
            $stmt->execute([$nome, $instrumento, $contaBancariaId, $ativo, $id]);
            registrarAtividade('formas_pagamento', 'edicao', 'Forma de pagamento editada: ' . $nome);
        } else {
            $stmt = $pdo->prepare('INSERT INTO formas_pagamento (nome, instrumento, conta_bancaria_id, ativo) VALUES (?,?,?,?)');
            $stmt->execute([$nome, $instrumento, $contaBancariaId, $ativo]);
            registrarAtividade('formas_pagamento', 'criacao', 'Forma de pagamento criada: ' . $nome);
        }
        flash('success', 'Forma de pagamento salva com sucesso.');
        redirect('/formas_pagamento/listar.php');
    }

    $forma = ['nome' => $nome, 'instrumento' => $instrumento, 'conta_bancaria_id' => $contaBancariaId, 'ativo' => $ativo];
}

$stmt = $pdo->prepare('SELECT id, apelido, banco_nome, integracao FROM contas_bancarias WHERE ativo = 1 OR id = ? ORDER BY apelido');
$stmt->execute([(int) ($forma['conta_bancaria_id'] ?? 0)]);
$contasBancarias = $stmt->fetchAll();

$pageTitle = $id ? e($forma['nome']) : 'Nova forma de pagamento';
$pageBreadcrumb = 'Financeiro · Formas de pagamento';
$activeMenu = 'formas-pagamento';
$pageAction = '<a href="/formas_pagamento/listar.php" class="btn btn-outline-secondary">Cancelar</a>'
    . '<button type="submit" form="form-principal" class="btn btn-brand"><i class="bi bi-check-lg"></i> Salvar</button>';
require __DIR__ . '/../../includes/layout_header.php';
?>

<?php if ($erros): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($erros as $erro): ?><li><?= e($erro) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="secao" style="max-width: 640px;">
    <div class="secao-titulo"><i class="bi bi-credit-card"></i> Dados da forma de pagamento</div>
        <form method="post" id="form-principal">
            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label">Nome *</label>
                    <input type="text" name="nome" class="form-control" required value="<?= e($forma['nome'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Como a cobrança é feita</label>
                    <select name="instrumento" class="form-select">
                        <?php foreach (instrumentosCobranca() as $chave => $rotulo): ?>
                            <option value="<?= e($chave) ?>" <?= ($forma['instrumento'] ?? 'manual') === $chave ? 'selected' : '' ?>><?= e($rotulo) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Conta bancária</label>
                    <select name="conta_bancaria_id" class="form-select">
                        <option value="">Nenhuma</option>
                        <?php foreach ($contasBancarias as $cb): ?>
                            <option value="<?= (int) $cb['id'] ?>" <?= (int) ($forma['conta_bancaria_id'] ?? 0) === (int) $cb['id'] ? 'selected' : '' ?>>
                                <?= e($cb['apelido']) ?> (<?= e($cb['banco_nome']) ?><?= $cb['integracao'] === 'itau' ? ' · integrada' : '' ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (!$contasBancarias): ?>
                        <div class="form-text">Nenhuma conta cadastrada. <a href="/contas_bancarias/form.php">Cadastrar conta</a> (Configurações › Contas bancárias).</div>
                    <?php endif; ?>
                </div>
                <div class="col-12 form-check ps-4">
                    <input type="checkbox" name="ativo" class="form-check-input" id="ativo" <?= ($forma['ativo'] ?? 1) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="ativo">Ativa</label>
                </div>

            </div>
        </form>
</div>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
