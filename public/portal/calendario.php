<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirAluno();

$pdo = getDB();
$verPassadas = isset($_GET['passadas']);

$stmt = $pdo->prepare("SELECT turma_id FROM matriculas WHERE aluno_id = ? AND status = 'ativa'");
$stmt->execute([alunoAtualId()]);
$turmaIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
$aulas = $verPassadas
    ? array_reverse(buscarAulas($pdo, $turmaIds, null, date('Y-m-d', strtotime('-1 day'))))
    : buscarAulas($pdo, $turmaIds, date('Y-m-d'));
$porMes = [];
foreach ($aulas as $aula) {
    $porMes[substr($aula['data'], 0, 7)][] = $aula;
}
$meses = [1 => 'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
$diasCurtos = [1 => 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb', 'dom'];

$tituloPagina = 'Calendário';
$telaPortal = 'calendario';
require __DIR__ . '/../../includes/portal_header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-end gap-2">
    <div>
        <h1 class="portal-titulo">Calendário de aulas</h1>
        <p class="portal-sub mb-0"><?= $verPassadas ? 'Aulas que já aconteceram.' : 'Suas próximas aulas.' ?></p>
    </div>
    <a href="/portal/calendario.php<?= $verPassadas ? '' : '?passadas=1' ?>" class="btn btn-sm btn-outline-brand"><?= $verPassadas ? 'Ver próximas' : 'Ver aulas passadas' ?></a>
</div>

<?php if (!$turmaIds): ?>
    <div class="portal-card portal-vazio mt-3"><i class="bi bi-calendar"></i>O calendário aparece quando sua matrícula estiver confirmada.</div>
<?php elseif (!$aulas): ?>
    <div class="portal-card portal-vazio mt-3"><i class="bi bi-calendar"></i><?= $verPassadas ? 'Nenhuma aula passada.' : 'O calendário das suas turmas ainda não foi publicado.' ?></div>
<?php endif; ?>

<?php foreach ($porMes as $mes => $aulasMes): ?>
    <div class="portal-card mt-3">
        <div class="fw-semibold"><?= $meses[(int) substr($mes, 5, 2)] ?> de <?= substr($mes, 0, 4) ?></div>
        <?php foreach ($aulasMes as $aula): $t = strtotime($aula['data']); ?>
            <div class="dia-aula <?= $aula['status'] === 'cancelada' ? 'cancelada' : '' ?>">
                <div class="data"><div class="d"><?= date('d', $t) ?></div><div class="m"><?= $diasCurtos[(int) date('N', $t)] ?></div></div>
                <div class="principal">
                    <div class="t"><?= e($aula['curso_nome']) ?><?= $aula['tema'] ? ' — ' . e($aula['tema']) : '' ?></div>
                    <div class="small text-muted">
                        <?= e($aula['turma_nome']) ?>
                        <?php if ($aula['hora_inicio']): ?> · <?= substr($aula['hora_inicio'], 0, 5) ?><?= $aula['hora_fim'] ? ' às ' . substr($aula['hora_fim'], 0, 5) : '' ?><?php endif; ?>
                        <?php if ($aula['local']): ?> · <?= e($aula['local']) ?><?php endif; ?>
                    </div>
                    <?php if ($aula['status'] === 'cancelada'): ?><span class="etiqueta vencida mt-1">Aula cancelada</span><?php endif; ?>
                    <?php if ($aula['avulsa'] && $aula['status'] !== 'cancelada'): ?><span class="etiqueta aberta mt-1">Aula extra</span><?php endif; ?>
                    <?php if ($aula['observacao']): ?><div class="small mt-1"><?= e($aula['observacao']) ?></div><?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endforeach; ?>
<?php require __DIR__ . '/../../includes/portal_footer.php'; ?>
