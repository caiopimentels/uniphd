// Olhinho do CPF (cpfTela() no PHP): busca o CPF completo no servidor — que
// registra quem viu — e mostra no lugar do mascarado. Clicar de novo esconde.
// Num campo do cadastro (input), o CPF revelado vira editável. Escuta na
// captura para o clique não abrir a linha da lista em que o olhinho está.
// Usado também pelas buscas de aluno das telas: cpfCombina().
function cpfCombina(cpf, digitos) {
    const d = String(cpf || '');
    if (!digitos || !d) return false;
    // Sem a permissão a página só tem os 4 primeiros dígitos.
    return d.length === 11 ? d.includes(digitos) : (digitos.startsWith(d) || d.startsWith(digitos));
}

// Campo do cadastro: revelado, fica editável se a ficha estiver destravada
// (vai no envio com name="cpf"); escondido, volta mascarado e não é enviado.
function cpfCampoRevelar(campo, cpf) {
    campo.value = cpf;
    campo.dataset.revelado = '1';
    if (!campo.disabled) {
        campo.name = 'cpf';
        campo.readOnly = false;
        campo.dataset.mascara = 'cpf';
    }
}
function cpfCampoEsconder(campo, mascarado) {
    campo.value = mascarado;
    delete campo.dataset.revelado;
    delete campo.dataset.mascara;
    campo.removeAttribute('name');
    campo.readOnly = true;
}

document.addEventListener('click', async (ev) => {
    const botao = ev.target.closest('.ver-cpf');
    if (!botao) return;
    ev.preventDefault();
    ev.stopPropagation();
    const alvo = botao.closest('.cpf-campo').querySelector('.cpf-mascarado');
    const ehCampo = alvo.tagName === 'INPUT';
    if (botao.dataset.aberto) {
        if (ehCampo) {
            cpfCampoEsconder(alvo, botao.dataset.mascarado);
        } else {
            alvo.textContent = botao.dataset.mascarado;
        }
        delete botao.dataset.aberto;
        botao.innerHTML = '<i class="bi bi-eye"></i>';
        botao.title = 'Mostrar o CPF completo (fica registrado)';
        return;
    }
    botao.disabled = true;
    try {
        const corpo = new FormData();
        corpo.append('aluno', botao.dataset.aluno);
        const r = await (await fetch('/alunos/ver_cpf.php', { method: 'POST', body: corpo })).json();
        if (!r.sucesso) {
            botao.title = r.mensagem || 'Não foi possível mostrar o CPF.';
            return;
        }
        botao.dataset.mascarado = ehCampo ? alvo.value : alvo.textContent;
        botao.dataset.aberto = '1';
        botao.innerHTML = '<i class="bi bi-eye-slash"></i>';
        botao.title = 'Esconder o CPF';
        if (ehCampo) {
            cpfCampoRevelar(alvo, r.cpf);
        } else {
            alvo.textContent = r.cpf;
        }
    } finally {
        botao.disabled = false;
    }
}, true);
