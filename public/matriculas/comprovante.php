<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/documento.php';
exigirLogin();

// Comprovante de matrícula para imprimir e assinar: aluno, curso/turma,
// condições financeiras e parcelas (estas só para quem vê o financeiro).
if (!usuarioPodeVerCursos() && !usuarioPodeVerAlunos()) {
    flash('danger', 'Seu perfil não tem acesso a matrículas.');
    redirect('/sem_acesso.php');
}

$pdo = getDB();
$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare(
    'SELECT m.*, a.nome AS aluno_nome, a.cpf AS aluno_cpf, a.rg AS aluno_rg, a.data_nascimento, a.telefone AS aluno_telefone, a.email AS aluno_email,
            t.nome AS turma_nome, t.data_inicio, t.data_termino, t.minimo_alunos, t.duracao_meses, t.duracao_unidade,
            t.dias_semana, t.hora_inicio, t.hora_fim, t.agenda, t.horario, t.local,
            c.nome AS curso_nome, c.carga_horaria, p.nome AS professor_nome, fp.nome AS forma_pagamento_nome, u.nome AS usuario_nome
     FROM matriculas m
     JOIN alunos a ON a.id = m.aluno_id
     JOIN turmas t ON t.id = m.turma_id
     JOIN cursos c ON c.id = t.curso_id
     LEFT JOIN professores p ON p.id = t.professor_id
     LEFT JOIN formas_pagamento fp ON fp.id = m.forma_pagamento_id
     LEFT JOIN usuarios u ON u.id = m.usuario_id
     WHERE m.id = ?'
);
$stmt->execute([$id]);
$m = $stmt->fetch();
if (!$m) {
    flash('danger', 'Matrícula não encontrada.');
    redirect('/turmas/listar.php');
}

$stmt = $pdo->prepare('SELECT * FROM aluno_enderecos WHERE aluno_id = ? ORDER BY principal DESC, id LIMIT 1');
$stmt->execute([$m['aluno_id']]);
$endereco = $stmt->fetch();

$podeVerFinanceiro = usuarioPodeVerInadimplencia();
$parcelas = [];
if ($podeVerFinanceiro) {
    $stmt = $pdo->prepare("SELECT * FROM contas_receber WHERE matricula_id = ? AND status <> 'cancelado' ORDER BY data_vencimento, id");
    $stmt->execute([$id]);
    $parcelas = $stmt->fetchAll();
}
$mensalidade = valorMensalidadeComDesconto((float) $m['valor_mensalidade'], (float) $m['desconto_percentual']);

