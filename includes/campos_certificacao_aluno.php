<?php
// Campos para a certificação: nome da mãe e do pai e graduação. Usado no
// cadastro/“Meus dados” do portal e na ficha do aluno no sistema.
// Espera $dc (valores), $tiposGraduacaoCampo (lista) e $obrigatorioCertificacao (bool).
$possuiGraduacao = isset($dc['possui_graduacao']) && $dc['possui_graduacao'] !== null && $dc['possui_graduacao'] !== '' ? (int) $dc['possui_graduacao'] : null;
$marca = $obrigatorioCertificacao ? ' *' : '';
?>
<div class="row g-3 campos-certificacao">
    <div class="col-md-6">
        <label class="form-label">Nome da mãe<?= $marca ?></label>
        <input type="text" name="nome_mae" class="form-control" maxlength="150" <?= $obrigatorioCertificacao ? 'required' : '' ?> value="<?= e($dc['nome_mae'] ?? '') ?>">
    </div>
    <div class="col-md-6">
        <label class="form-label">Nome do pai</label>
        <input type="text" name="nome_pai" class="form-control" maxlength="150" value="<?= e($dc['nome_pai'] ?? '') ?>">
    </div>
    <div class="col-md-4">
        <label class="form-label d-block">Possui graduação?<?= $marca ?></label>
        <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" name="possui_graduacao" id="grad-sim" value="1" <?= $possuiGraduacao === 1 ? 'checked' : '' ?> <?= $obrigatorioCertificacao ? 'required' : '' ?> onchange="alternarGraduacao()">
            <label class="form-check-label" for="grad-sim">Sim</label>
        </div>
        <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" name="possui_graduacao" id="grad-nao" value="0" <?= $possuiGraduacao === 0 ? 'checked' : '' ?> onchange="alternarGraduacao()">
            <label class="form-check-label" for="grad-nao">Não</label>
        </div>
    </div>
    <div class="col-md-8 <?= $possuiGraduacao === 1 ? '' : 'd-none' ?>" id="bloco-graduacao">
        <label class="form-label">Qual graduação? *</label>
        <select name="graduacao_id" class="form-select" id="campo-graduacao">
            <option value="">Escolha...</option>
            <?php foreach ($tiposGraduacaoCampo as $tg): ?>
                <option value="<?= (int) $tg['id'] ?>" <?= (int) ($dc['graduacao_id'] ?? 0) === (int) $tg['id'] ? 'selected' : '' ?>><?= e($tg['nome']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
</div>
<script>
function alternarGraduacao() {
    const sim = document.getElementById('grad-sim').checked;
    document.getElementById('bloco-graduacao').classList.toggle('d-none', !sim);
    document.getElementById('campo-graduacao').required = sim;
}
alternarGraduacao();
</script>
