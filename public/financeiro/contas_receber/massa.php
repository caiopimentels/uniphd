<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
exigirInadimplencia();

// Ações do painel lateral do Contas a receber sobre as parcelas marcadas:
// gerar boleto + Pix no Itaú, excluir (com baixa do boleto no banco), mudar
// a forma de pagamento ou reparcelar (juntar e dividir de outro jeito).
// A baixa (recebimento) em lote tem tela própria: baixar_lote.php.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/financeiro/contas_receber/listar.php');
}
$pdo = getDB();
$acao = input('acao');
$ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])))));
$voltar = str_starts_with((string) input('voltar'), '/financeiro/contas_receber/') ? input('voltar') : '/financeiro/contas_receber/listar.php';
if (!$ids) {
    flash('danger', 'Marque ao menos uma parcela.');
    redirect($voltar);
}
// Cada parcela pode ir ao banco: limita o lote para a tela não esperar demais.
const LIMITE_LOTE_BANCO = 50;
if (count($ids) > LIMITE_LOTE_BANCO) {
    flash('danger', 'Marque no máximo ' . LIMITE_LOTE_BANCO . ' parcelas por vez.');
    redirect($voltar);
}

if ($acao === 'gerar_boletos') {
    $gerados = $jaTinham = 0;
    $falhas = [];
    foreach ($ids as $id) {
        $stmt = $pdo->prepare("SELECT cr.*, (SELECT COUNT(*) FROM cobrancas_bancarias cb WHERE cb.conta_receber_id = cr.id AND cb.status IN ('pendente_envio', 'registrada', 'paga')) AS tem_boleto FROM contas_receber cr WHERE cr.id = ?");
        $stmt->execute([$id]);
        $parcela = $stmt->fetch();
        if (!$parcela || $parcela['status'] !== 'pendente' || !$parcela['aluno_id']) {
            continue;
        }
        if ($parcela['tem_boleto']) {
            $jaTinham++;
            continue;
        }
        $meios = meiosCobrancaForma($pdo, $parcela['forma_pagamento_id'] ? (int) $parcela['forma_pagamento_id'] : null);
        if (!$meios['pix'] && !$meios['boleto']) {
            $falhas[] = $parcela['descricao'] . ': forma ' . $meios['nome'] . ' não gera boleto nem Pix';
            continue;
        }
        $resultado = registrarCobrancaItau($pdo, $id, usuarioAtualId());
        if ($resultado['ok']) {
            $gerados++;
        } else {
            $falhas[] = $parcela['descricao'] . ': ' . $resultado['mensagem'];
        }
    }
    registrarAtividade('contas_receber', 'edicao', "Boletos gerados em lote: {$gerados}" . ($falhas ? ', ' . count($falhas) . ' com erro' : ''));
    if ($gerados || $jaTinham) {
        flash('success', "{$gerados} boleto(s) + Pix gerado(s) no Itaú." . ($jaTinham ? " {$jaTinham} já tinham boleto." : ''));
    }
    if ($falhas) {
        flash('danger', 'Não gerados: ' . implode(' · ', array_slice($falhas, 0, 5)) . (count($falhas) > 5 ? ' · e mais ' . (count($falhas) - 5) : ''));
    }
    if (!$gerados && !$jaTinham && !$falhas) {
        flash('warning', 'Nenhuma das parcelas marcadas está em aberto com aluno.');
    }
    redirect($voltar);
}

if ($acao === 'excluir') {
    exigirPermissaoAcao('excluir_receber', 'Seu perfil não pode excluir lançamentos a receber.');
    $excluidas = $canceladas = 0;
    $aguardando = false;
    $falhas = [];
    foreach ($ids as $id) {
        $r = excluirParcelaReceber($pdo, $id);
        if (!$r['ok']) {
            $falhas[] = $r['mensagem'];
            continue;
        }
        $r['resultado'] === 'excluida' ? $excluidas++ : $canceladas++;
        $aguardando = $aguardando || $r['aguardando'];
    }
    if ($excluidas || $canceladas) {
        flash($aguardando ? 'warning' : 'success', ($excluidas + $canceladas) . ' parcela(s) excluída(s)' . ($canceladas ? " — {$canceladas} já tinham passado pelo banco e ficam no filtro \"Excluídas\"" : '') . '.'
            . ($aguardando ? ' ' . AVISO_APROVACAO_ITAU : ''));
    }
    if ($falhas) {
        flash('danger', implode(' · ', array_slice($falhas, 0, 5)) . (count($falhas) > 5 ? ' · e mais ' . (count($falhas) - 5) : ''));
    }
    redirect($voltar);
}

