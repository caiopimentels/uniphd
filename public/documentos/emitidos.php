<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();

// Certificados e declarações liberados para os alunos (Acadêmico ›
// Certificados emitidos), para controle: filtro por curso, turma, aluno e
// data de liberação, e se o aluno já baixou pelo portal.
if (!usuarioPodeLiberarCertificado() && !usuarioPodeVerAlunos()) {
    flash('danger', 'Seu perfil não tem acesso aos certificados emitidos.');
    redirect('/sem_acesso.php');
}

$pdo = getDB();
$cursoId = (int) ($_GET['curso'] ?? 0);
$turmaId = (int) ($_GET['turma'] ?? 0);
$busca = trim((string) ($_GET['busca'] ?? ''));
$dataInicio = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['data_inicio'] ?? '')) ? $_GET['data_inicio'] : '';
$dataFim = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['data_fim'] ?? '')) ? $_GET['data_fim'] : '';
$baixado = in_array($_GET['baixado'] ?? '', ['sim', 'nao'], true) ? $_GET['baixado'] : '';

$sql = "SELECT ce.id, ce.codigo, ce.liberado_em, ce.liberado_por, ce.emitido_em, ce.aluno_baixou_em, ce.aluno_ultimo_download, ce.aluno_downloads,
               COALESCE(e.nome, 'Certificado') AS documento_nome, a.id AS aluno_id, a.nome AS aluno_nome, a.cpf,
               c.nome AS curso_nome, t.id AS turma_id, t.nome AS turma_nome
        FROM certificados ce
        JOIN matriculas m ON m.id = ce.matricula_id
        JOIN alunos a ON a.id = m.aluno_id
        JOIN turmas t ON t.id = m.turma_id
        JOIN cursos c ON c.id = t.curso_id
        LEFT JOIN curso_emissoes e ON e.id = ce.curso_emissao_id
        WHERE ce.liberado = 1";
$params = [];
if ($cursoId) {
    $sql .= ' AND c.id = ?';
    $params[] = $cursoId;
}
if ($turmaId) {
    $sql .= ' AND t.id = ?';
    $params[] = $turmaId;
}
if ($busca !== '') {
    [$filtroAluno, $paramsAluno] = filtroBuscaAlunoReceber($busca);
    $sql .= $filtroAluno;
    $params = [...$params, ...$paramsAluno];
}
// Data de liberação (quando o documento ficou disponível ao aluno).
if ($dataInicio) {
    $sql .= ' AND DATE(COALESCE(ce.liberado_em, ce.emitido_em)) >= ?';
    $params[] = $dataInicio;
}
if ($dataFim) {
    $sql .= ' AND DATE(COALESCE(ce.liberado_em, ce.emitido_em)) <= ?';
    $params[] = $dataFim;
}
if ($baixado !== '') {
    $sql .= $baixado === 'sim' ? ' AND ce.aluno_baixou_em IS NOT NULL' : ' AND ce.aluno_baixou_em IS NULL';
}
$stmt = $pdo->prepare($sql . ' ORDER BY COALESCE(ce.liberado_em, ce.emitido_em) DESC, ce.id DESC');
$stmt->execute($params);
$documentos = $stmt->fetchAll();

$lista = estadoListagem('certificados_emitidos', [
    'aluno' => 'aluno_nome',
    'documento' => 'documento_nome',
    'curso' => fn ($d) => $d['curso_nome'] . ' ' . $d['turma_nome'],
    'liberado' => fn ($d) => $d['liberado_em'] ?: $d['emitido_em'],
    'baixado' => 'aluno_baixou_em',
]);
$documentos = ordenarLinhas($documentos, $lista);
$totalBaixados = count(array_filter($documentos, fn ($d) => $d['aluno_baixou_em']));
$pagina = paginarLinhas($documentos, $lista);

$cursos = $pdo->query('SELECT DISTINCT c.id, c.nome FROM cursos c JOIN turmas t ON t.curso_id = c.id JOIN matriculas m ON m.turma_id = t.id JOIN certificados ce ON ce.matricula_id = m.id ORDER BY c.nome')->fetchAll();
$stmt = $pdo->prepare('SELECT DISTINCT t.id, t.nome, c.nome AS curso_nome FROM turmas t JOIN cursos c ON c.id = t.curso_id JOIN matriculas m ON m.turma_id = t.id JOIN certificados ce ON ce.matricula_id = m.id'
    . ($cursoId ? ' WHERE c.id = ?' : '') . ' ORDER BY c.nome, t.nome');
$stmt->execute($cursoId ? [$cursoId] : []);
$turmas = $stmt->fetchAll();

$pageTitle = 'Certificados emitidos';
$pageBreadcrumb = 'Secretaria';
$activeMenu = 'certificados-emitidos';
require __DIR__ . '/../../includes/layout_header.php';
?>

