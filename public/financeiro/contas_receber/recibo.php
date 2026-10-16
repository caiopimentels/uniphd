<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
require_once __DIR__ . '/../../../includes/documento.php';
exigirLogin();

// Recibo de uma parcela paga, em duas vias na mesma folha (aluno e escola).
if (!usuarioPodeVerInadimplencia() && !usuarioPodeBaixarReceber()) {
    flash('danger', 'Seu perfil não tem acesso ao financeiro dos alunos.');
    redirect('/sem_acesso.php');
}

$pdo = getDB();
$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare(
    'SELECT cr.*, a.nome AS aluno_nome, a.cpf AS aluno_cpf, fp.nome AS forma_nome, t.nome AS turma_nome, c.nome AS curso_nome,
            ' . SQL_BAIXA_PELO_BANCO . ' AS pago_pelo_banco
     FROM contas_receber cr
     LEFT JOIN alunos a ON a.id = cr.aluno_id
     LEFT JOIN formas_pagamento fp ON fp.id = cr.forma_pagamento_id
     LEFT JOIN matriculas m ON m.id = cr.matricula_id
     LEFT JOIN turmas t ON t.id = m.turma_id
     LEFT JOIN cursos c ON c.id = t.curso_id
     WHERE cr.id = ?'
);
$stmt->execute([$id]);
$conta = $stmt->fetch();
$voltar = match ($_GET['voltar'] ?? '') {
    'aluno' => $conta && $conta['aluno_id'] ? '/alunos/form.php?id=' . (int) $conta['aluno_id'] . '&tab=financeiro' : '/alunos/listar.php',
    'matricula' => $conta && $conta['matricula_id'] ? '/matriculas/visualizar.php?id=' . (int) $conta['matricula_id'] : '/turmas/listar.php',
    default => '/financeiro/contas_receber/listar.php',
};
if (!$conta || $conta['status'] !== 'pago') {
    flash('danger', 'O recibo só sai para parcela paga.');
    redirect($voltar);
}

$valor = (float) ($conta['valor_recebido'] ?? $conta['valor']);
$numero = str_pad((string) $id, 6, '0', STR_PAD_LEFT);
$referente = $conta['descricao'] . ($conta['curso_nome'] && !str_contains($conta['descricao'], $conta['turma_nome']) ? ' — ' . $conta['curso_nome'] . ' · ' . $conta['turma_nome'] : '');

$doc = documentoInicio($pdo, 'Recibo ' . $numero . ' · ' . ($conta['aluno_nome'] ?: $conta['descricao']), $voltar);
foreach (['Via do aluno', 'Via da escola'] as $i => $via):
    if ($i > 0): ?><div class="duas-vias"><span>corte aqui</span></div><?php endif;
    documentoCabecalho($doc, 'Recibo nº ' . $numero, $via);
    ?>
    <div class="doc-destaque" style="display:flex; justify-content:space-between; align-items:center;">
        <span class="rotulo" style="font-size:11px; text-transform:uppercase; color:#666;">Valor recebido</span>
        <strong style="font-size:22px;"><?= formatMoney($valor) ?></strong>
    </div>
    <p class="doc-texto">
        Recebemos de <strong><?= e($conta['aluno_nome'] ?: '—') ?></strong><?= $conta['aluno_cpf'] ? ', CPF ' . e(cpfFormatado($conta['aluno_cpf'])) . ',' : '' ?>
        a importância de <strong><?= formatMoney($valor) ?></strong> (<?= e(valorPorExtenso($valor)) ?>),
        referente a <strong><?= e($referente) ?></strong><?= (float) $conta['valor'] !== $valor ? ' (valor da parcela ' . formatMoney($conta['valor']) . ')' : '' ?>,
        pago em <strong><?= formatDate($conta['data_recebimento']) ?></strong><?= $conta['forma_nome'] ? ' por ' . e(mb_strtolower($conta['forma_nome'])) : '' ?>.
        <?= $conta['pago_pelo_banco'] ? 'Pagamento confirmado pelo banco (boleto/Pix).' : '' ?>
        Para clareza, firmamos o presente recibo.
    </p>
    <p class="doc-texto"><?= e($doc['cidade'] ? explode(' - ', $doc['cidade'])[0] . ', ' : '') ?><?= e(dataPorExtenso($conta['data_recebimento'] ?: date('Y-m-d'))) ?>.</p>
    <div class="assinaturas" style="margin-top:14mm;">
        <div><?= e($doc['nome']) ?><?= !empty($doc['config']['empresa_cnpj']) ? '<br>CNPJ ' . e(cnpjFormatado($doc['config']['empresa_cnpj'])) : '' ?></div>
    </div>
<?php endforeach;
documentoFim();
