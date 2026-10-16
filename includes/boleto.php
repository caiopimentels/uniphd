<?php

// Código de barras e linha digitável do boleto Itaú (carteira 109), pelas
// regras FEBRABAN/Itaú. Calcular localmente serve para DESENHAR o boleto;
// para ser pagável ele precisa estar registrado no banco (API) — sem
// registro o banco recusa o pagamento.

function modulo10(string $numero): int
{
    $soma = 0;
    $peso = 2;
    for ($i = strlen($numero) - 1; $i >= 0; $i--) {
        $produto = (int) $numero[$i] * $peso;
        $soma += intdiv($produto, 10) + $produto % 10;
        $peso = $peso === 2 ? 1 : 2;
    }
    return (10 - $soma % 10) % 10;
}

// DV geral do código de barras: módulo 11, pesos 2 a 9; resto que daria
// 0, 1, 10 ou 11 vira 1 (regra FEBRABAN).
function modulo11CodigoBarras(string $numero): int
{
    $soma = 0;
    $peso = 2;
    for ($i = strlen($numero) - 1; $i >= 0; $i--) {
        $soma += (int) $numero[$i] * $peso;
        $peso = $peso === 9 ? 2 : $peso + 1;
    }
    $dv = 11 - $soma % 11;
    return in_array($dv, [0, 1, 10, 11], true) ? 1 : $dv;
}

/**
 * Fator de vencimento: dias desde 07/10/1997. Ao chegar em 9999
 * (21/02/2025) o fator reiniciou em 1000 (22/02/2025), conforme a regra da
 * FEBRABAN para o fim do primeiro ciclo.
 */
function fatorVencimento(string $data): string
{
    $vencimento = new DateTimeImmutable($data);
    $reinicio = new DateTimeImmutable('2025-02-22');
    if ($vencimento >= $reinicio) {
        $fator = 1000 + (int) $reinicio->diff($vencimento)->days;
    } else {
        $fator = (int) (new DateTimeImmutable('1997-10-07'))->diff($vencimento)->days;
    }
    return str_pad((string) $fator, 4, '0', STR_PAD_LEFT);
}

/**
 * Monta código de barras (44 dígitos) e linha digitável (47) de um boleto
 * Itaú carteira 109. Agência com 4 dígitos e conta com 5 (sem o DAC).
 */
function calcularBoletoItau(string $agencia, string $conta, string $carteira, int $nossoNumero, string $vencimento, float $valor): array
{
    $agencia = str_pad(apenasDigitos($agencia), 4, '0', STR_PAD_LEFT);
    $conta = str_pad(apenasDigitos($conta), 5, '0', STR_PAD_LEFT);
    $carteira = str_pad(apenasDigitos($carteira), 3, '0', STR_PAD_LEFT);
    $nosso = str_pad((string) $nossoNumero, 8, '0', STR_PAD_LEFT);

    $dacNossoNumero = modulo10($agencia . $conta . $carteira . $nosso);
    $dacAgenciaConta = modulo10($agencia . $conta);

    $campoLivre = $carteira . $nosso . $dacNossoNumero . $agencia . $conta . $dacAgenciaConta . '000';
    $fatorValor = fatorVencimento($vencimento) . str_pad((string) round($valor * 100), 10, '0', STR_PAD_LEFT);

    $semDv = '341' . '9' . $fatorValor . $campoLivre;
    $dvGeral = modulo11CodigoBarras($semDv);
    $codigoBarras = '3419' . $dvGeral . $fatorValor . $campoLivre;

    $campo1 = '3419' . substr($campoLivre, 0, 5);
    $campo2 = substr($campoLivre, 5, 10);
    $campo3 = substr($campoLivre, 15, 10);
    $campo1 .= modulo10($campo1);
    $campo2 .= modulo10($campo2);
    $campo3 .= modulo10($campo3);

    $linhaDigitavel = substr($campo1, 0, 5) . '.' . substr($campo1, 5) . ' '
        . substr($campo2, 0, 5) . '.' . substr($campo2, 5) . ' '
        . substr($campo3, 0, 5) . '.' . substr($campo3, 5) . ' '
        . $dvGeral . ' ' . $fatorValor;

    return [
        'codigo_barras' => $codigoBarras,
        'linha_digitavel' => $linhaDigitavel,
        'nosso_numero_formatado' => $carteira . '/' . $nosso . '-' . $dacNossoNumero,
        'agencia_codigo' => $agencia . ' / ' . $conta . '-' . $dacAgenciaConta,
        'dac_agencia_conta' => $dacAgenciaConta,
    ];
}

/**
 * Desenha o código de barras Intercalado 2 de 5 (padrão dos boletos) como
 * SVG: barra/espaço estreito = 1 unidade, largo = 3.
 */
function svgCodigoBarrasI25(string $codigo, int $altura = 50): string
{
    $padroes = ['nnwwn', 'wnnnw', 'nwnnw', 'wwnnn', 'nnwnw', 'wnwnn', 'nwwnn', 'nnnww', 'wnnwn', 'nwnwn'];

    // Início: barra, espaço, barra, espaço — todos estreitos.
    $elementos = 'nnnn';
    for ($i = 0; $i < strlen($codigo); $i += 2) {
        $barras = $padroes[(int) $codigo[$i]];
        $espacos = $padroes[(int) $codigo[$i + 1]];
        for ($j = 0; $j < 5; $j++) {
            $elementos .= $barras[$j] . $espacos[$j];
        }
    }
    // Fim: barra larga, espaço estreito, barra estreita.
    $elementos .= 'wnn';

    $x = 0;
    $retangulos = '';
    for ($i = 0; $i < strlen($elementos); $i++) {
        $largura = $elementos[$i] === 'w' ? 3 : 1;
        if ($i % 2 === 0) {
            $retangulos .= '<rect x="' . $x . '" y="0" width="' . $largura . '" height="' . $altura . '"/>';
        }
        $x += $largura;
    }

    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $x . ' ' . $altura . '" preserveAspectRatio="none" class="codigo-barras">'
        . '<g fill="#000">' . $retangulos . '</g></svg>';
}
