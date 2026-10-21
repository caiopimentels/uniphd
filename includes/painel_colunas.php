<?php
// Botão e painel "Colunas visíveis" das listas (como no Bling): liga/desliga
// cada coluna e arrasta para mudar a ordem. Quem inclui define:
//   $telaColunas      = chave da tela (ver telasComColunas())
//   $definicaoColunas = definicaoColunasTela($pdo, $telaColunas)
//   $colunas          = colunasVisiveis(...) — as que estão aparecendo
// O botão sai onde este arquivo é incluído; o painel vai junto (offcanvas).
// Se o botão fica dentro de outro <form>, inclua duas vezes: com
// $colunasParte = 'botao' lá dentro e 'painel' fora do form.
$ordemPainel = array_merge(array_keys($colunas), array_diff(array_keys($definicaoColunas), array_keys($colunas)));
$colunasParte ??= 'tudo';
?>
<?php if ($colunasParte !== 'painel'): ?>
<button type="button" class="btn btn-sm btn-outline-secondary btn-colunas" data-bs-toggle="offcanvas" data-bs-target="#painel-colunas" title="Escolher as colunas da lista">
    <i class="bi bi-toggles"></i>
</button>
<?php endif; ?>
<?php if ($colunasParte !== 'botao'): ?>

<div class="offcanvas offcanvas-end" tabindex="-1" id="painel-colunas">
    <form method="post" action="/listas/colunas.php" class="d-flex flex-column h-100">
        <input type="hidden" name="tela" value="<?= e($telaColunas) ?>">
        <input type="hidden" name="voltar" value="<?= e($_SERVER['REQUEST_URI'] ?? '/') ?>">
        <div class="offcanvas-header">
            <h5 class="offcanvas-title">Colunas visíveis</h5>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
        </div>
        <div class="offcanvas-body">
            <p class="text-muted small">Escolha as colunas da lista e arraste <i class="bi bi-grip-vertical"></i> para mudar a ordem. A impressão sai com as mesmas colunas. Vale só para o seu usuário.</p>
            <ul class="lista-colunas" id="lista-colunas">
                <?php foreach ($ordemPainel as $chave): ?>
                    <li draggable="true">
                        <i class="bi bi-grip-vertical alca"></i>
                        <div class="form-check form-switch mb-0">
                            <input class="form-check-input" type="checkbox" role="switch" name="colunas[]" value="<?= e($chave) ?>" id="col-<?= e($chave) ?>" <?= isset($colunas[$chave]) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="col-<?= e($chave) ?>"><?= e($definicaoColunas[$chave]['rotulo']) ?></label>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <div class="offcanvas-footer d-flex justify-content-end gap-2 p-3 border-top">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="offcanvas">Cancelar</button>
            <button type="submit" class="btn btn-brand">Aplicar</button>
        </div>
    </form>
</div>
<script>
(() => {
    // Arrastar para reordenar (a ordem dos campos é a ordem salva).
    const lista = document.getElementById('lista-colunas');
    let arrastando = null;
    lista.addEventListener('dragstart', (ev) => { arrastando = ev.target.closest('li'); arrastando.classList.add('arrastando'); });
    lista.addEventListener('dragend', () => { arrastando?.classList.remove('arrastando'); arrastando = null; });
    lista.addEventListener('dragover', (ev) => {
        ev.preventDefault();
        const alvo = ev.target.closest('li');
        if (!arrastando || !alvo || alvo === arrastando) return;
        const meio = alvo.getBoundingClientRect().top + alvo.offsetHeight / 2;
        lista.insertBefore(arrastando, ev.clientY < meio ? alvo : alvo.nextSibling);
    });
})();
</script>
<?php endif; ?>
