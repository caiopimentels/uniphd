<?php
// Documentos para imprimir (comprovante de matrícula, recibo, ficha do
// aluno): folha A4 com o cabeçalho da instituição (Configurações › Empresa)
// e a barra Voltar / Imprimir, no mesmo estilo dos relatórios.

/** "mil duzentos e trinta e quatro reais e cinquenta e seis centavos" */
function valorPorExtenso(float $valor): string
{
    $valor = round(abs($valor), 2);
    $reais = (int) floor($valor);
    $centavos = (int) round(($valor - $reais) * 100);

    $partes = [];
    if ($reais > 0) {
        $partes[] = numeroPorExtenso($reais) . ($reais === 1 ? ' real' : ($reais % 1000000 === 0 ? ' de reais' : ' reais'));
    }
    if ($centavos > 0) {
        $partes[] = numeroPorExtenso($centavos) . ($centavos === 1 ? ' centavo' : ' centavos');
    }
    return $partes ? implode(' e ', $partes) : 'zero real';
}

function numeroPorExtenso(int $n): string
{
    $unidades = ['', 'um', 'dois', 'três', 'quatro', 'cinco', 'seis', 'sete', 'oito', 'nove', 'dez', 'onze', 'doze', 'treze',
        'quatorze', 'quinze', 'dezesseis', 'dezessete', 'dezoito', 'dezenove'];
    $dezenas = ['', '', 'vinte', 'trinta', 'quarenta', 'cinquenta', 'sessenta', 'setenta', 'oitenta', 'noventa'];
    $centenas = ['', 'cento', 'duzentos', 'trezentos', 'quatrocentos', 'quinhentos', 'seiscentos', 'setecentos', 'oitocentos', 'novecentos'];

    $ate999 = function (int $n) use ($unidades, $dezenas, $centenas): string {
        if ($n === 100) {
            return 'cem';
        }
        $txt = [];
        if ($n >= 100) {
            $txt[] = $centenas[intdiv($n, 100)];
            $n %= 100;
        }
        if ($n >= 20) {
            $txt[] = $dezenas[intdiv($n, 10)];
            $n %= 10;
        }
        if ($n > 0) {
            $txt[] = $unidades[$n];
        }
        return implode(' e ', $txt);
    };

    if ($n === 0) {
        return 'zero';
    }
    $grupos = [[1000000000, 'bilhão', 'bilhões'], [1000000, 'milhão', 'milhões'], [1000, 'mil', 'mil'], [1, '', '']];
    $txt = [];
    $ultimoValor = 0;
    foreach ($grupos as [$base, $singular, $plural]) {
        $q = intdiv($n, $base);
        $n %= $base;
        if ($q === 0) {
            continue;
        }
        if ($base === 1000 && $q === 1) {
            $txt[] = 'mil';
        } elseif ($base === 1) {
            $txt[] = $ate999($q);
        } else {
            $txt[] = $ate999($q) . ' ' . ($q === 1 ? $singular : $plural);
        }
        $ultimoValor = $q;
    }
    // Regra do português: "mil e cem", "dois mil e trinta", mas "mil
    // duzentos e trinta" — o "e" entre grupos só antes do último, quando ele
    // é menor que cem ou uma centena redonda.
    if (count($txt) > 1) {
        $ultimo = array_pop($txt);
        $liga = ($ultimoValor < 100 || $ultimoValor % 100 === 0) ? ' e ' : ' ';
        return implode(' ', $txt) . $liga . $ultimo;
    }
    return $txt[0];
}

function cpfFormatado(?string $cpf): string
{
    $d = preg_replace('/\D/', '', (string) $cpf);
    return strlen($d) === 11 ? substr($d, 0, 3) . '.' . substr($d, 3, 3) . '.' . substr($d, 6, 3) . '-' . substr($d, 9) : (string) $cpf;
}

function cnpjFormatado(?string $cnpj): string
{
    $d = preg_replace('/\D/', '', (string) $cnpj);
    return strlen($d) === 14 ? substr($d, 0, 2) . '.' . substr($d, 2, 3) . '.' . substr($d, 5, 3) . '/' . substr($d, 8, 4) . '-' . substr($d, 12) : (string) $cnpj;
}

