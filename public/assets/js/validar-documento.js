// Validação de CPF/CNPJ no navegador (mesmo algoritmo do servidor), para dar
// feedback imediato ao sair do campo. A validação real e definitiva continua
// sendo feita no servidor — isto é só uma conferência rápida para o usuário.
function cpfValido(cpf) {
    if (/^(\d)\1{10}$/.test(cpf)) return false;
    for (let pos = 9; pos <= 10; pos++) {
        let soma = 0;
        for (let i = 0; i < pos; i++) soma += parseInt(cpf[i], 10) * ((pos + 1) - i);
        let digito = (soma * 10) % 11;
        if (digito === 10) digito = 0;
        if (digito !== parseInt(cpf[pos], 10)) return false;
    }
    return true;
}

function cnpjValido(cnpj) {
    if (/^(\d)\1{13}$/.test(cnpj)) return false;
    const calcularDigito = (base, pesos) => {
        let soma = 0;
        pesos.forEach((peso, i) => { soma += parseInt(base[i], 10) * peso; });
        const resto = soma % 11;
        return resto < 2 ? 0 : 11 - resto;
    };
    const d1 = calcularDigito(cnpj, [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]);
    if (d1 !== parseInt(cnpj[12], 10)) return false;
    const d2 = calcularDigito(cnpj, [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]);
    return d2 === parseInt(cnpj[13], 10);
}

function documentoValido(valor) {
    const limpo = (valor || '').replace(/\D/g, '');
    if (limpo.length === 11) return cpfValido(limpo);
    if (limpo.length === 14) return cnpjValido(limpo);
    return false;
}

// Chame no onblur do campo. Se vazio, considera válido (o campo pode ser
// opcional — quem exige preenchimento é o atributo required, não isto aqui).
function validarCampoDocumento(campo) {
    const valor = campo.value.trim();
    campo.classList.toggle('is-invalid', valor !== '' && !documentoValido(valor));
}
