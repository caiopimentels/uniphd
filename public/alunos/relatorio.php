<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirPermissao('ver_alunos', 'Seu perfil não pode ver os alunos.');

// Relatórios › Alunos por graduação e curso. Duas visões, cada linha abre a
// lista dos alunos dela:
//   graduacao → a graduação que o aluno informou (inclui "sem graduação")
//   cursos    → os cursos da UniPHD em que ele tem matrícula (inclui "sem
//               nenhum curso": nunca se matriculou ou só tem matrícula cancelada)
// ?ativos=1 conta só os alunos com cadastro ativo.
$pdo = getDB();
$visao = ($_GET['visao'] ?? '') === 'cursos' ? 'cursos' : 'graduacao';
$soAtivos = !empty($_GET['ativos']);
$grupo = (string) ($_GET['grupo'] ?? '');
$filtroAluno = $soAtivos ? ' AND a.ativo = 1' : '';
$statusComCurso = "'ativa', 'pendente', 'trancada', 'concluida'";

$totalAlunos = (int) $pdo->query("SELECT COUNT(*) FROM alunos a WHERE 1 = 1{$filtroAluno}")->fetchColumn();

if ($visao === 'graduacao') {
    $linhas = $pdo->query(
        "SELECT tg.id AS grupo, tg.nome, COUNT(a.id) AS total
         FROM tipos_graduacao tg LEFT JOIN alunos a ON a.graduacao_id = tg.id{$filtroAluno}
         GROUP BY tg.id, tg.nome HAVING total > 0 OR MAX(tg.ativo) = 1 ORDER BY total DESC, tg.nome"
    )->fetchAll();
    $semGraduacao = (int) $pdo->query("SELECT COUNT(*) FROM alunos a WHERE a.possui_graduacao = 0{$filtroAluno}")->fetchColumn();
    $naoInformado = (int) $pdo->query("SELECT COUNT(*) FROM alunos a WHERE (a.possui_graduacao IS NULL OR (a.possui_graduacao = 1 AND a.graduacao_id IS NULL)){$filtroAluno}")->fetchColumn();
    $especiais = [
        ['grupo' => 'sem', 'nome' => 'Sem graduação', 'total' => $semGraduacao, 'dica' => 'Respondeu que não tem graduação'],
        ['grupo' => 'nao_informado', 'nome' => 'Não informado', 'total' => $naoInformado, 'dica' => 'O cadastro não diz se tem graduação'],
    ];
} else {
    $linhas = $pdo->query(
        "SELECT c.id AS grupo, c.nome,
                COUNT(DISTINCT CASE WHEN m.status IN ('ativa', 'pendente') THEN a.id END) AS cursando,
                COUNT(DISTINCT CASE WHEN m.status = 'trancada' THEN a.id END) AS trancados,
                COUNT(DISTINCT CASE WHEN m.status = 'concluida' THEN a.id END) AS concluidos,
                COUNT(DISTINCT a.id) AS total
         FROM cursos c
         LEFT JOIN turmas t ON t.curso_id = c.id
         LEFT JOIN matriculas m ON m.turma_id = t.id AND m.status IN ({$statusComCurso})
         LEFT JOIN alunos a ON a.id = m.aluno_id{$filtroAluno}
         GROUP BY c.id, c.nome HAVING total > 0 OR MAX(c.ativo) = 1 ORDER BY total DESC, c.nome"
    )->fetchAll();
    $semCurso = (int) $pdo->query(
        "SELECT COUNT(*) FROM alunos a WHERE NOT EXISTS (SELECT 1 FROM matriculas m WHERE m.aluno_id = a.id AND m.status IN ({$statusComCurso})){$filtroAluno}"
    )->fetchColumn();
    $especiais = [
        ['grupo' => 'sem', 'nome' => 'Sem nenhum curso', 'total' => $semCurso, 'dica' => 'Nunca se matriculou ou só tem matrícula cancelada'],
    ];
}

