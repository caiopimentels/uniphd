<?php
// Filtro de situação com caixas de marcar (várias de uma vez), dentro do
// <form method="get"> da listagem. Quem inclui define $opcoesStatus
// (chave => rótulo), $statusMarcados (chaves) e, se quiser, $statusDicas
// (chave => texto do title) e $statusDivisoriaApos (chave).
$statusDicas = $statusDicas ?? [];
$statusDivisoriaApos = $statusDivisoriaApos ?? null;
$rotuloStatusFiltro = $statusMarcados ? implode(' + ', array_map(fn ($s) => $opcoesStatus[$s], $statusMarcados)) : 'Todas';
$textoBotaoStatus = count($statusMarcados) > 2 ? count($statusMarcados) . ' situações' : $rotuloStatusFiltro;
?>
<div class="dropdown filtro-status">
    <button type="button" class="form-select filtro-controle text-start text-truncate" style="width:190px" data-bs-toggle="dropdown" data-bs-auto-close="outside" title="<?= e($rotuloStatusFiltro) ?>">
        <?= e($textoBotaoStatus) ?>
    </button>
    <div class="dropdown-menu p-2" style="min-width: 220px">
        <?php foreach ($opcoesStatus as $chave => $rotulo): ?>
            <label class="dropdown-item d-flex align-items-center gap-2 rounded" style="cursor: pointer"<?= isset($statusDicas[$chave]) ? ' title="' . e($statusDicas[$chave]) . '"' : '' ?>>
                <input type="checkbox" class="form-check-input m-0" name="status[]" value="<?= e($chave) ?>" <?= in_array($chave, $statusMarcados, true) ? 'checked' : '' ?>> <?= e($rotulo) ?>
            </label>
            <?php if ($chave === $statusDivisoriaApos): ?><div class="dropdown-divider my-1"></div><?php endif; ?>
        <?php endforeach; ?>
        <div class="small text-muted px-2 pt-1">Sem nenhuma marcada, mostra todas.</div>
        <div class="d-flex gap-2 px-2 pt-2">
            <button type="button" class="btn btn-sm btn-outline-secondary flex-grow-1" onclick="this.closest('.dropdown-menu').querySelectorAll('input').forEach((c) => c.checked = false)">Limpar</button>
            <button type="submit" class="btn btn-sm btn-brand flex-grow-1">Aplicar</button>
        </div>
    </div>
</div>
