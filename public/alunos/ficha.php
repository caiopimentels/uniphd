<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/documento.php';
exigirLogin();
exigirAlunos();

// Ficha do aluno para imprimir: cadastro, contatos, endereços, matrículas e
// (para quem vê o financeiro) o resumo e as parcelas em aberto.
$pdo = getDB();
$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT a.*, tg.nome AS graduacao_nome FROM alunos a LEFT JOIN tipos_graduacao tg ON tg.id = a.graduacao_id WHERE a.id = ?');
$stmt->execute([$id]);
$aluno = $stmt->fetch();
if (!$aluno) {
    flash('danger', 'Aluno não encontrado.');
    redirect('/alunos/listar.php');
}

$stmt = $pdo->prepare('SELECT telefone FROM aluno_telefones WHERE aluno_id = ? ORDER BY id');
$stmt->execute([$id]);
$telefones = array_filter(array_merge([$aluno['telefone']], $stmt->fetchAll(PDO::FETCH_COLUMN)));

$stmt = $pdo->prepare('SELECT * FROM aluno_enderecos WHERE aluno_id = ? ORDER BY principal DESC, id');
$stmt->execute([$id]);
$enderecos = $stmt->fetchAll();

$stmt = $pdo->prepare(
    "SELECT m.*, t.nome AS turma_nome, t.data_inicio, t.data_termino, t.minimo_alunos, t.duracao_unidade, c.nome AS curso_nome
     FROM matriculas m JOIN turmas t ON t.id = m.turma_id JOIN cursos c ON c.id = t.curso_id
     WHERE m.aluno_id = ? ORDER BY m.data_matricula DESC"
);
$stmt->execute([$id]);
$matriculas = $stmt->fetchAll();

$podeVerFinanceiro = usuarioPodeVerInadimplencia();
$resumo = $podeVerFinanceiro ? resumoFinanceiroAluno($pdo, $id) : null;
$abertas = [];
if ($podeVerFinanceiro) {
    $stmt = $pdo->prepare("SELECT * FROM contas_receber WHERE aluno_id = ? AND status = 'pendente' ORDER BY data_vencimento, id");
    $stmt->execute([$id]);
    $abertas = $stmt->fetchAll();
}

