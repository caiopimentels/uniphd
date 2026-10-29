<?php
// Janela (modal) de um aviso no portal: $avisoModal (o aviso) e
// $idModal. Com arte, só a arte e o X por cima; com texto, título e texto.
// "Li e entendi" só para aluno logado em aviso que pede.
[, $corModal] = coresAviso()[$avisoModal['cor']];
$ehArteModal = $avisoModal['montagem'] === 'arte';
$pedeConfirmacao = $avisoModal['exige_confirmacao'] && alunoLogado() && !$avisoModal['confirmado_em'];
?>
<div class="modal fade modal-aviso" id="<?= e($idModal) ?>" tabindex="-1" <?= $pedeConfirmacao ? 'data-bs-backdrop="static"' : '' ?>>
    <div class="modal-dialog modal-dialog-centered <?= $ehArteModal ? '' : 'modal-dialog-scrollable' ?>" style="max-width: min(<?= larguraJanelaAviso($avisoModal) ?>px, calc(100vw - 24px));">
        <?php if ($ehArteModal): ?>
            <div class="modal-content border-0 overflow-hidden modal-aviso-arte">
                <button type="button" class="modal-aviso-fechar" data-bs-dismiss="modal" aria-label="Fechar"><i class="bi bi-x-lg"></i></button>
                <?= htmlArteAviso(arteAviso($avisoModal)) ?>
        <?php else: ?>
            <div class="modal-content" style="border-top: 5px solid <?= $corModal ?>">
                <div class="modal-header">
                    <h5 class="modal-title" style="color: <?= $corModal ?>"><i class="bi bi-megaphone"></i> <?= e($avisoModal['titulo']) ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body aviso-conteudo"><?= htmlAviso($avisoModal['conteudo']) ?></div>
        <?php endif; ?>
            <?php if ($pedeConfirmacao): ?>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Ver depois</button>
                    <button type="button" class="btn text-white" style="background: <?= $corModal ?>" onclick="avisoLido(<?= (int) $avisoModal['id'] ?>, true).then(() => bootstrap.Modal.getInstance(document.getElementById('<?= e($idModal) ?>')).hide())"><i class="bi bi-check2"></i> Li e entendi</button>
                </div>
            <?php elseif (!$ehArteModal): ?>
                <div class="modal-footer"><button type="button" class="btn btn-brand" data-bs-dismiss="modal">Ok, entendi</button></div>
            <?php endif; ?>
        </div>
    </div>
</div>
