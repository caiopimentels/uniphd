<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirInadimplencia();

$pdo = getDB();
$config = buscarConfiguracoes($pdo);

$turmaId = (int) ($_GET['turma_id'] ?? 0);
$cursoId = (int) ($_GET['curso_id'] ?? 0);

$inadimplentes = listarInadimplentes($pdo, ['turma_id' => $turmaId, 'curso_id' => $cursoId]);
$totalVencido = array_sum(array_column($inadimplentes, 'valor_vencido'));

$cursos = $pdo->query('SELECT id, nome FROM cursos ORDER BY nome')->fetchAll();
$turmas = $pdo->query(
    'SELECT t.id, t.nome, c.nome AS curso_nome FROM turmas t JOIN cursos c ON c.id = t.curso_id ORDER BY c.nome, t.nome'
)->fetchAll();

$nomeInstituicao = $config['nome_sistema'] ?? APP_NAME;

$pageTitle = 'Inadimplência';
$pageBreadcrumb = 'Financeiro';
$activeMenu = 'inadimplencia';
require __DIR__ . '/../../includes/layout_header.php';
?>

<div class="filtros">
    <form method="get" class="d-flex flex-wrap gap-2 align-items-center">
        <select name="curso_id" class="form-select filtro-controle" style="width:220px" onchange="this.form.submit()">
            <option value="">Todos os cursos</option>
            <?php foreach ($cursos as $c): ?>
                <option value="<?= (int) $c['id'] ?>" <?= $cursoId === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['nome']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="turma_id" class="form-select filtro-controle" style="width:260px" onchange="this.form.submit()">
            <option value="">Todas as turmas</option>
            <?php foreach ($turmas as $t): ?>
                <option value="<?= (int) $t['id'] ?>" <?= $turmaId === (int) $t['id'] ? 'selected' : '' ?>><?= e($t['curso_nome']) ?> · <?= e($t['nome']) ?></option>
            <?php endforeach; ?>
        </select>
        <?php if ($turmaId || $cursoId): ?>
            <a href="/financeiro/inadimplencia.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x-lg"></i> Limpar</a>
        <?php endif; ?>
    </form>
    <span class="ms-auto filtro-chip">Total em atraso: <?= formatMoney($totalVencido) ?></span>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-md-4">
        <div class="card h-100"><div class="card-body">
            <div class="kpi-rotulo">Alunos em atraso</div>
            <div class="kpi-valor <?= $inadimplentes ? 'estoque-baixo' : '' ?>"><?= count($inadimplentes) ?></div>
        </div></div>
    </div>
    <div class="col-6 col-md-4">
        <div class="card h-100"><div class="card-body">
            <div class="kpi-rotulo">Valor em atraso</div>
            <div class="kpi-valor <?= $totalVencido > 0 ? 'estoque-baixo' : '' ?>"><?= formatMoney($totalVencido) ?></div>
        </div></div>
    </div>
    <div class="col-12 col-md-4">
        <div class="card h-100"><div class="card-body">
            <div class="kpi-rotulo">Parcelas em atraso</div>
            <div class="kpi-valor"><?= array_sum(array_column($inadimplentes, 'parcelas')) ?></div>
        </div></div>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead><tr><th>Aluno</th><th>Turma(s)</th><th>Telefone</th><th class="text-center">Parcelas</th><th>Mais antiga</th><th class="text-end">Dias de atraso</th><th class="text-end">Valor</th><th class="text-end">Cobrar</th></tr></thead>
            <tbody>
            <?php if (!$inadimplentes): ?>
                <tr><td colspan="8" class="text-center text-muted py-4"><i class="bi bi-emoji-smile"></i> Nenhum aluno em atraso.</td></tr>
            <?php endif; ?>
            <?php foreach ($inadimplentes as $i): ?>
                <?php
                    $mensagem = 'Olá, ' . explode(' ', trim($i['nome']))[0] . '! Aqui é da ' . $nomeInstituicao . '. '
                        . 'Identificamos ' . (int) $i['parcelas'] . ' parcela(s) em aberto, no total de ' . formatMoney($i['valor_vencido'])
                        . ', com vencimento desde ' . formatDate($i['vencimento_mais_antigo']) . '. '
                        . 'Podemos ajudar a regularizar?';
                    // wa.me exige o DDI; telefone cadastrado só com DDD+número ganha o 55.
                    $digitosTelefone = apenasDigitos((string) $i['telefone']);
                    $whatsapp = linkWhatsapp(strlen($digitosTelefone) <= 11 && $digitosTelefone !== '' ? '55' . $digitosTelefone : $digitosTelefone, $mensagem);
                ?>
                <tr class="cursor-pointer" onclick="window.location='/alunos/form.php?id=<?= (int) $i['aluno_id'] ?>&tab=financeiro'">
                    <td><?= e($i['nome']) ?></td>
                    <td class="small"><?= e($i['turmas'] ?: 'Lançamento avulso') ?></td>
                    <td><?= e($i['telefone'] ?: '-') ?></td>
                    <td class="text-center"><?= (int) $i['parcelas'] ?></td>
                    <td><?= formatDate($i['vencimento_mais_antigo']) ?></td>
                    <td class="text-end <?= (int) $i['dias_atraso'] > 30 ? 'estoque-baixo' : '' ?>"><?= (int) $i['dias_atraso'] ?></td>
                    <td class="text-end fw-semibold"><?= formatMoney($i['valor_vencido']) ?></td>
                    <td class="text-end" onclick="event.stopPropagation();">
                        <?php if ($whatsapp): ?>
                            <a href="<?= e($whatsapp) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-success" title="Abrir WhatsApp com mensagem de cobrança"><i class="bi bi-whatsapp"></i></a>
                        <?php endif; ?>
                        <?php if ($i['email']): ?>
                            <a href="mailto:<?= e($i['email']) ?>" class="btn btn-sm btn-outline-secondary" title="Enviar e-mail"><i class="bi bi-envelope"></i></a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <?php if ($inadimplentes): ?>
            <tfoot><tr><th colspan="6" class="text-end">Total em atraso</th><th class="text-end"><?= formatMoney($totalVencido) ?></th><th></th></tr></tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
