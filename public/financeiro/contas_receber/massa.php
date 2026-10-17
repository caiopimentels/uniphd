<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
exigirInadimplencia();

// Ações do painel lateral do Contas a receber sobre as parcelas marcadas:
// gerar boleto + Pix no Itaú ou excluir (com baixa do boleto no banco).
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
        flash($aguardando ? 'warning' : 'success', "{$excluidas} excluída(s)" . ($canceladas ? ", {$canceladas} cancelada(s) com baixa do boleto no banco" : '') . '.'
            . ($aguardando ? ' ' . AVISO_APROVACAO_ITAU : ''));
    }
    if ($falhas) {
        flash('danger', implode(' · ', array_slice($falhas, 0, 5)) . (count($falhas) > 5 ? ' · e mais ' . (count($falhas) - 5) : ''));
    }
    redirect($voltar);
}

flash('danger', 'Ação desconhecida.');
redirect($voltar);
