<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();
exigirPermissao('gerenciar_avisos', 'Seu perfil não pode enviar avisos aos alunos.');

// Avisos aos alunos: no ar, agendados, encerrados e desligados, com quantos
// alunos já viram e confirmaram.
$pdo = getDB();
$situacao = (string) ($_GET['situacao'] ?? 'atuais'); // atuais | no_ar | agendados | encerrados | desligados | todos
$formato = array_key_exists((string) ($_GET['formato'] ?? ''), formatosAviso()) ? $_GET['formato'] : '';
$busca = trim((string) ($_GET['busca'] ?? ''));

$sql = 'SELECT * FROM avisos WHERE 1 = 1';
$params = [];
$sql .= match ($situacao) {
    'no_ar' => ' AND ativo = 1 AND inicio <= NOW() AND (fim IS NULL OR fim >= NOW())',
    'agendados' => ' AND ativo = 1 AND inicio > NOW()',
    'encerrados' => ' AND ativo = 1 AND fim < NOW()',
    'desligados' => ' AND ativo = 0',
    'todos' => '',
    default => ' AND ativo = 1 AND (fim IS NULL OR fim >= NOW())', // no ar + agendados
};
if ($formato) {
    $sql .= ' AND formato = ?';
    $params[] = $formato;
}
if ($busca !== '') {
    $sql .= ' AND titulo LIKE ?';
    $params[] = '%' . $busca . '%';
}
$stmt = $pdo->prepare($sql . ' ORDER BY inicio DESC, id DESC');
$stmt->execute($params);
$avisos = $stmt->fetchAll();

$lista = estadoListagem('avisos', ['titulo' => 'titulo', 'inicio' => 'inicio', 'fim' => 'fim', 'formato' => 'formato']);
$avisos = ordenarLinhas($avisos, $lista);
$pagina = paginarLinhas($avisos, $lista);

$pageTitle = 'Avisos aos alunos';
$pageBreadcrumb = 'Portal';
$activeMenu = 'avisos';
$pageAction = '<a href="/avisos/form.php" class="btn btn-brand"><i class="bi bi-plus-lg"></i> Novo aviso</a>';
require __DIR__ . '/../../includes/layout_header.php';
$formatos = formatosAviso();
$cores = coresAviso();
?>

<div class="filtros">
    <form method="get">
        <select name="situacao" class="form-select filtro-controle" style="width:220px" onchange="this.form.submit()">
            <?php foreach (['atuais' => 'No ar e agendados', 'no_ar' => 'Só os no ar', 'agendados' => 'Agendados', 'encerrados' => 'Encerrados', 'desligados' => 'Desligados', 'todos' => 'Todos'] as $chave => $rotulo): ?>
                <option value="<?= $chave ?>" <?= $situacao === $chave ? 'selected' : '' ?>><?= $rotulo ?></option>
            <?php endforeach; ?>
        </select>
        <select name="formato" class="form-select filtro-controle" style="width:190px" onchange="this.form.submit()">
            <option value="">Formato: todos</option>
            <?php foreach ($formatos as $chave => [$rotulo]): ?>
                <option value="<?= $chave ?>" <?= $formato === $chave ? 'selected' : '' ?>><?= e($rotulo) ?></option>
            <?php endforeach; ?>
        </select>
        <div class="input-group filtro-busca">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input type="search" name="busca" class="form-control" placeholder="Título do aviso" value="<?= e($busca) ?>" onsearch="this.form.submit()">
        </div>
    </form>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead><tr>
                <?= thOrdenavel('Título', 'titulo', $lista) ?>
                <?= thOrdenavel('Formato', 'formato', $lista) ?>
                <th>Para</th>
                <?= thOrdenavel('Início', 'inicio', $lista) ?>
                <?= thOrdenavel('Fim', 'fim', $lista) ?>
                <th>Situação</th>
                <th class="text-center">Viram</th>
            </tr></thead>
            <tbody>
            <?php if (!$pagina): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">Nenhum aviso encontrado.</td></tr>
            <?php endif; ?>
            <?php foreach ($pagina as $av):
                [$recebem, $viram, $confirmaram] = resumoLeiturasAviso($pdo, $av);
                [$situacaoTexto, $situacaoCor] = situacaoAviso($av);
                $nomes = array_column(nomesDestinosAviso($pdo, $av), 'nome');
            ?>
                <tr class="cursor-pointer" onclick="location.href='/avisos/ver.php?id=<?= (int) $av['id'] ?>'">
                    <td><span class="d-inline-block rounded-circle me-1" style="width:10px;height:10px;background:<?= $cores[$av['cor']][1] ?>"></span><span class="fw-semibold"><?= e($av['titulo']) ?></span>
                        <?php if ($av['exige_confirmacao']): ?><span class="badge text-bg-light border fw-normal ms-1">Li e entendi</span><?php endif; ?><?php if ($av['repeticao'] !== 'uma_vez'): ?><span class="badge text-bg-light border fw-normal ms-1"><?= e(repeticoesAviso()[$av['repeticao']][0]) ?></span><?php endif; ?></td>
                    <td class="small text-nowrap"><i class="bi <?= $formatos[$av['formato']][2] ?>"></i> <?= e($formatos[$av['formato']][0]) ?></td>
                    <td class="small"><?php if ($av['publico'] === 'site'): ?>Todo mundo (portal)<?= $av['quem_site'] !== 'todos' ? ' · ' . ($av['quem_site'] === 'visitantes' ? 'só visitantes' : 'só logados') : '' ?><?php elseif ($av['publico'] === 'todos'): ?>Todos os alunos<?php else: ?>
                        <?= e(implode(', ', array_slice($nomes, 0, 2))) ?><?= count($nomes) > 2 ? ' <span class="text-muted">+' . (count($nomes) - 2) . '</span>' : '' ?><?php endif; ?></td>
                    <td class="small text-nowrap"><?= formatDateTime($av['inicio']) ?></td>
                    <td class="small text-nowrap"><?= $av['fim'] ? formatDateTime($av['fim']) : '<span class="text-muted">sem fim</span>' ?></td>
                    <td><span class="badge text-bg-<?= $situacaoCor ?> <?= $situacaoCor === 'light' ? 'border' : '' ?>"><?= $situacaoTexto ?></span></td>
                    <td class="text-center text-nowrap small"><strong><?= $viram ?></strong> de <?= $recebem ?><?php if ($av['exige_confirmacao']): ?><div class="text-muted"><?= $confirmaram ?> confirmaram</div><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= rodapePaginacao($lista) ?>
</div>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
