<?php
// Grade de cartões de turma. Espera $turmasCartao.
?>
<?php if (!$turmasCartao): ?>
    <div class="portal-card portal-vazio"><i class="bi bi-calendar-x"></i>Nenhuma turma com inscrição aberta no momento. Volte em breve!</div>
<?php else: ?>
    <div class="turmas-grade">
        <?php foreach ($turmasCartao as $t): $restam = (int) $t['vagas_site']; ?>
            <a class="turma-card" href="/portal/turma.php?id=<?= (int) $t['id'] ?>">
                <div class="capa">
                    <?php if ($t['portal_imagem']): ?><img src="<?= e($t['portal_imagem']) ?>" alt=""><?php else: ?><i class="bi bi-mortarboard"></i><?php endif; ?>
                </div>
                <div class="corpo">
                    <div class="curso"><?= e($t['curso_nome']) ?></div>
                    <div class="info"><?= e($t['nome']) ?> · <?= $t['data_inicio'] ? 'início ' . formatDate($t['data_inicio']) : 'início a definir' ?></div>
                    <?php if ($agenda = textoAgendaTurma($t)): ?><div class="info"><i class="bi bi-clock"></i> <?= e($agenda) ?></div><?php endif; ?>
                    <div><span class="selo-vagas <?= $restam <= 5 ? 'poucas' : '' ?>"><?= $restam > 0 && $restam <= 5 ? '<i class="bi bi-fire"></i> ' : '' ?><?= e(textoVagasRestantes($restam)) ?></span></div>
                    <div class="preco">
                        <?= (float) $t['valor_mensalidade'] > 0 ? numParcelasPortal($t) . 'x de ' . formatMoney($t['valor_mensalidade']) : 'Gratuito' ?>
                    </div>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
