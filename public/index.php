<?php
require_once __DIR__ . '/../includes/bootstrap.php';
// Raiz do domínio próprio do portal (ex.: portal.uniphd.com.br): vai ao portal.
if (($urlPortal = urlPortalConfigurada()) && strcasecmp((string) parse_url($urlPortal, PHP_URL_HOST), preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? ''))) === 0) {
    redirect('/portal/');
}
exigirLogin();
exigirDashboard();

$pdo = getDB();
$podeVerFinanceiro = usuarioPodeVerInadimplencia();
$acessoFinanceiro = usuarioPodeVerFinanceiro();

$alunosAtivos = (int) $pdo->query(
    "SELECT COUNT(DISTINCT aluno_id) FROM matriculas WHERE status = 'ativa'"
)->fetchColumn();
$turmasEmAndamento = (int) $pdo->query(
    "SELECT COUNT(*) FROM turmas t JOIN situacoes_turma s ON s.id = t.situacao_id WHERE s.comportamento = 'em_andamento'"
)->fetchColumn();
$turmasPlanejadas = (int) $pdo->query(
    "SELECT COUNT(*) FROM turmas t JOIN situacoes_turma s ON s.id = t.situacao_id WHERE s.comportamento = 'aberta'"
)->fetchColumn();

$turmasAbertas = $pdo->query(
    "SELECT t.id, t.nome, t.vagas, t.data_inicio, s.nome AS situacao_nome, s.cor AS situacao_cor, c.nome AS curso_nome,
            ((SELECT COUNT(*) FROM matriculas m WHERE m.turma_id = t.id AND " . SQL_MATRICULA_OCUPA_VAGA . ") + " . SQL_RESERVAS_TURMA . ") AS ocupadas
     FROM turmas t
     JOIN cursos c ON c.id = t.curso_id
     JOIN situacoes_turma s ON s.id = t.situacao_id
     WHERE s.comportamento IN ('aberta', 'em_andamento')
     ORDER BY t.data_inicio ASC
     LIMIT 8"
)->fetchAll();

if ($podeVerFinanceiro) {
    $resumoMes = $pdo->query(
        "SELECT
            COALESCE(SUM(CASE WHEN status = 'pago' AND data_recebimento BETWEEN DATE_FORMAT(CURDATE(), '%Y-%m-01') AND LAST_DAY(CURDATE()) THEN COALESCE(valor_recebido, valor) END), 0) AS recebido_mes,
            COALESCE(SUM(CASE WHEN status = 'pendente' AND data_vencimento BETWEEN CURDATE() AND LAST_DAY(CURDATE()) THEN valor END), 0) AS a_receber_mes,
            COALESCE(SUM(CASE WHEN status = 'pendente' AND data_vencimento < CURDATE() THEN valor END), 0) AS em_atraso
         FROM contas_receber"
    )->fetch();

    $maioresAtrasos = array_slice(listarInadimplentes($pdo), 0, 8);
}

if ($acessoFinanceiro) {
    $despesasMes = (float) $pdo->query(
        "SELECT COALESCE(SUM(valor), 0) FROM contas_pagar
         WHERE status <> 'cancelado' AND data_emissao BETWEEN DATE_FORMAT(CURDATE(), '%Y-%m-01') AND LAST_DAY(CURDATE())"
    )->fetchColumn();

    $contasAPagar = $pdo->query(
        "SELECT cp.id, cp.descricao, cp.valor, cp.data_vencimento, f.nome AS fornecedor_nome
         FROM contas_pagar cp LEFT JOIN fornecedores f ON f.id = cp.fornecedor_id
         WHERE cp.status = 'pendente' AND cp.data_vencimento <= CURDATE() + INTERVAL 7 DAY
         ORDER BY cp.data_vencimento ASC
         LIMIT 8"
    )->fetchAll();
}

$primeiroNome = explode(' ', trim(usuarioAtualNome()))[0];
$pageTitle = 'Olá, ' . $primeiroNome;
$pageBreadcrumb = 'Início';
$activeMenu = 'dashboard';
require __DIR__ . '/../includes/layout_header.php';
?>

<div class="atalhos">
    <?php if (usuarioPodeGerenciarMatriculas()): ?>
        <a href="/matriculas/form.php" class="atalho"><i class="bi bi-person-check"></i> Nova matrícula</a>
    <?php endif; ?>
    <?php if (usuarioPodeCadastrarAluno()): ?>
        <a href="/alunos/form.php" class="atalho"><i class="bi bi-person-plus"></i> Novo aluno</a>
    <?php endif; ?>
    <?php if (usuarioPodeCriarTurma()): ?>
        <a href="/turmas/form.php" class="atalho"><i class="bi bi-people"></i> Nova turma</a>
    <?php endif; ?>
    <?php if (usuarioTemAcessoFinanceiro()): ?>
        <a href="/financeiro/contas_pagar/form.php" class="atalho"><i class="bi bi-arrow-down-circle"></i> Lançar despesa</a>
    <?php endif; ?>
</div>

