<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();
exigirPermissao('ver_protocolos', 'Seu perfil não tem acesso aos protocolos.');

// Acompanhamento geral dos protocolos abertos pelos alunos: filtro por
// situação, assunto, curso, aluno e período de abertura.
$pdo = getDB();
$situacao = (string) ($_GET['situacao'] ?? 'abertos'); // abertos | responder | todos | ID do status
$assuntoId = (int) ($_GET['assunto'] ?? 0);
$temaId = (int) ($_GET['tema'] ?? 0);
$cursoId = (int) ($_GET['curso'] ?? 0);
$busca = trim((string) ($_GET['busca'] ?? ''));
$dataInicio = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['data_inicio'] ?? '')) ? $_GET['data_inicio'] : '';
$dataFim = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['data_fim'] ?? '')) ? $_GET['data_fim'] : '';

$sql = "SELECT p.id, p.numero, p.criado_em, p.atualizado_em, p.aguardando, p.data_referencia, a.id AS aluno_id, a.nome AS aluno_nome, a.cpf,
               pa.nome AS assunto_nome, pt.nome AS tema_nome, ps.nome AS status_nome, ps.cor AS status_cor, ps.encerra,
               c.nome AS curso_nome, t.nome AS turma_nome, cm.nome AS modulo_nome
        FROM protocolos p
        JOIN alunos a ON a.id = p.aluno_id
        JOIN protocolo_assuntos pa ON pa.id = p.assunto_id
        LEFT JOIN protocolo_temas pt ON pt.id = pa.tema_id
        JOIN protocolo_status ps ON ps.id = p.status_id
        LEFT JOIN matriculas m ON m.id = p.matricula_id
        LEFT JOIN turmas t ON t.id = m.turma_id
        LEFT JOIN cursos c ON c.id = t.curso_id
        LEFT JOIN curso_modulos cm ON cm.id = p.curso_modulo_id
        WHERE 1 = 1";
$params = [];
if ($situacao === 'abertos') {
    $sql .= ' AND ps.encerra = 0';
} elseif ($situacao === 'responder') {
    $sql .= " AND ps.encerra = 0 AND p.aguardando = 'secretaria'";
} elseif (ctype_digit($situacao)) {
    $sql .= ' AND p.status_id = ?';
    $params[] = (int) $situacao;
}
if ($temaId) {
    $sql .= ' AND pa.tema_id = ?';
    $params[] = $temaId;
}
if ($assuntoId) {
    $sql .= ' AND p.assunto_id = ?';
    $params[] = $assuntoId;
}
if ($cursoId) {
    $sql .= ' AND c.id = ?';
    $params[] = $cursoId;
}
if ($busca !== '') {
    $digitosBusca = preg_replace('/\D/', '', $busca);
    if ($digitosBusca !== '' && strlen($digitosBusca) >= 12 && $digitosBusca === preg_replace('/[\s.#-]/', '', $busca)) {
        // Número do protocolo (13 dígitos; CPF tem 11 e cai na busca do aluno).
        $sql .= ' AND p.numero LIKE ?';
        $params[] = '%' . $digitosBusca;
    } else {
        [$filtroAluno, $paramsAluno] = filtroBuscaAlunoReceber($busca);
        $sql .= $filtroAluno;
        $params = [...$params, ...$paramsAluno];
    }
}
if ($dataInicio) {
    $sql .= ' AND DATE(p.criado_em) >= ?';
    $params[] = $dataInicio;
}
if ($dataFim) {
    $sql .= ' AND DATE(p.criado_em) <= ?';
    $params[] = $dataFim;
}
$stmt = $pdo->prepare($sql . " ORDER BY ps.encerra, p.aguardando = 'secretaria' DESC, p.atualizado_em DESC");
$stmt->execute($params);
$protocolos = $stmt->fetchAll();

$lista = estadoListagem('protocolos', [
    'numero' => 'id',
    'aluno' => 'aluno_nome',
    'assunto' => 'assunto_nome',
    'aberto' => 'criado_em',
    'atualizado' => 'atualizado_em',
    'status' => 'status_nome',
]);
$protocolos = ordenarLinhas($protocolos, $lista);
$pagina = paginarLinhas($protocolos, $lista);

$assuntos = assuntosProtocolo($pdo, false);
$temas = temasProtocolo($pdo, false);
$statusLista = statusProtocolo($pdo, false);
$cursos = $pdo->query('SELECT DISTINCT c.id, c.nome FROM cursos c JOIN turmas t ON t.curso_id = c.id JOIN matriculas m ON m.turma_id = t.id JOIN protocolos p ON p.matricula_id = m.id ORDER BY c.nome')->fetchAll();
$aResponder = protocolosAguardandoSecretaria($pdo);

$pageTitle = 'Protocolos';
$pageBreadcrumb = 'Secretaria';
$activeMenu = 'protocolos';
$pageAction = (usuarioPodeConfigurarProtocolos() ? '<a href="/protocolos/configuracao.php" class="btn btn-outline-secondary"><i class="bi bi-gear"></i> Configurar</a>' : '')
    . (usuarioPodeAbrirProtocolo() ? '<a href="/protocolos/novo.php" class="btn btn-brand"><i class="bi bi-plus-lg"></i> Novo protocolo</a>' : '');