// Alunos do grupo clicado.
$alunosGrupo = null;
$tituloGrupo = '';
if ($grupo !== '') {
    $params = [];
    if ($visao === 'graduacao') {
        if ($grupo === 'sem') {
            $where = 'a.possui_graduacao = 0';
            $tituloGrupo = 'Sem graduação';
        } elseif ($grupo === 'nao_informado') {
            $where = '(a.possui_graduacao IS NULL OR (a.possui_graduacao = 1 AND a.graduacao_id IS NULL))';
            $tituloGrupo = 'Graduação não informada';
        } else {
            $where = 'a.graduacao_id = ?';
            $params[] = (int) $grupo;
            $stmt = $pdo->prepare('SELECT nome FROM tipos_graduacao WHERE id = ?');
            $stmt->execute([(int) $grupo]);
            $tituloGrupo = 'Graduação: ' . (string) $stmt->fetchColumn();
        }
    } elseif ($grupo === 'sem') {
        $where = "NOT EXISTS (SELECT 1 FROM matriculas m WHERE m.aluno_id = a.id AND m.status IN ({$statusComCurso}))";
        $tituloGrupo = 'Sem nenhum curso';
    } else {
        $where = "EXISTS (SELECT 1 FROM matriculas m JOIN turmas t ON t.id = m.turma_id WHERE m.aluno_id = a.id AND t.curso_id = ? AND m.status IN ({$statusComCurso}))";
        $params[] = (int) $grupo;
        $stmt = $pdo->prepare('SELECT nome FROM cursos WHERE id = ?');
        $stmt->execute([(int) $grupo]);
        $tituloGrupo = 'Curso: ' . (string) $stmt->fetchColumn();
    }
    $stmt = $pdo->prepare(
        "SELECT a.id, a.ra, a.nome, a.telefone, a.email, a.ativo, tg.nome AS graduacao, a.possui_graduacao,
                (SELECT GROUP_CONCAT(DISTINCT CONCAT(c.nome, ' (', m.status, ')') ORDER BY c.nome SEPARATOR '; ')
                 FROM matriculas m JOIN turmas t ON t.id = m.turma_id JOIN cursos c ON c.id = t.curso_id
                 WHERE m.aluno_id = a.id AND m.status IN ({$statusComCurso})) AS cursos
         FROM alunos a LEFT JOIN tipos_graduacao tg ON tg.id = a.graduacao_id
         WHERE {$where}{$filtroAluno} ORDER BY a.nome"
    );
    $stmt->execute($params);
    $alunosGrupo = $stmt->fetchAll();
}

$trocaStatus = ['(ativa)' => '(cursando)', '(pendente)' => '(aguardando confirmação)', '(trancada)' => '(trancado)', '(concluida)' => '(concluído)'];
$link = fn (array $extra) => '?' . http_build_query(array_filter(['visao' => $visao, 'ativos' => $soAtivos ? 1 : null] + $extra, fn ($v) => $v !== null && $v !== ''));
$percentual = fn (int $n) => $totalAlunos ? number_format($n * 100 / $totalAlunos, 1, ',', '') . '%' : '-';

$pageTitle = 'Alunos por graduação e curso';
$pageBreadcrumb = 'Relatórios';
$activeMenu = 'relatorio-alunos';
$pageAction = '<button type="button" class="btn btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer"></i> Imprimir</button>';
require __DIR__ . '/../../includes/layout_header.php';
?>

<div class="filtros nao-imprimir">
    <ul class="nav nav-pills">
        <li class="nav-item"><a class="nav-link <?= $visao === 'graduacao' ? 'active' : '' ?>" href="?<?= e(http_build_query(array_filter(['visao' => 'graduacao', 'ativos' => $soAtivos ? 1 : null]))) ?>"><i class="bi bi-mortarboard"></i> Por graduação</a></li>
        <li class="nav-item"><a class="nav-link <?= $visao === 'cursos' ? 'active' : '' ?>" href="?<?= e(http_build_query(array_filter(['visao' => 'cursos', 'ativos' => $soAtivos ? 1 : null]))) ?>"><i class="bi bi-journal-bookmark"></i> Por curso da UniPHD</a></li>
    </ul>
    <form method="get" class="ms-auto d-flex align-items-center gap-2">
        <input type="hidden" name="visao" value="<?= e($visao) ?>">
        <?php if ($grupo !== ''): ?><input type="hidden" name="grupo" value="<?= e($grupo) ?>"><?php endif; ?>
        <div class="form-check form-switch mb-0">
            <input type="checkbox" class="form-check-input" role="switch" id="so-ativos" name="ativos" value="1" <?= $soAtivos ? 'checked' : '' ?> onchange="this.form.submit()">
            <label class="form-check-label" for="so-ativos">Só alunos com cadastro ativo</label>
        </div>
    </form>
