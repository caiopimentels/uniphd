<?php
// Boleto impresso de uma parcela (via do pagador + ficha de compensação).
// Compartilhado pela tela da secretaria (/financeiro/boleto.php) e pelo
// portal do aluno. Espera $dados = dadosBoletoParcela(...) já carregado.

$parcela = $dados['parcela'];
$aluno = $dados['aluno'];
$end = $dados['endereco'];
$conta = $dados['conta'];
$empresa = $dados['empresa'];
$registrada = $dados['registrada'];

$beneficiario = $empresa['empresa_razao_social'] ?: ($empresa['nome_sistema'] ?? '');
$beneficiarioEndereco = trim(implode(', ', array_filter([
    trim(($empresa['empresa_logradouro'] ?? '') . ' ' . ($empresa['empresa_numero'] ?? '')),
    $empresa['empresa_bairro'] ?? '',
    trim(($empresa['empresa_cidade'] ?? '') . ($empresa['empresa_uf'] ? '/' . $empresa['empresa_uf'] : '')),
    $empresa['empresa_cep'] ? 'CEP ' . $empresa['empresa_cep'] : '',
])));
$pagadorEndereco = $end ? trim(implode(', ', array_filter([
    trim(($end['logradouro'] ?? '') . ' ' . ($end['numero'] ?? '') . ' ' . ($end['complemento'] ?? '')),
    $end['bairro'] ?? '',
    trim(($end['cidade'] ?? '') . ($end['uf'] ? '/' . $end['uf'] : '')),
    $end['cep'] ? 'CEP ' . $end['cep'] : '',
]))) : '';

// Instruções impressas: o que o banco aplica sozinho após o vencimento,
// conforme a configuração de juros/multa da conta.
$instrucoes = [];
if ($conta && $conta['multa_tipo'] === '02' && (float) $conta['multa_percentual'] > 0) {
    $instrucoes[] = 'Após o vencimento, multa de ' . formatPercent($conta['multa_percentual']) . '.';
}
if ($conta && $conta['juros_tipo'] !== '05' && (float) $conta['juros_percentual'] > 0) {
    $periodo = ['90' => 'ao mês', '91' => 'ao dia', '92' => 'ao ano'][$conta['juros_tipo']] ?? '';
    $instrucoes[] = 'Juros de ' . formatPercent($conta['juros_percentual']) . ' ' . $periodo . ' após o vencimento.';
}
$desconto = $dados['desconto'];
if ($desconto) {
    array_unshift($instrucoes, 'ATÉ ' . formatDate($desconto['data_limite']) . ': DESCONTO DE ' . formatMoney($desconto['desconto'])
        . ' — PAGUE ' . formatMoney($desconto['valor_com_desconto']) . '.');
}
$instrucoes[] = 'Referente a: ' . $parcela['descricao'] . '.';
$instrucoes[] = 'Pague também pelo Pix, lendo o QR Code ao lado.';

