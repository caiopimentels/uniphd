<?php
// Partial reutilizável de filtro por período (calendário duplo, estilo Bling).
// Espera $dataInicio e $dataFim (strings 'Y-m-d' ou '') já definidas no escopo,
// e deve ser usado DENTRO de um <form method="get"> existente.
// Opcional: $periodoTipos (valor => rótulo, ex.: vencimento/pagamento) e
// $periodoTipo (o escolhido) — qual data o período usa; vai no campo
// "data_tipo", escolhido no topo do painel e mostrado no botão.
$periodoTipos = $periodoTipos ?? [];
$periodoTipo = $periodoTipo ?? (array_key_first($periodoTipos) ?? '');
$labelPeriodo = ($dataInicio || $dataFim)
    ? ($dataInicio ? formatDate($dataInicio) : '...') . ' até ' . ($dataFim ? formatDate($dataFim) : '...')
    : 'Todo o período';
if ($periodoTipos) {
    $labelPeriodo = ($periodoTipos[$periodoTipo] ?? '') . ': ' . $labelPeriodo;
}
?>
<div class="dropdown filtro-periodo">
    <button type="button" class="btn btn-outline-secondary dropdown-toggle filtro-controle" data-bs-toggle="dropdown" data-bs-auto-close="outside">
        <i class="bi bi-calendar3"></i> <?= e($labelPeriodo) ?>
    </button>
    <div class="dropdown-menu p-3 periodo-painel">
        <?php if ($periodoTipos): ?>
            <div class="d-flex align-items-center gap-2 mb-3 pb-2 border-bottom">
                <span class="small text-muted">Filtrar pela data de</span>
                <div class="btn-group btn-group-sm" role="group">
                    <?php foreach ($periodoTipos as $valorTipo => $rotuloTipo): ?>
                        <input type="radio" class="btn-check" name="data_tipo" id="periodo-tipo-<?= e($valorTipo) ?>" value="<?= e($valorTipo) ?>" <?= $periodoTipo === $valorTipo ? 'checked' : '' ?>>
                        <label class="btn btn-outline-secondary" for="periodo-tipo-<?= e($valorTipo) ?>"><?= e($rotuloTipo) ?></label>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
        <div class="periodo-body">
            <div class="periodo-calendarios">
                <div class="periodo-calendario" data-lado="inicio">
                    <div class="periodo-titulo-lado">Início do período</div>
                    <div class="periodo-nav">
                        <button type="button" class="btn btn-sm btn-link p-0 periodo-nav-prev"><i class="bi bi-chevron-left"></i></button>
                        <span class="periodo-nav-titulo"></span>
                        <button type="button" class="btn btn-sm btn-link p-0 periodo-nav-next"><i class="bi bi-chevron-right"></i></button>
                    </div>
                    <table class="periodo-grid">
                        <thead><tr><th>D</th><th>S</th><th>T</th><th>Q</th><th>Q</th><th>S</th><th>S</th></tr></thead>
                        <tbody></tbody>
                    </table>
                </div>
                <div class="periodo-calendario" data-lado="fim">
                    <div class="periodo-titulo-lado">Fim do período</div>
                    <div class="periodo-nav">
                        <button type="button" class="btn btn-sm btn-link p-0 periodo-nav-prev"><i class="bi bi-chevron-left"></i></button>
                        <span class="periodo-nav-titulo"></span>
                        <button type="button" class="btn btn-sm btn-link p-0 periodo-nav-next"><i class="bi bi-chevron-right"></i></button>
                    </div>
                    <table class="periodo-grid">
                        <thead><tr><th>D</th><th>S</th><th>T</th><th>Q</th><th>Q</th><th>S</th><th>S</th></tr></thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
            <div class="periodo-presets">
                <button type="button" data-preset="hoje">Hoje</button>
                <button type="button" data-preset="semana">Esta semana</button>
                <button type="button" data-preset="semana_passada">Semana passada</button>
                <button type="button" data-preset="mes">Este mês</button>
                <button type="button" data-preset="mes_passado">Mês passado</button>
                <button type="button" data-preset="tudo">Tudo</button>
                <button type="button" data-preset="customizado" class="ativo">Período customizado</button>
            </div>
        </div>
        <div class="d-flex justify-content-end gap-2 mt-2 pt-2 border-top">
            <button type="button" class="btn btn-sm btn-brand periodo-filtrar">Filtrar</button>
        </div>
    </div>
    <input type="hidden" name="data_inicio" class="periodo-hidden-inicio" value="<?= e($dataInicio) ?>">
    <input type="hidden" name="data_fim" class="periodo-hidden-fim" value="<?= e($dataFim) ?>">
</div>