</div>

<p class="text-muted small mb-2"><?= $totalAlunos ?> aluno(s) <?= $soAtivos ? 'ativos ' : '' ?>no cadastro. Clique numa linha para ver quem são.
    <?= $visao === 'cursos' ? 'Um aluno com mais de um curso conta em cada um deles; matrícula cancelada não conta.' : '' ?></p>

<div class="card mb-4" style="max-width: 1000px;">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead><tr>
                <th><?= $visao === 'graduacao' ? 'Graduação' : 'Curso' ?></th>
                <?php if ($visao === 'cursos'): ?><th class="text-end">Cursando</th><th class="text-end">Trancados</th><th class="text-end">Concluídos</th><?php endif; ?>
                <th class="text-end">Alunos</th><th class="text-end">% do cadastro</th>
            </tr></thead>
            <tbody>
            <?php foreach ($linhas as $l): ?>
                <tr class="cursor-pointer <?= (string) $l['grupo'] === $grupo ? 'table-active' : '' ?>" onclick="location.href='<?= e($link(['grupo' => $l['grupo']])) ?>#alunos'">
                    <td><?= e($l['nome']) ?></td>
                    <?php if ($visao === 'cursos'): ?>
                        <td class="text-end"><?= (int) $l['cursando'] ?></td><td class="text-end"><?= (int) $l['trancados'] ?></td><td class="text-end"><?= (int) $l['concluidos'] ?></td>
                    <?php endif; ?>
                    <td class="text-end fw-semibold"><?= (int) $l['total'] ?></td>
                    <td class="text-end text-muted small"><?= $percentual((int) $l['total']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php foreach ($especiais as $l): ?>
                <tr class="cursor-pointer table-light <?= $l['grupo'] === $grupo ? 'table-active' : '' ?>" onclick="location.href='<?= e($link(['grupo' => $l['grupo']])) ?>#alunos'">
                    <td><strong><?= e($l['nome']) ?></strong> <span class="small text-muted">· <?= e($l['dica']) ?></span></td>
                    <?php if ($visao === 'cursos'): ?><td></td><td></td><td></td><?php endif; ?>
                    <td class="text-end fw-semibold"><?= (int) $l['total'] ?></td>
                    <td class="text-end text-muted small"><?= $percentual((int) $l['total']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($alunosGrupo !== null): ?>
<div class="secao" id="alunos">
    <div class="secao-titulo"><i class="bi bi-people"></i> <?= e($tituloGrupo) ?> · <?= count($alunosGrupo) ?> aluno(s)
        <a href="<?= e($link([])) ?>" class="btn btn-sm btn-outline-secondary ms-auto nao-imprimir"><i class="bi bi-x-lg"></i> Fechar lista</a>
    </div>
    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead><tr><th>Aluno</th><th>Telefone</th><th>E-mail</th><th><?= $visao === 'graduacao' ? 'Cursos na UniPHD' : 'Graduação' ?></th><th>Cadastro</th></tr></thead>
                <tbody>
                <?php if (!$alunosGrupo): ?>
                    <tr><td colspan="5" class="text-center text-muted py-4">Nenhum aluno.</td></tr>
                <?php endif; ?>
                <?php foreach ($alunosGrupo as $a): ?>
                    <tr class="cursor-pointer" onclick="location.href='/alunos/form.php?id=<?= (int) $a['id'] ?>'">
                        <td><?= e($a['nome']) ?><?= $a['ra'] ? '<div class="small text-muted">' . e($a['ra']) . '</div>' : '' ?></td>
                        <td class="text-nowrap"><?= e(telefoneFormatado($a['telefone']) ?: '-') ?></td>
                        <td class="small"><?= e($a['email'] ?: '-') ?></td>
                        <td class="small">
                            <?php if ($visao === 'graduacao'): ?>
                                <?= $a['cursos'] ? e(strtr($a['cursos'], $trocaStatus)) : '<span class="text-muted">nenhum</span>' ?>
                            <?php else: ?>
                                <?= e($a['graduacao'] ?: ($a['possui_graduacao'] === 0 || $a['possui_graduacao'] === '0' ? 'Sem graduação' : 'Não informada')) ?>
                            <?php endif; ?>
                        </td>
                        <td><?= seloAtivo((bool) $a['ativo']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