/** Abre a página: <head>, barra de ações e o cabeçalho da instituição. */
function documentoInicio(PDO $pdo, string $titulo, string $voltar): array
{
    $config = buscarConfiguracoes($pdo);
    $cor = preg_match('/^#[0-9a-fA-F]{6}$/', (string) $config['cor_primaria']) && strtolower($config['cor_primaria']) !== '#ffffff' ? $config['cor_primaria'] : '#071f4a';
    $nome = $config['empresa_nome_fantasia'] ?: ($config['empresa_razao_social'] ?: nomeSistema());
    $endereco = trim(implode(', ', array_filter([$config['empresa_logradouro'] ?? '', $config['empresa_numero'] ?? '', $config['empresa_bairro'] ?? ''])));
    $cidade = trim(($config['empresa_cidade'] ?? '') . (!empty($config['empresa_uf']) ? ' - ' . $config['empresa_uf'] : ''));
    ?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($titulo) ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="<?= e(assetUrl('/assets/css/relatorio.css')) ?>">
<style>
    :root { --azul: <?= e($cor) ?>; }
    .toolbar a { display:inline-block; margin-right:6px; border-radius:7px; padding:10px 22px; background:#fff; color:#333; text-decoration:none; font-weight:700; border:1px solid #ccc; }
    .doc-cabecalho { display:flex; align-items:center; gap:5mm; padding-bottom:4mm; margin-bottom:6mm; border-bottom:2px solid var(--azul); }
    .doc-cabecalho img { max-height:18mm; max-width:40mm; object-fit:contain; }
    .doc-cabecalho .empresa { font-size:11px; color:#555; line-height:1.45; }
    .doc-cabecalho .empresa strong { font-size:15px; color:var(--azul); display:block; }
    .doc-cabecalho .doc-titulo { margin-left:auto; text-align:right; }
    .doc-cabecalho .doc-titulo h1 { margin:0; font-size:18px; }
    .doc-cabecalho .doc-titulo div { font-size:11px; color:#6c757d; }
    .doc-secao { margin-bottom:6mm; }
    .doc-secao h2 { margin:0 0 2.5mm; font-size:12px; text-transform:uppercase; letter-spacing:.05em; color:var(--azul); border-bottom:1px solid #ddd; padding-bottom:1.5mm; }
    .doc-grade { display:grid; grid-template-columns:repeat(4, 1fr); gap:2.5mm 5mm; font-size:12px; }
    .doc-grade .dois { grid-column:span 2; } .doc-grade .todo { grid-column:1 / -1; }
    .doc-grade .rotulo { font-size:9.5px; text-transform:uppercase; color:#888; letter-spacing:.03em; }
    .doc-texto { font-size:13px; line-height:1.7; text-align:justify; }
    .doc-destaque { border:1.5px solid var(--azul); border-radius:3mm; padding:4mm 5mm; margin:4mm 0 6mm; }
    .assinaturas { display:flex; gap:12mm; margin-top:22mm; }
    .assinaturas div { flex:1; border-top:1px solid #333; padding-top:1.5mm; text-align:center; font-size:11px; }
    .doc-rodape { margin-top:8mm; font-size:10px; color:#999; text-align:center; }
    .duas-vias { border-top:1px dashed #999; margin:8mm 0; position:relative; }
    .duas-vias span { position:absolute; top:-2.2mm; left:50%; transform:translateX(-50%); background:#fff; padding:0 3mm; font-size:9px; color:#999; }
</style>
</head>
<body>
<div class="toolbar">
    <a href="<?= e($voltar) ?>"><i class="bi bi-arrow-left"></i> Voltar</a>
    <button type="button" onclick="window.print()"><i class="bi bi-printer"></i> Imprimir</button>
</div>
<main class="relatorio">
    <?php
    return ['config' => $config, 'nome' => $nome, 'endereco' => $endereco, 'cidade' => $cidade];
}

/** Cabeçalho com logo e dados da instituição + título do documento. */
function documentoCabecalho(array $doc, string $titulo, string $subtitulo = ''): void
{
    $c = $doc['config'];
    ?>
    <div class="doc-cabecalho">
        <?php if (!empty($c['logo_sistema'])): ?><img src="<?= e($c['logo_sistema']) ?>" alt=""><?php endif; ?>
        <div class="empresa">
            <strong><?= e($doc['nome']) ?></strong>
            <?php if (!empty($c['empresa_razao_social']) && $c['empresa_razao_social'] !== $doc['nome']): ?><?= e($c['empresa_razao_social']) ?><br><?php endif; ?>
            <?php if (!empty($c['empresa_cnpj'])): ?>CNPJ <?= e(cnpjFormatado($c['empresa_cnpj'])) ?><br><?php endif; ?>
            <?php if ($doc['endereco'] || $doc['cidade']): ?><?= e(trim($doc['endereco'] . ($doc['endereco'] && $doc['cidade'] ? ' — ' : '') . $doc['cidade'])) ?><br><?php endif; ?>
            <?= e(implode(' · ', array_filter([$c['empresa_telefone'] ?? '', $c['empresa_email'] ?? '']))) ?>
        </div>
        <div class="doc-titulo">
            <h1><?= e($titulo) ?></h1>
            <?php if ($subtitulo): ?><div><?= e($subtitulo) ?></div><?php endif; ?>
        </div>
    </div>
    <?php
}

function documentoFim(): void
{
    ?>
    <div class="doc-rodape">Emitido em <?= formatDateTime(date('Y-m-d H:i:s')) ?> por <?= e(usuarioAtualNome()) ?></div>
</main>
</body>
</html>
    <?php
}
