<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirAdmin();

$pdo = getDB();
$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
$conta = null;
$maiorNossoNumeroUsado = 0;
$formasVinculadas = [];

if ($id) {
    $stmt = $pdo->prepare('SELECT * FROM contas_bancarias WHERE id = ?');
    $stmt->execute([$id]);
    $conta = $stmt->fetch();
    if (!$conta) {
        flash('danger', 'Conta bancária não encontrada.');
        redirect('/contas_bancarias/listar.php');
    }

    $stmt = $pdo->prepare('SELECT COALESCE(MAX(nosso_numero), 0) FROM cobrancas_bancarias WHERE conta_bancaria_id = ?');
    $stmt->execute([$id]);
    $maiorNossoNumeroUsado = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare('SELECT id, nome, instrumento FROM formas_pagamento WHERE conta_bancaria_id = ? ORDER BY nome');
    $stmt->execute([$id]);
    $formasVinculadas = $stmt->fetchAll();
}

$bancos = bancosConhecidos();
$erros = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dados = [
        'apelido' => input('apelido'),
        'banco_codigo' => apenasDigitos(input('banco_codigo')),
        'agencia' => input('agencia'),
        'conta' => input('conta'),
        'conta_digito' => input('conta_digito'),
        'chave_pix' => input('chave_pix'),
        'integracao' => input('integracao') === 'itau' ? 'itau' : 'nenhuma',
        'itau_carteira' => apenasDigitos(input('itau_carteira', '109')) ?: '109',
        'itau_especie' => array_key_exists(input('itau_especie'), especiesItau()) ? input('itau_especie') : '04',
        'proximo_nosso_numero' => (int) ($_POST['proximo_nosso_numero'] ?? 1),
        'juros_tipo' => array_key_exists(input('juros_tipo'), tiposJurosItau()) ? input('juros_tipo') : '05',
        'juros_percentual' => decimalInput('juros_percentual', 0),
        'multa_tipo' => array_key_exists(input('multa_tipo'), tiposMultaItau()) ? input('multa_tipo') : '03',
        'multa_percentual' => decimalInput('multa_percentual', 0),
        'tarifa_emissao' => max(0, decimalInput('tarifa_emissao', 0)),
        'tarifa_liquidacao' => max(0, decimalInput('tarifa_liquidacao', 0)),
        'tarifa_baixa' => max(0, decimalInput('tarifa_baixa', 0)),
        'tarifa_alteracao' => max(0, decimalInput('tarifa_alteracao', 0)),
        'api_ambiente' => input('api_ambiente') === 'producao' ? 'producao' : 'sandbox',
        'api_client_id' => input('api_client_id'),
        'api_url_token' => input('api_url_token'),
        'api_url_base' => input('api_url_base'),
        'padrao' => isset($_POST['padrao']) ? 1 : 0,
        'ativo' => isset($_POST['ativo']) ? 1 : 0,
    ];
    // Segredo em branco = mantém o que já está salvo (ele nunca volta para
    // a tela, então não dá para "reenviar" o valor atual).
    $novoClientSecret = (string) ($_POST['api_client_secret'] ?? '');
    $novaSenhaChave = (string) ($_POST['api_chave_senha'] ?? '');
    $enviouCertificado = ($_FILES['api_certificado']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;
    $enviouChave = ($_FILES['api_chave']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;
    $dados['banco_nome'] = $bancos[$dados['banco_codigo']] ?? input('banco_nome');

    if ($dados['apelido'] === '') {
        $erros[] = 'Dê um nome para identificar a conta (ex.: "Itaú principal").';
    }
    if (strlen($dados['banco_codigo']) !== 3) {
        $erros[] = 'O código do banco tem 3 dígitos (ex.: 341 para Itaú).';
    }
    if ($dados['banco_nome'] === '') {
        $erros[] = 'Informe o nome do banco.';
    }
    if ($dados['agencia'] === '' || $dados['conta'] === '') {
        $erros[] = 'Agência e conta são obrigatórias.';
    }

    if ($dados['integracao'] === 'itau') {
        if ($dados['banco_codigo'] !== '341') {
            $erros[] = 'A integração Itaú só vale para conta do banco 341 (Itaú).';
        }
        if (!idBeneficiarioItau($dados)) {
            $erros[] = 'Para a integração Itaú: agência com até 4 dígitos, conta com até 7 dígitos e o dígito (DAC) com 1 dígito.';
        }
        // Voltar a sequência para trás reaproveitaria um nosso número já
        // registrado, e o banco recusa título repetido na carteira.
        if ($dados['proximo_nosso_numero'] <= $maiorNossoNumeroUsado) {
            $erros[] = 'O próximo nosso número precisa ser maior que o último já usado nesta conta (' . formatarNossoNumeroItau($maiorNossoNumeroUsado) . ').';
        }
        if ($dados['proximo_nosso_numero'] < 1 || $dados['proximo_nosso_numero'] > 99999999) {
            $erros[] = 'Na carteira 109 o nosso número vai de 1 a 99999999.';
        }
        if ($dados['juros_tipo'] !== '05' && $dados['juros_percentual'] <= 0) {
            $erros[] = 'Informe o percentual de juros (ou escolha "Isento").';
        }
        if ($dados['multa_tipo'] !== '03' && $dados['multa_percentual'] <= 0) {
            $erros[] = 'Informe o percentual de multa (ou escolha "Isento").';
        }
    }
    foreach (['api_url_token' => 'URL do token', 'api_url_base' => 'URL base da API'] as $campo => $rotulo) {
        if ($dados[$campo] !== '' && !preg_match('#^https://#i', $dados[$campo])) {
            $erros[] = "A {$rotulo} precisa começar com https://.";
        }
    }
    if ($enviouCertificado !== $enviouChave) {
        $erros[] = 'Envie o certificado e a chave juntos (os dois arquivos do par).';
    }

    if ($dados['padrao'] && !$dados['ativo']) {
        $erros[] = 'Uma conta inativa não pode ser a padrão.';
    }

    if (!$erros) {
        $colunas = array_keys($dados);
        $valores = array_map(fn ($v) => $v === '' ? null : $v, array_values($dados));

        $pdo->beginTransaction();
        if ($dados['padrao']) {
            $pdo->exec('UPDATE contas_bancarias SET padrao = 0');
        }
        if ($id) {
            $sets = implode(', ', array_map(fn ($c) => "{$c} = ?", $colunas));
            $stmt = $pdo->prepare("UPDATE contas_bancarias SET {$sets} WHERE id = ?");
            $stmt->execute([...$valores, $id]);
            $operacao = 'edicao';
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO contas_bancarias (' . implode(', ', $colunas) . ') VALUES (' . implode(',', array_fill(0, count($colunas), '?')) . ')'
            );
            $stmt->execute($valores);
            $id = (int) $pdo->lastInsertId();
            $operacao = 'criacao';
        }

        if ($enviouCertificado && $enviouChave) {
            $senhaParaAbrir = $novaSenhaChave !== '' ? $novaSenhaChave : (descriptografar($conta['api_chave_senha_cripto'] ?? null) ?? '');
            $certificado = salvarCertificadoApi($id, $_FILES['api_certificado']['tmp_name'], $_FILES['api_chave']['tmp_name'], $senhaParaAbrir);
            if (!$certificado['ok']) {
                $pdo->rollBack();
                $erros[] = $certificado['mensagem'];
                // Conta nova: o INSERT foi desfeito, então o id não existe mais.
                if ($operacao === 'criacao') {
                    $id = null;
                }
            } else {
                $stmt = $pdo->prepare('UPDATE contas_bancarias SET api_certificado_arquivo = ?, api_chave_arquivo = ?, api_certificado_validade = ? WHERE id = ?');
                $stmt->execute([$certificado['certificado'], $certificado['chave'], $certificado['validade'], $id]);
            }
        }
    }

    if (!$erros) {
        if ($novoClientSecret !== '') {
            $stmt = $pdo->prepare('UPDATE contas_bancarias SET api_client_secret_cripto = ? WHERE id = ?');
            $stmt->execute([criptografar($novoClientSecret), $id]);
        }
        if ($novaSenhaChave !== '') {
            $stmt = $pdo->prepare('UPDATE contas_bancarias SET api_chave_senha_cripto = ? WHERE id = ?');
            $stmt->execute([criptografar($novaSenhaChave), $id]);
        }
        // Qualquer mudança de credencial invalida o token guardado — senão
        // o sistema continuaria usando um token da configuração antiga.
        $mudouCredencial = $novoClientSecret !== '' || $novaSenhaChave !== '' || $enviouCertificado
            || $dados['api_client_id'] !== (string) ($conta['api_client_id'] ?? '')
            || $dados['api_url_token'] !== (string) ($conta['api_url_token'] ?? '')
            || $dados['api_ambiente'] !== ($conta['api_ambiente'] ?? 'sandbox');
        if ($mudouCredencial) {
            $stmt = $pdo->prepare('UPDATE contas_bancarias SET api_token_cripto = NULL, api_token_expira_em = NULL, api_ultimo_teste_ok = NULL, api_ultimo_teste_mensagem = NULL WHERE id = ?');
            $stmt->execute([$id]);
        }

        $pdo->commit();

        registrarAtividade('configuracoes', $operacao, ($operacao === 'criacao' ? 'Conta bancária criada: ' : 'Conta bancária editada: ') . $dados['apelido']);
        flash('success', 'Conta bancária salva.');
        redirect('/contas_bancarias/form.php?id=' . $id);
    }

    $conta = $dados + ($conta ?? []);
}

