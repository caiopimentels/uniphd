<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
exigirPermissaoAcao('baixar_receber', 'Seu perfil não pode estornar baixas.');

// Desfaz uma baixa lançada à mão por engano: a parcela volta a ficar em
// aberto. Baixa vinda do banco (boleto/Pix pago no Itaú) não se desfaz —
// o dinheiro entrou de verdade.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/financeiro/contas_receber/listar.php');
}

$pdo = getDB();
$id = (int) ($_POST['id'] ?? 0);

$stmt = $pdo->prepare(
    'SELECT cr.*, a.nome AS aluno_nome, ' . SQL_BAIXA_PELO_BANCO . ' AS pago_pelo_banco
     FROM contas_receber cr LEFT JOIN alunos a ON a.id = cr.aluno_id
     WHERE cr.id = ?'
);
$stmt->execute([$id]);
$conta = $stmt->fetch();

$voltar = match (input('voltar')) {
    'aluno' => $conta && $conta['aluno_id'] ? '/alunos/form.php?id=' . (int) $conta['aluno_id'] . '&tab=financeiro' : null,
    'matricula' => $conta && $conta['matricula_id'] ? '/matriculas/visualizar.php?id=' . (int) $conta['matricula_id'] : null,
    default => null,
} ?? '/financeiro/contas_receber/listar.php';

if (!$conta || $conta['status'] !== 'pago') {
    flash('danger', 'Só parcelas pagas podem ter a baixa estornada.');
    redirect($voltar);
}
if ($conta['pago_pelo_banco']) {
    flash('danger', 'Esta parcela foi paga por boleto/Pix e confirmada pelo banco — a baixa não pode ser estornada.');
    redirect($voltar);
}

$stmt = $pdo->prepare(
    "UPDATE contas_receber SET status = 'pendente', data_recebimento = NULL, valor_recebido = NULL, baixa_origem = NULL
     WHERE id = ? AND status = 'pago'"
);
$stmt->execute([$id]);

registrarAtividade('contas_receber', 'estorno', 'Baixa estornada: ' . $conta['descricao'] . ($conta['aluno_nome'] ? ' (' . $conta['aluno_nome'] . ')' : '')
    . ' - ' . formatMoney($conta['valor_recebido'] ?? $conta['valor']) . ' recebido em ' . formatDate($conta['data_recebimento']));

// Se este pagamento confirmou uma inscrição do portal, a matrícula não
// volta sozinha: a secretaria decide (o aluno pode já estar assistindo aula).
$stmt = $pdo->prepare("SELECT id FROM matriculas WHERE conta_receber_confirmacao_id = ? AND status = 'ativa'");
$stmt->execute([$id]);
$matriculaConfirmada = $stmt->fetchColumn();

flash('success', 'Baixa estornada: a parcela voltou a ficar em aberto.'
    . ($matriculaConfirmada ? ' Atenção: este pagamento tinha confirmado a matrícula do aluno, que continua ativa. Se ele não pagou mesmo, cancele a matrícula.' : ''));
redirect($voltar);
