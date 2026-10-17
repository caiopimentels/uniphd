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
    'SELECT m.*, a.nome AS aluno_nome, a.cpf AS aluno_cpf, a.telefone AS aluno_telefone,
            t.nome AS turma_nome, t.data_inicio, t.data_termino, t.minimo_alunos, t.duracao_unidade, c.nome AS curso_nome,
            fp.nome AS forma_pagamento_nome, u.nome AS usuario_nome
     FROM matriculas m
     JOIN alunos a ON a.id = m.aluno_id
     JOIN turmas t ON t.id = m.turma_id
     JOIN cursos c ON c.id = t.curso_id
     LEFT JOIN formas_pagamento fp ON fp.id = m.forma_pagamento_id
     LEFT JOIN usuarios u ON u.id = m.usuario_id
     WHERE m.id = ?'
);
$stmt->execute([$id]);
$matricula = $stmt->fetch();

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
         WHERE cr.matricula_id = ?
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

$pageTitle = 'Matrícula · ' . e($matricula['aluno_nome']);
$pageBreadcrumb = 'Acadêmico · ' . $matricula['curso_nome'] . ' · ' . $matricula['turma_nome'];
$activeMenu = 'turmas';
$pageAction = '<a href="/turmas/visualizar.php?id=' . (int) $matricula['turma_id'] . '" class="btn btn-outline-secondary">Ver turma</a>'
    . '<a href="/alunos/form.php?id=' . (int) $matricula['aluno_id'] . '" class="btn btn-outline-secondary">Ver aluno</a>'
    . '<a href="/matriculas/comprovante.php?id=' . (int) $id . '" target="_blank" class="btn btn-outline-secondary"><i class="bi bi-printer"></i> Comprovante</a>'
    . ($matricula['status'] === 'concluida' ? '<a href="/portal/certificado.php?matricula=' . (int) $id . '" target="_blank" class="btn btn-brand"><i class="bi bi-award"></i> Certificado</a>' : '');
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
        <div class="card"><div class="card-body">
            <div class="row g-3">
                <div class="col-md-6"><div class="kpi-rotulo">Aluno</div><div><a href="/alunos/form.php?id=<?= (int) $matricula['aluno_id'] ?>"><?= e($matricula['aluno_nome']) ?></a><?= $matricula['aluno_cpf'] ? ' · ' . e($matricula['aluno_cpf']) : '' ?></div></div>
                <div class="col-md-6"><div class="kpi-rotulo">Turma</div><div><a href="/turmas/visualizar.php?id=<?= (int) $matricula['turma_id'] ?>"><?= e($matricula['curso_nome']) ?> · <?= e($matricula['turma_nome']) ?></a></div></div>
                <div class="col-md-3"><div class="kpi-rotulo">Data da matrícula</div><div><?= formatDate($matricula['data_matricula']) ?></div></div>
                <div class="col-md-3"><div class="kpi-rotulo">Período da turma</div><div><?= e(periodoTurma($matricula)) ?></div></div>
                <div class="col-md-3"><div class="kpi-rotulo">Mensalidade</div><div><?= formatMoney($mensalidadeFinal) ?><?php if ((float) $matricula['desconto_percentual'] > 0): ?> <span class="small text-muted">(<?= formatMoney($matricula['valor_mensalidade']) ?> − <?= formatPercent($matricula['desconto_percentual']) ?>)</span><?php endif; ?></div></div>
                <div class="col-md-3"><div class="kpi-rotulo">Mensalidades</div><div><?= (int) $matricula['num_parcelas'] ?>× a partir de <?= formatDate($matricula['primeiro_vencimento']) ?></div></div>
                <div class="col-md-3"><div class="kpi-rotulo">Taxa de matrícula</div><div><?= (float) $matricula['taxa_matricula'] > 0 ? formatMoney($matricula['taxa_matricula']) : 'Sem taxa' ?></div></div>
                <div class="col-md-6"><div class="kpi-rotulo">Desconto por pontualidade</div><div><?= e(textoDescontoPontualidade($matricula)) ?></div></div>
                <div class="col-md-3"><div class="kpi-rotulo">Forma de pagamento</div><div><?= e($matricula['forma_pagamento_nome'] ?: '-') ?></div></div>
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
                        <tr>
                            <td><a href="#" onclick="abrirHistoricoParcela(<?= (int) $p['id'] ?>); return false;" title="Ver o histórico desta parcela"><?= e($p['descricao']) ?></a> <i class="bi bi-clock-history text-muted small"></i></td>
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
                                    <span class="badge <?= contaStatusBadgeClass($p['status']) ?>"><?= contaStatusLabel($p['status']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <?php if ($p['status'] === 'pendente'): ?>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="abrirPix(<?= (int) $p['id'] ?>)" title="Gerar / ver Pix (QR Code e copia e cola)"><i class="bi bi-qr-code"></i></button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="abrirBoleto(<?= (int) $p['id'] ?>)" title="Gerar / abrir boleto"><i class="bi bi-upc-scan"></i></button>
                                <?php endif; ?>
                                <?php if ($p['status'] === 'pendente' && usuarioPodeEditarFinanceiroAluno()): ?>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="editarParcela(this)" data-parcela="<?= e(json_encode(dadosEdicaoParcela($pdo, $p), JSON_UNESCAPED_UNICODE)) ?>" title="<?= $p['boleto_no_banco'] ? 'Alterar (o boleto é alterado também no banco)' : 'Alterar valor, vencimento ou desconto' ?>"><i class="bi bi-pencil"></i></button>
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
                                <span class="btn btn-sm btn-outline-primary" style="cursor: default;" title="Pago por boleto/Pix pelo banco — não pode ser estornado"><i class="bi bi-bank"></i></span>
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
            <form action="/matriculas/alterar_pontualidade.php" method="post">
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
                <p class="small text-muted my-2">Vale para as mensalidades em aberto. Boletos já registrados no banco mantêm o desconto com que foram registrados.</p>
                <button type="submit" class="btn btn-outline-secondary btn-sm w-100"><i class="bi bi-check-lg"></i> Salvar desconto</button>
            </form>
        </div></div>
    </div>
    <?php endif; ?>

    <?php if (usuarioPodeLiberarCertificado() && in_array($matricula['status'], ['ativa', 'concluida'], true)): ?>
    <div class="secao">
        <div class="secao-titulo"><i class="bi bi-award"></i> Certificado</div>
        <div class="card"><div class="card-body">
            <p class="small mb-2">
                <?php if ($matricula['certificado_liberado']): ?>
                    <span class="badge text-bg-success">Liberado</span> <?= $matricula['status'] === 'concluida' ? 'O aluno vê e baixa no portal.' : 'Aparece no portal quando a matrícula for concluída.' ?>
                <?php else: ?>
                    <span class="badge text-bg-danger">Bloqueado</span> O aluno vê "em análise pela secretaria".
                <?php endif; ?>
            </p>
            <form action="/matriculas/certificado_liberar.php" method="post">
                <input type="hidden" name="ids[]" value="<?= (int) $id ?>">
                <input type="hidden" name="liberado" value="<?= $matricula['certificado_liberado'] ? 0 : 1 ?>">
                <button type="submit" class="btn btn-outline-secondary w-100"><i class="bi bi-<?= $matricula['certificado_liberado'] ? 'lock' : 'unlock' ?>"></i> <?= $matricula['certificado_liberado'] ? 'Bloquear certificado' : 'Liberar certificado' ?></button>
            </form>
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
<?php require __DIR__ . '/../../includes/modal_historico_parcela.php'; ?>
<?php $voltarEdicaoParcela = 'matricula'; require __DIR__ . '/../../includes/modal_editar_parcela.php'; ?>
<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
