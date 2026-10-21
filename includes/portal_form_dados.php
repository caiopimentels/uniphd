<?php
// Campos de dados pessoais + endereço, usados no cadastro e em "Meus dados".
// Espera $d (valores) e $cpfEditavel (bool).
?>
<div class="row g-3">
    <div class="col-md-8">
        <label class="form-label">Nome completo *</label>
        <input type="text" name="nome" class="form-control" required maxlength="150" autocomplete="name" value="<?= e($d['nome'] ?? '') ?>">
    </div>
    <div class="col-md-4">
        <label class="form-label">CPF *</label>
        <input type="text" name="cpf" class="form-control" inputmode="numeric" data-mascara="cpf" <?= $cpfEditavel ? 'required' : 'disabled' ?> value="<?= e($d['cpf'] ?? '') ?>">
    </div>
    <div class="col-md-4">
        <label class="form-label">Data de nascimento</label>
        <input type="date" name="data_nascimento" class="form-control" value="<?= e($d['data_nascimento'] ?? '') ?>">
    </div>
    <div class="col-md-4">
        <label class="form-label">Celular / WhatsApp *</label>
        <input type="tel" name="telefone" class="form-control" required data-mascara="telefone" autocomplete="tel" value="<?= e($d['telefone'] ?? '') ?>">
    </div>
    <div class="col-md-4">
        <label class="form-label">E-mail *</label>
        <input type="email" name="email" class="form-control" required maxlength="150" autocomplete="email" value="<?= e($d['email'] ?? '') ?>">
    </div>

    <div class="col-12"><div class="fw-semibold mt-2">Para o certificado</div></div>
    <div class="col-12">
        <?php
        $dc = $d;
        $tiposGraduacaoCampo = tiposGraduacao(getDB(), true, (int) ($d['graduacao_id'] ?? 0) ?: null);
        $obrigatorioCertificacao = true;
        require __DIR__ . '/campos_certificacao_aluno.php';
        ?>
    </div>

    <div class="col-12"><div class="fw-semibold mt-2">Endereço <span class="text-muted fw-normal small"></span></div></div>
    <div class="col-md-3">
        <label class="form-label">CEP *</label>
        <input type="text" name="cep" class="form-control" required inputmode="numeric" data-mascara="cep" autocomplete="postal-code" value="<?= e($d['cep'] ?? '') ?>">
    </div>
    <div class="col-md-7">
        <label class="form-label">Rua / Avenida *</label>
        <input type="text" name="logradouro" class="form-control" required maxlength="150" value="<?= e($d['logradouro'] ?? '') ?>">
    </div>
    <div class="col-md-2">
        <label class="form-label">Número *</label>
        <input type="text" name="numero" class="form-control" required maxlength="20" value="<?= e($d['numero'] ?? '') ?>">
    </div>
    <div class="col-md-4">
        <label class="form-label">Complemento</label>
        <input type="text" name="complemento" class="form-control" maxlength="100" value="<?= e($d['complemento'] ?? '') ?>">
    </div>
    <div class="col-md-3">
        <label class="form-label">Bairro *</label>
        <input type="text" name="bairro" class="form-control" required maxlength="100" value="<?= e($d['bairro'] ?? '') ?>">
    </div>
    <div class="col-md-3">
        <label class="form-label">Cidade *</label>
        <input type="text" name="cidade" class="form-control" required maxlength="100" value="<?= e($d['cidade'] ?? '') ?>">
    </div>
    <div class="col-md-2">
        <label class="form-label">UF *</label>
        <input type="text" name="uf" class="form-control text-uppercase" required maxlength="2" value="<?= e($d['uf'] ?? '') ?>">
    </div>
</div>