$conta = $conta ?? [];
$valor = fn (string $campo, $padrao = '') => $conta[$campo] ?? $padrao;

$pageTitle = 'Configurações';
$pageBreadcrumb = 'Administração · Contas bancárias';
$activeMenu = 'configuracoes';
$pageAction = '<a href="/contas_bancarias/listar.php" class="btn btn-outline-secondary">Cancelar</a>'
    . '<button type="submit" form="form-conta" class="btn btn-brand"><i class="bi bi-check-lg"></i> Salvar</button>';
require __DIR__ . '/../../includes/layout_header.php';
$abaAtiva = 'contas';
require __DIR__ . '/../../includes/abas_configuracoes.php';
if ($id) {
    $contaAbaId = $id;
    $contaAba = 'dados';
    require __DIR__ . '/../../includes/abas_conta_bancaria.php';
}
?>

<?php if ($erros): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($erros as $erro): ?><li><?= e($erro) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" id="form-conta" enctype="multipart/form-data">
<div class="secao" style="max-width: 900px;">
    <div class="secao-titulo"><i class="bi bi-bank"></i> Dados da conta</div>
    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label">Nome da conta *</label>
            <input type="text" name="apelido" class="form-control" required maxlength="60" placeholder="Ex.: Itaú principal" value="<?= e($valor('apelido')) ?>">
        </div>
        <div class="col-md-6">
            <label class="form-label">Banco *</label>
            <select name="banco_codigo" id="banco_codigo" class="form-select" onchange="atualizarIntegracao()">
                <?php foreach ($bancos as $codigo => $nome): ?>
                    <option value="<?= e($codigo) ?>" <?= $valor('banco_codigo', '341') === (string) $codigo ? 'selected' : '' ?>><?= e($codigo) ?> · <?= e($nome) ?></option>
                <?php endforeach; ?>
                <?php if ($valor('banco_codigo') && !isset($bancos[$valor('banco_codigo')])): ?>
                    <option value="<?= e($valor('banco_codigo')) ?>" selected><?= e($valor('banco_codigo')) ?> · <?= e($valor('banco_nome')) ?></option>
                <?php endif; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label">Agência *</label>
            <input type="text" name="agencia" id="agencia" class="form-control" required value="<?= e($valor('agencia')) ?>" oninput="atualizarBeneficiario()">
        </div>
        <div class="col-md-3">
            <label class="form-label">Conta *</label>
            <input type="text" name="conta" id="conta" class="form-control" required value="<?= e($valor('conta')) ?>" oninput="atualizarBeneficiario()">
        </div>
        <div class="col-md-2">
            <label class="form-label">Dígito</label>
            <input type="text" name="conta_digito" id="conta_digito" class="form-control" maxlength="2" value="<?= e($valor('conta_digito')) ?>" oninput="atualizarBeneficiario()">
        </div>
        <div class="col-md-4">
            <label class="form-label">Chave Pix</label>
            <input type="text" name="chave_pix" class="form-control" maxlength="77" placeholder="CNPJ, e-mail, telefone ou aleatória" value="<?= e($valor('chave_pix')) ?>">
        </div>
        <div class="col-12 form-check ps-4">
            <input type="checkbox" name="padrao" class="form-check-input" id="padrao" <?= $valor('padrao', 0) ? 'checked' : '' ?>>
            <label class="form-check-label" for="padrao">Conta padrão (usada quando a forma de pagamento da parcela não indica outra)</label>
        </div>
        <div class="col-12 form-check ps-4">
            <input type="checkbox" name="ativo" class="form-check-input" id="ativo" <?= $valor('ativo', 1) ? 'checked' : '' ?>>
            <label class="form-check-label" for="ativo">Ativa</label>
        </div>
    </div>