$doc = documentoInicio($pdo, 'Comprovante de matrícula · ' . $m['aluno_nome'], '/matriculas/visualizar.php?id=' . $id);
documentoCabecalho($doc, 'Comprovante de matrícula', 'Matrícula nº ' . str_pad((string) $id, 6, '0', STR_PAD_LEFT) . ' · ' . formatDate($m['data_matricula']));
?>
    <div class="doc-secao">
        <h2>Aluno</h2>
        <div class="doc-grade">
            <div class="dois"><div class="rotulo">Nome</div><?= e($m['aluno_nome']) ?></div>
            <div><div class="rotulo">CPF</div><?= e(cpfFormatado($m['aluno_cpf']) ?: '-') ?></div>
            <div><div class="rotulo">RG</div><?= e($m['aluno_rg'] ?: '-') ?></div>
            <div><div class="rotulo">Nascimento</div><?= formatDate($m['data_nascimento']) ?></div>
            <div><div class="rotulo">Telefone</div><?= e($m['aluno_telefone'] ?: '-') ?></div>
            <div class="dois"><div class="rotulo">E-mail</div><?= e($m['aluno_email'] ?: '-') ?></div>
            <?php if ($endereco): ?>
                <div class="todo"><div class="rotulo">Endereço</div><?= e(implode(', ', array_filter([$endereco['logradouro'], $endereco['numero'], $endereco['complemento'], $endereco['bairro']]))
                    . ($endereco['cidade'] ? ' — ' . $endereco['cidade'] . ($endereco['uf'] ? '/' . $endereco['uf'] : '') : '') . ($endereco['cep'] ? ' — CEP ' . $endereco['cep'] : '')) ?></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="doc-secao">
        <h2>Curso e turma</h2>
        <div class="doc-grade">
            <div class="dois"><div class="rotulo">Curso</div><?= e($m['curso_nome']) ?></div>
            <div><div class="rotulo">Turma</div><?= e($m['turma_nome']) ?></div>
            <div><div class="rotulo">Carga horária</div><?= $m['carga_horaria'] ? (int) $m['carga_horaria'] . ' horas' : '-' ?></div>
            <div class="dois"><div class="rotulo">Período</div><?= e(periodoTurma($m, true)) ?> (<?= e(textoDuracaoTurma($m)) ?>)</div>
            <div class="dois"><div class="rotulo">Dias e horário</div><?= e(textoAgendaTurma($m) ?: '-') ?></div>
            <div class="dois"><div class="rotulo">Local</div><?= e($m['local'] ?: '-') ?></div>
            <div class="dois"><div class="rotulo">Professor(a)</div><?= e($m['professor_nome'] ?: 'A definir') ?></div>
        </div>
    </div>

    <?php if ($podeVerFinanceiro): ?>
    <div class="doc-secao">
        <h2>Condições financeiras</h2>
        <div class="doc-grade">
            <div><div class="rotulo">Mensalidade</div><?= formatMoney($mensalidade) ?><?= (float) $m['desconto_percentual'] > 0 ? ' (' . formatPercent($m['desconto_percentual']) . ' de desconto)' : '' ?></div>
            <div><div class="rotulo">Nº de mensalidades</div><?= (int) $m['num_parcelas'] ?></div>
            <div><div class="rotulo">Taxa de matrícula</div><?= (float) $m['taxa_matricula'] > 0 ? formatMoney($m['taxa_matricula']) : 'Sem taxa' ?></div>
            <div><div class="rotulo">Forma de pagamento</div><?= e($m['forma_pagamento_nome'] ?: '-') ?></div>
            <?php if ($m['desconto_pontualidade_tipo'] !== 'nenhum' && (float) $m['desconto_pontualidade_valor'] > 0): ?>
                <div class="todo"><div class="rotulo">Desconto por pontualidade</div>
                    <?= $m['desconto_pontualidade_tipo'] === 'percentual' ? formatPercent($m['desconto_pontualidade_valor']) : formatMoney($m['desconto_pontualidade_valor']) ?>
                    por mensalidade paga até <?= (int) $m['desconto_pontualidade_dias'] ?> dia(s) antes do vencimento</div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($parcelas): ?>
    <div class="doc-secao">
        <h2>Parcelas</h2>
        <table>
            <thead><tr><th>Descrição</th><th>Vencimento</th><th class="text-end">Valor</th><th>Situação</th></tr></thead>
            <tbody>
            <?php foreach ($parcelas as $p): ?>
                <tr>
                    <td><?= e($p['descricao']) ?></td>
                    <td><?= formatDate($p['data_vencimento']) ?></td>
                    <td class="text-end"><?= formatMoney($p['valor']) ?></td>
                    <td><?= $p['status'] === 'pago' ? 'Pago em ' . formatDate($p['data_recebimento']) : 'A pagar' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr><td colspan="2" class="text-end">Total</td><td class="text-end"><?= formatMoney(array_sum(array_column($parcelas, 'valor'))) ?></td><td></td></tr></tfoot>
        </table>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <p class="doc-texto">
        Declaramos que <strong><?= e($m['aluno_nome']) ?></strong><?= $m['aluno_cpf'] ? ', CPF ' . e(cpfFormatado($m['aluno_cpf'])) . ',' : '' ?>
        está matriculado(a) no curso <strong><?= e($m['curso_nome']) ?></strong>, turma <strong><?= e($m['turma_nome']) ?></strong>,
        nas condições acima<?= $m['status'] === 'pendente' ? ', aguardando a confirmação do pagamento' : '' ?>.
    </p>
    <p class="doc-texto"><?= e($doc['cidade'] ? explode(' - ', $doc['cidade'])[0] . ', ' : '') ?><?= e(dataPorExtenso(date('Y-m-d'))) ?>.</p>

    <div class="assinaturas">
        <div><?= e($m['aluno_nome']) ?><br>Aluno(a) ou responsável</div>
        <div><?= e($doc['nome']) ?><br><?= e($m['usuario_nome'] ? 'Atendido por ' . $m['usuario_nome'] : 'Secretaria') ?></div>
    </div>
<?php documentoFim(); ?>
