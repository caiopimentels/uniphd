<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
exigirInadimplencia();

// Dados do modal da parcela (clique na parcela ou no lápis): a aba Boleto
// (parcela, desconto que vale e o que está registrado no Itaú), os dados
// para a aba Alterar e a aba Histórico, tudo em JSON.
header('Content-Type: application/json; charset=utf-8');

$pdo = getDB();
$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare(
    'SELECT cr.*, ' . SQL_BOLETO_NO_BANCO . ' AS boleto_no_banco, a.nome AS aluno_nome, fp.nome AS forma_nome, cf.nome AS categoria_nome, u.nome AS lancado_por
     FROM contas_receber cr
     LEFT JOIN alunos a ON a.id = cr.aluno_id
     LEFT JOIN formas_pagamento fp ON fp.id = cr.forma_pagamento_id
     LEFT JOIN categorias_financeiras cf ON cf.id = cr.categoria_id
     LEFT JOIN usuarios u ON u.id = cr.usuario_id
     WHERE cr.id = ?'
);
$stmt->execute([$id]);
$p = $stmt->fetch();
if (!$p) {
    http_response_code(404);
    echo json_encode(['erro' => 'Parcela não encontrada.']);
    exit;
}

$hoje = date('Y-m-d');
$vencida = $p['status'] === 'pendente' && $p['data_vencimento'] < $hoje;
$podeAlterar = $p['status'] === 'pendente' && !$p['excluido_em'] && usuarioPodeEditarFinanceiroAluno();

// Último boleto/Pix da parcela no banco (o que vale, ou o último que houve).
$stmt = $pdo->prepare(
    "SELECT cb.*, u.nome AS usuario_nome FROM cobrancas_bancarias cb LEFT JOIN usuarios u ON u.id = cb.usuario_id
     WHERE cb.conta_receber_id = ? ORDER BY cb.status IN ('registrada', 'paga', 'pendente_envio') DESC, cb.id DESC LIMIT 1"
);
$stmt->execute([$id]);
$cobranca = $stmt->fetch() ?: null;
$payload = $cobranca ? (json_decode((string) $cobranca['payload'], true) ?: []) : [];
$dadoBoleto = $payload['dado_boleto'] ?? [];
$individual = $dadoBoleto['dados_individuais_boleto'][0] ?? [];

// Desconto que vale hoje no sistema e o que foi gravado no boleto.
$desconto = descontoPontualidadeParcela($pdo, $p);
$origemDesconto = $p['desconto_valor'] !== null
    ? ((float) $p['desconto_valor'] > 0 ? 'Desconto só nesta parcela' : 'Sem desconto nesta parcela')
    : ($desconto ? 'Regra da matrícula' : '');
$descontoBanco = $cobranca ? descontoDoBlocoItau($dadoBoleto['desconto'] ?? null) : null;
// Diferença entre o banco e o sistema (só em boleto valendo de parcela que
// ainda não venceu — a vencida já foi ao banco sem desconto).
$descontoDiferente = $cobranca && $cobranca['status'] === 'registrada' && !$vencida
    && descontoDoBlocoItau(blocoDescontoItau($desconto)) != $descontoBanco;

$textoDesconto = function (?array $d, float $valor): string {
    if (!$d) {
        return 'Sem desconto';
    }
    $reais = $d['tipo'] === 'valor' ? $d['valor'] : round($valor * $d['valor'] / 100, 2);
    return formatMoney($reais) . ($d['tipo'] === 'percentual' ? ' (' . formatPercent($d['valor']) . ')' : '')
        . ' pagando até ' . formatDate($d['data']) . ' — paga ' . formatMoney($valor - $reais);
};

$statusBanco = [
    'pendente_envio' => ['Enviando ao banco', 'text-bg-warning'],
    'registrada' => ['Registrado no Itaú', 'text-bg-success'],
    'paga' => ['Pago pelo banco', 'text-bg-primary'],
    'baixada' => ['Baixado (cancelado)', 'text-bg-secondary'],
    'erro' => ['Erro no envio', 'text-bg-danger'],
];
$juros = $dadoBoleto['juros'] ?? null;
$multa = $dadoBoleto['multa'] ?? null;
$textoJuros = $juros && ($juros['codigo_tipo_juros'] ?? '05') !== '05' && (float) ($juros['percentual_juros'] ?? 0) > 0
    ? formatPercent((float) $juros['percentual_juros']) . ' ' . (['90' => 'ao mês', '91' => 'ao dia', '92' => 'ao ano'][$juros['codigo_tipo_juros']] ?? '') . ' a partir de ' . formatDate($juros['data_juros'] ?? null)
    : 'Sem juros';
