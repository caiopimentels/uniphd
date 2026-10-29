<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirDRE();

// Detalhamento de uma linha do DRE (JSON): os lançamentos daquela
// categoria (receita / despesa) no período, pagos ou não.
$tipo = in_array($_GET['tipo'] ?? '', ['receita', 'despesa'], true) ? $_GET['tipo'] : 'receita';
$data = fn ($v, $padrao) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) ? $v : $padrao;
$linhas = detalheDRE(getDB(), $tipo, (int) ($_GET['id'] ?? 0), $data($_GET['data_inicio'] ?? '', '1000-01-01'), $data($_GET['data_fim'] ?? '', '9999-12-31'));
responderJson(['sucesso' => true, 'linhas' => array_map(fn ($l) => $l + ['data_br' => formatDate($l['data']), 'valor_br' => formatMoney($l['valor'])], $linhas),
    'total' => formatMoney(array_sum(array_column($linhas, 'valor')))]);
