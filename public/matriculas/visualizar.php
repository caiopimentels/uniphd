<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();

// A matrícula é vista tanto por quem cuida das turmas quanto por quem
// cuida dos alunos — qualquer um dos dois acessos basta.
if (!usuarioPodeVerCursos() && !usuarioPodeVerAlunos()) {
    flash('danger', 'Seu perfil não tem acesso a matrículas.');
    redirect('/sem_acesso.php');
}

$pdo = getDB();
$id = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare(
    'SELECT m.*, a.nome AS aluno_nome, a.ra AS aluno_ra, a.cpf AS aluno_cpf, a.telefone AS aluno_telefone,
            t.nome AS turma_nome, t.data_inicio, t.data_termino, t.minimo_alunos, t.duracao_unidade, c.nome AS curso_nome,
            fp.nome AS forma_pagamento_nome, fm.nome AS forma_mensalidade_nome, u.nome AS usuario_nome
     FROM matriculas m
     JOIN alunos a ON a.id = m.aluno_id
     JOIN turmas t ON t.id = m.turma_id
     JOIN cursos c ON c.id = t.curso_id
     LEFT JOIN formas_pagamento fp ON fp.id = m.forma_pagamento_id
     LEFT JOIN formas_pagamento fm ON fm.id = m.forma_mensalidade_id
     LEFT JOIN usuarios u ON u.id = m.usuario_id
     WHERE m.id = ?'
);
$stmt->execute([$id]);
$matricula = $stmt->fetch();
if ($matricula) {
    sincronizarDocumentosMatricula($pdo, (int) $matricula['id']);
}

if (!$matricula) {
    flash('danger', 'Matrícula não encontrada.');
    redirect('/turmas/listar.php');
}

$podeVerFinanceiro = usuarioPodeVerInadimplencia();
$podeGerenciar = usuarioPodeGerenciarMatriculas();

$parcelas = [];
if ($podeVerFinanceiro) {
    $stmt = $pdo->prepare(
        'SELECT cr.*, fp.nome AS forma_pagamento_nome, ' . SQL_BAIXA_PELO_BANCO . ' AS pago_pelo_banco, ' . SQL_BOLETO_NO_BANCO . ' AS boleto_no_banco
         FROM contas_receber cr
         LEFT JOIN formas_pagamento fp ON fp.id = cr.forma_pagamento_id
         WHERE cr.matricula_id = ? AND cr.excluido_em IS NULL
         ORDER BY cr.data_vencimento, cr.id'
    );
    $stmt->execute([$id]);
    $parcelas = $stmt->fetchAll();
}

$stmt = $pdo->prepare("SELECT COUNT(*) FROM contas_receber WHERE matricula_id = ? AND status = 'pago'");
$stmt->execute([$id]);
$temPagamento = (int) $stmt->fetchColumn() > 0;

$mensalidadeFinal = valorMensalidadeComDesconto((float) $matricula['valor_mensalidade'], (float) $matricula['desconto_percentual']);
$hoje = date('Y-m-d');

// Cupom: aqui aparece o valor cheio, com o valor depois do cupom embaixo
// (os valores gravados na matrícula já vêm com o desconto).
$tipoCupom = null;
if ($matricula['cupom_id'] && (float) $matricula['cupom_desconto'] > 0) {
    $stmt = $pdo->prepare('SELECT tipo FROM cupons WHERE id = ?');
    $stmt->execute([$matricula['cupom_id']]);
    $tipoCupom = $stmt->fetchColumn() ?: null;
}
$cupomNoCurso = $tipoCupom && $tipoCupom !== 'matricula_zero';
$mensalidadeCheia = $cupomNoCurso ? round($mensalidadeFinal + (float) $matricula['cupom_desconto'] / max(1, (int) $matricula['num_parcelas']), 2) : $mensalidadeFinal;

