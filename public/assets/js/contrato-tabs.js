// Tabulação como no Word: cada TAB do contrato (span.ql-tab) estica até a
// próxima marca da régua; depois da última marca, de 1,25 em 1,25 cm.
// Usado no editor e na página impressa / PDF (a mesma conta nos dois).
// marcasCm: posições em cm a partir da margem esquerda (início da área).
// escala: quando a folha aparece reduzida (miniatura com transform: scale).
function aplicarTabulacoes(area, marcasCm, escala = 1) {
    const pxPorCm = 96 / 2.54;
    const marcas = (marcasCm || []).map((cm) => cm * pxPorCm).sort((a, b) => a - b);
    const passo = 1.25 * pxPorCm;
    const tabs = area.querySelectorAll('.ql-tab');
    if (!tabs.length) return;
    // Zera todas antes de medir: a largura de uma muda a posição das seguintes.
    tabs.forEach((tab) => { tab.style.width = '0px'; });
    const inicio = area.getBoundingClientRect().left;
    tabs.forEach((tab) => {
        const x = (tab.getBoundingClientRect().left - inicio) / escala;
        let alvo = marcas.find((m) => m > x + 1);
        if (alvo === undefined) alvo = (Math.floor((x + 1) / passo) + 1) * passo;
        tab.style.width = Math.max(1, alvo - x) + 'px';
    });
}
