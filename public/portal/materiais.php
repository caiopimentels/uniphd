<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirAluno();

// Materiais de um curso do aluno (?m=matricula): os do curso e os extras da
// turma, por local (pasta) e módulo. Matrícula ativa, pendente ou concluída.
$pdo = getDB();
$stmt = $pdo->prepare("SELECT m.id, m.turma_id, t.nome AS turma_nome, c.nome AS curso_nome FROM matriculas m JOIN turmas t ON t.id = m.turma_id JOIN cursos c ON c.id = t.curso_id
                       WHERE m.id = ? AND m.aluno_id = ? AND m.status IN (" . statusAcessoConteudoPortal($pdo) . ")");
$stmt->execute([(int) ($_GET['m'] ?? 0), alunoAtualId()]);
$matricula = $stmt->fetch();
if (!$matricula) {
    flash('warning', 'Curso não encontrado.');
    redirect('/portal/meus_cursos.php');
}
$materiais = materiaisDaTurma($pdo, (int) $matricula['turma_id'], true);
$stmt = $pdo->prepare('SELECT material_id FROM material_acessos WHERE aluno_id = ?');
$stmt->execute([alunoAtualId()]);
$abertos = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
$modulos = array_values(array_unique(array_filter(array_column($materiais, 'modulo_nome'))));
$porLocal = [];
foreach ($materiais as $mt) {
    $porLocal[$mt['local_nome']]['icone'] = $mt['local_icone'];
    $porLocal[$mt['local_nome']]['itens'][] = $mt;
}

$tituloPagina = 'Materiais · ' . $matricula['curso_nome'];
require __DIR__ . '/../../includes/portal_header.php';
?>
<style>
    .mat-local { font-weight: 700; margin: 22px 0 8px; display: flex; align-items: center; gap: 8px; }
    .mat-item { display: flex; align-items: center; gap: 14px; padding: 12px 16px; border: 1px solid var(--border, #e5e7eb); border-radius: 12px; background: var(--surface, #fff); margin-bottom: 8px; color: inherit; text-decoration: none; }
    .mat-item:hover { border-color: var(--accent); box-shadow: 0 2px 10px rgba(0,0,0,.06); }
    .mat-item .acao { margin-left: auto; color: var(--accent); white-space: nowrap; font-size: 14px; }
</style>
<div class="d-flex flex-wrap align-items-end justify-content-between gap-2 mb-2">
    <div>
        <h1 class="portal-titulo mb-0">Materiais</h1>
        <p class="portal-sub mb-0"><?= e($matricula['curso_nome']) ?> · <?= e($matricula['turma_nome']) ?></p>
    </div>
    <?php if (count($modulos) > 1): ?>
        <select class="form-select" style="max-width: 280px" onchange="filtrarModulo(this.value)">
            <option value="">Todos os módulos</option>
            <?php foreach ($modulos as $mo): ?><option><?= e($mo) ?></option><?php endforeach; ?>
        </select>
    <?php endif; ?>
</div>

<?php if (!$materiais): ?>
    <div class="portal-card portal-vazio"><i class="bi bi-folder2-open"></i>Ainda não há materiais para este curso. Quando os professores colocarem, eles aparecem aqui.</div>
<?php endif; ?>

<?php foreach ($porLocal as $local => $grupo): ?>
    <div class="mat-grupo">
        <div class="mat-local"><i class="bi <?= e($grupo['icone']) ?>"></i> <?= e($local) ?></div>
        <?php foreach ($grupo['itens'] as $mt):
            $novo = !in_array((int) $mt['id'], $abertos, true) && strtotime($mt['liberar_em'] ?: $mt['criado_em']) > strtotime('-14 days');
            $url = '/portal/material.php?c=' . $mt['codigo'];
        ?>
            <a class="mat-item" href="<?= e($url) ?>" target="_blank" rel="noopener" data-modulo="<?= e($mt['modulo_nome'] ?? '') ?>">
                <i class="bi <?= $mt['tipo'] === 'link' ? 'bi-play-btn text-danger' : iconeArquivo($mt['arquivo_tipo'], $mt['arquivo_nome']) ?> fs-3"></i>
                <span style="min-width: 0">
                    <span class="fw-semibold d-block"><?= e($mt['titulo']) ?><?php if ($novo): ?> <span class="badge text-bg-danger">Novo</span><?php endif; ?></span>
                    <span class="small text-muted"><?= $mt['modulo_nome'] ? e($mt['modulo_nome']) . ' · ' : '' ?><?= $mt['tipo'] === 'link' ? e(parse_url($mt['link'], PHP_URL_HOST) ?: 'link') : tamanhoLegivel((int) $mt['tamanho']) ?></span>
                    <?php if ($mt['descricao']): ?><span class="small d-block"><?= e($mt['descricao']) ?></span><?php endif; ?>
                </span>
                <span class="acao"><i class="bi <?= $mt['tipo'] === 'link' ? 'bi-box-arrow-up-right' : (in_array($mt['arquivo_tipo'], ['application/pdf', 'image/png', 'image/jpeg'], true) ? 'bi-eye' : 'bi-download') ?>"></i> <?= $mt['tipo'] === 'link' ? 'Abrir' : (in_array($mt['arquivo_tipo'], ['application/pdf', 'image/png', 'image/jpeg'], true) ? 'Ver' : 'Baixar') ?></span>
            </a>
        <?php endforeach; ?>
    </div>
<?php endforeach; ?>

<div class="mt-4"><a href="/portal/meus_cursos.php"><i class="bi bi-arrow-left"></i> Meus cursos</a></div>
<script>
function filtrarModulo(modulo) {
    document.querySelectorAll('.mat-item').forEach((a) => { a.style.display = !modulo || a.dataset.modulo === modulo ? '' : 'none'; });
    document.querySelectorAll('.mat-grupo').forEach((g) => { g.style.display = [...g.querySelectorAll('.mat-item')].some((a) => a.style.display !== 'none') ? '' : 'none'; });
}
document.querySelectorAll('.mat-item').forEach((a) => a.addEventListener('click', () => a.querySelector('.badge')?.remove()));
</script>
<?php require __DIR__ . '/../../includes/portal_footer.php'; ?>
