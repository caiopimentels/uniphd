<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
exigirPermissaoAcao('baixar_receber', 'Seu perfil não pode dar baixa em contas a receber.');

// Baixa várias parcelas de uma vez (seleção na lista de contas a receber):
// mesma data e forma para todas, cada uma pelo seu valor — com o desconto
// de pontualidade quando pedido e a data estiver dentro do prazo.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/financeiro/contas_receber/listar.php');
}

$pdo = getDB();
$ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])))));
$dataRecebimento = input('data_recebimento') ?: date('Y-m-d');
$formaPagamentoId = (int) ($_POST['forma_pagamento_id'] ?? 0) ?: null;
$usarDesconto = !empty($_POST['usar_desconto']);

if (!$ids) {
    flash('danger', 'Selecione ao menos uma parcela.');
    redirect('/financeiro/contas_receber/listar.php');
}
if (!strtotime($dataRecebimento) || $dataRecebimento > date('Y-m-d')) {
    flash('danger', 'A data do recebimento não pode ser no futuro.');
    redirect('/financeiro/contas_receber/listar.php');
}

$marcas = implode(',', array_fill(0, count($ids), '?'));
$stmt = $pdo->prepare("SELECT * FROM contas_receber WHERE id IN ({$marcas}) AND status = 'pendente'");
$stmt->execute($ids);
$parcelas = $stmt->fetchAll();

$atualiza = $pdo->prepare(
    "UPDATE contas_receber SET status = 'pago', data_recebimento = ?, valor_recebido = ?, forma_pagamento_id = ?, baixa_origem = 'manual'
     WHERE id = ? AND status = 'pendente'"
);
$baixadas = 0;
$total = 0.0;
$comDesconto = 0;
$confirmadas = 0;
$listaEspera = 0;
foreach ($parcelas as $p) {
    $valor = (float) $p['valor'];
    $desconto = $usarDesconto ? descontoPontualidadeParcela($pdo, $p) : null;
    if ($desconto && $dataRecebimento <= $desconto['data_limite']) {
        $valor = $desconto['valor_com_desconto'];
        $comDesconto++;
    }
    $atualiza->execute([$dataRecebimento, $valor, $formaPagamentoId ?: $p['forma_pagamento_id'], $p['id']]);
    if (!$atualiza->rowCount()) {
        continue;
    }
    $baixadas++;
    $total += $valor;
    registrarHistoricoParcela($pdo, (int) $p['id'], 'baixa', formatMoney($valor) . ' recebido em ' . formatDate($dataRecebimento) . ' (baixa de várias de uma vez)');
    $confirmacao = confirmarMatriculaPorPagamento($pdo, (int) $p['id']);
    $confirmadas += $confirmacao === 'confirmada' ? 1 : 0;
    $listaEspera += $confirmacao === 'lista_espera' ? 1 : 0;
}

registrarAtividade('contas_receber', 'baixa', "Baixa em lote: {$baixadas} parcela(s), " . formatMoney($total) . ' em ' . formatDate($dataRecebimento));

$ignoradas = count($ids) - $baixadas;
flash($baixadas ? 'success' : 'warning',
    "{$baixadas} parcela(s) baixada(s), total de " . formatMoney($total) . '.'
    . ($comDesconto ? " {$comDesconto} com desconto de pontualidade." : '')
    . ($ignoradas ? " {$ignoradas} já não estava(m) em aberto e ficou(aram) como estava(m)." : '')
    . ($confirmadas ? " {$confirmadas} inscrição(ões) do portal confirmada(s)." : '')
    . ($listaEspera ? " Atenção: {$listaEspera} aluno(s) pagou(aram) com a turma cheia e ficou(aram) na lista de espera." : ''));
redirect('/financeiro/contas_receber/listar.php');
