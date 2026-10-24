<?php
// Modal da parcela: abas Boleto (dados da parcela, desconto e o que está
// registrado no Itaú), Alterar (valor, vencimento, desconto, forma) e
// Histórico. Abre com abrirParcela(id, aba); o conteúdo vem de
// /financeiro/contas_receber/detalhes.php. Quem inclui define $voltarParcela
// ('aluno', 'matricula' ou 'lista'), para onde a tela volta depois de salvar.
// Com boleto já no banco, salvar pede confirmação antes (a mudança vai ao Itaú).
?>
<div class="modal fade" id="modal-parcela" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <div class="lh-sm">
                    <h5 class="modal-title" id="pa-titulo">Parcela</h5>
                    <div class="small text-muted" id="pa-subtitulo"></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="px-3 pt-2 border-bottom">
                <ul class="nav nav-tabs border-0" role="tablist">
                    <li class="nav-item"><button type="button" class="nav-link active" data-pa-aba="boleto"><i class="bi bi-upc-scan"></i> Boleto</button></li>
                    <li class="nav-item" id="pa-aba-alterar-item"><button type="button" class="nav-link" data-pa-aba="alterar"><i class="bi bi-pencil"></i> Alterar</button></li>
                    <li class="nav-item"><button type="button" class="nav-link" data-pa-aba="historico"><i class="bi bi-clock-history"></i> Histórico</button></li>
                </ul>
            </div>
            <div class="modal-body" style="min-height: 420px;">
                <div id="pa-carregando" class="text-center py-4"><div class="spinner-border text-secondary"></div></div>
                <div id="pa-erro" class="alert alert-danger mb-0 d-none">Não foi possível carregar a parcela.</div>
                <div class="pa-painel" data-pa-painel="boleto"></div>
                <div class="pa-painel" data-pa-painel="historico"></div>
                <form class="pa-painel" data-pa-painel="alterar" id="pa-form" action="/financeiro/contas_receber/editar.php" method="post">
                    <input type="hidden" name="id" id="ep-id">
                    <input type="hidden" name="voltar" class="pa-voltar">
                    <div id="pa-campos">
                        <div id="ep-aviso-banco" class="alert alert-info small py-2 d-none">
                            <i class="bi bi-bank"></i> Esta parcela tem boleto/Pix registrado no Itaú. Mudanças de <strong>valor e vencimento</strong>
                            vão para o banco ao salvar; o que o banco recusar não muda. Mudando o <strong>desconto</strong>, o boleto é
                            baixado e emitido de novo — o boleto e o Pix já enviados ao aluno deixam de valer.
                        </div>
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
                                <label class="form-label">Forma de pagamento</label>
                                <select name="forma_pagamento_id" id="ep-forma" class="form-select" onchange="resumoParcela()">
                                    <option value="">-</option>
                                    <?php foreach (getDB()->query("SELECT id, nome, instrumento FROM formas_pagamento WHERE ativo = 1 ORDER BY instrumento = 'manual', nome")->fetchAll() as $formaEp): ?>
                                        <option value="<?= (int) $formaEp['id'] ?>" data-online="<?= $formaEp['instrumento'] === 'manual' ? 0 : 1 ?>"><?= e(rotuloFormaPagamento($formaEp)) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text" id="ep-forma-aviso"></div>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Observação</label>
                                <input type="text" name="observacao" id="ep-observacao" class="form-control" maxlength="255" placeholder="Ex.: desconto combinado com a coordenação">
                            </div>
                        </div>
                        <div class="alert alert-light border small mt-3 mb-3" id="ep-resumo"></div>
                        <div class="text-end">
                            <button type="submit" class="btn btn-brand"><i class="bi bi-check-lg"></i> Salvar</button>
                        </div>
                    </div>
                    <div id="pa-confirmar-banco" class="d-none">
                        <div class="alert alert-warning">
                            <div class="fw-semibold mb-2"><i class="bi bi-bank"></i> Esta alteração vai para o boleto registrado no Itaú</div>
                            <ul class="mb-2" id="pa-lista-banco"></ul>
                            <div class="small" id="pa-aviso-reemitir"></div>
                        </div>
                        <div class="d-flex justify-content-end gap-2">
                            <button type="button" class="btn btn-outline-secondary" onclick="voltarEdicaoParcela()">Voltar</button>
                            <button type="submit" class="btn btn-warning" onclick="document.getElementById('pa-form').dataset.confirmado = '1'"><i class="bi bi-check-lg"></i> Confirmar e enviar ao banco</button>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer justify-content-between">
                <button type="button" class="btn btn-outline-secondary d-none" id="pa-abrir-boleto"><i class="bi bi-upc-scan"></i> Abrir boleto</button>
                <button type="button" class="btn btn-outline-secondary ms-auto" data-bs-dismiss="modal">Fechar</button>
            </div>
        </div>
    </div>
