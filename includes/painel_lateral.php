<?php
// Coluna lateral das listas (alunos, cursos, professores...): "Alterar em
// massa" e "Informações", como na lista de turmas. Quem inclui define:
//   $acoesMassa   = [['rotulo', 'icone bi-*', 'url do POST', 'acao', 'pergunta de confirmação', 'classe extra'(, 'js ao clicar')], ...]
//                   com o 7º item, o botão só chama aquele JS (a página cuida do resto).
//   $informacoes  = [['rotulo', 'valor já formatado', 'classe extra'], ...]
// A seleção (caixinhas .selecao-item) e o envio ficam em /assets/js/selecao-massa.js.
?>
<aside class="pedidos-lateral">
    <?php if ($acoesMassa): ?>
    <div class="lateral-bloco">
        <div class="lateral-titulo">Alterar em massa</div>
        <?php foreach ($acoesMassa as $acaoMassa): [$rotulo, $icone, $url, $acao, $pergunta, $classe] = $acaoMassa; ?>
            <?php if (!empty($acaoMassa[6])): ?>
                <button type="button" class="lateral-acao <?= e($classe) ?>" onclick="<?= e($acaoMassa[6]) ?>">
                    <i class="bi <?= e($icone) ?>"></i> <?= e($rotulo) ?>
                </button>
            <?php else: ?>
                <button type="button" class="lateral-acao <?= e($classe) ?>" data-acao-massa="<?= e($acao) ?>" data-url="<?= e($url) ?>" data-confirmar="<?= e($pergunta) ?>">
                    <i class="bi <?= e($icone) ?>"></i> <?= e($rotulo) ?>
                </button>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="lateral-bloco">
        <div class="lateral-titulo">Informações</div>
        <?php foreach ($informacoes as [$rotulo, $valor, $classe]): ?>
            <div class="lateral-info">
                <span class="lateral-info-rotulo"><?= e($rotulo) ?></span>
                <span class="lateral-info-valor <?= e($classe) ?>"><?= e((string) $valor) ?></span>
            </div>
        <?php endforeach; ?>
        <div class="lateral-info">
            <span class="lateral-info-rotulo">Selecionados</span>
            <span class="lateral-info-valor destaque" id="info-selecionadas">0</span>
        </div>
    </div>
</aside>