</div>

<div class="secao" style="max-width: 900px;">
    <div class="secao-titulo"><i class="bi bi-plug"></i> Integração com o banco</div>
    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label">Integração</label>
            <select name="integracao" id="integracao" class="form-select" onchange="atualizarIntegracao()">
                <option value="nenhuma" <?= $valor('integracao', 'nenhuma') === 'nenhuma' ? 'selected' : '' ?>>Nenhuma (só controle manual)</option>
                <option value="itau" <?= $valor('integracao') === 'itau' ? 'selected' : '' ?>>Itaú — API de Cobrança (boleto / boleto + Pix)</option>
            </select>
        </div>
        <div class="col-md-6 bloco-itau">
            <label class="form-label">Código do beneficiário (id_beneficiario)</label>
            <input type="text" id="id_beneficiario" class="form-control" disabled>
            <div class="form-text">Agência (4) + conta (7) + dígito (1). Confira com o que o Itaú informou.</div>
        </div>

        <div class="col-md-3 bloco-itau">
            <label class="form-label">Carteira</label>
            <input type="text" name="itau_carteira" class="form-control" maxlength="3" value="<?= e($valor('itau_carteira', '109')) ?>">
        </div>
        <div class="col-md-5 bloco-itau">
            <label class="form-label">Espécie do título</label>
            <select name="itau_especie" class="form-select">
                <?php foreach (especiesItau() as $codigo => $rotulo): ?>
                    <option value="<?= e($codigo) ?>" <?= $valor('itau_especie', '04') === (string) $codigo ? 'selected' : '' ?>><?= e($codigo) ?> - <?= e($rotulo) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4 bloco-itau">
            <label class="form-label">Próximo nosso número</label>
            <input type="number" name="proximo_nosso_numero" class="form-control" min="1" max="99999999" value="<?= (int) $valor('proximo_nosso_numero', 1) ?>">
            <div class="form-text">
                <?= $maiorNossoNumeroUsado ? 'Último usado: ' . e(formatarNossoNumeroItau($maiorNossoNumeroUsado)) . '.' : 'Nenhum usado ainda.' ?>
                Se a conta já emitia boletos por outro sistema, comece depois do último número dele.
            </div>
        </div>

        <div class="col-md-4 bloco-itau">
            <label class="form-label">Juros após o vencimento</label>
            <select name="juros_tipo" class="form-select">
                <?php foreach (tiposJurosItau() as $codigo => $rotulo): ?>
                    <option value="<?= e($codigo) ?>" <?= $valor('juros_tipo', '05') === (string) $codigo ? 'selected' : '' ?>><?= e($rotulo) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2 bloco-itau">
            <label class="form-label">Juros (%)</label>
            <input type="text" name="juros_percentual" class="form-control" value="<?= e(number_format((float) $valor('juros_percentual', 0), 2, ',', '')) ?>">
        </div>
        <div class="col-md-4 bloco-itau">
            <label class="form-label">Multa após o vencimento</label>
            <select name="multa_tipo" class="form-select">
                <?php foreach (tiposMultaItau() as $codigo => $rotulo): ?>
                    <option value="<?= e($codigo) ?>" <?= $valor('multa_tipo', '03') === (string) $codigo ? 'selected' : '' ?>><?= e($rotulo) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2 bloco-itau">
            <label class="form-label">Multa (%)</label>
            <input type="text" name="multa_percentual" class="form-control" value="<?= e(number_format((float) $valor('multa_percentual', 0), 2, ',', '')) ?>">
        </div>
        <div class="col-12 bloco-itau">
            <div class="form-text">Comum em mensalidade: multa de 2% e juros de 1% ao mês. Valem a partir do dia seguinte ao vencimento.</div>
        </div>
    </div>