require __DIR__ . '/../../includes/layout_header.php';
?>

<div class="filtros">
    <form method="get">
        <select name="situacao" class="form-select filtro-controle" style="width:210px" onchange="this.form.submit()">
            <option value="abertos" <?= $situacao === 'abertos' ? 'selected' : '' ?>>Em aberto</option>
            <option value="responder" <?= $situacao === 'responder' ? 'selected' : '' ?>>Para responder (<?= $aResponder ?>)</option>
            <option value="todos" <?= $situacao === 'todos' ? 'selected' : '' ?>>Todos</option>
            <optgroup label="Status">
                <?php foreach ($statusLista as $st): ?>
                    <option value="<?= (int) $st['id'] ?>" <?= $situacao === (string) $st['id'] ? 'selected' : '' ?>><?= e($st['nome']) ?></option>
                <?php endforeach; ?>
            </optgroup>
        </select>
        <select name="tema" class="form-select filtro-controle" style="width:170px" onchange="this.form.assunto.value = ''; this.form.submit()">
            <option value="">Tema: todos</option>
            <?php foreach ($temas as $tm): ?>
                <option value="<?= (int) $tm['id'] ?>" <?= $temaId === (int) $tm['id'] ? 'selected' : '' ?>><?= e($tm['nome']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="assunto" class="form-select filtro-controle" style="width:220px" onchange="this.form.submit()">
            <option value="">Assunto: todos</option>
            <?php foreach ($assuntos as $as): if ($temaId && (int) $as['tema_id'] !== $temaId) continue; ?>
                <option value="<?= (int) $as['id'] ?>" <?= $assuntoId === (int) $as['id'] ? 'selected' : '' ?>><?= e($as['nome']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="curso" class="form-select filtro-controle" style="width:220px" onchange="this.form.submit()">
            <option value="">Curso: todos</option>
            <?php foreach ($cursos as $c): ?>
                <option value="<?= (int) $c['id'] ?>" <?= $cursoId === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['nome']) ?></option>
            <?php endforeach; ?>
        </select>
        <div class="input-group filtro-busca">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input type="search" name="busca" class="form-control" placeholder="Aluno (nome ou CPF) ou nº" value="<?= e($busca) ?>" onsearch="this.form.submit()">
        </div>
        <?php require __DIR__ . '/../../includes/filtro_periodo.php'; ?>
    </form>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead><tr>
                <?= thOrdenavel('Nº', 'numero', $lista) ?>
                <?= thOrdenavel('Aluno', 'aluno', $lista) ?>
                <?= thOrdenavel('Assunto', 'assunto', $lista) ?>
                <th>Turma / módulo</th>
                <?= thOrdenavel('Aberto em', 'aberto', $lista) ?>
                <?= thOrdenavel('Atualizado', 'atualizado', $lista) ?>
                <?= thOrdenavel('Status', 'status', $lista) ?>
            </tr></thead>
            <tbody>
            <?php if (!$pagina): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">Nenhum protocolo encontrado.</td></tr>
            <?php endif; ?>
            <?php foreach ($pagina as $p): ?>
                <tr class="cursor-pointer" onclick="location.href='/protocolos/ver.php?id=<?= (int) $p['id'] ?>'">
                    <td class="text-nowrap fw-semibold"><?= numeroProtocolo($p) ?></td>
                    <td><?= e($p['aluno_nome']) ?><?php if ($p['cpf']): ?><div class="small text-muted"><?= e(cpfFormatado($p['cpf'])) ?></div><?php endif; ?></td>
                    <td><?= e($p['assunto_nome']) ?><?php if ($p['tema_nome']): ?> <span class="badge text-bg-light border fw-normal"><?= e($p['tema_nome']) ?></span><?php endif; ?><?php if ($p['data_referencia']): ?><div class="small text-muted">data: <?= formatDate($p['data_referencia']) ?></div><?php endif; ?></td>
                    <td class="small"><?= $p['turma_nome'] ? e($p['curso_nome']) . '<div class="text-muted">' . e($p['turma_nome']) . ($p['modulo_nome'] ? ' · ' . e($p['modulo_nome']) : '') . '</div>' : '<span class="text-muted">—</span>' ?></td>
                    <td class="text-nowrap small"><?= formatDateTime($p['criado_em']) ?></td>
                    <td class="text-nowrap small"><?= formatDateTime($p['atualizado_em']) ?></td>
                    <td class="text-nowrap">
                        <?= badgeStatusProtocolo($p['status_nome'], $p['status_cor']) ?>
                        <?php if (!$p['encerra'] && $p['aguardando'] === 'secretaria'): ?><div><span class="badge text-bg-warning mt-1">Responder</span></div><?php elseif (!$p['encerra'] && $p['aguardando'] === 'aluno'): ?><div class="small text-muted">aguardando o aluno</div><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= rodapePaginacao($lista) ?>
</div>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
