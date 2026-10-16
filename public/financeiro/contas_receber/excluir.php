<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
exigirPermissaoAcao('excluir_receber', 'Seu perfil não pode excluir lançamentos a receber.');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/financeiro/contas_receber/listar.php');
}

$id = (int) ($_POST['id'] ?? 0);
$pdo = getDB();

$stmt = $pdo->prepare('SELECT * FROM contas_receber WHERE id = ?');
$stmt->execute([$id]);
$conta = $stmt->fetch();

if (!$conta) {
    flash('danger', 'Lançamento não encontrado.');
    redirect('/financeiro/contas_receber/listar.php');
}

// Parcela com boleto/Pix no banco não some daqui: o registro no banco (e o
// pagamento, se houve) continua existindo.
$stmt = $pdo->prepare('SELECT COUNT(*) FROM cobrancas_bancarias WHERE conta_receber_id = ?');
$stmt->execute([$id]);
if ((int) $stmt->fetchColumn() > 0) {
    flash('danger', 'Esta parcela tem boleto/Pix registrado no banco e não pode ser excluída.');
    redirect('/financeiro/contas_receber/listar.php');
}

$stmt = $pdo->prepare('DELETE FROM contas_receber WHERE id = ?');
$stmt->execute([$id]);

registrarAtividade('contas_receber', 'exclusao', 'Conta a receber excluída: ' . $conta['descricao'] . ' - ' . formatMoney($conta['valor']));

flash('success', 'Lançamento excluído.');
redirect('/financeiro/contas_receber/listar.php');
