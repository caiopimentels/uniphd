<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
exigirPermissaoAcao('pagar_conta', 'Seu perfil não pode dar baixa em contas a pagar.');

$pdo = getDB();
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

$stmt = $pdo->prepare(
    'SELECT cp.*, f.nome AS fornecedor_nome
     FROM contas_pagar cp LEFT JOIN fornecedores f ON f.id = cp.fornecedor_id
     WHERE cp.id = ?'
);
$stmt->execute([$id]);
$conta = $stmt->fetch();

if (!$conta || $conta['status'] !== 'pendente') {
    flash('danger', 'Este lançamento não pode ser baixado.');
    redirect('/financeiro/contas_pagar/listar.php');
}

$formasPagamento = $pdo->query("SELECT * FROM formas_pagamento WHERE ativo = 1 ORDER BY nome")->fetchAll();

// Chamada da tela da turma (despesas da turma): volta para lá.
$urlVoltar = ($_GET['voltar'] ?? $_POST['voltar'] ?? '') === 'turma' && $conta['turma_id']
    ? '/turmas/visualizar.php?id=' . (int) $conta['turma_id'] : '/financeiro/contas_pagar/listar.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dataPagamento = input('data_pagamento') ?: date('Y-m-d');
    $formaPagamentoId = (int) ($_POST['forma_pagamento_id'] ?? 0) ?: null;

    $stmt = $pdo->prepare("UPDATE contas_pagar SET status = 'pago', data_pagamento = ?, forma_pagamento_id = ? WHERE id = ?");
    $stmt->execute([$dataPagamento, $formaPagamentoId, $id]);

    registrarAtividade('contas_pagar', 'baixa', 'Conta a pagar baixada: ' . $conta['descricao'] . ' - ' . formatMoney($conta['valor']));

    flash('success', 'Conta baixada com sucesso.');
    redirect($urlVoltar);
}

$pageTitle = 'Baixar conta a pagar';
$activeMenu = 'contas-pagar';
require __DIR__ . '/../../../includes/layout_header.php';
?>

<div class="card" style="max-width: 480px;">
    <div class="card-body">
        <p><strong><?= e($conta['descricao']) ?></strong><?php if ($conta['fornecedor_nome']): ?> · <?= e($conta['fornecedor_nome']) ?><?php endif; ?></p>
        <p>Valor: <strong><?= formatMoney($conta['valor']) ?></strong> · Vencimento: <?= formatDate($conta['data_vencimento']) ?></p>
        <form method="post">
            <input type="hidden" name="voltar" value="<?= e($_GET['voltar'] ?? '') ?>">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Data do pagamento</label>
                    <input type="date" name="data_pagamento" class="form-control" value="<?= date('Y-m-d') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Forma de pagamento</label>
                    <select name="forma_pagamento_id" class="form-select">
                        <option value="">-</option>
                        <?php foreach ($formasPagamento as $f): ?>
                            <option value="<?= (int) $f['id'] ?>"><?= e($f['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="mt-3 d-flex gap-2">
                <button type="submit" class="btn btn-brand"><i class="bi bi-check-lg"></i> Confirmar baixa</button>
                <a href="<?= e($urlVoltar) ?>" class="btn btn-outline-secondary">Cancelar</a>
            </div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../../../includes/layout_footer.php'; ?>
