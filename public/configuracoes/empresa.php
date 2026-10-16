<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirAdmin();

$pdo = getDB();
$config = buscarConfiguracoes($pdo);

$campos = [
    'empresa_razao_social' => 'Razão social',
    'empresa_nome_fantasia' => 'Nome fantasia',
    'empresa_cnpj' => 'CNPJ',
    'empresa_inscricao_municipal' => 'Inscrição municipal',
    'empresa_email' => 'E-mail',
    'empresa_telefone' => 'Telefone',
    'empresa_cep' => 'CEP',
    'empresa_logradouro' => 'Logradouro',
    'empresa_numero' => 'Número',
    'empresa_complemento' => 'Complemento',
    'empresa_bairro' => 'Bairro',
    'empresa_cidade' => 'Cidade',
    'empresa_uf' => 'UF',
];

$erros = [];
$dados = [];
foreach ($campos as $campo => $rotulo) {
    $dados[$campo] = $config[$campo] ?? '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($campos as $campo => $rotulo) {
        $dados[$campo] = input($campo);
    }
    $dados['empresa_uf'] = strtoupper($dados['empresa_uf']);

    if ($dados['empresa_cnpj'] !== '' && !validarCnpj($dados['empresa_cnpj'])) {
        $erros[] = 'O CNPJ informado é inválido.';
    }
    if ($dados['empresa_uf'] !== '' && !preg_match('/^[A-Z]{2}$/', $dados['empresa_uf'])) {
        $erros[] = 'A UF deve ter 2 letras.';
    }
    if ($dados['empresa_cep'] !== '' && strlen(apenasDigitos($dados['empresa_cep'])) !== 8) {
        $erros[] = 'O CEP deve ter 8 dígitos.';
    }

    if (!$erros) {
        $sets = implode(', ', array_map(fn ($c) => "{$c} = ?", array_keys($campos)));
        $stmt = $pdo->prepare("UPDATE configuracoes SET {$sets} WHERE id = 1");
        $stmt->execute(array_map(fn ($v) => $v !== '' ? $v : null, array_values($dados)));

        $antes = [];
        foreach ($campos as $campo => $rotulo) {
            $antes[$campo] = $config[$campo] ?? '';
        }
        registrarAtividade('configuracoes', 'edicao', 'Dados da empresa: ' . (descreverAlteracoes($antes, $dados, $campos) ?: 'sem alterações'));

        flash('success', 'Dados da empresa salvos.');
        redirect('/configuracoes/empresa.php');
    }
}

$pageTitle = 'Configurações';
$pageBreadcrumb = 'Administração';
$activeMenu = 'configuracoes';
$pageAction = '<button type="submit" form="form-empresa" class="btn btn-brand"><i class="bi bi-check-lg"></i> Salvar</button>';
require __DIR__ . '/../../includes/layout_header.php';
$abaAtiva = 'empresa';
require __DIR__ . '/../../includes/abas_configuracoes.php';
?>

<?php if ($erros): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($erros as $erro): ?><li><?= e($erro) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<p class="text-muted">Dados da instituição. São usados como <strong>beneficiário</strong> nas cobranças bancárias (boleto e Pix) e precisam bater com o cadastro da empresa no banco.</p>

<form method="post" id="form-empresa">
<div class="secao" style="max-width: 900px;">
    <div class="secao-titulo"><i class="bi bi-building"></i> Identificação</div>
    <div class="row g-3">
        <div class="col-md-8">
            <label class="form-label">Razão social</label>
            <input type="text" name="empresa_razao_social" class="form-control" maxlength="150" value="<?= e($dados['empresa_razao_social']) ?>">
        </div>
        <div class="col-md-4">
            <label class="form-label">CNPJ</label>
            <input type="text" name="empresa_cnpj" class="form-control" value="<?= e($dados['empresa_cnpj']) ?>"
                   onblur="if (this.value.trim()) validarCampoDocumento(this)" oninput="this.classList.remove('is-invalid')">
            <div class="invalid-feedback">CNPJ inválido.</div>
        </div>
        <div class="col-md-8">
            <label class="form-label">Nome fantasia</label>
            <input type="text" name="empresa_nome_fantasia" class="form-control" maxlength="150" value="<?= e($dados['empresa_nome_fantasia']) ?>">
        </div>
        <div class="col-md-4">
            <label class="form-label">Inscrição municipal</label>
            <input type="text" name="empresa_inscricao_municipal" class="form-control" maxlength="30" value="<?= e($dados['empresa_inscricao_municipal']) ?>">
        </div>
        <div class="col-md-6">
            <label class="form-label">E-mail</label>
            <input type="email" name="empresa_email" class="form-control" value="<?= e($dados['empresa_email']) ?>">
        </div>
        <div class="col-md-6">
            <label class="form-label">Telefone</label>
            <input type="text" name="empresa_telefone" class="form-control" value="<?= e($dados['empresa_telefone']) ?>">
        </div>
    </div>
</div>

<div class="secao" style="max-width: 900px;">
    <div class="secao-titulo"><i class="bi bi-geo-alt"></i> Endereço</div>
    <div class="row g-3">
        <div class="col-md-3">
            <label class="form-label">CEP</label>
            <input type="text" name="empresa_cep" class="form-control" value="<?= e($dados['empresa_cep']) ?>">
        </div>
        <div class="col-md-7">
            <label class="form-label">Logradouro</label>
            <input type="text" name="empresa_logradouro" class="form-control" value="<?= e($dados['empresa_logradouro']) ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label">Número</label>
            <input type="text" name="empresa_numero" class="form-control" value="<?= e($dados['empresa_numero']) ?>">
        </div>
        <div class="col-md-4">
            <label class="form-label">Complemento</label>
            <input type="text" name="empresa_complemento" class="form-control" value="<?= e($dados['empresa_complemento']) ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label">Bairro</label>
            <input type="text" name="empresa_bairro" class="form-control" value="<?= e($dados['empresa_bairro']) ?>">
        </div>
        <div class="col-md-4">
            <label class="form-label">Cidade</label>
            <input type="text" name="empresa_cidade" class="form-control" value="<?= e($dados['empresa_cidade']) ?>">
        </div>
        <div class="col-md-1">
            <label class="form-label">UF</label>
            <input type="text" name="empresa_uf" class="form-control text-uppercase" maxlength="2" value="<?= e($dados['empresa_uf']) ?>">
        </div>
    </div>
</div>
</form>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
