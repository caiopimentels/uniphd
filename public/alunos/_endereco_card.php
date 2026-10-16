<div class="card mb-2 endereco-card">
    <div class="card-body">
        <div class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label">Apelido</label>
                <input type="text" name="endereco_apelido[<?= $i ?>]" class="form-control form-control-sm" placeholder="Ex: Residencial, Comercial" value="<?= e($end['apelido'] ?? '') ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">CEP</label>
                <div class="input-group input-group-sm">
                    <input type="text" name="endereco_cep[<?= $i ?>]" class="form-control campo-cep" inputmode="numeric" maxlength="9" placeholder="00000-000" value="<?= e($end['cep'] ?? '') ?>">
                    <span class="input-group-text cep-status" title="Digite o CEP: o endereço é preenchido sozinho"><i class="bi bi-search"></i></span>
                </div>
            </div>
            <div class="col-md-2">
                <div class="form-check">
                    <input type="radio" name="endereco_principal" value="<?= $i ?>" class="form-check-input" id="end-principal-<?= $i ?>" <?= ($end['principal'] ?? false) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="end-principal-<?= $i ?>">Principal</label>
                </div>
            </div>
            <div class="col-md-4 text-end">
                <button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('.endereco-card').remove()"><i class="bi bi-trash"></i> Remover endereço</button>
            </div>
        </div>
        <div class="row g-2 mt-1">
            <div class="col-md-6">
                <label class="form-label">Logradouro</label>
                <input type="text" name="endereco_logradouro[<?= $i ?>]" class="form-control form-control-sm" value="<?= e($end['logradouro'] ?? '') ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Número</label>
                <input type="text" name="endereco_numero[<?= $i ?>]" class="form-control form-control-sm" value="<?= e($end['numero'] ?? '') ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Complemento</label>
                <input type="text" name="endereco_complemento[<?= $i ?>]" class="form-control form-control-sm" value="<?= e($end['complemento'] ?? '') ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Bairro</label>
                <input type="text" name="endereco_bairro[<?= $i ?>]" class="form-control form-control-sm" value="<?= e($end['bairro'] ?? '') ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Cidade</label>
                <input type="text" name="endereco_cidade[<?= $i ?>]" class="form-control form-control-sm" value="<?= e($end['cidade'] ?? '') ?>">
            </div>
            <div class="col-md-1">
                <label class="form-label">UF</label>
                <input type="text" name="endereco_uf[<?= $i ?>]" maxlength="2" class="form-control form-control-sm text-uppercase" value="<?= e($end['uf'] ?? '') ?>">
            </div>
        </div>
    </div>
</div>