</div>

<div class="secao bloco-itau" style="max-width: 900px;">
    <div class="secao-titulo"><i class="bi bi-receipt"></i> Tarifas do banco</div>
    <p class="text-muted small mb-3">
        Quanto o banco cobra de você em cada operação (veja no seu contrato ou pergunte ao gerente). O sistema anota cada tarifa quando a
        operação acontece, para você ver o total na aba <strong>Tarifas</strong> e lançar como despesa. Deixe <strong>0</strong> no que o banco não cobra —
        por exemplo, se a <strong>alteração</strong> de valor ou vencimento não paga tarifa.
    </p>
    <div class="row g-3">
        <?php foreach (['tarifa_emissao' => 'Emissão (boleto gerado)', 'tarifa_liquidacao' => 'Liquidação (boleto pago)', 'tarifa_baixa' => 'Baixa (boleto cancelado)', 'tarifa_alteracao' => 'Alteração (por instrução)'] as $campo => $rotulo): ?>
            <div class="col-md-3">
                <label class="form-label"><?= e($rotulo) ?></label>
                <div class="input-group">
                    <span class="input-group-text">R$</span>
                    <input type="text" name="<?= $campo ?>" class="form-control" value="<?= e(number_format((float) $valor($campo, 0), 2, ',', '')) ?>">
                </div>
            </div>
        <?php endforeach; ?>
        <div class="col-12"><div class="form-text">Alteração: cada mudança enviada ao banco (valor e vencimento contam separado) é uma instrução.</div></div>
    </div>