$pageTitle = 'Matrícula · ' . e($matricula['aluno_nome']);
$pageBreadcrumb = 'Acadêmico · ' . $matricula['curso_nome'] . ' · ' . $matricula['turma_nome'];
$activeMenu = 'turmas';
$pageAction = '<a href="/turmas/visualizar.php?id=' . (int) $matricula['turma_id'] . '" class="btn btn-outline-secondary">Ver turma</a>'
    . '<a href="/alunos/form.php?id=' . (int) $matricula['aluno_id'] . '" class="btn btn-outline-secondary">Ver aluno</a>'
    . '<a href="/matriculas/comprovante.php?id=' . (int) $id . '" target="_blank" class="btn btn-outline-secondary"><i class="bi bi-printer"></i> Comprovante</a>'
    . '<a href="/matriculas/contrato.php?id=' . (int) $id . '" target="_blank" class="btn btn-outline-secondary"><i class="bi bi-file-earmark-text"></i> Contrato</a>'
    . ($matricula['status'] === 'concluida' ? '<a href="#documentos" class="btn btn-brand"><i class="bi bi-award"></i> Documentos</a>' : '');
require __DIR__ . '/../../includes/layout_header.php';
?>

<div class="row g-4">
<div class="col-lg-8">
    <div class="secao">
        <div class="secao-titulo">
            <i class="bi bi-mortarboard"></i> Dados da matrícula
            <span class="badge <?= matriculaStatusBadgeClass($matricula['status']) ?> ms-2"><?= matriculaStatusLabel($matricula['status']) ?></span>
        </div>
        <?php if ($matricula['status'] === 'pendente'): ?>
            <div class="alert alert-warning">
                <?php if ($matricula['lista_espera']): ?>
                    <strong>O aluno pagou a inscrição, mas a turma estava cheia.</strong> Abra uma vaga (aumente o nº de alunos da turma) e confirme abaixo, ou cancele e devolva o valor.
                <?php else: ?>
                    <strong>Inscrição pelo portal aguardando pagamento</strong> até <?= formatDateTime($matricula['pagamento_ate']) ?>.
                    <?= $matricula['segura_vaga'] ? 'A vaga está reservada até lá.' : 'A vaga só é garantida com o pagamento.' ?>
                    Quando a primeira parcela for paga (pelo banco ou pela baixa manual), a matrícula é confirmada sozinha.
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <?php if ($matricula['status'] === 'trancada'): ?>
            <div class="alert alert-info">
                <strong><i class="bi bi-pause-circle"></i> Matrícula trancada</strong> desde <?= formatDate($matricula['data_trancamento']) ?><?= $matricula['trancamento_previsao'] ? ' · volta prevista em <strong>' . formatDate($matricula['trancamento_previsao']) . '</strong>' : '' ?>.
                <?= $matricula['trancamento_motivo'] ? '<div class="small mt-1">Motivo: ' . e($matricula['trancamento_motivo']) . '</div>' : '' ?>
                <div class="small mt-1 text-muted">
                    <?= $matricula['trancamento_vaga'] ? 'A vaga na turma está guardada.' : 'A vaga na turma foi liberada.' ?>
                    <?= ['suspender' => 'Mensalidades futuras suspensas — voltam ao destrancar.', 'cancelar' => 'Mensalidades futuras canceladas no trancamento.', 'continuar' => 'Mensalidades continuam sendo cobradas.'][$matricula['trancamento_parcelas']] ?? '' ?>
                </div>
            </div>
        <?php endif; ?>
        <div class="card"><div class="card-body">
            <div class="row g-3">
                <div class="col-md-6"><div class="kpi-rotulo">Aluno</div><div><a href="/alunos/form.php?id=<?= (int) $matricula['aluno_id'] ?>"><?= e($matricula['aluno_nome']) ?></a><?= $matricula['aluno_ra'] ? ' · ' . e($matricula['aluno_ra']) : '' ?><?= $matricula['aluno_cpf'] ? ' · ' . e(cpfFormatado($matricula['aluno_cpf'])) : '' ?></div></div>
                <div class="col-md-6"><div class="kpi-rotulo">Turma</div><div><a href="/turmas/visualizar.php?id=<?= (int) $matricula['turma_id'] ?>"><?= e($matricula['curso_nome']) ?> · <?= e($matricula['turma_nome']) ?></a></div></div>
                <div class="col-md-3"><div class="kpi-rotulo">Data da matrícula</div><div><?= formatDate($matricula['data_matricula']) ?></div>
                    <div class="small text-muted">lançada em <?= formatDateTime($matricula['criado_em']) ?></div></div>
                <div class="col-md-3"><div class="kpi-rotulo">Período da turma</div><div><?= e(periodoTurma($matricula)) ?></div></div>
                <div class="col-md-3"><div class="kpi-rotulo">Mensalidade</div><div><?= formatMoney($mensalidadeCheia) ?><?php if ((float) $matricula['desconto_percentual'] > 0): ?> <span class="small text-muted">(<?= formatMoney($matricula['valor_mensalidade']) ?> − <?= formatPercent($matricula['desconto_percentual']) ?>)</span><?php endif; ?><?php if ($cupomNoCurso): ?><div class="small text-success"><?= formatMoney($mensalidadeFinal) ?> com o cupom</div><?php endif; ?></div></div>
                <div class="col-md-3"><div class="kpi-rotulo">Mensalidades</div><div><?= $matricula['pagamento_avista'] ? '<strong>À vista</strong> em ' . formatDate($matricula['primeiro_vencimento']) : (int) $matricula['num_parcelas'] . '× a partir de ' . formatDate($matricula['primeiro_vencimento']) ?><?php if ($matricula['valor_total'] !== null): ?><div class="small text-muted">curso: <?= formatMoney((float) $matricula['valor_total'] + ($cupomNoCurso ? (float) $matricula['cupom_desconto'] : 0)) ?><?= $cupomNoCurso ? ' · <span class="text-success">' . formatMoney($matricula['valor_total']) . ' com o cupom</span>' : '' ?></div><?php endif; ?></div></div>
                <div class="col-md-3"><div class="kpi-rotulo">Taxa de matrícula</div><div><?php if ($tipoCupom === 'matricula_zero'): ?><?= formatMoney($matricula['cupom_desconto']) ?><div class="small text-success">isenta com o cupom</div><?php else: ?><?= (float) $matricula['taxa_matricula'] > 0 ? formatMoney($matricula['taxa_matricula']) : 'Sem taxa' ?><?php endif; ?></div></div>
                <div class="col-md-6"><div class="kpi-rotulo">Desconto por pontualidade</div><div><?= e(textoDescontoPontualidade($matricula)) ?></div></div>
                <?php if ($textoCupom = resumoCupomMatricula($pdo, $matricula)): ?>
                <div class="col-md-6"><div class="kpi-rotulo">Cupom</div><div class="text-success"><i class="bi bi-ticket-perforated"></i> <?= e($textoCupom) ?></div></div>
                <?php endif; ?>
                <div class="col-md-3"><div class="kpi-rotulo">Formas de pagamento</div><div>
                    <?php if ((float) $matricula['taxa_matricula'] > 0): ?>Matrícula: <?= e($matricula['forma_pagamento_nome'] ?: '-') ?><br><?php endif; ?>
                    Mensalidades: <?= e(($matricula['forma_mensalidade_nome'] ?: $matricula['forma_pagamento_nome']) ?: '-') ?>
                    <div class="small text-muted">Cada parcela pode mudar em Contas a receber.</div>
                </div></div>
                <div class="col-md-3"><div class="kpi-rotulo">Matriculado por</div><div><?= $matricula['origem'] === 'portal' ? '<i class="bi bi-globe2"></i> O aluno, pelo portal' : e($matricula['usuario_nome'] ?: '-') ?></div></div>
                <?php if ($matricula['data_cancelamento']): ?>
                    <div class="col-md-3"><div class="kpi-rotulo">Cancelada em</div><div><?= formatDate($matricula['data_cancelamento']) ?></div></div>
                <?php endif; ?>
                <?php if ($matricula['observacao']): ?>
                    <div class="col-12"><div class="kpi-rotulo">Observação</div><div><?= e($matricula['observacao']) ?></div></div>
                <?php endif; ?>
            </div>
        </div></div>
    </div>

    <?php if ($podeVerFinanceiro): ?>
    <div class="secao">
        <div class="secao-titulo"><i class="bi bi-cash-coin"></i> Parcelas</div>
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead><tr><th>Descrição</th><th>Vencimento</th><th class="text-end">Valor</th><th>Pago em</th><th class="text-end">Valor pago</th><th>Status</th><th class="text-end">Ações</th></tr></thead>
                    <tbody>
                    <?php if (!$parcelas): ?>
                        <tr><td colspan="7" class="text-center text-muted py-3">Nenhuma parcela gerada.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($parcelas as $p): ?>
                        <?php $vencida = $p['status'] === 'pendente' && $p['data_vencimento'] < $hoje; ?>
                        <tr class="cursor-pointer" onclick="abrirParcela(<?= (int) $p['id'] ?>)" title="Clique para ver o boleto, alterar e ver o histórico">
                            <td><?= e($p['descricao']) ?><?php if ($seloBanco = seloBancoParcela($pdo, (int) $p['id'])): ?><div class="mt-1"><?= $seloBanco ?></div><?php endif; ?></td>
                            <td class="<?= $vencida ? 'estoque-baixo' : '' ?>"><?= formatDate($p['data_vencimento']) ?></td>
                            <td class="text-end"><?= formatMoney($p['valor']) ?></td>
                            <td>
                            <?= $p['status'] === 'pago' ? formatDate($p['data_recebimento']) . ($p['forma_pagamento_nome'] ? ' · ' . e($p['forma_pagamento_nome']) : '') : '-' ?>
                            <?php if ($p['status'] === 'pendente' && $p['desconto_valor'] !== null): ?>
                        <div class="small <?= (float) $p['desconto_valor'] > 0 ? 'text-success' : 'text-muted' ?>"><?= (float) $p['desconto_valor'] > 0 ? '− ' . formatMoney($p['desconto_valor']) . ' até ' . formatDate($p['desconto_ate'] ?: $p['data_vencimento']) : 'sem desconto' ?></div>
                    <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <?= $p['status'] === 'pago' ? '<span class="text-success fw-semibold">' . formatMoney($p['valor_recebido'] ?? $p['valor']) . '</span>' : '-' ?>
                        </td>
                            <td>
                                <?php if ($vencida): ?>
                                    <span class="badge text-bg-danger">Em atraso</span>
                                <?php else: ?>
                                    <span class="badge <?= contaStatusBadgeClass(statusTelaParcela($p, false)) ?>"><?= contaStatusLabel(statusTelaParcela($p, false)) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end text-nowrap" onclick="event.stopPropagation();">
                                <?php if ($p['status'] === 'pendente'): ?>
                                    <?= botoesCobrancaParcela($pdo, $p) ?>
                                <?php endif; ?>
                                <?php if ($p['status'] === 'pendente' && usuarioPodeBaixarReceber()): ?>
                                    <a href="/financeiro/contas_receber/baixar.php?id=<?= (int) $p['id'] ?>&voltar=matricula" class="btn btn-sm btn-outline-success" title="Dar baixa (receber)"><i class="bi bi-check-lg"></i></a>
                                <?php endif; ?>
                                <?php if ($p['status'] === 'pago'): ?><a href="/financeiro/contas_receber/recibo.php?id=<?= (int) $p['id'] ?>&voltar=matricula" target="_blank" class="btn btn-sm btn-outline-secondary" title="Imprimir recibo"><i class="bi bi-receipt"></i></a><?php endif; ?>
                            <?php if ($p['status'] === 'pago' && !$p['pago_pelo_banco'] && usuarioPodeBaixarReceber()): ?>
                                <form action="/financeiro/contas_receber/estornar.php" method="post" class="d-inline" onsubmit="return confirm(<?= e(json_encode('Estornar a baixa de ' . $p['descricao'] . '? A parcela volta a ficar em aberto, como se não tivesse sido paga.')) ?>);">
                                    <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                                    <input type="hidden" name="voltar" value="matricula">
                                    <button type="submit" class="btn btn-sm btn-outline-warning" title="Estornar baixa (lançada errado)"><i class="bi bi-arrow-counterclockwise"></i></button>
                                </form>
                            <?php elseif ($p['status'] === 'pago' && $p['pago_pelo_banco']): ?>
                                <span class="btn btn-sm acao-inativa" title="Pago por boleto/Pix pelo banco — não pode ser estornado"><i class="bi bi-bank"></i></span>
                            <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<div class="col-lg-4">
    <?php if (usuarioPodeEditarFinanceiroAluno() && $matricula['status'] === 'ativa'): ?>
    <div class="secao">
        <div class="secao-titulo"><i class="bi bi-alarm"></i> Desconto por pontualidade</div>
        <div class="card"><div class="card-body">
            <?php $boletosPontualidade = count(boletosSeguemPontualidade($pdo, (int) $id)); ?>
            <form action="/matriculas/alterar_pontualidade.php" method="post" id="form-pontualidade" data-boletos="<?= $boletosPontualidade ?>">
                <input type="hidden" name="id" value="<?= (int) $id ?>">
                <div class="row g-2">
                    <div class="col-12">
                        <select name="desconto_pontualidade_tipo" class="form-select form-select-sm">
                            <option value="nenhum" <?= $matricula['desconto_pontualidade_tipo'] === 'nenhum' ? 'selected' : '' ?>>Sem desconto</option>
                            <option value="valor" <?= $matricula['desconto_pontualidade_tipo'] === 'valor' ? 'selected' : '' ?>>Valor fixo (R$)</option>
                            <option value="percentual" <?= $matricula['desconto_pontualidade_tipo'] === 'percentual' ? 'selected' : '' ?>>Percentual (%)</option>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label small mb-0">Desconto</label>
                        <input type="text" name="desconto_pontualidade_valor" class="form-control form-control-sm" value="<?= e(number_format((float) $matricula['desconto_pontualidade_valor'], 2, ',', '')) ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label small mb-0">Dias antes do venc.</label>
                        <input type="number" name="desconto_pontualidade_dias" class="form-control form-control-sm" min="0" max="31" value="<?= (int) $matricula['desconto_pontualidade_dias'] ?>">
                    </div>
                </div>
                <p class="small text-muted my-2">Vale para as mensalidades em aberto (menos as que têm desconto próprio).<?= $boletosPontualidade ? ' ' . $boletosPontualidade . ' delas já têm boleto no Itaú: ao salvar, eles são baixados e emitidos de novo com o desconto novo.' : '' ?></p>
                <button type="submit" class="btn btn-outline-secondary btn-sm w-100"><i class="bi bi-check-lg"></i> Salvar desconto</button>
            </form>
        </div></div>
    </div>
    <?php endif; ?>

    <?php if (usuarioPodeLiberarCertificado() && in_array($matricula['status'], ['ativa', 'concluida'], true) && ($docsMatricula = documentosMatricula($pdo, (int) $id))): ?>
    <div class="secao" id="documentos">
        <div class="secao-titulo"><i class="bi bi-award"></i> Documentos do aluno</div>
        <?php $bloqueiosMat = bloqueiosDocumentosMatricula($pdo, matriculaParaDocumentos($pdo, (int) $id)); ?>
        <div class="card"><div class="card-body">
            <?php foreach ($docsMatricula as $doc): ?>
                <div class="d-flex flex-wrap align-items-center gap-2 py-2 border-bottom">
                    <div class="flex-grow-1">
                        <div class="fw-semibold small"><?= e($doc['nome']) ?></div>
                        <span class="badge text-bg-<?= e($doc['cor']) ?><?= $doc['cor'] === 'light' ? ' border' : '' ?> fw-normal text-wrap text-start"><?= e($doc['texto']) ?></span>
                        <?php if ($doc['emitido'] && $doc['emitido']['liberado_por']): ?><div class="small text-muted"><?= $doc['emitido']['liberado'] ? 'liberado' : 'bloqueado' ?> por <?= e($doc['emitido']['liberado_por']) ?> em <?= formatDateTime($doc['emitido']['liberado_em']) ?></div><?php endif; ?>
                    </div>
                    <?php if ($doc['emitido']): ?>
                        <a href="/portal/certificado.php?c=<?= e($doc['emitido']['codigo']) ?>" target="_blank" class="btn btn-sm btn-outline-primary" title="Abrir / imprimir"><i class="bi bi-box-arrow-up-right"></i></a>
                    <?php endif; ?>
                    <form action="/matriculas/documentos_liberar.php" method="post" class="d-inline-flex gap-1 align-items-center">
                        <input type="hidden" name="ids[]" value="<?= (int) $id ?>">
                        <input type="hidden" name="emissao_id" value="<?= (int) $doc['id'] ?>">
                        <input type="hidden" name="voltar" value="/matriculas/visualizar.php?id=<?= (int) $id ?>#documentos">
                        <?php if ($doc['situacao'] === 'liberado'): ?>
                            <button type="submit" name="liberado" value="0" class="btn btn-sm btn-outline-secondary"><i class="bi bi-lock"></i> Bloquear</button>
                        <?php elseif ($doc['disponivel'] && !$bloqueiosMat && in_array($doc['situacao'], ['aguardando', 'bloqueado'], true)): ?>
                            <button type="submit" name="liberado" value="1" class="btn btn-sm btn-success"><i class="bi bi-unlock"></i> Liberar</button>
                        <?php elseif (usuarioEhAdmin()): ?>
                            <input type="hidden" name="forcar" value="1">
                            <button type="submit" name="liberado" value="1" class="btn btn-sm btn-outline-success" title="Liberar mesmo com pendência / antes de concluir"
                                    onclick="return confirm('Liberar mesmo assim? (<?= e(addslashes($doc['texto'])) ?>)');"><i class="bi bi-unlock"></i> Liberar mesmo assim</button>
                        <?php endif; ?>
                    </form>
                </div>
            <?php endforeach; ?>
            <?php if ($faltamDocs = pendenciasDocumentosMatricula($pdo, (int) $id)): ?>
                <div class="small text-muted mt-2"><i class="bi bi-file-earmark-x"></i> Falta o aluno enviar / aprovar: <?= e(implode(', ', $faltamDocs)) ?>.
                    <a href="/alunos/form.php?id=<?= (int) $matricula['aluno_id'] ?>&tab=documentos">Ver documentos</a></div>
            <?php endif; ?>
        </div></div>
    </div>
    <?php endif; ?>

    <?php if ($podeGerenciar && $matricula['status'] === 'pendente'): ?>
    <div class="secao">
        <div class="secao-titulo"><i class="bi bi-person-check"></i> Confirmar sem esperar o pagamento</div>
        <div class="card"><div class="card-body">
            <p class="small text-muted mb-2">Coloca o aluno na turma agora. As parcelas continuam em aberto para ele pagar.</p>
            <form action="/matriculas/confirmar.php" method="post" onsubmit="return confirm('Confirmar a matrícula agora, sem o pagamento?');">
                <input type="hidden" name="id" value="<?= (int) $id ?>">
                <button type="submit" class="btn btn-outline-success w-100"><i class="bi bi-check-lg"></i> Confirmar matrícula</button>
            </form>
        </div></div>
    </div>
    <?php endif; ?>

    <?php if (usuarioPodeTrancarMatricula() && $matricula['status'] === 'ativa'): $regras = buscarConfiguracoes($pdo); $op = opcoesTrancamento(); ?>
    <div class="secao">
        <div class="secao-titulo"><i class="bi bi-pause-circle"></i> Trancar matrícula</div>
        <div class="card"><div class="card-body">
            <p class="small text-muted mb-2">
                Mensalidades futuras: <strong><?= e(mb_strtolower($op['parcelas'][$regras['trancamento_parcelas']][0])) ?></strong> ·
                vaga: <strong><?= e(mb_strtolower($op['vaga'][$regras['trancamento_vaga']][0])) ?></strong>.
                <?php if (usuarioEhAdmin()): ?><a href="/configuracoes/matriculas.php">Mudar regras</a><?php endif; ?>
            </p>
            <form action="/matriculas/trancar.php" method="post" data-confirmar="Trancar a matrícula de <?= e($matricula['aluno_nome']) ?>?">
                <input type="hidden" name="id" value="<?= (int) $id ?>">
                <input type="hidden" name="acao" value="trancar">
                <div class="row g-2 mb-2">
                    <div class="col-6"><label class="form-label small mb-1">Trancar a partir de</label><input type="date" name="data" class="form-control form-control-sm" value="<?= $hoje ?>" required></div>
                    <div class="col-6"><label class="form-label small mb-1">Volta prevista</label><input type="date" name="previsao" class="form-control form-control-sm"></div>
                </div>
                <input type="text" name="motivo" class="form-control form-control-sm mb-2" maxlength="255" placeholder="Motivo (opcional)">
                <button type="submit" class="btn btn-outline-info w-100"><i class="bi bi-pause-fill"></i> Trancar matrícula</button>
            </form>
        </div></div>
    </div>
    <?php endif; ?>

    <?php if (usuarioPodeTrancarMatricula() && $matricula['status'] === 'trancada'): $turmasRetorno = turmasRetornoTrancamento($pdo, $matricula); ?>
    <div class="secao">
        <div class="secao-titulo"><i class="bi bi-play-circle"></i> Destrancar</div>
        <div class="card"><div class="card-body">
            <form action="/matriculas/trancar.php" method="post" data-confirmar="Destrancar a matrícula de <?= e($matricula['aluno_nome']) ?>?">
                <input type="hidden" name="id" value="<?= (int) $id ?>">
                <input type="hidden" name="acao" value="destrancar">
                <label class="form-label small mb-1">Volta para a turma</label>
                <select name="turma_id" class="form-select form-select-sm mb-2" required>
                    <?php foreach ($turmasRetorno as $t): $propria = (int) $t['id'] === (int) $matricula['turma_id']; ?>
                        <option value="<?= (int) $t['id'] ?>" <?= !$t['aberta'] || (!$t['livres'] && !($propria && $matricula['trancamento_vaga'])) ? 'disabled' : '' ?>>
                            <?= e($t['nome']) ?><?= $propria ? ' (a mesma)' : '' ?> — <?= !$t['aberta'] ? 'encerrada' : ($propria && $matricula['trancamento_vaga'] ? 'vaga guardada' : $t['livres'] . ' vaga(s)') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <label class="form-label small mb-1">Data da volta</label>
                <input type="date" name="data" class="form-control form-control-sm mb-2" value="<?= $hoje ?>" required>
                <?php if ($matricula['trancamento_parcelas'] === 'suspender'): ?>
                    <p class="small text-muted mb-2">As mensalidades suspensas voltam: a primeira vence a partir da data da volta (mesmo dia do mês) e as outras acompanham.</p>
                <?php endif; ?>
                <button type="submit" class="btn btn-outline-success w-100"><i class="bi bi-play-fill"></i> Destrancar matrícula</button>
            </form>
        </div></div>
    </div>
    <?php endif; ?>

    <?php if (usuarioPodeCancelarMatricula() && in_array($matricula['status'], ['ativa', 'pendente'], true)): ?>
    <div class="secao">
        <div class="secao-titulo"><i class="bi bi-x-circle"></i> Cancelar matrícula</div>
        <div class="card"><div class="card-body">
            <p class="small text-muted mb-2">
                As mensalidades com vencimento a partir da data abaixo deixam de ser cobradas.
                As que já venceram e não foram pagas <strong>continuam em aberto</strong>.
            </p>
            <form action="/matriculas/cancelar.php" method="post" onsubmit="return confirm('Cancelar esta matrícula? As mensalidades futuras serão canceladas.');">
                <input type="hidden" name="id" value="<?= (int) $id ?>">
                <label class="form-label">Data do cancelamento</label>
                <input type="date" name="data_cancelamento" class="form-control mb-2" value="<?= $hoje ?>" required>
                <button type="submit" class="btn btn-outline-danger w-100"><i class="bi bi-x-lg"></i> Cancelar matrícula</button>
            </form>
        </div></div>
    </div>
    <?php endif; ?>

    <?php if ($podeGerenciar && $matricula['status'] === 'ativa'): ?>
    <div class="secao">
        <div class="secao-titulo"><i class="bi bi-check2-circle"></i> Concluir</div>
        <div class="card"><div class="card-body">
            <p class="small text-muted mb-2">Marca que o aluno terminou o curso. As parcelas não mudam.</p>
            <form action="/matriculas/concluir.php" method="post" onsubmit="return confirm('Marcar esta matrícula como concluída?');">
                <input type="hidden" name="id" value="<?= (int) $id ?>">
                <button type="submit" class="btn btn-outline-secondary w-100"><i class="bi bi-check-lg"></i> Marcar como concluída</button>
            </form>
        </div></div>
    </div>
    <?php endif; ?>

    <?php if (usuarioPodeCancelarMatricula() && !$temPagamento): ?>
    <div class="secao">
        <div class="secao-titulo"><i class="bi bi-trash"></i> Excluir</div>
        <div class="card"><div class="card-body">
            <p class="small text-muted mb-2">Para corrigir uma matrícula feita por engano. Apaga a matrícula e todas as parcelas dela. Só é possível enquanto nenhuma parcela foi paga.</p>
            <form action="/matriculas/excluir.php" method="post" onsubmit="return confirm('Excluir esta matrícula e todas as parcelas? Esta ação não pode ser desfeita.');">
                <input type="hidden" name="id" value="<?= (int) $id ?>">
                <button type="submit" class="btn btn-outline-danger w-100"><i class="bi bi-trash"></i> Excluir matrícula</button>
            </form>
        </div></div>
    </div>
    <?php endif; ?>
