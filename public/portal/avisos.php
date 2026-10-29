<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirAluno();

// "Meus avisos": lista dos avisos da secretaria no ar para o aluno. Clicar
// abre o aviso do mesmo jeito que chegou (janela com a arte ou o texto) e
// conta como visto. ?abrir=ID já abre um.
$pdo = getDB();
$avisosMural = avisosDoAluno($pdo, alunoAtualId());
$comArteAviso = (bool) array_filter($avisosMural, fn ($a) => $a['montagem'] === 'arte');
$abrir = (int) ($_GET['abrir'] ?? 0);

$tituloPagina = 'Meus avisos';
require __DIR__ . '/../../includes/portal_header.php';
$cores = coresAviso();
?>
<div class="mb-3">
    <h1 class="portal-titulo mb-0">Meus avisos</h1>
    <p class="portal-sub mb-0">Recados da secretaria. Clique para abrir.</p>
</div>

<?php if (!$avisosMural): ?>
    <div class="portal-card text-center text-muted py-5"><i class="bi bi-bell-slash fs-2 d-block mb-2"></i>Nenhum aviso no momento.</div>
<?php else: ?>
    <div class="list-group lista-avisos">
        <?php foreach ($avisosMural as $a): $falta = $a['exige_confirmacao'] && !$a['confirmado_em']; ?>
            <button type="button" class="list-group-item list-group-item-action d-flex align-items-center gap-3 <?= avisoPendente($a) ? 'pendente' : '' ?>" id="item-aviso-<?= (int) $a['id'] ?>" onclick="abrirAvisoMural(<?= (int) $a['id'] ?>)">
                <span class="bolinha-aviso" style="background: <?= $cores[$a['cor']][1] ?>"></span>
                <span class="flex-grow-1 text-start">
                    <span class="titulo-aviso"><?= e($a['titulo']) ?></span>
                    <?php if (!$a['visto_em']): ?><span class="badge text-bg-danger ms-1 selo-novo">Novo</span><?php endif; ?>
                    <?php if ($falta): ?><span class="badge text-bg-warning ms-1">Falta confirmar</span><?php elseif ($a['confirmado_em']): ?><span class="small text-success ms-1"><i class="bi bi-check2-all"></i> confirmado</span><?php endif; ?>
                </span>
                <span class="small text-muted text-nowrap"><?= formatDate($a['inicio']) ?></span>
                <i class="bi bi-chevron-right text-muted"></i>
            </button>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<script>
function abrirAvisoMural(id) {
    const item = document.getElementById('item-aviso-' + id);
    // Era novo (e não falta confirmar): o sininho diminui na hora.
    if (item?.querySelector('.selo-novo') && !item.querySelector('.text-bg-warning')) {
        document.querySelectorAll('#portal-nav .badge.text-bg-danger').forEach((b) => {
            const n = parseInt(b.textContent, 10) - 1;
            if (n > 0) b.textContent = n; else b.remove();
        });
        item.classList.remove('pendente');
    }
    item?.querySelector('.selo-novo')?.remove();
    avisoLido(id, false);
    bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-aviso-' + id)).show();
}
<?php if ($abrir): ?>document.addEventListener('DOMContentLoaded', () => document.getElementById('modal-aviso-<?= $abrir ?>') && abrirAvisoMural(<?= $abrir ?>));<?php endif; ?>
</script>

<?php require __DIR__ . '/../../includes/portal_footer.php'; ?>
