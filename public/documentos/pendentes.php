<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();
// Liberado no perfil: "Aprovar, recusar ou dispensar documentos dos alunos".
exigirPermissao('avaliar_documentos', 'Seu perfil não analisa documentos dos alunos (libere em Perfis).');

// Secretaria › Documentos para analisar: todos os documentos que os alunos
// enviaram (diploma, RG...) esperando a secretaria conferir, num lugar só
// — o aviso do sininho some no meio de muitos. Ver, aprovar, recusar (com
// motivo, o aluno vê no portal) ou dispensar sem abrir a ficha de cada um.
$pdo = getDB();
$situacao = in_array($_GET['situacao'] ?? '', ['enviado', 'recusado', 'aprovado', 'todos'], true) ? $_GET['situacao'] : 'enviado';
$cursoId = (int) ($_GET['curso'] ?? 0);
$busca = trim((string) ($_GET['busca'] ?? ''));

$sql = "SELECT ad.*, cd.nome AS documento_nome, cd.trava_certificado, c.id AS curso_id, c.nome AS curso_nome, a.nome AS aluno_nome, a.cpf,
               (SELECT t.nome FROM matriculas m JOIN turmas t ON t.id = m.turma_id
                 WHERE m.aluno_id = ad.aluno_id AND t.curso_id = c.id ORDER BY FIELD(m.status, 'ativa', 'pendente', 'concluida') DESC, m.id DESC LIMIT 1) AS turma_nome
        FROM aluno_documentos ad
        JOIN curso_documentos cd ON cd.id = ad.curso_documento_id AND cd.ativo = 1
        JOIN cursos c ON c.id = cd.curso_id
        JOIN alunos a ON a.id = ad.aluno_id
        WHERE ad.arquivo IS NOT NULL";
$params = [];
if ($situacao !== 'todos') {
    $sql .= ' AND ad.status = ?';
    $params[] = $situacao;
}
if ($cursoId) {
    $sql .= ' AND c.id = ?';
    $params[] = $cursoId;
}
if ($busca !== '') {
    [$filtroAluno, $paramsAluno] = filtroBuscaAlunoReceber($busca);
    $sql .= $filtroAluno;
    $params = [...$params, ...$paramsAluno];
}
$stmt = $pdo->prepare($sql . ' ORDER BY ad.enviado_em');
$stmt->execute($params);
$documentos = $stmt->fetchAll();

$lista = estadoListagem('documentos_pendentes', [
    'aluno' => 'aluno_nome',
    'documento' => 'documento_nome',
    'curso' => 'curso_nome',
    'enviado' => 'enviado_em',
]);
$documentos = ordenarLinhas($documentos, $lista);
$pagina = paginarLinhas($documentos, $lista);
$aguardando = documentosAguardandoAnalise($pdo);
$cursos = $pdo->query('SELECT DISTINCT c.id, c.nome FROM cursos c JOIN curso_documentos cd ON cd.curso_id = c.id ORDER BY c.nome')->fetchAll();
$podeAvaliar = true;
$voltar = '/documentos/pendentes.php?' . http_build_query(['situacao' => $situacao, 'curso' => $cursoId ?: null, 'busca' => $busca ?: null]);
$statusDoc = statusDocumentoAluno();

$pageTitle = 'Documentos para analisar';
$pageBreadcrumb = 'Secretaria';
$activeMenu = 'documentos-pendentes';
require __DIR__ . '/../../includes/layout_header.php';
?>

