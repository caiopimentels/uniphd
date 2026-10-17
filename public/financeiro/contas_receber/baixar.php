<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
exigirPermissaoAcao('baixar_receber', 'Seu perfil não pode dar baixa em contas a receber.');

$pdo = getDB();
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

$stmt = $pdo->prepare(
    'SELECT cr.*, a.nome AS aluno_nome
     FROM contas_receber cr LEFT JOIN alunos a ON a.id = cr.aluno_id
     WHERE cr.id = ?'
);
$stmt->execute([$id]);
$conta = $stmt->fetch();

if (!$conta || $conta['status'] !== 'pendente') {
    flash('danger', 'Este lançamento não pode ser baixado.');
    redirect('/financeiro/contas_receber/listar.php');
}

// Volta para a tela de onde a baixa foi chamada, para quem está atendendo
// o aluno não perder o contexto.
$urlVoltar = '/financeiro/contas_receber/listar.php';
if (($_GET['voltar'] ?? '') === 'aluno' && $conta['aluno_id']) {
    $urlVoltar = '/alunos/form.php?id=' . (int) $conta['aluno_id'] . '&tab=financeiro';
} elseif (($_GET['voltar'] ?? '') === 'matricula' && $conta['matricula_id']) {
    $urlVoltar = '/matriculas/visualizar.php?id=' . (int) $conta['matricula_id'];
}

$formasPagamento = $pdo->query("SELECT * FROM formas_pagamento WHERE ativo = 1 ORDER BY nome")->fetchAll();

$desconto = descontoPontualidadeParcela($pdo, $conta);
$erros = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dataRecebimento = input('data_recebimento') ?: date('Y-m-d');
    $formaPagamentoId = (int) ($_POST['forma_pagamento_id'] ?? 0) ?: null;
    $valorRecebido = decimalInput('valor_recebido', $conta['valor']);

    if ($valorRecebido <= 0) {
        $erros[] = 'Informe o valor recebido.';
    }

    if (!$erros) {
        $stmt = $pdo->prepare("UPDATE contas_receber SET status = 'pago', data_recebimento = ?, valor_recebido = ?, forma_pagamento_id = ?, baixa_origem = 'manual' WHERE id = ?");
        $stmt->execute([$dataRecebimento, $valorRecebido, $formaPagamentoId, $id]);
        $confirmacao = confirmarMatriculaPorPagamento($pdo, $id);
        // Na próxima tela aparece o atalho para imprimir o recibo.
        $_SESSION['recibo_disponivel'] = $id;

        $diferenca = round($valorRecebido - (float) $conta['valor'], 2);
        $stmtForma = $pdo->prepare('SELECT nome FROM formas_pagamento WHERE id = ?');
        $stmtForma->execute([$formaPagamentoId]);
        $nomeForma = $stmtForma->fetchColumn();
        registrarHistoricoParcela($pdo, $id, 'baixa', formatMoney($valorRecebido) . ' recebido em ' . formatDate($dataRecebimento)
            . ($nomeForma ? ' · ' . $nomeForma : '')
            . ($diferenca < 0 ? ' (desconto de ' . formatMoney(-$diferenca) . ')' : ($diferenca > 0 ? ' (acréscimo de ' . formatMoney($diferenca) . ')' : '')));
        registrarAtividade('contas_receber', 'baixa', 'Conta a receber recebida: ' . $conta['descricao'] . ' - ' . formatMoney($valorRecebido)
            . ($diferenca < 0 ? ' (desconto de ' . formatMoney(-$diferenca) . ')' : ($diferenca > 0 ? ' (acréscimo de ' . formatMoney($diferenca) . ')' : '')));

        flash('success', 'Conta recebida com sucesso.' . match ($confirmacao) {
            'confirmada' => ' A inscrição do portal foi confirmada: o aluno entrou na turma.',
            'lista_espera' => ' Atenção: era a inscrição de um aluno do portal, mas a turma já está cheia — ele ficou na lista de espera.',
            default => '',
        });
        redirect($urlVoltar);
    }
}

$pageTitle = 'Baixar conta a receber';
$activeMenu = 'contas-receber';
require __DIR__ . '/../../../includes/layout_header.php';
?>

<div class="card" style="max-width: 480px;">
    <div class="card-body">
        <p><strong><?= e($conta['descricao']) ?></strong><?php if ($conta['aluno_nome']): ?> · <?= e($conta['aluno_nome']) ?><?php endif; ?></p>
        <p class="mb-2">Valor: <strong><?= formatMoney($conta['valor']) ?></strong> · Vencimento: <?= formatDate($conta['data_vencimento']) ?></p>
        <?php if ($desconto): ?>
            <p class="small text-success mb-3"><i class="bi bi-alarm"></i> <?= e($desconto['texto']) ?></p>
        <?php endif; ?>
        <?php if ($erros): ?>
            <div class="alert alert-danger py-2"><?php foreach ($erros as $erro): ?><?= e($erro) ?><br><?php endforeach; ?></div>
        <?php endif; ?>
        <form method="post">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Data do recebimento</label>
                    <input type="date" name="data_recebimento" id="data_recebimento" class="form-control" value="<?= date('Y-m-d') ?>" oninput="sugerirValor()">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Valor recebido</label>
                    <input type="text" name="valor_recebido" id="valor_recebido" class="form-control" value="<?= e(number_format((float) $conta['valor'], 2, ',', '')) ?>">
                    <div class="form-text" id="dica_valor">Ajuste se o aluno pagou com juros/multa ou com desconto.</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Forma de recebimento</label>
                    <select name="forma_pagamento_id" class="form-select">
                        <option value="">-</option>
                        <?php foreach ($formasPagamento as $f): ?>
                            <option value="<?= (int) $f['id'] ?>" <?= (int) $conta['forma_pagamento_id'] === (int) $f['id'] ? 'selected' : '' ?>><?= e($f['nome']) ?></option>
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

<script>
// Sugere o valor conforme a data: dentro do prazo do desconto de
// pontualidade, o valor com desconto; depois dele, o valor cheio. O campo
// continua editável para casos de juros/multa ou acerto.
const DESCONTO = <?= json_encode($desconto ? ['limite' => $desconto['data_limite'], 'com_desconto' => $desconto['valor_com_desconto']] : null) ?>;
const VALOR_CHEIO = <?= json_encode((float) $conta['valor']) ?>;
let valorEditadoAMao = false;
document.getElementById('valor_recebido').addEventListener('input', () => { valorEditadoAMao = true; });

function sugerirValor() {
    if (!DESCONTO || valorEditadoAMao) return;
    const data = document.getElementById('data_recebimento').value;
    const dentroDoPrazo = data && data <= DESCONTO.limite;
    const valor = dentroDoPrazo ? DESCONTO.com_desconto : VALOR_CHEIO;
    document.getElementById('valor_recebido').value = valor.toFixed(2).replace('.', ',');
    document.getElementById('dica_valor').textContent = dentroDoPrazo
        ? 'Dentro do prazo do desconto por pontualidade.'
        : 'Fora do prazo do desconto: valor cheio.';
}
sugerirValor();
</script>

<?php require __DIR__ . '/../../../includes/layout_footer.php'; ?>
