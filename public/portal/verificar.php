<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

$pdo = getDB();
$codigo = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($_GET['codigo'] ?? '')));
$certificado = null;
$consultou = $codigo !== '';
if ($consultou && !limiteDeTentativasExcedido($pdo, 'certificado_verificar', enderecoIpAtual(), 30, 30, 10)) {
    registrarTentativaAcesso($pdo, 'certificado_verificar', enderecoIpAtual());
    $certificado = strlen($codigo) === 12 ? buscarCertificadoCompleto($pdo, 'codigo', $codigo) : null;
}

$tituloPagina = 'Conferir certificado';
require __DIR__ . '/../../includes/portal_header.php';
?>
<div class="portal-acesso">
    <h1 class="portal-titulo text-center">Conferir certificado</h1>
    <p class="portal-sub text-center">Digite o código impresso no certificado.</p>
    <form class="portal-card" method="get">
        <div class="input-group input-group-lg">
            <input type="text" name="codigo" class="form-control text-uppercase" placeholder="XXXX-XXXX-XXXX" required value="<?= e($_GET['codigo'] ?? '') ?>">
            <button class="btn btn-brand" type="submit">Conferir</button>
        </div>
    </form>
    <?php if ($consultou): ?>
        <div class="portal-card mt-3 text-center">
            <?php if ($certificado && !$certificado['certificado_liberado']): ?>
                <i class="bi bi-pause-circle fs-1 text-warning"></i>
                <div class="fw-semibold mt-1">Certificado suspenso pela instituição.</div>
                <div class="small text-muted mt-1">Para mais informações, entre em contato com a secretaria.</div>
            <?php elseif ($certificado): ?>
                <i class="bi bi-patch-check-fill fs-1 text-success"></i>
                <div class="fw-semibold fs-5 mt-1">Certificado válido</div>
                <p class="mb-0 mt-2">
                    <strong><?= e($certificado['aluno_nome']) ?></strong> concluiu <strong><?= e($certificado['curso_nome']) ?></strong>
                    (<?= e($certificado['turma_nome']) ?>)<?= $certificado['carga_horaria'] ? ', ' . (int) $certificado['carga_horaria'] . ' horas' : '' ?>.
                </p>
                <div class="small text-muted mt-1">Emitido em <?= formatDate(substr($certificado['emitido_em'], 0, 10)) ?></div>
            <?php else: ?>
                <i class="bi bi-x-octagon fs-1 text-danger"></i>
                <div class="fw-semibold mt-1">Nenhum certificado com este código.</div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../../includes/portal_footer.php'; ?>