</div>

<div class="secao bloco-itau" style="max-width: 900px;">
    <div class="secao-titulo">
        <i class="bi bi-shield-lock"></i> API Itaú (OAuth 2.0)
        <?php if ($id && ($conta['integracao'] ?? '') === 'itau'): ?>
            <a href="/contas_bancarias/webhook.php?id=<?= (int) $id ?>" class="btn btn-sm btn-outline-secondary ms-auto"><i class="bi bi-broadcast"></i> Webhook de pagamento</a>
            <button type="submit" form="form-testar-api" class="btn btn-sm btn-outline-secondary"><i class="bi bi-plug"></i> Testar conexão</button>
        <?php endif; ?>
    </div>

    <?php if ($id && ($conta['api_ultimo_teste_em'] ?? null) && $conta['api_ultimo_teste_ok'] !== null): ?>
        <div class="alert alert-<?= $conta['api_ultimo_teste_ok'] ? 'success' : 'danger' ?> py-2">
            <strong>Último teste (<?= formatDateTime($conta['api_ultimo_teste_em']) ?>):</strong> <?= e($conta['api_ultimo_teste_mensagem']) ?>
        </div>
    <?php endif; ?>

    <div class="row g-3">
        <div class="col-md-4">
            <label class="form-label">Ambiente</label>
            <select name="api_ambiente" class="form-select">
                <option value="sandbox" <?= $valor('api_ambiente', 'sandbox') === 'sandbox' ? 'selected' : '' ?>>Sandbox (testes)</option>
                <option value="producao" <?= $valor('api_ambiente') === 'producao' ? 'selected' : '' ?>>Produção</option>
            </select>
        </div>
        <div class="col-md-8">
            <label class="form-label">Client ID</label>
            <input type="text" name="api_client_id" class="form-control" maxlength="100" autocomplete="off" value="<?= e($valor('api_client_id')) ?>">
        </div>
        <div class="col-md-12">
            <label class="form-label">Client Secret</label>
            <input type="password" name="api_client_secret" class="form-control" autocomplete="new-password"
                   placeholder="<?= $valor('api_client_secret_cripto') ? '•••••••• salvo — deixe em branco para manter' : 'Cole aqui o client_secret' ?>">
            <div class="form-text">Guardado criptografado; nunca é mostrado de volta.</div>
        </div>
        <div class="col-md-6">
            <label class="form-label">URL do token (OAuth)</label>
            <input type="url" name="api_url_token" class="form-control" placeholder="https://..." value="<?= e($valor('api_url_token')) ?>">
        </div>
        <div class="col-md-6">
            <label class="form-label">URL base da API</label>
            <input type="url" name="api_url_base" class="form-control" placeholder="https://..." value="<?= e($valor('api_url_base')) ?>">
        </div>
        <div class="col-12"><div class="form-text mt-0">
            Copie as URLs da documentação do devportal do Itaú — mudam entre sandbox e produção.
            Produção: API de Cobrança v2 <code>https://api.itau.com.br/cash_management/v2</code> (registra em <code>/boletos</code>)
            ou Boleto com Pix <code>https://pix-pj.api.itau.com/recebimentos-pix/v1</code> (em <code><?= e(ITAU_ENDPOINT_BOLETO_PIX) ?></code>) —
            use a que o Itaú liberou para a sua aplicação (o "Testar conexão" mostra as permissões).
        </div></div>

        <div class="col-md-4">
            <label class="form-label">Certificado (.crt / .pem) <span class="text-muted small">— obrigatório em produção</span></label>
            <input type="file" name="api_certificado" class="form-control" accept=".crt,.pem,.cer">
        </div>
        <div class="col-md-4">
            <label class="form-label">Chave privada (.key)</label>
            <input type="file" name="api_chave" class="form-control" accept=".key,.pem">
        </div>
        <div class="col-md-4">
            <label class="form-label">Senha da chave</label>
            <input type="password" name="api_chave_senha" class="form-control" autocomplete="new-password"
                   placeholder="<?= $valor('api_chave_senha_cripto') ? '•••••••• salva' : 'Se a chave tiver senha' ?>">
        </div>
        <div class="col-12">
            <?php if ($valor('api_certificado_arquivo') && (!is_file($valor('api_certificado_arquivo')) || !is_file((string) $valor('api_chave_arquivo')))): ?>
                <span class="badge text-bg-danger"><i class="bi bi-exclamation-triangle"></i> Arquivos do certificado não encontrados no servidor</span>
                <span class="small text-muted">Confira a permissão da pasta <code>storage/</code> e envie os dois arquivos de novo.</span>
            <?php elseif ($valor('api_certificado_arquivo')): ?>
                <span class="badge text-bg-success"><i class="bi bi-patch-check"></i> Certificado instalado</span>
                <?php if ($valor('api_certificado_validade')): ?>
                    <span class="small <?= $valor('api_certificado_validade') <= date('Y-m-d', strtotime('+30 days')) ? 'text-danger fw-semibold' : 'text-muted' ?>">
                        válido até <?= formatDate($valor('api_certificado_validade')) ?>
                    </span>
                <?php endif; ?>
                <span class="small text-muted">· envie os dois arquivos de novo para trocar.</span>
                <?php if ($valor('api_certificado_renovacao_erro')): ?>
                    <div class="small text-danger mt-1"><i class="bi bi-exclamation-triangle"></i> Renovação automática falhou<?= $valor('api_certificado_renovacao_em') ? ' em ' . formatDateTime($valor('api_certificado_renovacao_em')) : '' ?>: <?= e($valor('api_certificado_renovacao_erro')) ?></div>
                <?php endif; ?>
            <?php else: ?>
                <span class="small text-muted">Nenhum certificado instalado. Os arquivos ficam guardados fora da pasta pública do site.</span>
            <?php endif; ?>
            <?php if ($id && ($valor('api_ambiente') === 'producao')): ?>
                <div class="mt-2" id="certificado">
                    <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modal-gerar-certificado"><i class="bi bi-arrow-repeat"></i> Gerar certificado novo no Itaú</button>
                    <span class="small text-muted ms-1">O certificado vale 1 ano; a partir de 30 dias antes o sistema tenta renovar sozinho todo dia.</span>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($id): ?>