</div>
</div>

<?php require __DIR__ . '/../../includes/modal_pix.php'; ?>
<?php require __DIR__ . '/../../includes/modal_confirmar.php'; ?>
<script>
document.querySelectorAll('form[data-confirmar]').forEach((f) => f.addEventListener('submit', async (ev) => {
    if (f.dataset.ok) return;
    ev.preventDefault();
    if (await confirmarNoModal({ titulo: 'Confirmar', html: escapeHtml(f.dataset.confirmar) })) {
        f.dataset.ok = '1';
        f.submit();
    }
}));
</script>
<?php $voltarParcela = 'matricula'; require __DIR__ . '/../../includes/modal_parcela.php'; ?>
<script>
// Mudou o desconto e há boletos no Itaú: confirma antes, porque eles serão
// baixados e emitidos de novo.
(() => {
    const form = document.getElementById('form-pontualidade');
    if (!form || !Number(form.dataset.boletos)) return;
    const inicial = new URLSearchParams(new FormData(form)).toString();
    form.addEventListener('submit', async (ev) => {
        if (form.dataset.confirmado || new URLSearchParams(new FormData(form)).toString() === inicial) return;
        ev.preventDefault();
        const n = Number(form.dataset.boletos);
        const ok = await confirmarNoModal({
            titulo: 'Alterar o desconto também no banco?',
            html: '<p>Esta matrícula tem <strong>' + n + ' boleto(s) já registrado(s) no Itaú</strong> com o desconto antigo.</p>'
                + '<p class="mb-0">Ao confirmar, esses boletos serão <strong>baixados e emitidos de novo</strong> com o desconto novo. '
                + 'O boleto e o Pix já enviados ao aluno deixam de valer — mande os novos.</p>',
            botao: 'Confirmar e alterar no banco',
        });
        if (ok) { form.dataset.confirmado = '1'; form.requestSubmit(); }
    });
})();
</script>
<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
