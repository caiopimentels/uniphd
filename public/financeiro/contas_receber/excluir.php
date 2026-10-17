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

$voltar = match (input('voltar')) {
    'aluno' => $conta['aluno_id'] ? '/alunos/form.php?id=' . (int) $conta['aluno_id'] . '&tab=financeiro' : null,
    'matricula' => $conta['matricula_id'] ? '/matriculas/visualizar.php?id=' . (int) $conta['matricula_id'] : null,
    default => null,
} ?? '/financeiro/contas_receber/listar.php';

// Parcela que já passou pelo banco não some: o histórico do boleto fica.
// Se o boleto está registrado, o banco recebe a baixa antes (sem a baixa,
// nada muda aqui) e a parcela fica como cancelada.
$stmt = $pdo->prepare('SELECT COUNT(*) FROM cobrancas_bancarias WHERE conta_receber_id = ?');
$stmt->execute([$id]);
if ((int) $stmt->fetchColumn() > 0) {
    if ($conta['status'] === 'pago') {
        flash('danger', 'Esta parcela já foi paga pelo banco e não pode ser excluída.');
        redirect($voltar);
    }
    $baixa = baixarBoletoBancoItau($pdo, $id);
    if (!$baixa['ok']) {
        flash('danger', $baixa['mensagem'] . ' A parcela não foi excluída.');
        redirect($voltar);
    }
    $pdo->prepare("UPDATE contas_receber SET status = 'cancelado' WHERE id = ?")->execute([$id]);
    registrarHistoricoParcela($pdo, $id, 'cancelamento', 'Excluída pelo usuário' . ($baixa['havia_boleto'] ? ' — boleto baixado no banco' : '') . ' (fica como cancelada, porque já passou pelo banco)');
    registrarAtividade('contas_receber', 'exclusao', 'Conta a receber cancelada (boleto baixado no banco): ' . $conta['descricao'] . ' - ' . formatMoney($conta['valor']));
    $aguardando = str_contains($baixa['mensagem'], AVISO_APROVACAO_ITAU);
    flash($aguardando ? 'warning' : 'success', $baixa['havia_boleto']
        ? ($aguardando ? 'Parcela cancelada. ' . $baixa['mensagem'] : 'Boleto baixado no banco e parcela cancelada (fica no histórico como cancelada).')
        : 'Parcela cancelada (fica no histórico como cancelada, porque já passou pelo banco).');
    redirect($voltar);
}

$stmt = $pdo->prepare('DELETE FROM contas_receber WHERE id = ?');
$stmt->execute([$id]);

registrarAtividade('contas_receber', 'exclusao', 'Conta a receber excluída: ' . $conta['descricao'] . ' - ' . formatMoney($conta['valor']));

flash('success', 'Lançamento excluído.');
redirect($voltar);