$especie = $conta ? ($conta['itau_especie'] ?? '04') : '04';
$siglaEspecie = explode(' - ', especiesItau()[$especie] ?? 'ME')[0];
$numeroDocumento = 'CR' . str_pad((string) $parcela['id'], 6, '0', STR_PAD_LEFT);
$valor = formatMoney($parcela['valor']);
$cpfPagador = $aluno['cpf'] ?? '';
?>
<!doctype html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Boleto <?= e($numeroDocumento) ?> - <?= e($aluno['nome'] ?? '') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #e9ecef; font-family: Arial, Helvetica, sans-serif; color: #000; }
        .barra { display: flex; gap: .5rem; align-items: center; justify-content: center; padding: .75rem; background: #fff; border-bottom: 1px solid #ccc; flex-wrap: wrap; }
        .barra button, .barra a { font: inherit; font-size: 14px; padding: .45rem .9rem; border-radius: 6px; border: 1px solid #999; background: #fff; color: #222; text-decoration: none; cursor: pointer; }
        .barra .principal { background: #ec7000; border-color: #ec7000; color: #fff; }
        .aviso { max-width: 190mm; margin: .75rem auto 0; padding: .6rem .9rem; border-radius: 6px; font-size: 13px; }
        .aviso.previa { background: #fff3cd; border: 1px solid #f0c36d; }
        .aviso.pendencias { background: #f8d7da; border: 1px solid #f1aeb5; }
        .aviso ul { margin: .3rem 0 0; padding-left: 1.2rem; }

        .folha { position: relative; width: 190mm; margin: .75rem auto 1.5rem; background: #fff; padding: 8mm; overflow: hidden; }
        .marca-previa { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; pointer-events: none; z-index: 5; }
        .marca-previa span { transform: rotate(-30deg); font-size: 72px; font-weight: 800; color: rgba(220, 53, 69, .16); letter-spacing: .2em; white-space: nowrap; }

        .cabecalho { display: flex; align-items: flex-end; border-bottom: 2px solid #000; padding-bottom: 2px; }
        .banco-logo { width: 34mm; font-size: 22px; font-weight: 800; color: #ec7000; }
        .banco-logo small { display: block; font-size: 9px; color: #000; font-weight: 400; }
        .banco-codigo { font-size: 20px; font-weight: 700; border-left: 2px solid #000; border-right: 2px solid #000; text-align: center; padding: 0 3mm; white-space: nowrap; }
        .linha-digitavel { flex: 1; text-align: right; font-size: 13.5px; font-weight: 700; white-space: nowrap; padding-left: 3mm; }

        table.campos { width: 100%; border-collapse: collapse; }
        table.campos td { border: 1px solid #000; padding: 1px 4px 2px; vertical-align: top; font-size: 11px; height: 9mm; }
        table.campos td .rotulo { display: block; font-size: 8px; color: #333; margin-bottom: 1px; }
        table.campos td.direita { width: 48mm; text-align: right; }
        table.campos td.destaque { background: #f2f2f2; font-weight: 700; }
        .instrucoes-area { display: flex; gap: 3mm; }
        .instrucoes-area .texto { flex: 1; font-size: 10.5px; line-height: 1.45; }
        .qr-pix { width: 30mm; text-align: center; font-size: 8px; }
        .qr-pix .qr { width: 28mm; height: 28mm; margin: 0 auto 1mm; position: relative; }
        .qr-pix .qr.borrado canvas, .qr-pix .qr.borrado img { filter: blur(3px); opacity: .6; }
        .qr-pix .qr .selo { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; font-size: 8px; font-weight: 700; color: #b02a37; background: rgba(255,255,255,.55); text-align: center; padding: 2mm; }
        .qr-pix canvas, .qr-pix img { width: 100% !important; height: 100% !important; }

        .codigo-barras-area { margin-top: 3mm; display: flex; justify-content: space-between; align-items: flex-end; }
        .codigo-barras { width: 103mm; height: 13mm; display: block; }
        .autenticacao { font-size: 9px; text-align: right; }
        .recorte { border-top: 1px dashed #000; margin: 6mm 0 4mm; position: relative; }
        .recorte span { position: absolute; right: 0; top: -9px; background: #fff; font-size: 8px; padding-left: 4px; }
        .titulo-via { font-size: 10px; font-weight: 700; text-align: right; margin: 1mm 0; }

        @media print {
            body { background: #fff; }
            .barra, .aviso { display: none !important; }
            .folha { margin: 0; padding: 0; width: auto; }
            @page { size: A4; margin: 10mm; }
        }
    </style>
</head>
<body>

<div class="barra">
    <button type="button" class="principal" onclick="window.print()"><i class="bi bi-printer"></i> Imprimir / salvar PDF</button>
    <?php if (usuarioPodeVerFinanceiro()): ?>
        <a href="/financeiro/cobranca_previa.php?id=<?= (int) $parcela['id'] ?>"><i class="bi bi-braces"></i> Dados enviados ao banco</a>
    <?php endif; ?>
    <a href="javascript:window.close()">Fechar</a>
</div>

<?php if (!$registrada): ?>
    <div class="aviso previa">
        <strong>Prévia — este boleto ainda não está registrado no Itaú.</strong>
        Linha digitável e código de barras foram calculados pelo sistema só para conferência; o banco recusa o pagamento de
        boleto não registrado. O QR Code Pix é gerado pelo banco no registro.
    </div>
<?php endif; ?>
<?php if ($dados['pendencias']): ?>
    <div class="aviso pendencias">
        <strong>Falta preencher para registrar:</strong>
        <ul><?php foreach ($dados['pendencias'] as $p): ?><li><?= e($p) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<div class="folha">
    <?php if (!$registrada): ?><div class="marca-previa"><span>PRÉVIA</span></div><?php endif; ?>

    <!-- ================= RECIBO DO PAGADOR ================= -->
    <div class="cabecalho">
        <div class="banco-logo">Itaú<small>Banco Itaú S.A.</small></div>
        <div class="banco-codigo">341-7</div>
        <div class="linha-digitavel"><?= e($dados['linha_digitavel'] ?: '—') ?></div>
    </div>
    <div class="titulo-via">Recibo do Pagador</div>
    <table class="campos">
        <tr>
            <td colspan="3"><span class="rotulo">Beneficiário</span><?= e($beneficiario) ?><?= $empresa['empresa_cnpj'] ? ' — CNPJ ' . e($empresa['empresa_cnpj']) : '' ?></td>
            <td class="direita"><span class="rotulo">Agência / Código do beneficiário</span><?= e($dados['agencia_codigo'] ?: '—') ?></td>
        </tr>
        <tr>
            <td colspan="3"><span class="rotulo">Pagador</span><?= e($aluno['nome'] ?? '—') ?><?= $cpfPagador ? ' — CPF ' . e($cpfPagador) : '' ?></td>
            <td class="direita destaque"><span class="rotulo">Vencimento</span><?= formatDate($parcela['data_vencimento']) ?></td>
        </tr>
        <tr>
            <td><span class="rotulo">Nº do documento</span><?= e($numeroDocumento) ?></td>
            <td><span class="rotulo">Data do documento</span><?= formatDate($parcela['data_emissao']) ?></td>
            <td><span class="rotulo">Referente a</span><?= e($parcela['descricao']) ?></td>
            <td class="direita"><span class="rotulo">Nosso número</span><?= e($dados['nosso_numero'] ?: '—') ?></td>
        </tr>
        <tr>
            <td colspan="3"><span class="rotulo">Endereço do beneficiário</span><?= e($beneficiarioEndereco ?: '—') ?></td>
            <td class="direita destaque"><span class="rotulo">(=) Valor do documento</span><?= e($valor) ?></td>
        </tr>
        <?php if ($desconto): ?>
        <tr>
            <td colspan="3"><span class="rotulo">Desconto por pagamento em dia</span><strong>Pagando até <?= formatDate($desconto['data_limite']) ?>, desconto de <?= e(formatMoney($desconto['desconto'])) ?>.</strong></td>
            <td class="direita"><span class="rotulo">Valor até <?= formatDate($desconto['data_limite']) ?></span><strong><?= e(formatMoney($desconto['valor_com_desconto'])) ?></strong></td>
        </tr>
        <?php endif; ?>
    </table>
    <div class="autenticacao">Autenticação mecânica</div>

    <div class="recorte"><span>✂ corte aqui</span></div>

    <!-- ================= FICHA DE COMPENSAÇÃO ================= -->
    <div class="cabecalho">
        <div class="banco-logo">Itaú<small>Banco Itaú S.A.</small></div>
        <div class="banco-codigo">341-7</div>
        <div class="linha-digitavel"><?= e($dados['linha_digitavel'] ?: '—') ?></div>
    </div>
    <table class="campos">
        <tr>
            <td colspan="5"><span class="rotulo">Local de pagamento</span>Pagável em qualquer banco, app ou Pix (QR Code abaixo) até o vencimento.</td>
            <td class="direita destaque"><span class="rotulo">Vencimento</span><?= formatDate($parcela['data_vencimento']) ?></td>
        </tr>
        <tr>
            <td colspan="5"><span class="rotulo">Beneficiário</span><?= e($beneficiario) ?><?= $empresa['empresa_cnpj'] ? ' — CNPJ ' . e($empresa['empresa_cnpj']) : '' ?></td>
            <td class="direita"><span class="rotulo">Agência / Código do beneficiário</span><?= e($dados['agencia_codigo'] ?: '—') ?></td>
        </tr>
        <tr>
            <td><span class="rotulo">Data do documento</span><?= formatDate($parcela['data_emissao']) ?></td>
            <td><span class="rotulo">Nº do documento</span><?= e($numeroDocumento) ?></td>
            <td><span class="rotulo">Espécie doc.</span><?= e($siglaEspecie) ?></td>
            <td><span class="rotulo">Aceite</span>N</td>
            <td><span class="rotulo">Data processamento</span><?= date('d/m/Y') ?></td>
            <td class="direita"><span class="rotulo">Nosso número</span><?= e($dados['nosso_numero'] ?: '—') ?></td>
        </tr>
        <tr>
            <td><span class="rotulo">Uso do banco</span>&nbsp;</td>
            <td><span class="rotulo">Carteira</span><?= e($conta['itau_carteira'] ?? '109') ?></td>
            <td><span class="rotulo">Espécie</span>R$</td>
            <td><span class="rotulo">Quantidade</span>&nbsp;</td>
            <td><span class="rotulo">Valor</span>&nbsp;</td>
            <td class="direita destaque"><span class="rotulo">(=) Valor do documento</span><?= e($valor) ?></td>
        </tr>
        <tr>
            <td colspan="5" rowspan="4" style="height: 34mm;">
                <span class="rotulo">Instruções (texto de responsabilidade do beneficiário)</span>
                <div class="instrucoes-area">
                    <div class="texto"><?php foreach ($instrucoes as $n => $i): ?><?= $desconto && $n === 0 ? '<strong>' . e($i) . '</strong>' : e($i) ?><br><?php endforeach; ?></div>
                    <div class="qr-pix">
                        <div class="qr <?= $dados['pix_copia_cola'] ? '' : 'borrado' ?>" id="qr-boleto">
                            <?php if (!$dados['pix_copia_cola']): ?><div class="selo">QR Pix gerado no registro</div><?php endif; ?>
                        </div>
                        Pague com Pix
                    </div>
                </div>
            </td>
            <td class="direita"><span class="rotulo">(-) Desconto / abatimento</span>&nbsp;</td>
        </tr>
        <tr><td class="direita"><span class="rotulo">(+) Mora / multa</span>&nbsp;</td></tr>
        <tr><td class="direita"><span class="rotulo">(+) Outros acréscimos</span>&nbsp;</td></tr>
        <tr><td class="direita"><span class="rotulo">(=) Valor cobrado</span>&nbsp;</td></tr>
        <tr>
            <td colspan="6" style="height: 14mm;">
                <span class="rotulo">Pagador</span>
                <?= e($aluno['nome'] ?? '—') ?><?= $cpfPagador ? ' — CPF ' . e($cpfPagador) : '' ?><br>
                <?= e($pagadorEndereco ?: 'Endereço não cadastrado') ?>
            </td>
        </tr>
    </table>
    <div class="codigo-barras-area">
        <div><?= $dados['codigo_barras'] ? svgCodigoBarrasI25($dados['codigo_barras']) : '' ?></div>
        <div class="autenticacao">Autenticação mecânica<br><strong>Ficha de Compensação</strong></div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
// Sem registro não existe Pix: desenha um QR com um texto neutro (que não
// leva a pagamento nenhum) só para mostrar o lugar dele no boleto, borrado.
new QRCode(document.getElementById('qr-boleto'), {
    text: <?= json_encode($dados['pix_copia_cola'] ?: 'PREVIA - BOLETO NAO REGISTRADO') ?>,
    width: 256,
    height: 256,
    correctLevel: QRCode.CorrectLevel.M,
});
</script>
</body>
</html>
