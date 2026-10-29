<?php
// Coluna da esquerda do e-mail: Escrever, pastas e atalhos. Espera $pastas,
// $pasta (aberta, ou null) e $contagens (não lidas por pasta).
$iconesPasta = ['inbox' => 'bi-inbox', 'enviados' => 'bi-send', 'rascunhos' => 'bi-file-earmark', 'spam' => 'bi-exclamation-octagon', 'lixeira' => 'bi-trash', 'arquivo' => 'bi-archive'];
?>
<aside class="email-lateral">
    <a href="/email/escrever.php" class="btn btn-brand email-escrever"><i class="bi bi-pencil"></i> Escrever</a>
    <nav class="email-pastas">
        <?php foreach ($pastas as $i => $p):
            $especial = $p['especial'];
            $anterior = $pastas[$i - 1]['especial'] ?? 'inbox';
            $qtd = ($contagens[$p['nome']] ?? null)[$especial === 'rascunhos' ? 'mensagens' : 'nao_lidas'] ?? 0;
        ?>
            <?php if (!$especial && $anterior): ?><div class="sep"></div><?php endif; ?>
            <a href="/email/index.php?pasta=<?= e(rawurlencode($p['nome'])) ?>" class="<?= ($pasta ?? null) === $p['nome'] ? 'ativa' : '' ?>">
                <i class="bi <?= $iconesPasta[$especial] ?? 'bi-folder' ?>"></i> <?= e($p['rotulo']) ?>
                <?php if ($qtd): ?><span class="qtd"><?= (int) $qtd ?></span><?php endif; ?>
            </a>
        <?php endforeach; ?>
        <div class="sep"></div>
        <?php if (usuarioPodeDispararEmail()): ?><a href="/email/disparos.php"><i class="bi bi-megaphone"></i> Disparo em massa</a><?php endif; ?>
        <a href="/email/minha_caixa.php"><i class="bi bi-gear"></i> Minha caixa e assinatura</a>
    </nav>
</aside>
