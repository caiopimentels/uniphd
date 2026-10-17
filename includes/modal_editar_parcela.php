<?php
// Modal de edição da parcela (botão de lápis). Quem inclui define $voltarEdicaoParcela
// ('aluno', 'matricula' ou 'lista'); cada botão traz os dados em data-parcela
// (ver dadosEdicaoParcela()).
?>
<div class="modal fade" id="modal-editar-parcela" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" action="/financeiro/contas_receber/editar.php" method="post">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-pencil"></i> Condições da parcela</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="ep-aviso-banco" class="alert alert-info small py-2 d-none">
                    <i class="bi bi-bank"></i> Esta parcela tem boleto/Pix registrado no Itaú. Mudanças de <strong>valor e vencimento</strong>
                    são enviadas ao banco ao salvar; o que o banco recusar não é alterado. Mudando o <strong>desconto</strong>, o boleto é
                    baixado e emitido de novo — o boleto e o Pix já enviados ao aluno deixam de valer.
                </div>
                <input type="hidden" name="id" id="ep-id">
                <input type="hidden" name="voltar" value="<?= e($voltarEdicaoParcela ?? 'lista') ?>">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label">Descrição</label>
                        <input type="text" name="descricao" id="ep-descricao" class="form-control" maxlength="255" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Valor</label>
                        <input type="text" name="valor" id="ep-valor" class="form-control" required oninput="resumoParcela()">
                    </div>
                    <div class="col-6">
                        <label class="form-label">Vencimento</label>
                        <input type="date" name="data_vencimento" id="ep-vencimento" class="form-control" required oninput="resumoParcela()">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Desconto</label>
                        <select name="desconto_modo" id="ep-modo" class="form-select" onchange="resumoParcela()">
                            <option value="padrao" id="ep-opcao-padrao">Regra da matrícula</option>
                            <option value="personalizado">Desconto só nesta parcela</option>
                            <option value="nenhum">Sem desconto</option>
                        </select>
                    </div>
                    <div class="col-6 ep-personalizado">
                        <label class="form-label">Desconto (R$)</label>
                        <input type="text" name="desconto_valor" id="ep-desconto" class="form-control" placeholder="0,00" oninput="resumoParcela()">
                    </div>
                    <div class="col-6 ep-personalizado">
                        <label class="form-label">Pagando até</label>
                        <input type="date" name="desconto_ate" id="ep-desconto-ate" class="form-control" oninput="resumoParcela()">
                        <div class="form-text">Em branco: até o vencimento.</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Observação</label>
                        <input type="text" name="observacao" id="ep-observacao" class="form-control" maxlength="255" placeholder="Ex.: desconto combinado com a coordenação">
                    </div>
                </div>
                <div class="alert alert-light border small mt-3 mb-0" id="ep-resumo"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-brand"><i class="bi bi-check-lg"></i> Salvar</button>
            </div>
        </form>
    </div>
</div>
<script>
let parcelaEmEdicao = null;
const moedaParcela = (v) => v.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
const numeroParcela = (t) => parseFloat(String(t || '').replace(/\./g, '').replace(',', '.')) || 0;
const dataParcela = (iso) => iso ? iso.split('-').reverse().join('/') : '';

function editarParcela(botao) {
    const p = JSON.parse(botao.dataset.parcela);
    parcelaEmEdicao = p;
    document.getElementById('ep-id').value = p.id;
    document.getElementById('ep-descricao').value = p.descricao;
    document.getElementById('ep-valor').value = p.valor.toFixed(2).replace('.', ',');
    document.getElementById('ep-vencimento').value = p.vencimento;
    document.getElementById('ep-observacao').value = p.observacao;
    document.getElementById('ep-aviso-banco').classList.toggle('d-none', !p.no_banco);
    const opcaoPadrao = document.getElementById('ep-opcao-padrao');
    opcaoPadrao.textContent = p.regra ? 'Regra da matrícula' : 'Sem desconto (padrão)';
    document.getElementById('ep-modo').value = p.desconto_valor === null ? 'padrao' : (p.desconto_valor > 0 ? 'personalizado' : 'nenhum');
    document.getElementById('ep-desconto').value = p.desconto_valor > 0 ? p.desconto_valor.toFixed(2).replace('.', ',') : '';
    document.getElementById('ep-desconto-ate').value = p.desconto_ate || '';
    resumoParcela();
    bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-editar-parcela')).show();
}

// Mostra como o aluno vai ver a cobrança com o que está digitado.
function resumoParcela() {
    if (!parcelaEmEdicao) return;
    const modo = document.getElementById('ep-modo').value;
    document.querySelectorAll('.ep-personalizado').forEach((el) => { el.style.display = modo === 'personalizado' ? '' : 'none'; });
    const valor = numeroParcela(document.getElementById('ep-valor').value);
    const venc = document.getElementById('ep-vencimento').value;
    let texto = 'Cobrança de <strong>' + moedaParcela(valor) + '</strong> com vencimento em <strong>' + dataParcela(venc) + '</strong>.';
    if (modo === 'personalizado') {
        const desconto = numeroParcela(document.getElementById('ep-desconto').value);
        const ate = document.getElementById('ep-desconto-ate').value || venc;
        if (desconto > 0 && desconto < valor) {
            texto += '<br>Pagando até <strong>' + dataParcela(ate) + '</strong>, paga <strong>' + moedaParcela(valor - desconto) + '</strong> (desconto de ' + moedaParcela(desconto) + ' · ' + (desconto / valor * 100).toFixed(1).replace('.', ',') + '%).';
        } else if (desconto >= valor) {
            texto += '<br><span class="text-danger">O desconto precisa ser menor que o valor.</span>';
        }
    } else if (modo === 'padrao' && parcelaEmEdicao.regra) {
        texto += '<br>Regra da matrícula: ' + escapeHtml(parcelaEmEdicao.regra) + ' <span class="text-muted">(calculada sobre o valor atual salvo)</span>';
    } else {
        texto += '<br>Sem desconto.';
    }
    document.getElementById('ep-resumo').innerHTML = texto;
}
</script>