$doc = documentoInicio($pdo, 'Ficha · ' . $aluno['nome'], '/alunos/form.php?id=' . $id);
documentoCabecalho($doc, 'Ficha do aluno', 'Cadastro nº ' . str_pad((string) $id, 6, '0', STR_PAD_LEFT) . ' · desde ' . formatDate(substr((string) $aluno['criado_em'], 0, 10)));
?>
    <div class="doc-secao">
        <h2>Dados pessoais</h2>
        <div class="doc-grade">
            <div><div class="rotulo">RA</div><strong><?= e($aluno['ra'] ?: '-') ?></strong></div>
            <div class="dois"><div class="rotulo">Nome</div><strong><?= e($aluno['nome']) ?></strong></div>
            <div><div class="rotulo">Situação</div><?= $aluno['ativo'] ? 'Ativo' : 'Inativo' ?></div>
            <div><div class="rotulo">Nascimento</div><?= formatDate($aluno['data_nascimento']) ?></div>
            <div><div class="rotulo">CPF</div><?= e(cpfFormatado($aluno['cpf']) ?: '-') ?></div>
            <div><div class="rotulo">RG</div><?= e($aluno['rg'] ?: '-') ?></div>
            <div class="dois"><div class="rotulo">Nome da mãe</div><?= e($aluno['nome_mae'] ?: '-') ?></div>
            <div class="dois"><div class="rotulo">Nome do pai</div><?= e($aluno['nome_pai'] ?: '-') ?></div>
            <div class="dois"><div class="rotulo">Graduação</div><?= $aluno['possui_graduacao'] === null ? '-' : ((int) $aluno['possui_graduacao'] === 1 ? e($aluno['graduacao_nome'] ?: 'Sim') : 'Não possui') ?></div>
            <div class="dois"><div class="rotulo">Acesso ao portal</div><?= $aluno['senha_hash'] ? ($aluno['senha_provisoria'] ? 'Senha provisória' : 'Criado') . ($aluno['portal_ultimo_acesso'] ? ' · último acesso ' . formatDateTime($aluno['portal_ultimo_acesso']) : '') : 'Não criado' ?></div>
        </div>
    </div>

    <div class="doc-secao">
        <h2>Contato</h2>
        <div class="doc-grade">
            <div class="dois"><div class="rotulo">Telefone(s)</div><?= e($telefones ? implode(' · ', array_map('telefoneFormatado', $telefones)) : '-') ?></div>
            <div class="dois"><div class="rotulo">E-mail</div><?= e($aluno['email'] ?: '-') ?></div>
            <?php foreach ($enderecos as $end): ?>
                <div class="todo"><div class="rotulo">Endereço<?= $end['apelido'] ? ' — ' . e($end['apelido']) : '' ?><?= $end['principal'] ? ' (principal)' : '' ?></div>
                    <?= e(implode(', ', array_filter([$end['logradouro'], $end['numero'], $end['complemento'], $end['bairro']]))
                        . ($end['cidade'] ? ' — ' . $end['cidade'] . ($end['uf'] ? '/' . $end['uf'] : '') : '') . ($end['cep'] ? ' — CEP ' . $end['cep'] : '')) ?></div>
            <?php endforeach; ?>
            <?php if (!$enderecos): ?><div class="todo"><div class="rotulo">Endereço</div>-</div><?php endif; ?>
            <?php if ($aluno['observacao']): ?><div class="todo"><div class="rotulo">Observação</div><?= e($aluno['observacao']) ?></div><?php endif; ?>
        </div>
    </div>

    <div class="doc-secao">
        <h2>Matrículas</h2>
        <table>
            <thead><tr><th>Curso · turma</th><th>Período</th><th>Matrícula</th><th class="text-end">Mensalidade</th><th>Situação</th></tr></thead>
            <tbody>
            <?php if (!$matriculas): ?><tr><td colspan="5">Nenhuma matrícula.</td></tr><?php endif; ?>
            <?php foreach ($matriculas as $m): ?>
                <tr>
                    <td><?= e($m['curso_nome'] . ' · ' . $m['turma_nome']) ?></td>
                    <td><?= e(periodoTurma($m, true)) ?></td>
                    <td><?= formatDate($m['data_matricula']) ?></td>
                    <td class="text-end"><?= formatMoney(valorMensalidadeComDesconto((float) $m['valor_mensalidade'], (float) $m['desconto_percentual'])) ?></td>
                    <td><?= e(matriculaStatusLabel($m['status'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($resumo): ?>
    <div class="doc-secao">
        <h2>Financeiro</h2>
        <div class="doc-grade" style="margin-bottom:3mm;">
            <div><div class="rotulo">Já pago</div><?= formatMoney($resumo['pago']) ?></div>
            <div><div class="rotulo">Em aberto</div><?= formatMoney($resumo['em_aberto']) ?></div>
            <div><div class="rotulo">Vencido</div><strong<?= $resumo['vencido'] > 0 ? ' style="color:#b91c1c"' : '' ?>><?= formatMoney($resumo['vencido']) ?></strong></div>
            <div><div class="rotulo">Parcelas vencidas</div><?= (int) $resumo['parcelas_vencidas'] ?></div>
        </div>
        <?php if ($abertas): ?>
        <table>
            <thead><tr><th>Parcela em aberto</th><th>Vencimento</th><th class="text-end">Valor</th></tr></thead>
            <tbody>
            <?php foreach ($abertas as $p): ?>
                <tr<?= $p['data_vencimento'] < date('Y-m-d') ? ' style="color:#b91c1c"' : '' ?>>
                    <td><?= e($p['descricao']) ?><?= $p['data_vencimento'] < date('Y-m-d') ? ' (vencida)' : '' ?></td>
                    <td><?= formatDate($p['data_vencimento']) ?></td>
                    <td class="text-end"><?= formatMoney($p['valor']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <?php endif; ?>
<?php documentoFim(); ?>
