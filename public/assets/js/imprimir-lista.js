// Imprimir lista: com linhas marcadas, imprime só elas (manda ids[] na
// URL); sem nada marcado, a lista toda. O link diz onde estão as caixinhas:
//   <a href="/alunos/imprimir.php" data-imprimir=".selecao-item">
document.addEventListener('click', (ev) => {
    const link = ev.target.closest('a[data-imprimir]');
    if (!link) return;
    const marcados = [...document.querySelectorAll(link.dataset.imprimir + ':checked')].map((c) => c.value);
    if (!marcados.length) return;
    ev.preventDefault();
    const url = new URL(link.href, window.location.href);
    marcados.forEach((id) => url.searchParams.append('ids[]', id));
    window.open(url.toString(), '_blank');
});