<div class="secao" style="max-width: 900px;">
    <div class="secao-titulo"><i class="bi bi-credit-card"></i> Formas de pagamento que caem nesta conta</div>
    <?php if (!$formasVinculadas): ?>
        <p class="text-muted mb-0">Nenhuma. Vincule em <a href="/formas_pagamento/listar.php">Formas de pagamento</a> (ex.: Boleto e Pix → esta conta).</p>
    <?php else: ?>
        <ul class="mb-0">
            <?php foreach ($formasVinculadas as $f): ?>
                <li><a href="/formas_pagamento/form.php?id=<?= (int) $f['id'] ?>"><?= e($f['nome']) ?></a> <span class="text-muted small">· <?= e(instrumentosCobranca()[$f['instrumento']] ?? $f['instrumento']) ?></span></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
<?php endif; ?>
</form>

<?php if ($id): ?>
<form method="post" action="/contas_bancarias/testar_api.php" id="form-testar-api">
    <input type="hidden" name="id" value="<?= (int) $id ?>">
</form>
<?php endif; ?>

<script>
// Mesma regra de idBeneficiarioItau() no PHP.
function atualizarBeneficiario() {
    const agencia = document.getElementById('agencia').value.replace(/\D/g, '');
    const conta = document.getElementById('conta').value.replace(/\D/g, '');
    const digito = document.getElementById('conta_digito').value.replace(/\D/g, '');
    const saida = document.getElementById('id_beneficiario');
    if (!agencia || !conta || digito.length !== 1 || agencia.length > 4 || conta.length > 7) {
        saida.value = 'Preencha agência, conta e dígito';
        return;
    }
    saida.value = agencia.padStart(4, '0') + conta.padStart(7, '0') + digito;
}

