// Escapa texto antes de inserir em HTML via innerHTML/template literal —
// qualquer dado digitado por usuário (ex.: nome de aluno) que o JS monte
// como HTML. Sem isso, um nome como "<img onerror=...>" executaria na tela
// de quem abrir a página.
function escapeHtml(valor) {
    if (valor === null || valor === undefined) {
        return '';
    }
    return String(valor)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}
