<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirTelaPortal('certificados');
exigirAluno();

// Certificados e declarações de cada curso: os liberados abrem; os outros
// dizem por quê (pendência de documentação, financeira, ainda não concluiu...).
$pdo = getDB();
sincronizarDocumentosAluno($pdo, alunoAtualId());
$stmt = $pdo->prepare(
    "SELECT m.id, m.status, t.nome AS turma_nome, c.nome AS curso_nome, c.carga_horaria
     FROM matriculas m JOIN turmas t ON t.id = m.turma_id JOIN cursos c ON c.id = t.curso_id
     WHERE m.aluno_id = ? AND m.status IN ('ativa', 'concluida') ORDER BY m.status = 'ativa', t.data_termino DESC"
);
$stmt->execute([alunoAtualId()]);
$cursos = [];
foreach ($stmt->fetchAll() as $m) {
    if ($docs = documentosMatricula($pdo, (int) $m['id'])) {
        $cursos[] = $m + ['documentos' => $docs];
    }
}

$tituloPagina = 'Certificados';
$telaPortal = 'certificados';
require __DIR__ . '/../../includes/portal_header.php';
?>
<h1 class="portal-titulo">Certificados</h1>
<p class="portal-sub">Certificados e declarações dos seus cursos.</p>
<?php if (!$cursos): ?>
    <div class="portal-card portal-vazio"><i class="bi bi-award"></i>Você ainda não tem certificados. Quando concluir um curso, ele aparece aqui.</div>
<?php endif; ?>
<?php foreach ($cursos as $c): ?>
    <div class="portal-card">
        <div class="fw-semibold fs-5"><?= e($c['curso_nome']) ?></div>
        <div class="text-muted small mb-2"><?= e($c['turma_nome']) ?><?= $c['carga_horaria'] ? ' · ' . (int) $c['carga_horaria'] . ' horas' : '' ?></div>
        <?php foreach ($c['documentos'] as $d): ?>
            <div class="lista-linha">
                <i class="bi bi-award fs-3" style="color: var(--accent);"></i>
                <div class="principal">
                    <div class="t"><?= e($d['nome']) ?></div>
                    <div class="s"><?php if ($d['situacao'] === 'liberado'): ?>código <?= e(formatarCodigoCertificado($d['emitido']['codigo'])) ?><?php else: ?><?= e($d['texto']) ?><?php endif; ?></div>
                </div>
                <?php if ($d['situacao'] === 'liberado'): ?>
                    <a href="/portal/certificado.php?documento=<?= (int) $d['emitido']['id'] ?>" class="btn btn-sm btn-brand">Abrir</a>
                <?php elseif ($d['situacao'] === 'pendente'): ?>
                    <?php $bloq = bloqueiosDocumentosMatricula($pdo, matriculaParaDocumentos($pdo, (int) $c['id'])); ?>
                    <div class="d-flex flex-column align-items-end gap-1">
                        <?php if (isset($bloq['documentacao'])): ?><a href="/portal/documentos.php" class="etiqueta vencida text-decoration-none" title="<?= e($bloq['documentacao']) ?>">Pendente de documentação</a><?php endif; ?>
                        <?php if (isset($bloq['financeiro'])): ?><a href="/portal/financeiro.php" class="etiqueta vencida text-decoration-none">Pendência financeira</a><?php endif; ?>
                    </div>
                <?php elseif ($d['situacao'] === 'indisponivel'): ?>
                    <span class="etiqueta aberta">Em breve</span>
                <?php else: ?>
                    <span class="etiqueta aguardando" title="Em análise pela secretaria. Em caso de dúvida, fale com a gente.">Em análise</span>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php endforeach; ?>
<?php require __DIR__ . '/../../includes/portal_footer.php'; ?>
