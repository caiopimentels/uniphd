<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirAluno();

// "Meus documentos": o aluno envia os documentos que os cursos dele pedem.
$pdo = getDB();
$alunoId = alunoAtualId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Só documentos dos cursos em que o aluno está.
    $docId = (int) ($_POST['curso_documento_id'] ?? 0);
    $doc = null;
    foreach (documentosDoAluno($pdo, $alunoId) as $grupo) {
        foreach ($grupo['documentos'] as $d) {
            if ((int) $d['id'] === $docId) {
                $doc = $d;
            }
        }
    }
    if (!$doc) {
        flash('danger', 'Documento não encontrado.');
    } elseif ($doc['status'] === 'aprovado' || $doc['status'] === 'dispensado') {
        flash('warning', 'Este documento já foi ' . ($doc['status'] === 'aprovado' ? 'aprovado' : 'dispensado') . '.');
    } elseif (limiteDeTentativasExcedido($pdo, 'portal_documento', (string) $alunoId, 30, 30, 60)) {
        flash('danger', 'Muitos envios seguidos. Tente de novo mais tarde.');
    } else {
        registrarTentativaAcesso($pdo, 'portal_documento', (string) $alunoId);
        $resultado = salvarDocumentoAluno($pdo, $alunoId, $doc, $_FILES['arquivo'] ?? [], 'O próprio aluno (portal)');
        if ($resultado['ok']) {
            criarNotificacao($pdo, 'documento', 'Documento para analisar', alunoAtualNome() . ' enviou "' . $doc['nome'] . '".',
                '/alunos/form.php?id=' . $alunoId . '&tab=documentos', $alunoId);
        }
        flash($resultado['ok'] ? 'success' : 'danger', $resultado['mensagem']);
    }
    redirect('/portal/documentos.php');
}

$grupos = documentosDoAluno($pdo, $alunoId);
$anexosLiberados = anexosAluno($pdo, $alunoId, true);
$status = statusDocumentoAluno();
$tituloPagina = 'Meus documentos';
require __DIR__ . '/../../includes/portal_header.php';
?>
<h1 class="portal-titulo">Meus documentos</h1>
<p class="portal-sub">Alguns cursos pedem documentos (por exemplo, para emitir o certificado). Envie em PDF ou foto (JPG/PNG), bem legível.</p>

<?php if (!$grupos && !$anexosLiberados): ?>
    <div class="portal-card text-center text-muted">Nenhum dos seus cursos pede documentos.</div>
<?php endif; ?>

<?php if ($anexosLiberados): ?>
    <div class="portal-card mb-3">
        <h2 class="h5 mb-3">Documentos da secretaria</h2>
        <?php foreach ($anexosLiberados as $ax): ?>
            <div class="lista-linha">
                <i class="bi bi-file-earmark-<?= $ax['mime'] === 'application/pdf' ? 'pdf' : 'image' ?> fs-4"></i>
                <div class="principal"><div class="t"><?= e($ax['nome']) ?></div><div class="s"><?= formatDate($ax['criado_em']) ?></div></div>
                <a href="/documentos/ver.php?anexo=<?= (int) $ax['id'] ?>" target="_blank" class="btn btn-sm btn-outline-brand"><i class="bi bi-eye"></i> Abrir</a>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php foreach ($grupos as $grupo): ?>
    <div class="portal-card mb-3">
        <h2 class="h5 mb-3"><?= e($grupo['curso']['nome']) ?></h2>
        <?php foreach ($grupo['documentos'] as $d): [$cor, $rotulo] = $status[$d['status']]; ?>
            <div class="border rounded p-3 mb-2">
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <strong class="flex-grow-1"><?= e($d['nome']) ?></strong>
                    <span class="badge text-bg-<?= $cor ?>"><?= e($rotulo) ?></span>
                </div>
                <?php if ($d['instrucao']): ?><div class="small text-muted mt-1"><?= e($d['instrucao']) ?></div><?php endif; ?>
                <?php if ($d['status'] === 'recusado' && $d['motivo']): ?>
                    <div class="alert alert-danger py-2 small mt-2 mb-0"><strong>Motivo da recusa:</strong> <?= e($d['motivo']) ?> — envie de novo.</div>
                <?php endif; ?>
                <?php if ($d['arquivo']): ?>
                    <div class="small mt-2"><a href="/documentos/ver.php?id=<?= (int) $d['envio_id'] ?>" target="_blank"><i class="bi bi-eye"></i> Ver o que enviei</a> <span class="text-muted">· <?= formatDateTime($d['enviado_em']) ?></span></div>
                <?php endif; ?>
                <?php if (!in_array($d['status'], ['aprovado', 'dispensado'], true)): ?>
                    <form method="post" enctype="multipart/form-data" class="d-flex flex-wrap gap-2 align-items-center mt-2" onsubmit="this.querySelector('button').disabled = true; this.querySelector('button').innerHTML = 'Enviando...';">
                        <input type="hidden" name="curso_documento_id" value="<?= (int) $d['id'] ?>">
                        <input type="file" name="arquivo" class="form-control form-control-sm" style="max-width: 360px;" required accept="<?= e(acceptDocumento($d)) ?>">
                        <button type="submit" class="btn btn-sm btn-brand"><i class="bi bi-upload"></i> <?= $d['arquivo'] ? 'Enviar de novo' : 'Enviar' ?></button>
                        <span class="small text-muted"><?= e(textoTiposDocumento($d)) ?></span>
                    </form>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php endforeach; ?>
<?php require __DIR__ . '/../../includes/portal_footer.php'; ?>