if ($acao === 'mudar_forma') {
    exigirPermissaoAcao('editar_financeiro_aluno', 'Seu perfil não pode alterar parcelas.');
    $formaNova = (int) ($_POST['forma_pagamento_id'] ?? 0) ?: null;
    $mudadas = 0;
    $falhas = [];
    foreach ($ids as $id) {
        $r = trocarFormaParcela($pdo, $id, $formaNova);
        if (!$r['ok']) {
            $falhas[] = $r['mensagem'];
        } elseif ($r['mudou']) {
            $mudadas++;
        }
    }
    $stmt = $pdo->prepare('SELECT nome FROM formas_pagamento WHERE id = ?');
    $stmt->execute([$formaNova]);
    $nomeForma = $formaNova ? (string) $stmt->fetchColumn() : 'sem forma';
    registrarAtividade('contas_receber', 'edicao', "Forma de pagamento mudada para {$nomeForma} em {$mudadas} parcela(s)");
    flash($falhas ? 'warning' : 'success', "{$mudadas} parcela(s) agora em {$nomeForma}." . ($falhas ? ' Não mudadas: ' . implode(' · ', array_slice($falhas, 0, 5)) : ''));
    redirect($voltar);
}

if ($acao === 'reparcelar') {
    exigirPermissaoAcao('editar_financeiro_aluno', 'Seu perfil não pode alterar parcelas.');
    exigirPermissaoAcao('excluir_receber', 'Seu perfil não pode excluir lançamentos a receber (o reparcelamento substitui as parcelas).');
    $marcas = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT cr.*, a.nome AS aluno_nome FROM contas_receber cr LEFT JOIN alunos a ON a.id = cr.aluno_id WHERE cr.id IN ({$marcas}) ORDER BY cr.data_vencimento, cr.id");
    $stmt->execute($ids);
    $antigas = $stmt->fetchAll();
    $erros = [];
    if (count($antigas) !== count($ids) || array_filter($antigas, fn ($p) => $p['status'] !== 'pendente' || $p['excluido_em'])) {
        $erros[] = 'Só parcelas em aberto podem ser reparceladas.';
    }
    $alunosDiferentes = array_unique(array_map(fn ($p) => (int) $p['aluno_id'], $antigas));
    if (count($alunosDiferentes) !== 1 || !$alunosDiferentes[0]) {
        $erros[] = 'Marque parcelas de um aluno só.';
    }
    $soma = round(array_sum(array_map(fn ($p) => (float) $p['valor'], $antigas)), 2);
    $total = decimalInput('total', $soma);
    $numParcelas = (int) ($_POST['num_parcelas'] ?? 0);
    $primeiro = input('primeiro_vencimento');
    $formaNova = (int) ($_POST['forma_pagamento_id'] ?? 0) ?: null;
    $descricaoBase = mb_substr(input('descricao'), 0, 200) ?: 'Renegociação';
    if ($total <= 0) {
        $erros[] = 'Informe o valor total.';
    }
    if ($numParcelas < 1 || $numParcelas > 60) {
        $erros[] = 'O número de parcelas vai de 1 a 60.';
    }
    // Parcelas como ficaram na tela (data e valor de cada uma); sem elas,
    // divide igual, mês a mês. A soma tem que fechar no total, ao centavo.
    $valoresTela = (array) ($_POST['parcela_valor'] ?? []);
    $datasTela = (array) ($_POST['parcela_venc'] ?? []);
    $novasParcelas = [];
    if ($valoresTela) {
        foreach (array_values($valoresTela) as $i => $textoValor) {
            $valorTela = (float) str_replace(',', '.', str_replace('.', '', (string) $textoValor));
            $dataTela = (string) (array_values($datasTela)[$i] ?? '');
            $novasParcelas[] = ['valor' => round($valorTela, 2), 'vencimento' => $dataTela];
            if ($valorTela <= 0) {
                $erros[] = 'A parcela ' . ($i + 1) . ' está sem valor.';
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataTela) || !strtotime($dataTela)) {
                $erros[] = 'A parcela ' . ($i + 1) . ' está sem vencimento.';
            }
        }
        if (count($novasParcelas) !== $numParcelas) {
            $erros[] = 'O número de parcelas não bate com a tabela. Abra o reparcelamento de novo.';
        }
        $somaTela = array_sum(array_map(fn ($p) => (int) round($p['valor'] * 100), $novasParcelas));
        if ($somaTela !== (int) round($total * 100)) {
            $erros[] = 'As parcelas somam ' . formatMoney($somaTela / 100) . ', mas precisam fechar em ' . formatMoney($total) . '.';
        }
    }
    if (!$valoresTela && (!$primeiro || !strtotime($primeiro))) {
        $erros[] = 'Informe o vencimento da 1ª parcela.';
    }
    // Total menor que a soma é desconto: entra na alçada do perfil.
    if ($total > $soma + 0.001) {
        $erros[] = 'O total (' . formatMoney($total) . ') não pode passar da soma das parcelas (' . formatMoney($soma) . '). Para cobrar mais, lance à parte.';
    } elseif ($soma > 0 && $total < $soma && !usuarioEhAdmin() && (($soma - $total) / $soma * 100) > usuarioDescontoMax() + 0.001) {
        $erros[] = 'O desconto (' . formatMoney($soma - $total) . ') passa do seu limite de ' . formatPercent(usuarioDescontoMax()) . '.';
    }
    if ($erros) {
        flash('danger', implode(' ', $erros));
        redirect($voltar);
    }

    // Matrícula e categoria: as das parcelas antigas (se todas iguais).
    $matriculas = array_unique(array_map(fn ($p) => (int) $p['matricula_id'], $antigas));
    $categorias = array_unique(array_map(fn ($p) => (int) $p['categoria_id'], $antigas));
    $matriculaId = count($matriculas) === 1 && $matriculas[0] ? $matriculas[0] : null;
    $categoriaId = count($categorias) === 1 && $categorias[0] ? $categorias[0] : categoriaSistemaId($pdo, 'mensalidade');
    $origem = implode(', ', array_map(fn ($p) => $p['descricao'] . ' (' . formatMoney($p['valor']) . ')', $antigas));

    // Primeiro cria as novas; depois tira as antigas (baixando os boletos).
    // Se o banco recusar a baixa de alguma, ela fica — e o aviso diz qual.
    $insere = $pdo->prepare(
        "INSERT INTO contas_receber (aluno_id, matricula_id, categoria_id, descricao, valor, data_emissao, data_vencimento, forma_pagamento_id, status, observacao, usuario_id)
         VALUES (?, ?, ?, ?, ?, CURDATE(), ?, ?, 'pendente', ?, ?)"
    );
    $novas = [];
    if (!$novasParcelas) {
        foreach (dividirValor($total, $numParcelas) as $i => $valor) {
            $novasParcelas[] = ['valor' => $valor, 'vencimento' => somarMeses($primeiro, $i)];
        }
    }
    foreach ($novasParcelas as $i => $nova) {
        $insere->execute([$alunosDiferentes[0], $matriculaId, $categoriaId, $descricaoBase . ' ' . ($i + 1) . '/' . $numParcelas, $nova['valor'],
            $nova['vencimento'], $formaNova, 'Reparcelamento', usuarioAtualId()]);
        $novaId = (int) $pdo->lastInsertId();
        $novas[] = $novaId;
        registrarHistoricoParcela($pdo, $novaId, 'criacao', 'Criada no reparcelamento de: ' . $origem
            . ($total < $soma ? ' — desconto de ' . formatMoney($soma - $total) : ''));
    }
    $ficaram = [];
    foreach ($antigas as $p) {
        registrarHistoricoParcela($pdo, (int) $p['id'], 'cancelamento', 'Substituída no reparcelamento (' . $numParcelas . 'x, total ' . formatMoney($total) . ')');
        $r = excluirParcelaReceber($pdo, (int) $p['id']);
        if (!$r['ok']) {
            $ficaram[] = $r['mensagem'];
        }
    }
    sincronizarDocumentosAluno($pdo, $alunosDiferentes[0]);
    registrarAtividade('contas_receber', 'edicao', 'Reparcelamento de ' . $antigas[0]['aluno_nome'] . ': ' . count($antigas) . ' parcela(s) somando ' . formatMoney($soma)
        . " → {$numParcelas}x, total " . formatMoney($total) . ($total < $soma ? ' (desconto de ' . formatMoney($soma - $total) . ')' : ''));
    if ($ficaram) {
        flash('warning', "Criadas {$numParcelas} parcela(s) novas, mas estas antigas NÃO saíram (o banco recusou a baixa) e precisam ser excluídas à mão: " . implode(' · ', $ficaram));
    } else {
        flash('success', count($antigas) . " parcela(s) de {$antigas[0]['aluno_nome']} trocadas por {$numParcelas}x, total " . formatMoney($total) . '.');
    }
    redirect($voltar);
}

flash('danger', 'Ação desconhecida.');
redirect($voltar);
