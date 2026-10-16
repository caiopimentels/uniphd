<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirAluno();

$pdo = getDB();
$stmt = $pdo->prepare(
    "SELECT m.id, m.certificado_liberado, t.nome AS turma_nome, t.data_termino, c.nome AS curso_nome, c.carga_horaria, ce.codigo
     FROM matriculas m JOIN turmas t ON t.id = m.turma_id JOIN cursos c ON c.id = t.curso_id
     LEFT JOIN certificados ce ON ce.matricula_id = m.id
     WHERE m.aluno_id = ? AND m.status = 'concluida' ORDER BY t.data_termino DESC"
);
$stmt->execute([alunoAtualId()]);
$concluidos = $stmt->fetchAll();

$tituloPagina = 'Certificados';
$telaPortal = 'certificados';
require __DIR__ . '/../../includes/portal_header.php';
?>
<h1 class="portal-titulo">Certificados</h1>
<p class="portal-sub">Disponíveis assim que o curso é concluído.</p>
<div class="portal-card">
    <?php if (!$concluidos): ?>
        <div class="portal-vazio"><i class="bi bi-award"></i>Você ainda não concluiu nenhum curso. Quando concluir, o certificado aparece aqui.</div>
    <?php endif; ?>
    <?php foreach ($concluidos as $c): ?>
        <div class="lista-linha">
            <i class="bi bi-award fs-3" style="color: var(--accent);"></i>
            <div class="principal">
                <div class="t"><?= e($c['curso_nome']) ?></div>
                <div class="s"><?= e($c['turma_nome']) ?><?= $c['carga_horaria'] ? ' · ' . (int) $c['carga_horaria'] . ' horas' : '' ?><?= $c['codigo'] ? ' · código ' . e(formatarCodigoCertificado($c['codigo'])) : '' ?></div>
            </div>
            <?php if ($c['certificado_liberado']): ?>
                <a href="/portal/certificado.php?matricula=<?= (int) $c['id'] ?>" class="btn btn-sm btn-brand">Abrir</a>
            <?php else: ?>
                <span class="etiqueta aguardando" title="Seu certificado está em análise pela secretaria. Em caso de dúvida, fale com a gente.">Em análise</span>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>
<?php require __DIR__ . '/../../includes/portal_footer.php'; ?>