$textoMulta = $multa && ($multa['codigo_tipo_multa'] ?? '03') === '02' && (float) ($multa['percentual_multa'] ?? 0) > 0
    ? formatPercent((float) $multa['percentual_multa']) . ' a partir de ' . formatDate($multa['data_multa'] ?? null)
    : 'Sem multa';

$campo = fn (string $rotulo, string $html, string $classe = 'col-sm-6') => '<div class="' . $classe . '"><div class="kpi-rotulo">' . e($rotulo) . '</div><div>' . $html . '</div></div>';
$copiar = fn (string $texto) => '<div class="input-group input-group-sm"><input type="text" class="form-control font-monospace" readonly value="' . e($texto) . '">'
    . '<button type="button" class="btn btn-outline-secondary" onclick="copiarCampoParcela(this)" title="Copiar"><i class="bi bi-clipboard"></i></button></div>';

// Linha digitável de boleto (47 dígitos) no formato impresso.
$linhaFormatada = fn (string $d) => strlen($d) === 47
    ? substr($d, 0, 5) . '.' . substr($d, 5, 5) . ' ' . substr($d, 10, 5) . '.' . substr($d, 15, 6) . ' ' . substr($d, 21, 5) . '.' . substr($d, 26, 6) . ' ' . $d[32] . ' ' . substr($d, 33)
    : $d;

ob_start();
?>
<div class="row g-3 mb-3">
    <?= $campo('Aluno', e($p['aluno_nome'] ?: '-')) ?>
    <?= $campo('Situação', $vencida ? '<span class="badge text-bg-danger">Em atraso</span>' : '<span class="badge ' . contaStatusBadgeClass(statusTelaParcela($p, false)) . '">' . contaStatusLabel(statusTelaParcela($p, false)) . '</span>'
        . ($p['status'] === 'pago' ? ' <span class="small text-success fw-semibold">' . formatMoney($p['valor_recebido'] ?? $p['valor']) . ' em ' . formatDate($p['data_recebimento']) . '</span>' : '')) ?>
    <?= $campo('Vencimento', formatDate($p['data_vencimento']), 'col-sm-4') ?>
    <?= $campo('Valor', '<strong>' . formatMoney($p['valor']) . '</strong>', 'col-sm-4') ?>
    <?= $campo('Forma de pagamento', e($p['forma_nome'] ?: '-'), 'col-sm-4') ?>
    <?= $campo('Desconto', e($textoDesconto(descontoDoBlocoItau(blocoDescontoItau($desconto)), (float) $p['valor']))
        . ($origemDesconto ? '<div class="small text-muted">' . e($origemDesconto) . '</div>' : '')
        . ($desconto && $desconto['data_limite'] < $hoje && $p['status'] === 'pendente' ? '<div class="small text-danger">O prazo do desconto já passou.</div>' : ''), 'col-12') ?>
    <?php if ($p['observacao']): ?><?= $campo('Observação', e($p['observacao']), 'col-12') ?><?php endif; ?>
    <?= $campo('Categoria', e($p['categoria_nome'] ?: '-')) ?>
    <?= $campo('Lançada em', formatDate($p['data_emissao']) . ($p['lancado_por'] ? ' por ' . e($p['lancado_por']) : '')) ?>
</div>

