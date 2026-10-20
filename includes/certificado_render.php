<?php
// Desenha as folhas do certificado (A4 deitada). Espera $paginasCertificado
// (folhas: fundo + elementos), $valores (marcador => texto) e
// $modulosCertificado (módulos do curso). Medidas em % da folha e fonte em
// cqw (1 cqw = 2,97 mm = 8,418 pt), então serve igual para a tela, a
// impressão e a prévia do editor. Encaixe de texto e QR Code são feitos por
// /assets/js/certificado.js.
?>
<?php
// Configurações da instituição
$config = $config ?? buscarConfiguracoes($pdo);

// Marca d'água: a logo do sistema repetida em diagonal, conforme o modelo
// ($marcaDaguaCertificado = marcaDaguaModelo($modelo); sem ela, o padrão).
// O padrão é desenhado por /assets/js/certificado.js (desenharMarcaDagua).
$logoMarcaDagua = trim((string) ($config['logo_sistema'] ?? ''));
$marcaDaguaCertificado = $marcaDaguaCertificado ?? marcaDaguaModelo(null);

if (!function_exists('formatarTextoCertificado')) {
    function formatarTextoCertificado(string $texto): string
    {
        // Escapa HTML para evitar injeção de código
        $texto = htmlspecialchars(
            $texto,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );

        // *texto* = negrito
        $texto = preg_replace(
            '/\*([^*\r\n]+)\*/u',
            '<strong>$1</strong>',
            $texto
        );

        // _texto_ = itálico
        $texto = preg_replace(
            '/_([^_\r\n]+)_/u',
            '<em>$1</em>',
            $texto
        );

        // &texto& = sublinhado
        $texto = preg_replace(
            '/&amp;([^&\r\n]+)&amp;/u',
            '<u>$1</u>',
            $texto
        );

        // %texto% = tachado
        $texto = preg_replace(
            '/%([^%\r\n]+)%/u',
            '<s>$1</s>',
            $texto
        );

        return $texto;
    }
}
?>

<?php
// Orientação do modelo: horizontal (A4 deitada) ou vertical (A4 em pé). A
// fonte é em cqw (% da largura da folha), então a conversão muda com ela.
$orientacaoCertificado = ($orientacaoCertificado ?? 'horizontal') === 'vertical' ? 'vertical' : 'horizontal';
$fatorFonteCertificado = $orientacaoCertificado === 'vertical' ? 5.953 : 8.418;
?>
<?php foreach ($paginasCertificado as $pagina): ?>
<div class="cert-folha<?= $orientacaoCertificado === 'vertical' ? ' cert-vertical' : '' ?>" <?= $pagina['fundo'] ? 'style="background-image:url(\'' . e($pagina['fundo']) . '\')"' : '' ?>>
    <?php if ($logoMarcaDagua !== ''): ?>
        <div class="cert-marca-dagua" aria-hidden="true" data-logo="<?= e($logoMarcaDagua) ?>"
             data-tamanho="<?= $marcaDaguaCertificado['tamanho'] ?>" data-espaco="<?= $marcaDaguaCertificado['espaco'] ?>"
             data-opacidade="<?= $marcaDaguaCertificado['opacidade'] / 100 ?>"<?= $marcaDaguaCertificado['ativa'] ? '' : ' hidden' ?>></div>
    <?php endif; ?>
    <?php foreach ($pagina['elementos'] as $campo): ?>
        <?php
        $estilo = 'left: ' . (float) $campo['x'] . '%; top: ' . (float) $campo['y'] . '%; width: ' . (float) $campo['largura'] . '%;'
            . ($campo['altura'] > 0 ? ' height: ' . (float) $campo['altura'] . '%;' : '');
        $classeCaixa = $campo['altura'] > 0 ? ' cert-caixa' : '';
        if (isset($campo['tamanho'])) {
            $fonte = round($campo['tamanho'] / $fatorFonteCertificado, 3);
            $estilo .= " font-size: {$fonte}cqw; color: " . e($campo['cor']) . '; font-weight: ' . ($campo['negrito'] ? 700 : 400) . '; text-align: ' . e($campo['alinhamento']) . ';';
        }
        ?>
        <?php if ($campo['tipo'] === 'imagem'): ?>
            <div class="cert-campo cert-imagem<?= $classeCaixa ?>" style="<?= $estilo ?>"><img src="<?= e($campo['imagem']) ?>" alt=""></div>
        <?php elseif ($campo['tipo'] === 'qrcode'): ?>
            <div class="cert-campo cert-qr" style="<?= $estilo ?>" data-qr="<?= e($valores['{link_verificacao}'] ?? '') ?>"></div>
        <?php elseif ($campo['tipo'] === 'modulos'): ?>
            <div class="cert-campo cert-modulos<?= $classeCaixa ?>" data-fonte="<?= $fonte ?>" style="<?= $estilo ?>">
                <div class="cert-conteudo" style="column-count: <?= (int) $campo['colunas'] ?>;">
                    <?php foreach ($modulosCertificado as $i => $modulo): ?>
                        <div class="cert-modulo"><?= $campo['numerar'] ? ($i + 1) . '. ' : '' ?><?= e($modulo['nome']) ?><?=
                            $campo['mostrar_carga'] && $modulo['carga_horaria'] ? ' — ' . (int) $modulo['carga_horaria'] . ' h' : '' ?></div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php else: ?>
            <?php
            $textoModelo = $campo['texto'];

            if (empty(trim((string) ($valores['{filiacao}'] ?? '')))) {
                $textoModelo = str_replace(
                    ['*{filiacao}*', '_{filiacao}_', '&{filiacao}&', '%{filiacao}%', '{filiacao}'],
                    '',
                    $textoModelo
                );
            }

            $texto = strtr($textoModelo, $valores);

            if ($campo['maiusculo']) {
                $texto = mb_strtoupper($texto, 'UTF-8');
            }
            ?>
        <?php
            if ($campo['uma_linha']) {
                $texto = preg_replace('/\s*\R\s*/u', ' ', $texto);
            }

            $textoFormatado = formatarTextoCertificado($texto);

            if (!$campo['uma_linha']) {
                $textoFormatado = nl2br($textoFormatado);
            }
            ?>

            <div
                class="cert-campo<?= $classeCaixa ?><?= $campo['uma_linha'] ? ' cert-uma-linha' : '' ?>"
                data-fonte="<?= $fonte ?>"
                style="<?= $estilo ?>"
            >
                <div class="cert-conteudo">
                    <?= $textoFormatado ?>
                </div>
            </div>
        <?php endif; ?>
    <?php endforeach; ?>
</div>
<?php endforeach; ?>
