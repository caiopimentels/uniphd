<?php
if ($paginaTela) {
    $blocosRender = blocosPagina($pdo, (int) $paginaTela['id'], 'fim');
    require __DIR__ . '/portal_blocos_render.php';
}
$linksRodape = itensMenuPortal($pdo, 'rodape');
$redes = redesConfiguradasPortal($configPortal);
$itensRodape = itensRodapePortal($configPortal);
?>
</main>

<footer class="portal-rodape">
    <div class="portal-rodape-inner">
        <div style="max-width: 520px;">
            <strong><?= e($configPortal['empresa_nome_fantasia'] ?: ($configPortal['empresa_razao_social'] ?: $nomePortal)) ?></strong>
            <?php if ($itensRodape['endereco']): ?>
                <?php $endereco = trim(implode(', ', array_filter([trim(($configPortal['empresa_logradouro'] ?? '') . ' ' . ($configPortal['empresa_numero'] ?? '')), $configPortal['empresa_bairro'] ?? '',
                    trim(($configPortal['empresa_cidade'] ?? '') . ($configPortal['empresa_uf'] ? '/' . $configPortal['empresa_uf'] : ''))]))); ?>
                <?php if ($endereco): ?><div><i class="bi bi-geo-alt"></i> <?= e($endereco) ?></div><?php endif; ?>
            <?php endif; ?>
            <?php if ($configPortal['portal_rodape_texto']): ?><div class="mt-1" style="white-space: pre-line;"><?= e($configPortal['portal_rodape_texto']) ?></div><?php endif; ?>
        </div>
        <div class="d-flex flex-column gap-1">
            <?php if ($itensRodape['telefone'] || $itensRodape['email'] || $itensRodape['whatsapp']): ?>
                <div class="d-flex flex-wrap gap-3">
                    <?php if ($itensRodape['telefone'] && $configPortal['empresa_telefone']): ?><span><i class="bi bi-telephone"></i> <?= e($configPortal['empresa_telefone']) ?></span><?php endif; ?>
                    <?php if ($itensRodape['email'] && $configPortal['empresa_email']): $emailRodape = mb_strtolower(trim($configPortal['empresa_email']), 'UTF-8'); ?><a href="mailto:<?= e($emailRodape) ?>"><i class="bi bi-envelope"></i> <?= e($emailRodape) ?></a><?php endif; ?>
                    <?php if ($itensRodape['whatsapp'] && $configPortal['whatsapp_numero'] && ($linkZap = linkWhatsapp($configPortal['whatsapp_numero']))): ?>
                        <a href="<?= e($linkZap) ?>" target="_blank" rel="noopener"><i class="bi bi-whatsapp"></i> WhatsApp</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <?php if ($linksRodape): ?>
                <div class="d-flex flex-wrap gap-3">
                    <?php foreach ($linksRodape as $link): ?>
                        <a href="<?= e($link['href']) ?>"<?= $link['nova_aba'] ? ' target="_blank" rel="noopener"' : '' ?>><?= e($link['rotulo']) ?></a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if ($redes): ?>
                <div class="d-flex gap-3 portal-redes">
                    <?php foreach ($redes as $rede => $url): ?>
                        <a href="<?= e($url) ?>" target="_blank" rel="noopener" title="<?= e(redesSociaisPortal()[$rede][0]) ?>"><i class="bi <?= e(redesSociaisPortal()[$rede][1]) ?>"></i></a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</footer>

<?php if ($itensRodape['whatsapp_flutuante'] && !empty($configPortal['whatsapp_numero']) && ($linkZapFlutuante = linkWhatsapp($configPortal['whatsapp_numero']))): ?>
    <a href="<?= e($linkZapFlutuante) ?>" class="whatsapp-flutuante" target="_blank" rel="noopener" title="Fale com a gente pelo WhatsApp" aria-label="WhatsApp"><i class="bi bi-whatsapp"></i></a>
<?php endif; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= e(assetUrl('/assets/js/csrf.js')) ?>"></script>
<script src="<?= e(assetUrl('/assets/js/portal.js')) ?>"></script>
</body>
</html>