<div class="border-top pt-3">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
        <div class="fw-semibold"><i class="bi bi-bank"></i> No banco (Itaú)</div>
        <?php if ($cobranca): ?><span class="badge <?= $statusBanco[$cobranca['status']][1] ?>"><?= $statusBanco[$cobranca['status']][0] ?></span><?php endif; ?>
    </div>
    <?php if (!$cobranca): ?>
        <div class="text-muted small">Nenhum boleto ou Pix foi gerado no banco para esta parcela.</div>
    <?php else: ?>
        <?php if ($descontoDiferente): ?>
            <div class="alert alert-warning small py-2">
                <i class="bi bi-exclamation-triangle"></i> <strong>O desconto do boleto no Itaú está diferente do sistema.</strong>
                No banco: <?= e($textoDesconto($descontoBanco, (float) $cobranca['valor'])) ?>.
                <?php if ($podeAlterar): ?>
                    <div class="mt-2" id="pa-reemitir">
                        <button type="button" class="btn btn-sm btn-warning" onclick="document.getElementById('pa-reemitir-confirmar').classList.remove('d-none'); this.classList.add('d-none');">
                            <i class="bi bi-arrow-repeat"></i> Emitir de novo com o desconto do sistema</button>
                        <form id="pa-reemitir-confirmar" class="d-none" action="/financeiro/contas_receber/reemitir_boleto.php" method="post">
                            <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                            <input type="hidden" name="voltar" class="pa-voltar">
                            <div class="mb-2">O boleto atual será <strong>baixado no Itaú</strong> e um novo será emitido com o desconto do sistema. O boleto e o Pix já enviados ao aluno deixam de valer.</div>
                            <button type="submit" class="btn btn-sm btn-warning"><i class="bi bi-check-lg"></i> Confirmar e enviar ao banco</button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <div class="row g-3">
            <?= $campo('Nosso número', e(formatarNossoNumeroItau((int) $cobranca['nosso_numero'])), 'col-sm-4') ?>
            <?= $campo('Vencimento do boleto', formatDate($cobranca['data_vencimento']) . ($cobranca['data_vencimento'] !== $p['data_vencimento'] ? '<div class="small text-muted">parcela vencida: boleto com data nova</div>' : ''), 'col-sm-4') ?>
            <?= $campo('Valor do boleto', '<strong>' . formatMoney($cobranca['valor']) . '</strong>', 'col-sm-4') ?>
            <?= $campo('Desconto no boleto', e($textoDesconto($descontoBanco, (float) $cobranca['valor'])), 'col-12') ?>
            <?= $campo('Juros por atraso', e($textoJuros)) ?>
            <?= $campo('Multa', e($textoMulta)) ?>
            <?php if (!empty($individual['data_limite_pagamento'])): ?><?= $campo('Pode pagar até', formatDate($individual['data_limite_pagamento'])) ?><?php endif; ?>
            <?= $campo('Gerado em', formatDateTime($cobranca['criado_em']) . ($cobranca['usuario_nome'] ? ' por ' . e($cobranca['usuario_nome']) : '')) ?>
            <?php if ($cobranca['linha_digitavel']): ?><?= $campo('Linha digitável', $copiar($linhaFormatada($cobranca['linha_digitavel'])), 'col-12') ?><?php endif; ?>
            <?php if ($cobranca['pix_copia_cola']): ?><?= $campo('Pix copia e cola', $copiar($cobranca['pix_copia_cola']), 'col-12') ?><?php endif; ?>
            <?php if ($cobranca['status'] === 'erro' && $cobranca['mensagem_erro']): ?><?= $campo('Erro', '<span class="text-danger small">' . e($cobranca['mensagem_erro']) . '</span>', 'col-12') ?><?php endif; ?>
        </div>
    <?php endif; ?>
</div>
<?php
$htmlBoleto = ob_get_clean();

ob_start();
?>
<ul class="list-unstyled mb-0 historico-parcela">
    <?php foreach (historicoParcela($pdo, $id) as $h): ?>
        <li class="d-flex gap-2 py-2 border-bottom">
            <i class="bi <?= e($h['icone']) ?> text-<?= e($h['cor']) ?>"></i>
            <div class="flex-grow-1 lh-sm">
                <div class="d-flex justify-content-between gap-2">
                    <strong><?= e($h['titulo']) ?></strong>
                    <span class="small text-muted text-nowrap"><?= formatDateTime($h['quando']) ?></span>
                </div>
                <div class="small text-muted"><?= $h['detalhe'] !== '' ? e($h['detalhe']) . ' · ' : '' ?>por <?= e($h['quem']) ?></div>
            </div>
        </li>
    <?php endforeach; ?>
</ul>
<?php
$htmlHistorico = ob_get_clean();

echo json_encode([
    'titulo' => $p['descricao'],
    'subtitulo' => trim(($p['aluno_nome'] ?: '') . ' · vence ' . formatDate($p['data_vencimento']) . ' · ' . formatMoney($p['valor']), ' ·'),
    'boleto' => $htmlBoleto,
    'historico' => $htmlHistorico,
    'editar' => $podeAlterar ? dadosEdicaoParcela($pdo, $p) : null,
    'pode_abrir_boleto' => $p['status'] === 'pendente' && meiosCobrancaForma($pdo, $p['forma_pagamento_id'] ? (int) $p['forma_pagamento_id'] : null)['boleto'],
], JSON_UNESCAPED_UNICODE);
