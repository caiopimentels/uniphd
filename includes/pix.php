<?php

// Pix das cobranças: o QR Code é DINÂMICO e vem do banco junto com o
// boleto (bolecode, POST /boletos-pix) — pagar pelo Pix ou pela linha
// digitável quita o mesmo título. Aqui só existe a conferência do
// "copia e cola" recebido; o sistema não gera Pix por conta própria, para
// não criar um pagamento solto, desligado do boleto.

// CRC16-CCITT (polinômio 0x1021, valor inicial 0xFFFF) do padrão BR Code.
function crc16Pix(string $dados): string
{
    $crc = 0xFFFF;
    $tamanho = strlen($dados);
    for ($i = 0; $i < $tamanho; $i++) {
        $crc ^= ord($dados[$i]) << 8;
        for ($bit = 0; $bit < 8; $bit++) {
            $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) : ($crc << 1);
            $crc &= 0xFFFF;
        }
    }
    return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
}

// Confere o "copia e cola" devolvido pelo banco: começa no campo 00 e
// termina no campo 63 com o CRC que bate com o resto do texto.
function pixCopiaEColaValido(?string $codigo): bool
{
    if (!$codigo || !str_starts_with($codigo, '000201') || strlen($codigo) < 30) {
        return false;
    }
    return substr($codigo, -8, 4) === '6304' && crc16Pix(substr($codigo, 0, -4)) === strtoupper(substr($codigo, -4));
}