<div class="filtros">
    <form method="get">
        <select name="situacao" class="form-select filtro-controle" style="width:230px" onchange="this.form.submit()">
            <option value="enviado" <?= $situacao === 'enviado' ? 'selected' : '' ?>>Aguardando análise (<?= $aguardando ?>)</option>
            <option value="recusado" <?= $situacao === 'recusado' ? 'selected' : '' ?>>Recusados (aluno precisa reenviar)</option>
            <option value="aprovado" <?= $situacao === 'aprovado' ? 'selected' : '' ?>>Aprovados</option>
            <option value="todos" <?= $situacao === 'todos' ? 'selected' : '' ?>>Todos os enviados</option>
        </select>
        <select name="curso" class="form-select filtro-controle" style="width:240px" onchange="this.form.submit()">
            <option value="">Curso: todos</option>
            <?php foreach ($cursos as $c): ?>
                <option value="<?= (int) $c['id'] ?>" <?= $cursoId === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['nome']) ?></option>
            <?php endforeach; ?>
        </select>
        <div class="input-group filtro-busca">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input type="search" name="busca" class="form-control" placeholder="Aluno (nome ou CPF)" value="<?= e($busca) ?>" onsearch="this.form.submit()">
        </div>
    </form>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead><tr>
                <?= thOrdenavel('Aluno', 'aluno', $lista) ?>
                <?= thOrdenavel('Documento', 'documento', $lista) ?>
                <?= thOrdenavel('Curso / turma', 'curso', $lista) ?>
                <?= thOrdenavel('Enviado em', 'enviado', $lista) ?>
                <th>Situação</th>
                <th class="text-end">Ações</th>
            </tr></thead>
            <tbody>
            <?php if (!$pagina): ?>
                <tr><td colspan="6" class="text-center text-muted py-4">
                    <?= $situacao === 'enviado' ? '<i class="bi bi-check2-circle fs-3 d-block mb-1 text-success"></i>Nenhum documento esperando análise.' : 'Nenhum documento encontrado.' ?>
                </td></tr>
            <?php endif; ?>
            <?php foreach ($pagina as $d): [$cor, $rotulo] = $statusDoc[$d['status']] ?? ['secondary', $d['status']]; ?>
                <tr>
                    <td><a href="/alunos/form.php?id=<?= (int) $d['aluno_id'] ?>&amp;tab=documentos"><?= e($d['aluno_nome']) ?></a><?php if ($d['cpf']): ?><div class="small text-muted"><?= cpfTela($d['cpf'], (int) $d['aluno_id']) ?></div><?php endif; ?></td>
                    <td>
                        <?= e($d['documento_nome']) ?>
                        <?php if ($d['trava_certificado']): ?><div><span class="badge text-bg-light border fw-normal" title="Sem este documento aprovado o certificado não é liberado"><i class="bi bi-lock"></i> trava o certificado</span></div><?php endif; ?>
                    </td>
                    <td class="small"><?= e($d['curso_nome']) ?><?php if ($d['turma_nome']): ?><div class="text-muted"><?= e($d['turma_nome']) ?></div><?php endif; ?></td>
                    <td class="small text-nowrap"><?= formatDateTime($d['enviado_em']) ?><div class="text-muted"><?= e($d['enviado_por']) ?></div></td>
                    <td>
                        <span class="badge text-bg-<?= e($cor) ?>"><?= e($rotulo) ?></span>
                        <?php if ($d['motivo']): ?><div class="small text-muted"><?= e($d['motivo']) ?></div><?php endif; ?>
                        <?php if ($d['avaliado_em']): ?><div class="small text-muted"><?= e($d['avaliado_por']) ?> · <?= formatDateTime($d['avaliado_em']) ?></div><?php endif; ?>
                    </td>
                    <td class="text-end text-nowrap">
                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="verDocumento(<?= (int) $d['id'] ?>, <?= $d['mime'] === 'application/pdf' ? 'true' : 'false' ?>, <?= e(json_encode($d['documento_nome'] . ' — ' . $d['aluno_nome'])) ?>)"><i class="bi bi-eye"></i> Ver</button>
                        <?php if ($podeAvaliar): ?>
                            <?php if ($d['status'] !== 'aprovado'): ?><button type="button" class="btn btn-sm btn-outline-success" onclick="avaliar(this, 'aprovar')" title="Aprovar"><i class="bi bi-check-lg"></i></button><?php endif; ?>
                            <?php if ($d['status'] !== 'recusado'): ?><button type="button" class="btn btn-sm btn-outline-danger" onclick="avaliar(this, 'recusar')" title="Recusar"><i class="bi bi-x-lg"></i></button><?php endif; ?>
                            <?php if ($d['status'] === 'enviado'): ?><button type="button" class="btn btn-sm btn-outline-info" onclick="avaliar(this, 'dispensar')" title="Dispensar (não precisa deste documento)"><i class="bi bi-slash-circle"></i></button><?php endif; ?>
                        <?php endif; ?>
                        <form method="post" action="/alunos/documento_acao.php" class="d-none">
                            <input type="hidden" name="aluno_id" value="<?= (int) $d['aluno_id'] ?>">
                            <input type="hidden" name="curso_documento_id" value="<?= (int) $d['curso_documento_id'] ?>">
                            <input type="hidden" name="voltar" value="<?= e($voltar) ?>">
                            <input type="hidden" name="acao">
                            <input type="hidden" name="motivo">
                            <input type="hidden" name="rotulo" value="<?= e($d['documento_nome'] . ' de ' . $d['aluno_nome']) ?>">
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= rodapePaginacao($lista) ?>
</div>

<?php require __DIR__ . '/../../includes/modal_documento.php'; ?>
<?php require __DIR__ . '/../../includes/modal_confirmar.php'; ?>
<script>
// Aprovar direto; recusar pede o motivo (o aluno vê no portal); dispensar
// aceita um motivo opcional. Tudo em modal, sem alert/prompt do navegador.
async function avaliar(botao, acao) {
    const form = botao.closest('td').querySelector('form');
    const rotulo = escapeHtml(form.rotulo.value);
    const textos = {
        aprovar: { titulo: 'Aprovar documento', html: `Aprovar <strong>${rotulo}</strong>?`, botao: 'Aprovar', classeBotao: 'btn-success' },
        recusar: { titulo: 'Recusar documento', html: `<p>Recusar <strong>${rotulo}</strong>? O aluno vê o motivo no portal e envia de novo.</p>
            <label class="form-label">Motivo da recusa *</label><input type="text" class="form-control" id="motivo-avaliacao" maxlength="255" placeholder="Ex.: foto ilegível, documento cortado">`, botao: 'Recusar', classeBotao: 'btn-danger' },
        dispensar: { titulo: 'Dispensar documento', html: `<p>Dispensar <strong>${rotulo}</strong>? O aluno não precisa mais enviar.</p>
            <label class="form-label">Motivo <span class="text-muted small">(opcional)</span></label><input type="text" class="form-control" id="motivo-avaliacao" maxlength="255">`, botao: 'Dispensar', classeBotao: 'btn-info' },
    }[acao];
    setTimeout(() => document.getElementById('motivo-avaliacao')?.focus(), 400);
    if (!(await confirmarNoModal(textos))) return;
    const motivo = (document.getElementById('motivo-avaliacao')?.value || '').trim();
    if (acao === 'recusar' && !motivo) {
        await confirmarNoModal({ titulo: 'Falta o motivo', html: 'Para recusar, escreva o motivo — é o que o aluno vê no portal.', botao: 'Ok' });
        return avaliar(botao, acao);
    }
    form.acao.value = acao;
    form.motivo.value = motivo;
    form.submit();
}
</script>
<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
