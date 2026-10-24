<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
exigirPermissaoAcao('editar_financeiro_aluno', 'Seu perfil não pode alterar parcelas.');

// Boleto com desconto diferente do sistema (modal da parcela, aba Boleto):
// baixa o boleto no Itaú e emite um novo com o desconto que vale agora.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/financeiro/contas_receber/listar.php');
}

$pdo = getDB();
$id = (int) ($_POST['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM contas_receber WHERE id = ?');
$stmt->execute([$id]);
$conta = $stmt->fetch();

$voltar = match (input('voltar')) {
    'aluno' => $conta && $conta['aluno_id'] ? '/alunos/form.php?id=' . (int) $conta['aluno_id'] . '&tab=financeiro' : null,
    'matricula' => $conta && $conta['matricula_id'] ? '/matriculas/visualizar.php?id=' . (int) $conta['matricula_id'] : null,
    default => null,
} ?? '/financeiro/contas_receber/listar.php';

if (!$conta || $conta['status'] !== 'pendente' || !cobrancaRegistradaParcela($pdo, $id)) {
    flash('danger', 'Só parcela em aberto com boleto registrado no banco pode ter o boleto emitido de novo.');
    redirect($voltar);
}

$baixa = baixarBoletoBancoItau($pdo, $id);
if (!$baixa['ok']) {
    flash('danger', $baixa['mensagem'] . ' Nada foi alterado.');
    redirect($voltar);
}
$novo = registrarCobrancaItau($pdo, $id, usuarioAtualId());
registrarHistoricoParcela($pdo, $id, 'edicao', 'Boleto baixado e emitido de novo com o desconto do sistema'
    . ($novo['ok'] ? '' : ' — o novo não foi emitido (' . $novo['mensagem'] . ')'));
registrarAtividade('contas_receber', 'edicao', 'Boleto reemitido (desconto diferente do banco): ' . $conta['descricao'] . ($novo['ok'] ? '' : ' (falhou: ' . $novo['mensagem'] . ')'));

$aprovacao = str_contains($baixa['mensagem'], AVISO_APROVACAO_ITAU) ? ' ' . AVISO_APROVACAO_ITAU : '';
flash($novo['ok'] && $aprovacao === '' ? 'success' : 'warning', $novo['ok']
    ? 'Boleto emitido de novo com o desconto do sistema — o boleto e o Pix enviados antes ao aluno não valem mais; mande o novo.' . $aprovacao
    : 'O boleto antigo foi baixado, mas o novo não foi emitido: ' . $novo['mensagem'] . ' Clique no ícone do boleto para tentar de novo.' . $aprovacao);
redirect($voltar);