function atualizarIntegracao() {
    const itau = document.getElementById('integracao').value === 'itau';
    document.querySelectorAll('.bloco-itau').forEach((el) => { el.style.display = itau ? '' : 'none'; });
}

atualizarBeneficiario();
atualizarIntegracao();
</script>

<?php if ($id && ($valor('api_ambiente') === 'producao')): ?>
<div class="modal fade" id="modal-gerar-certificado" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" method="post" action="/contas_bancarias/renovar_certificado.php" onsubmit="this.querySelectorAll('button[type=submit]').forEach((b) => { b.disabled = true; }); this.querySelector('.gerando').classList.remove('d-none');">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-arrow-repeat"></i> Gerar certificado novo no Itaú</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="id" value="<?= (int) $id ?>">
                <input type="hidden" name="modo" value="automatico" id="modo-certificado">
                <p class="small">O sistema gera a chave e o pedido (CSR), envia ao Itaú, instala o certificado novo e atualiza o <strong>client_secret</strong> que vem junto. Os arquivos antigos ficam guardados.</p>
                <p class="small mb-2"><strong>Renovar com o access token:</strong> usa a credencial e o certificado atuais (o certificado precisa ainda estar válido). É o que o sistema tenta sozinho a partir de 30 dias antes.</p>
                <details class="small">
                    <summary>Usar o token temporário do Itaú (certificado já vencido ou o Itaú recusou)</summary>
                    <textarea name="token_temporario" class="form-control font-monospace small mt-2" rows="4" placeholder="Cole aqui o token que a implantação do Itaú enviou por e-mail (vale 7 dias)"></textarea>
                    <button type="submit" class="btn btn-sm btn-outline-secondary mt-2" onclick="document.getElementById('modo-certificado').value = 'token';"><i class="bi bi-key"></i> Gerar com o token temporário</button>
                </details>
                <div class="gerando d-none mt-3 small"><span class="spinner-border spinner-border-sm"></span> Falando com o Itaú...</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-brand" onclick="document.getElementById('modo-certificado').value = 'automatico';"><i class="bi bi-arrow-repeat"></i> Renovar com o access token</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