<div class="secao">
    <div class="secao-titulo"><i class="bi bi-graph-up"></i> Resumo</div>
    <div class="row g-3">
        <div class="col-6 col-lg-3">
            <div class="card h-100"><div class="card-body">
                <div class="kpi-rotulo">Alunos cursando</div>
                <div class="kpi-valor"><?= $alunosAtivos ?></div>
            </div></div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card h-100"><div class="card-body">
                <div class="kpi-rotulo">Turmas em andamento</div>
                <div class="kpi-valor"><?= $turmasEmAndamento ?></div>
                <?php if ($turmasPlanejadas): ?><div class="small text-muted">+ <?= $turmasPlanejadas ?> planejada(s)</div><?php endif; ?>
            </div></div>
        </div>
        <?php if ($podeVerFinanceiro): ?>
        <div class="col-6 col-lg-3">
            <div class="card h-100"><div class="card-body">
                <div class="kpi-rotulo">Recebido no mês</div>
                <div class="kpi-valor text-success"><?= formatMoney($resumoMes['recebido_mes']) ?></div>
                <div class="small text-muted">Falta vencer: <?= formatMoney($resumoMes['a_receber_mes']) ?></div>
            </div></div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card h-100"><div class="card-body">
                <div class="kpi-rotulo">Em atraso</div>
                <div class="kpi-valor <?= $resumoMes['em_atraso'] > 0 ? 'estoque-baixo' : '' ?>"><?= formatMoney($resumoMes['em_atraso']) ?></div>
                <?php if ($acessoFinanceiro): ?><div class="small text-muted">Despesas do mês: <?= formatMoney($despesasMes) ?></div><?php endif; ?>
            </div></div>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="secao">
            <div class="secao-titulo"><i class="bi bi-people"></i> Turmas abertas</div>
            <div class="card">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead><tr><th>Turma</th><th>Início</th><th class="text-center">Alunos</th><th>Situação</th></tr></thead>
                    <tbody>
                    <?php if (!$turmasAbertas): ?>
                        <tr><td colspan="4" class="text-center text-muted py-3">Nenhuma turma planejada ou em andamento.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($turmasAbertas as $t): ?>
                        <tr class="cursor-pointer" onclick="window.location='/turmas/visualizar.php?id=<?= (int) $t['id'] ?>'">
                            <td><?= e($t['curso_nome']) ?> · <?= e($t['nome']) ?></td>
                            <td><?= $t['data_inicio'] ? formatDate($t['data_inicio']) : 'A definir' ?></td>
                            <td class="text-center <?= (int) $t['ocupadas'] >= (int) $t['vagas'] ? 'estoque-baixo' : '' ?>"><?= (int) $t['ocupadas'] ?>/<?= (int) $t['vagas'] ?></td>
                            <td><?= badgeSituacaoTurma($t['situacao_nome'], $t['situacao_cor']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            </div>
        </div>
    </div>

    <?php if ($podeVerFinanceiro): ?>
    <div class="col-lg-6">
        <div class="secao">
            <div class="secao-titulo">
                <i class="bi bi-exclamation-triangle"></i> Maiores atrasos
                <a href="/financeiro/inadimplencia.php" class="btn btn-sm btn-outline-secondary ms-auto">Ver todos</a>
            </div>
            <div class="card">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead><tr><th>Aluno</th><th class="text-end">Dias</th><th class="text-end">Valor</th></tr></thead>
                    <tbody>
                    <?php if (!$maioresAtrasos): ?>
                        <tr><td colspan="3" class="text-center text-muted py-3">Nenhum aluno em atraso.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($maioresAtrasos as $i): ?>
                        <tr class="cursor-pointer" onclick="window.location='/alunos/form.php?id=<?= (int) $i['aluno_id'] ?>&tab=financeiro'">
                            <td><?= e($i['nome']) ?></td>
                            <td class="text-end"><?= (int) $i['dias_atraso'] ?></td>
                            <td class="text-end estoque-baixo"><?= formatMoney($i['valor_vencido']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($acessoFinanceiro): ?>
    <div class="col-lg-6">
        <div class="secao">
            <div class="secao-titulo"><i class="bi bi-calendar-event"></i> Contas a pagar (vencidas e próximos 7 dias)</div>
            <div class="card">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead><tr><th>Descrição</th><th>Vencimento</th><th class="text-end">Valor</th></tr></thead>
                    <tbody>
                    <?php if (!$contasAPagar): ?>
                        <tr><td colspan="3" class="text-center text-muted py-3">Nada a pagar nos próximos dias.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($contasAPagar as $c): ?>
                        <tr class="cursor-pointer" onclick="window.location='/financeiro/contas_pagar/baixar.php?id=<?= (int) $c['id'] ?>'">
                            <td><?= e($c['descricao']) ?><?php if ($c['fornecedor_nome']): ?> <span class="small text-muted">· <?= e($c['fornecedor_nome']) ?></span><?php endif; ?></td>
                            <td class="<?= $c['data_vencimento'] < date('Y-m-d') ? 'estoque-baixo' : '' ?>"><?= formatDate($c['data_vencimento']) ?></td>
                            <td class="text-end"><?= formatMoney($c['valor']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/layout_footer.php'; ?>