<div class="filtros">
    <form method="get">
        <select name="curso" class="form-select filtro-controle" style="width:230px" onchange="this.form.turma.value = ''; this.form.submit()">
            <option value="">Curso: todos</option>
            <?php foreach ($cursos as $c): ?>
                <option value="<?= (int) $c['id'] ?>" <?= $cursoId === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['nome']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="turma" class="form-select filtro-controle" style="width:230px" onchange="this.form.submit()">
            <option value="">Turma: todas</option>
            <?php foreach ($turmas as $t): ?>
                <option value="<?= (int) $t['id'] ?>" <?= $turmaId === (int) $t['id'] ? 'selected' : '' ?>><?= e(($cursoId ? '' : $t['curso_nome'] . ' · ') . $t['nome']) ?></option>
            <?php endforeach; ?>
        </select>
        <div class="input-group filtro-busca">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input type="search" name="busca" class="form-control" placeholder="Aluno (nome ou CPF)" value="<?= e($busca) ?>" onsearch="this.form.submit()">
        </div>
        <?php require __DIR__ . '/../../includes/filtro_periodo.php'; ?>
        <select name="baixado" class="form-select filtro-controle" style="width:210px" onchange="this.form.submit()">
            <option value="">Baixado pelo aluno: todos</option>
            <option value="sim" <?= $baixado === 'sim' ? 'selected' : '' ?>>Já baixou</option>
            <option value="nao" <?= $baixado === 'nao' ? 'selected' : '' ?>>Ainda não baixou</option>
        </select>
        <?php if ($cursoId || $turmaId || $busca !== '' || $dataInicio || $dataFim || $baixado !== ''): ?>
            <a href="/documentos/emitidos.php" class="btn btn-link btn-sm">Limpar filtros</a>
        <?php endif; ?>
    </form>
</div>

<div class="small text-muted mb-2">
    <?= count($documentos) ?> documento(s) liberado(s) · <?= $totalBaixados ?> já baixado(s) pelo aluno no portal
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead><tr>
                <?= thOrdenavel('Aluno', 'aluno', $lista) ?>
                <?= thOrdenavel('Documento', 'documento', $lista) ?>
                <?= thOrdenavel('Curso / turma', 'curso', $lista) ?>
                <?= thOrdenavel('Liberado em', 'liberado', $lista) ?>
                <?= thOrdenavel('Baixado pelo aluno', 'baixado', $lista) ?>
                <th>Código</th>
                <th class="text-end">Ver</th>
            </tr></thead>
            <tbody>
            <?php if (!$pagina): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">Nenhum documento encontrado.</td></tr>
            <?php endif; ?>
            <?php foreach ($pagina as $d): ?>
                <tr>
                    <td><a href="/alunos/form.php?id=<?= (int) $d['aluno_id'] ?>"><?= e($d['aluno_nome']) ?></a><?php if ($d['cpf']): ?><div class="small text-muted"><?= e(cpfFormatado($d['cpf'])) ?></div><?php endif; ?></td>
                    <td><?= e($d['documento_nome']) ?></td>
                    <td><?= e($d['curso_nome']) ?><div class="small text-muted"><a href="/turmas/visualizar.php?id=<?= (int) $d['turma_id'] ?>" class="text-muted"><?= e($d['turma_nome']) ?></a></div></td>
                    <td class="text-nowrap"><?= formatDateTime($d['liberado_em'] ?: $d['emitido_em']) ?><?php if ($d['liberado_por']): ?><div class="small text-muted"><?= e($d['liberado_por']) ?></div><?php endif; ?></td>
                    <td class="text-nowrap">
                        <?php if ($d['aluno_baixou_em']): ?>
                            <span class="badge text-bg-success">Baixou em <?= formatDate($d['aluno_baixou_em']) ?></span>
                            <div class="small text-muted"><?= (int) $d['aluno_downloads'] ?>× · última <?= formatDateTime($d['aluno_ultimo_download']) ?></div>
                        <?php else: ?>
                            <span class="badge text-bg-light border">Ainda não</span>
                        <?php endif; ?>
                    </td>
                    <td class="small font-monospace"><?= e($d['codigo']) ?></td>
                    <td class="text-end"><a href="/portal/certificado.php?c=<?= e($d['codigo']) ?>" target="_blank" class="btn btn-sm btn-outline-secondary" title="Abrir o documento (PDF)"><i class="bi bi-file-earmark-pdf"></i></a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= rodapePaginacao($lista) ?>
</div>
<div class="form-text">"Baixado pelo aluno" conta só o que o aluno abriu no portal (a partir de hoje); abrir aqui pela secretaria não conta.</div>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