</div>
<script>
const voltarParcela = <?= json_encode($voltarParcela ?? 'lista') ?>;
let parcelaEmEdicao = null;
const moedaParcela = (v) => v.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
const numeroParcela = (t) => parseFloat(String(t || '').replace(/\./g, '').replace(',', '.')) || 0;
const dataParcela = (iso) => iso ? iso.split('-').reverse().join('/') : '';

function mostrarAbaParcela(aba) {
    if (aba === 'alterar' && !parcelaEmEdicao) aba = 'boleto';
    document.querySelectorAll('#modal-parcela [data-pa-aba]').forEach((b) => b.classList.toggle('active', b.dataset.paAba === aba));
    document.querySelectorAll('#modal-parcela .pa-painel').forEach((el) => el.classList.toggle('d-none', el.dataset.paPainel !== aba));
}
document.querySelectorAll('#modal-parcela [data-pa-aba]').forEach((b) => b.addEventListener('click', () => mostrarAbaParcela(b.dataset.paAba)));

// Abre o modal da parcela na aba pedida ('boleto', 'alterar' ou 'historico').
function abrirParcela(id, aba = 'boleto') {
    const modal = document.getElementById('modal-parcela');
    parcelaEmEdicao = null;
    document.getElementById('pa-titulo').textContent = 'Parcela';
    document.getElementById('pa-subtitulo').textContent = '';
    document.getElementById('pa-carregando').classList.remove('d-none');
    document.getElementById('pa-erro').classList.add('d-none');
    document.getElementById('pa-abrir-boleto').classList.add('d-none');
    modal.querySelectorAll('.pa-painel').forEach((el) => el.classList.add('d-none'));
    voltarEdicaoParcela();
    bootstrap.Modal.getOrCreateInstance(modal).show();
    fetch('/financeiro/contas_receber/detalhes.php?id=' + encodeURIComponent(id), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then((r) => r.ok ? r.json() : Promise.reject())
        .then((d) => {
            document.getElementById('pa-carregando').classList.add('d-none');
            document.getElementById('pa-titulo').textContent = d.titulo;
            document.getElementById('pa-subtitulo').textContent = d.subtitulo;
            modal.querySelector('[data-pa-painel="boleto"]').innerHTML = d.boleto;
            modal.querySelector('[data-pa-painel="historico"]').innerHTML = d.historico;
            modal.querySelectorAll('.pa-voltar').forEach((el) => { el.value = voltarParcela; });
            document.getElementById('pa-aba-alterar-item').classList.toggle('d-none', !d.editar);
            const botaoBoleto = document.getElementById('pa-abrir-boleto');
            botaoBoleto.classList.toggle('d-none', !d.pode_abrir_boleto || typeof abrirBoleto !== 'function');
            botaoBoleto.onclick = () => abrirBoleto(id);
            if (d.editar) preencherEdicaoParcela(d.editar);
            mostrarAbaParcela(aba);
        })
        .catch(() => {
            document.getElementById('pa-carregando').classList.add('d-none');
            document.getElementById('pa-erro').classList.remove('d-none');
        });
}

function copiarCampoParcela(botao) {
    const campo = botao.parentElement.querySelector('input');
    navigator.clipboard.writeText(campo.value).then(() => {
        botao.innerHTML = '<i class="bi bi-check-lg"></i>';
        setTimeout(() => { botao.innerHTML = '<i class="bi bi-clipboard"></i>'; }, 1500);
    });
}

function preencherEdicaoParcela(p) {
    parcelaEmEdicao = p;
    document.getElementById('ep-id').value = p.id;
    document.getElementById('ep-descricao').value = p.descricao;
    document.getElementById('ep-valor').value = p.valor.toFixed(2).replace('.', ',');
    document.getElementById('ep-vencimento').value = p.vencimento;
    document.getElementById('ep-observacao').value = p.observacao;
    document.getElementById('ep-forma').value = p.forma || '';
    document.getElementById('ep-aviso-banco').classList.toggle('d-none', !p.no_banco);
    document.getElementById('ep-opcao-padrao').textContent = p.regra ? 'Regra da matrícula' : 'Sem desconto (padrão)';
    document.getElementById('ep-modo').value = p.desconto_valor === null ? 'padrao' : (p.desconto_valor > 0 ? 'personalizado' : 'nenhum');
    document.getElementById('ep-desconto').value = p.desconto_valor > 0 ? p.desconto_valor.toFixed(2).replace('.', ',') : '';
    document.getElementById('ep-desconto-ate').value = p.desconto_ate || '';
    resumoParcela();
}

// O que foi mexido e chega ao boleto do banco (valor, vencimento, desconto, forma).
function mudancasBancoParcela() {
    const p = parcelaEmEdicao;
    const lista = [];
    const valor = numeroParcela(document.getElementById('ep-valor').value);
    const venc = document.getElementById('ep-vencimento').value;
    const modo = document.getElementById('ep-modo').value;
    const desconto = modo === 'personalizado' ? numeroParcela(document.getElementById('ep-desconto').value) : (modo === 'nenhum' ? 0 : null);
    const ate = modo === 'personalizado' ? (document.getElementById('ep-desconto-ate').value || null) : null;
    let reemitir = false;
    if (Math.abs(valor - p.valor) > 0.001) lista.push('Valor: ' + moedaParcela(p.valor) + ' → <strong>' + moedaParcela(valor) + '</strong>');
    if (venc !== p.vencimento) lista.push('Vencimento: ' + dataParcela(p.vencimento) + ' → <strong>' + dataParcela(venc) + '</strong>');
    const descAntes = p.desconto_valor === null ? null : p.desconto_valor;
    const semDesconto = (d) => d === 0 || (d === null && !p.regra);
    if (!(semDesconto(descAntes) && semDesconto(desconto)) && (descAntes !== desconto || (desconto > 0 && (p.desconto_ate || null) !== ate))) {
        const texto = (d, a) => d === null ? 'regra da matrícula' : (d > 0 ? moedaParcela(d) + ' até ' + (a ? dataParcela(a) : 'o vencimento') : 'sem desconto');
        lista.push('Desconto: ' + texto(descAntes, p.desconto_ate) + ' → <strong>' + texto(desconto, ate) + '</strong>');
        reemitir = true;
    }
    const forma = document.getElementById('ep-forma');
    if (String(forma.value) !== String(p.forma || '')) {
        const opcao = forma.options[forma.selectedIndex];
        lista.push('Forma de pagamento: <strong>' + escapeHtml(opcao ? opcao.textContent : '-') + '</strong>'
            + (opcao && opcao.dataset.online === '0' ? ' — o boleto/Pix será <strong>baixado</strong> no banco' : ''));
    }
    return { lista, reemitir };
}

function voltarEdicaoParcela() {
    document.getElementById('pa-campos').classList.remove('d-none');
    document.getElementById('pa-confirmar-banco').classList.add('d-none');
    delete document.getElementById('pa-form').dataset.confirmado;
}

// Com boleto no banco, salvar mostra antes o que vai mudar no Itaú.
document.getElementById('pa-form').addEventListener('submit', (ev) => {
    const form = ev.target;
    if (!parcelaEmEdicao || !parcelaEmEdicao.no_banco || form.dataset.confirmado) return;
    const { lista, reemitir } = mudancasBancoParcela();
    if (!lista.length) return;
    ev.preventDefault();
    document.getElementById('pa-lista-banco').innerHTML = lista.map((t) => '<li>' + t + '</li>').join('');
    document.getElementById('pa-aviso-reemitir').innerHTML = reemitir
        ? 'Para mudar o desconto, o boleto atual é <strong>baixado e emitido de novo</strong>: o boleto e o Pix já enviados ao aluno deixam de valer — mande o novo.'
        : 'O que o banco recusar não é alterado aqui (sistema e boleto ficam sempre iguais).';
    document.getElementById('pa-campos').classList.add('d-none');
    document.getElementById('pa-confirmar-banco').classList.remove('d-none');
});

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
    // Trocar para cartão/dinheiro com boleto no banco: o boleto é baixado.
    const forma = document.getElementById('ep-forma');
    const opcao = forma.options[forma.selectedIndex];
    const mudou = String(forma.value) !== String(parcelaEmEdicao.forma || '');
    document.getElementById('ep-forma-aviso').textContent = mudou && opcao && opcao.dataset.online === '0'
        ? (parcelaEmEdicao.no_banco ? 'O boleto/Pix desta parcela será baixado no banco. No portal, o aluno verá "pague na secretaria".' : 'No portal, o aluno verá "pague na secretaria".')
        : '';
}
</script>
