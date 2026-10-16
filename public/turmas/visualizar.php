<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();
exigirCursos();

$pdo = getDB();
$id = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare(
    'SELECT t.*, c.nome AS curso_nome, c.carga_horaria, p.nome AS professor_nome,
            s.nome AS situacao_nome, s.cor AS situacao_cor, s.comportamento
     FROM turmas t
     JOIN cursos c ON c.id = t.curso_id
     JOIN situacoes_turma s ON s.id = t.situacao_id
     LEFT JOIN professores p ON p.id = t.professor_id
     WHERE t.id = ?'
);
$stmt->execute([$id]);
$turma = $stmt->fetch();

if (!$turma) {
    flash('danger', 'Turma não encontrada.');
    redirect('/turmas/listar.php');
}

$podeVerFinanceiro = usuarioPodeVerInadimplencia();
$podeLiberarCertificado = usuarioPodeLiberarCertificado();

// Situação financeira considera só as parcelas DESTA turma: o aluno pode
// estar devendo em outra e em dia aqui.
$stmt = $pdo->prepare(
    "SELECT m.*, a.nome AS aluno_nome, a.telefone AS aluno_telefone,
            (SELECT COALESCE(SUM(cr.valor), 0) FROM contas_receber cr
              WHERE cr.matricula_id = m.id AND cr.status = 'pendente' AND cr.data_vencimento < CURDATE()) AS valor_vencido,
            (SELECT COALESCE(SUM(COALESCE(cr.valor_recebido, cr.valor)), 0) FROM contas_receber cr
              WHERE cr.matricula_id = m.id AND cr.status = 'pago') AS valor_pago,
            EXISTS (SELECT 1 FROM contas_receber cr WHERE cr.matricula_id = m.id AND cr.status = 'pago') AS tem_pago,
            EXISTS (SELECT 1 FROM cobrancas_bancarias cb JOIN contas_receber cr ON cr.id = cb.conta_receber_id WHERE cr.matricula_id = m.id) AS tem_boleto
     FROM matriculas m
     JOIN alunos a ON a.id = m.aluno_id
     WHERE m.turma_id = ?
     ORDER BY FIELD(m.status, 'ativa', 'pendente', 'concluida', 'cancelada'), a.nome"
);
$stmt->execute([$id]);
$matriculas = $stmt->fetchAll();

$ativas = array_filter($matriculas, fn ($m) => $m['status'] === 'ativa');
$qtdAtivas = count($ativas);
$qtdPendentes = count(array_filter($matriculas, fn ($m) => $m['status'] === 'pendente'));
$qtdConcluidas = count(array_filter($matriculas, fn ($m) => $m['status'] === 'concluida'));
// Inscrições do portal no prazo de pagamento também seguram vaga.
// Reservas da secretaria também (vagas guardadas para alguém, fora do site).
$reservas = buscarReservasTurma($pdo, $id);
$vagasLivres = max(0, (int) $turma['vagas'] - vagasOcupadasTurma($pdo, $id));
$podeGerenciar = usuarioPodeGerenciarMatriculas();
$podeCancelar = usuarioPodeCancelarMatricula();
$alunosReserva = [];
if ($podeGerenciar) {
    $alunosReserva = $pdo->query('SELECT id, nome, cpf FROM alunos WHERE ativo = 1 ORDER BY nome')->fetchAll();
}
$receitaMensalPrevista = array_sum(array_map(
    fn ($m) => valorMensalidadeComDesconto((float) $m['valor_mensalidade'], (float) $m['desconto_percentual']),
    $ativas
));
$totalVencido = array_sum(array_column($matriculas, 'valor_vencido'));
$inadimplentes = count(array_filter($matriculas, fn ($m) => (float) $m['valor_vencido'] > 0));

// Despesas da turma (professor, palestrante, material...) e o resultado.
$podeLancarDespesa = usuarioTemAcessoFinanceiro();
$podeVerDespesas = usuarioPodeVerFinanceiro();
$despesasTurma = [];
$resultadoTurma = null;
if ($podeVerDespesas) {
    $stmt = $pdo->prepare(
        "SELECT cp.*, cf.nome AS categoria_nome FROM contas_pagar cp
         LEFT JOIN categorias_financeiras cf ON cf.id = cp.categoria_id
         WHERE cp.turma_id = ? AND cp.status IN ('pendente', 'pago') ORDER BY cp.data_vencimento, cp.id"
    );
    $stmt->execute([$id]);
    $despesasTurma = $stmt->fetchAll();
    $resultadoTurma = resultadoTurmas($pdo, ['turma_id' => $id])[0] ?? null;
}
$turmaAceitaMatricula = in_array($turma['comportamento'], COMPORTAMENTOS_TURMA_ABERTA, true);
$turmaConcluida = $turma['comportamento'] === 'concluida';
// Turma encerrada não recebe nem tira aluno: na lista sobra só o certificado.
$mostrarAcoes = ($podeGerenciar || $podeCancelar) && $turmaAceitaMatricula;
$totalColunas = 5 + ($podeVerFinanceiro ? 2 : 0) + ($podeLiberarCertificado ? 1 : 0) + ($mostrarAcoes ? 1 : 0);
$concluidasTurma = array_filter($matriculas, fn ($m) => $m['status'] === 'concluida');
$certificadosLiberados = count(array_filter($concluidasTurma, fn ($m) => $m['certificado_liberado']));
$situacoes = buscarSituacoesTurma($pdo, true);

$stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM contas_receber cr JOIN matriculas m ON m.id = cr.matricula_id WHERE m.turma_id = ? AND cr.status = 'pago'"
);
$stmt->execute([$id]);
$temPagamento = (int) $stmt->fetchColumn() > 0;

$pageTitle = e($turma['nome']);
$pageBreadcrumb = 'Acadêmico · Turmas · ' . $turma['curso_nome'];
$activeMenu = 'turmas';
ob_start();
?>
<a href="/turmas/listar.php" class="btn btn-outline-secondary">Voltar</a>
<?php
// Situações que este perfil pode escolher: concluir/reabrir só com
// concluir_turma; as outras com editar_turma.
$situacoesPermitidas = array_values(array_filter($situacoes, function ($sit) use ($turma) {
    $mexeConclusao = $sit['comportamento'] === 'concluida' || $turma['comportamento'] === 'concluida';
    return $mexeConclusao ? usuarioPodeConcluirTurma() : usuarioPodeEditarTurma();
}));
?>
<?php if ($situacoesPermitidas): ?>
<div class="dropdown d-inline-block">
    <button class="btn btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
        <i class="bi bi-bookmark-check"></i> Situação
    </button>
    <ul class="dropdown-menu dropdown-menu-end">
        <li><h6 class="dropdown-header">Mudar situação para</h6></li>
        <?php foreach ($situacoesPermitidas as $sit): ?>
            <li>
                <form action="/turmas/alterar_situacao.php" method="post"
                      onsubmit="return confirmarSituacao(<?= e(json_encode($sit['nome'])) ?>, <?= e(json_encode($sit['comportamento'])) ?>, this);">
                    <input type="hidden" name="data_conclusao" value="">
                    <input type="hidden" name="data_inicio" value="">
                    <input type="hidden" name="ids[]" value="<?= (int) $id ?>">
                    <input type="hidden" name="situacao_id" value="<?= (int) $sit['id'] ?>">
                    <input type="hidden" name="voltar" value="/turmas/visualizar.php?id=<?= (int) $id ?>">
                    <button type="submit" class="dropdown-item d-flex align-items-center gap-2">
                        <span class="ponto-status" style="background: <?= e($sit['cor']) ?>;"></span>
                        <?= e($sit['nome']) ?>
                        <?php if ((int) $sit['id'] === (int) $turma['situacao_id']): ?><i class="bi bi-check-lg ms-auto"></i><?php endif; ?>
                    </button>
                </form>
            </li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>
<a href="/turmas/aulas.php?id=<?= (int) $id ?>" class="btn btn-outline-secondary"><i class="bi bi-calendar-week"></i> Calendário</a>
<?php if ($turma['portal_publicar'] && $turmaAceitaMatricula): ?>
    <a href="/portal/turma.php?id=<?= (int) $id ?>" target="_blank" class="btn btn-outline-secondary" title="Ver como o aluno vê"><i class="bi bi-globe2"></i> No portal</a>
<?php endif; ?>
<?php if (usuarioPodeEditarTurma()): ?>
<a href="/turmas/form.php?id=<?= (int) $id ?>" class="btn btn-outline-secondary"><i class="bi bi-pencil"></i> Editar</a>
<?php endif; ?>
<?php if (usuarioPodeExcluirTurma()): ?>
<?php
    // Com pagamento a exclusão é barrada no servidor; aqui só avisa antes.
    $mensagemExclusao = $temPagamento
        ? 'Esta turma já tem mensalidade paga e não pode ser excluída. Use uma situação de cancelada ou concluída.'
        : 'Excluir a turma ' . $turma['nome'] . '?' . ($matriculas ? ' As ' . count($matriculas) . ' matrícula(s) e as parcelas em aberto dela também serão apagadas.' : '') . ' Esta ação não pode ser desfeita.';
?>
<form action="/turmas/excluir.php" method="post" class="d-inline"
      onsubmit="<?= $temPagamento ? 'alert(' . e(json_encode($mensagemExclusao)) . '); return false;' : 'return confirm(' . e(json_encode($mensagemExclusao)) . ');' ?>">
    <input type="hidden" name="ids[]" value="<?= (int) $id ?>">
    <input type="hidden" name="voltar" value="/turmas/visualizar.php?id=<?= (int) $id ?>">
    <button type="submit" class="btn btn-outline-danger"><i class="bi bi-trash"></i> Excluir</button>
</form>
<?php endif; ?>
<?php if (usuarioPodeGerenciarMatriculas() && $turmaAceitaMatricula && $vagasLivres > 0): ?>
    <a href="/matriculas/form.php?turma_id=<?= (int) $id ?>" class="btn btn-brand"><i class="bi bi-person-plus"></i> Matricular aluno</a>
<?php endif; ?>
<?php
$pageAction = ob_get_clean();
require __DIR__ . '/../../includes/layout_header.php';
?>

<div class="modal fade" id="modal-conclusao" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-flag"></i> Concluir turma — <span id="conclusao-nome"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <?php if (!$turma['data_inicio']): ?>
                    <label class="form-label">Data de início *</label>
                    <input type="date" id="conclusao-inicio" class="form-control mb-1" max="<?= date('Y-m-d') ?>">
                    <div class="form-text mb-3">A turma está sem data de início; ela também sai no certificado.</div>
                <?php endif; ?>
                <label class="form-label">Data de conclusão *</label>
                <input type="date" id="conclusao-data" class="form-control" max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>" <?= $turma['data_inicio'] ? 'min="' . e($turma['data_inicio']) . '"' : '' ?>>
                <div class="form-text mb-3">É a data de término que sai no certificado.</div>
                <div class="small text-muted">
                    Ao concluir, a turma sai do site e não aceita mais alunos: os alunos ativos ficam como concluídos,
                    inscrições do site não pagas são canceladas e as vagas reservadas são apagadas. Depois é só liberar os certificados.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-brand" onclick="enviarConclusao()"><i class="bi bi-check-lg"></i> Concluir turma</button>
            </div>
        </div>
    </div>
</div>

<?php if (!$turmaAceitaMatricula): ?>
    <div class="alert alert-secondary d-flex gap-2 align-items-start">
        <i class="bi bi-lock fs-5"></i>
        <div>
            <strong>Turma <?= e(mb_strtolower($turma['situacao_nome'])) ?><?= $turma['data_conclusao'] ? ' em ' . formatDate($turma['data_conclusao']) : '' ?>:</strong> não aparece no site e não aceita novos alunos nem reservas.
            <?php if ($turmaConcluida): ?>Falta só liberar os certificados na coluna "Certificado" abaixo.<?php endif; ?>
            Para reabrir, mude a situação da turma (os alunos concluídos voltam a ficar ativos).
        </div>
    </div>
<?php endif; ?>

<div class="secao">
    <div class="secao-titulo">
        <i class="bi bi-info-circle"></i> Dados da turma
        <span class="ms-2"><?= badgeSituacaoTurma($turma['situacao_nome'], $turma['situacao_cor']) ?></span>
    </div>
    <div class="card"><div class="card-body">
        <div class="row g-3">
            <div class="col-md-3"><div class="kpi-rotulo">Curso</div><div><a href="/cursos/form.php?id=<?= (int) $turma['curso_id'] ?>"><?= e($turma['curso_nome']) ?></a><?= $turma['carga_horaria'] ? ' · ' . (int) $turma['carga_horaria'] . ' h' : '' ?></div></div>
            <div class="col-md-3"><div class="kpi-rotulo">Professor responsável</div><div><?= $turma['professor_id'] ? '<a href="/professores/form.php?id=' . (int) $turma['professor_id'] . '">' . e($turma['professor_nome']) . '</a>' : 'A definir' ?></div></div>
            <div class="col-md-3"><div class="kpi-rotulo">Período</div><div><?= e(periodoTurma($turma)) ?> (<?= e(textoDuracaoTurma($turma)) ?>)<?php if ($turma['data_conclusao']): ?><div class="small text-muted">concluída em <?= formatDate($turma['data_conclusao']) ?></div><?php endif; ?></div></div>
            <div class="col-md-3"><div class="kpi-rotulo">Mensalidade</div><div><?= formatMoney($turma['valor_mensalidade']) ?></div></div>
            <div class="col-md-3"><div class="kpi-rotulo">Dias e horário</div><div><?= e($turma['horario'] ?: '-') ?></div></div>
            <div class="col-md-3"><div class="kpi-rotulo">Local</div><div><?= e($turma['local'] ?: '-') ?></div></div>
            <div class="col-md-6"><div class="kpi-rotulo">Observação</div><div><?= e($turma['observacao'] ?: '-') ?></div></div>
        </div>
    </div></div>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="card h-100"><div class="card-body">
            <div class="kpi-rotulo">Alunos ativos (cursando)</div>
            <div class="kpi-valor"><?= $qtdAtivas ?>/<?= (int) $turma['vagas'] ?></div>
            <?php if (!$turma['data_inicio'] && $turma['minimo_alunos']): $faltam = max(0, (int) $turma['minimo_alunos'] - $qtdAtivas); ?>
                <div class="small <?= $faltam ? 'text-muted' : 'text-success fw-semibold' ?>">
                    <?= $faltam ? "faltam {$faltam} para o mínimo de " . (int) $turma['minimo_alunos'] : 'mínimo atingido — defina a data de início' ?>
                </div>
            <?php endif; ?>
            <?php if ($qtdPendentes): ?><div class="small text-muted"><?= $qtdPendentes ?> aguardando pagamento</div><?php endif; ?>
            <?php if ($qtdConcluidas): ?><div class="small text-muted"><?= $qtdConcluidas ?> concluído(s)</div><?php endif; ?>
        </div></div>
    </div>
    <?php if ($turmaAceitaMatricula): ?>
    <div class="col-6 col-lg-3">
        <div class="card h-100"><div class="card-body">
            <div class="kpi-rotulo">Vagas livres</div>
            <div class="kpi-valor <?= $vagasLivres === 0 ? 'estoque-baixo' : '' ?>"><?= $vagasLivres ?></div>
            <?php if ($turma['portal_publicar']): ?>
                <div class="small text-muted">no site: <?= e(mb_strtolower(textoVagasRestantes($vagasLivres))) ?></div>
            <?php endif; ?>
            <?php if ($reservas): ?><div class="small text-muted"><i class="bi bi-bookmark"></i> <?= count($reservas) ?> reservada(s) pela secretaria</div><?php endif; ?>
        </div></div>
    </div>
    <?php else: ?>
    <div class="col-6 col-lg-3">
        <div class="card h-100"><div class="card-body">
            <div class="kpi-rotulo">Certificados liberados</div>
            <div class="kpi-valor"><?= $certificadosLiberados ?>/<?= count($concluidasTurma) ?></div>
            <div class="small text-muted">dos alunos que concluíram</div>
        </div></div>
    </div>
    <?php endif; ?>
    <?php if ($podeVerFinanceiro): ?>
    <div class="col-6 col-lg-3">
        <div class="card h-100"><div class="card-body">
            <div class="kpi-rotulo">Receita mensal prevista</div>
            <div class="kpi-valor"><?= formatMoney($receitaMensalPrevista) ?></div>
        </div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card h-100"><div class="card-body">
            <div class="kpi-rotulo">Em atraso</div>
            <div class="kpi-valor <?= $totalVencido > 0 ? 'estoque-baixo' : '' ?>"><?= formatMoney($totalVencido) ?></div>
            <?php if ($inadimplentes): ?><div class="small text-muted"><?= $inadimplentes ?> aluno(s)</div><?php endif; ?>
        </div></div>
    </div>
    <?php endif; ?>
</div>

<div class="secao">
    <div class="secao-titulo">
        <i class="bi bi-people"></i> Alunos da turma
        <?php if ($podeGerenciar && $turmaAceitaMatricula): ?>
            <button type="button" class="btn btn-sm btn-outline-secondary ms-auto" data-bs-toggle="modal" data-bs-target="#modal-reserva" <?= $vagasLivres > 0 ? '' : 'disabled title="Turma cheia"' ?>>
                <i class="bi bi-bookmark-plus"></i> Reservar vaga
            </button>
        <?php endif; ?>
    </div>
    <?php if ($turmaAceitaMatricula): ?>
    <p class="small text-muted mb-2">
        <strong>Ativo</strong> = está cursando (matrícula feita pela secretaria, ou inscrição do site já paga ou confirmada).
        <strong>Aguardando pagamento</strong> = inscrito pelo site; fica ativo sozinho quando paga, ou clique em <i class="bi bi-check-circle"></i> para confirmar.
        <strong>Concluída</strong> = terminou o curso; <i class="bi bi-arrow-counterclockwise"></i> volta para ativo.
    </p>
    <?php endif; ?>
    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead>
                    <tr>
                        <th>Aluno</th><th>Telefone</th><th>Matrícula</th><th class="text-end">Mensalidade</th>
                        <?php if ($podeVerFinanceiro): ?><th class="text-end">Pago</th><th>Financeiro</th><?php endif; ?>
                        <th>Situação</th>
                        <?php if ($podeLiberarCertificado): ?>
                            <th class="text-center" title="Marque quem pode ver e baixar o certificado no portal">
                                Certificado<br><input type="checkbox" class="form-check-input" id="certificado-todos" title="Marcar / desmarcar todos">
                            </th>
                        <?php endif; ?>
                        <?php if ($mostrarAcoes): ?><th class="text-end">Ações</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$matriculas && !$reservas): ?>
                    <tr><td colspan="<?= $totalColunas ?>" class="text-center text-muted py-4">Nenhum aluno matriculado nesta turma ainda.</td></tr>
                <?php endif; ?>
                <?php foreach ($reservas as $r): ?>
                    <tr class="linha-reserva">
                        <td>
                            <i class="bi bi-bookmark-fill text-warning"></i> <?= e($r['nome']) ?>
                            <?php if ($r['aluno_id']): ?><div class="small text-muted">aluno cadastrado</div><?php endif; ?>
                        </td>
                        <td><?= e($r['telefone'] ?: '-') ?></td>
                        <td><?= formatDate(substr((string) $r['criado_em'], 0, 10)) ?></td>
                        <td class="text-end text-muted">-</td>
                        <?php if ($podeVerFinanceiro): ?><td class="text-end text-muted">-</td><td class="text-muted">-</td><?php endif; ?>
                        <td>
                            <span class="badge text-bg-warning">Vaga reservada</span>
                            <div class="small text-muted"><?= e($r['observacao'] ?: ($r['usuario_nome'] ? 'por ' . $r['usuario_nome'] : '')) ?></div>
                        </td>
                        <?php if ($podeLiberarCertificado): ?><td class="text-center text-muted">—</td><?php endif; ?>
                        <?php if ($mostrarAcoes): ?>
                            <td class="text-end text-nowrap">
                                <?php if ($podeGerenciar): ?>
                                <a href="/matriculas/form.php?turma_id=<?= (int) $id ?>&amp;reserva=<?= (int) $r['id'] ?>" class="btn btn-sm btn-outline-primary" title="Matricular nesta vaga"><i class="bi bi-person-check"></i> Matricular</a>
                                <form action="/turmas/reserva_excluir.php" method="post" class="d-inline" onsubmit="return confirm(<?= e(json_encode('Excluir a reserva de ' . $r['nome'] . '? A vaga fica livre e volta a aparecer no site.')) ?>);">
                                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Excluir reserva"><i class="bi bi-trash"></i></button>
                                </form>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                <?php foreach ($matriculas as $m): ?>
                    <tr class="cursor-pointer" onclick="window.location='/matriculas/visualizar.php?id=<?= (int) $m['id'] ?>'">
                        <td><?= e($m['aluno_nome']) ?></td>
                        <td><?= e($m['aluno_telefone'] ?: '-') ?></td>
                        <td><?= formatDate($m['data_matricula']) ?></td>
                        <td class="text-end">
                            <?= formatMoney(valorMensalidadeComDesconto((float) $m['valor_mensalidade'], (float) $m['desconto_percentual'])) ?>
                            <?php if ((float) $m['desconto_percentual'] > 0): ?><div class="small text-muted"><?= formatPercent($m['desconto_percentual']) ?> desc.</div><?php endif; ?>
                        </td>
                        <?php if ($podeVerFinanceiro): ?>
                        <td class="text-end"><?= formatMoney($m['valor_pago']) ?></td>
                        <td>
                            <?php if ((float) $m['valor_vencido'] > 0): ?>
                                <span class="badge text-bg-danger">Em atraso · <?= formatMoney($m['valor_vencido']) ?></span>
                            <?php else: ?>
                                <span class="badge text-bg-success">Em dia</span>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                        <td>
                            <span class="badge <?= matriculaStatusBadgeClass($m['status']) ?>"><?= $m['lista_espera'] ? 'Pagou — lista de espera' : matriculaStatusLabel($m['status']) ?></span>
                            <?php if ($m['status'] === 'pendente' && !$m['lista_espera']): ?><div class="small text-muted">até <?= formatDateTime($m['pagamento_ate']) ?></div><?php endif; ?>
                            <?php if ($m['origem'] === 'portal'): ?><div class="small text-muted"><i class="bi bi-globe2"></i> portal</div><?php endif; ?>
                        </td>
                        <?php if ($podeLiberarCertificado): ?>
                            <td class="text-center" onclick="event.stopPropagation()">
                                <?php if (in_array($m['status'], ['ativa', 'concluida'], true)): ?>
                                    <input type="checkbox" class="form-check-input certificado-aluno" value="<?= (int) $m['id'] ?>" <?= $m['certificado_liberado'] ? 'checked' : '' ?>>
                                    <div class="small text-muted estado-certificado" data-concluida="<?= $m['status'] === 'concluida' ? 1 : 0 ?>"></div>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                        <?php if ($mostrarAcoes): ?>
                            <td class="text-end text-nowrap" onclick="event.stopPropagation()">
                                <?php if ($podeGerenciar && $m['status'] === 'pendente' && $turmaAceitaMatricula): ?>
                                    <form action="/matriculas/confirmar.php" method="post" class="d-inline" onsubmit="return confirm(<?= e(json_encode('Confirmar a matrícula de ' . $m['aluno_nome'] . ' agora, sem esperar o pagamento? Ele passa a ser aluno ativo.')) ?>);">
                                        <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                                        <input type="hidden" name="voltar" value="turma">
                                        <button type="submit" class="btn btn-sm btn-outline-success" title="Confirmar matrícula (deixar ativo)"><i class="bi bi-check-circle"></i></button>
                                    </form>
                                <?php elseif ($podeGerenciar && $m['status'] === 'concluida' && $turmaAceitaMatricula): ?>
                                    <form action="/matriculas/reativar.php" method="post" class="d-inline" onsubmit="return confirm(<?= e(json_encode('Voltar ' . $m['aluno_nome'] . ' para ativo nesta turma?')) ?>);">
                                        <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                                        <input type="hidden" name="voltar" value="turma">
                                        <button type="submit" class="btn btn-sm btn-outline-secondary" title="Voltar para ativo"><i class="bi bi-arrow-counterclockwise"></i></button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($podeCancelar && !$m['tem_pago'] && !$m['tem_boleto']): ?>
                                    <form action="/matriculas/excluir.php" method="post" class="d-inline" onsubmit="return confirm(<?= e(json_encode('Excluir ' . $m['aluno_nome'] . ' desta turma? A matrícula e as parcelas dela serão apagadas.')) ?>);">
                                        <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Excluir aluno da turma"><i class="bi bi-trash"></i></button>
                                    </form>
                                <?php elseif ($podeCancelar && in_array($m['status'], ['ativa', 'pendente'], true)): ?>
                                    <form action="/matriculas/cancelar.php" method="post" class="d-inline" onsubmit="return confirm(<?= e(json_encode($m['aluno_nome'] . ' já tem ' . ($m['tem_pago'] ? 'pagamento' : 'boleto registrado no banco') . ', então não pode ser apagado — a matrícula será CANCELADA (sai da turma, a vaga fica livre e as mensalidades em aberto são canceladas). Continuar?')) ?>);">
                                        <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                                        <input type="hidden" name="voltar" value="turma">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Tirar da turma (cancelar matrícula — já tem pagamento)"><i class="bi bi-trash"></i></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if ($podeVerDespesas && $resultadoTurma): ?>
<div class="secao">
    <div class="secao-titulo">
        <i class="bi bi-receipt"></i> Despesas da turma
        <span class="ms-auto d-flex gap-2">
            <a href="/financeiro/turma_resultado.php?id=<?= (int) $id ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-graph-up"></i> Resultado da turma</a>
            <?php if ($podeLancarDespesa): ?><a href="/financeiro/contas_pagar/form.php?turma_id=<?= (int) $id ?>&voltar=turma" class="btn btn-sm btn-outline-secondary"><i class="bi bi-plus-lg"></i> Lançar despesa</a><?php endif; ?>
        </span>
    </div>
    <p class="small text-muted mb-2">
        Custo até agora: <strong><?= formatMoney($resultadoTurma['despesa_total']) ?></strong>
        <?php if ((float) $resultadoTurma['despesa_a_pagar'] > 0): ?>(<?= formatMoney($resultadoTurma['despesa_a_pagar']) ?> ainda a pagar)<?php endif; ?>
        · receita <?= formatMoney($resultadoTurma['receita_total']) ?>
        · resultado previsto <strong class="<?= $resultadoTurma['resultado_previsto'] < 0 ? 'text-danger' : 'text-success' ?>"><?= formatMoney($resultadoTurma['resultado_previsto']) ?></strong>
    </p>
    <div class="card"><div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead><tr><th>Vencimento</th><th>Descrição</th><th>Categoria</th><th class="text-end">Valor</th><th>Situação</th><th class="text-end">Ações</th></tr></thead>
            <tbody>
            <?php if (!$despesasTurma): ?>
                <tr><td colspan="6" class="text-center text-muted py-4">Nenhuma despesa lançada. Use "Lançar despesa" para pagamento do professor, palestrante, material...</td></tr>
            <?php endif; ?>
            <?php foreach ($despesasTurma as $d): $vencidaDespesa = $d['status'] === 'pendente' && $d['data_vencimento'] < date('Y-m-d'); ?>
                <tr>
                    <td class="<?= $vencidaDespesa ? 'estoque-baixo' : '' ?>"><?= formatDate($d['data_vencimento']) ?></td>
                    <td><?= e($d['descricao']) ?></td>
                    <td><?= e($d['categoria_nome'] ?: '-') ?></td>
                    <td class="text-end"><?= formatMoney($d['valor']) ?></td>
                    <td><span class="badge <?= contaStatusBadgeClass($d['status']) ?>"><?= $d['status'] === 'pago' ? 'Pago ' . formatDate($d['data_pagamento']) : contaStatusLabel($d['status']) ?></span></td>
                    <td class="text-end">
                        <?php if ($d['status'] === 'pendente' && usuarioPodePagarConta()): ?>
                            <a href="/financeiro/contas_pagar/baixar.php?id=<?= (int) $d['id'] ?>&voltar=turma" class="btn btn-sm btn-outline-success" title="Dar baixa (pagar)"><i class="bi bi-check-lg"></i></a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div></div>
</div>
<?php endif; ?>

<?php if ($podeGerenciar && $turmaAceitaMatricula): ?>
<div class="modal fade" id="modal-reserva" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" action="/turmas/reserva_salvar.php" method="post">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-bookmark-plus"></i> Reservar vaga</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small">A vaga fica guardada para esta pessoa: some do site e aparece na lista de alunos da turma. Depois é só clicar em "Matricular" na linha dela — ou excluir a reserva para liberar a vaga.</p>
                <input type="hidden" name="turma_id" value="<?= (int) $id ?>">
                <input type="hidden" name="aluno_id" id="reserva-aluno-id">
                <div class="mb-3">
                    <label class="form-label">Para quem? *</label>
                    <input type="text" name="nome" id="reserva-nome" class="form-control" required maxlength="150" list="reserva-alunos" autocomplete="off" placeholder="Nome (escolha um aluno da lista ou digite)">
                    <datalist id="reserva-alunos">
                        <?php foreach ($alunosReserva as $a): ?>
                            <option value="<?= e($a['nome']) ?>" data-id="<?= (int) $a['id'] ?>"><?= e($a['cpf'] ? 'CPF ' . $a['cpf'] : 'aluno cadastrado') ?></option>
                        <?php endforeach; ?>
                    </datalist>
                    <div class="form-text" id="reserva-aluno-aviso"></div>
                </div>
                <div class="row g-3">
                    <div class="col-md-5">
                        <label class="form-label">Telefone</label>
                        <input type="text" name="telefone" class="form-control" maxlength="30">
                    </div>
                    <div class="col-md-7">
                        <label class="form-label">Observação</label>
                        <input type="text" name="observacao" class="form-control" maxlength="255" placeholder="Ex.: paga na semana que vem">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-brand"><i class="bi bi-bookmark-check"></i> Reservar</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
// Reserva: escolhendo um nome da lista, liga a reserva ao cadastro do aluno.
document.getElementById('reserva-nome')?.addEventListener('input', (e) => {
    const opcao = [...document.querySelectorAll('#reserva-alunos option')].find((o) => o.value === e.target.value);
    document.getElementById('reserva-aluno-id').value = opcao ? opcao.dataset.id : '';
    document.getElementById('reserva-aluno-aviso').textContent = opcao ? 'Aluno cadastrado — se ele se inscrever pelo site, usa esta vaga.' : '';
});

// Certificado: cada caixa salva na hora; a do cabeçalho marca/desmarca todos.
const caixasCertificado = [...document.querySelectorAll('.certificado-aluno')];
function textoCertificado(caixa) {
    const estado = caixa.parentElement.querySelector('.estado-certificado');
    estado.textContent = !caixa.checked ? 'bloqueado' : (estado.dataset.concluida === '1' ? 'disponível' : 'sai ao concluir');
    estado.className = 'small estado-certificado ' + (caixa.checked ? 'text-success' : 'text-danger');
}
function salvarCertificado(caixas, liberado) {
    const dados = new URLSearchParams({ liberado: liberado ? 1 : 0 });
    caixas.forEach((c) => dados.append('ids[]', c.value));
    fetch('/matriculas/certificado_liberar.php', { method: 'POST', body: dados, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then((r) => r.json())
        .then((r) => { if (!r.sucesso) throw new Error(r.mensagem); })
        .catch((erro) => { caixas.forEach((c) => { c.checked = !liberado; textoCertificado(c); }); alert(erro.message || 'Não foi possível salvar.'); atualizarTodos(); });
}
function atualizarTodos() {
    const todos = document.getElementById('certificado-todos');
    if (!todos) return;
    const marcados = caixasCertificado.filter((c) => c.checked).length;
    todos.checked = marcados === caixasCertificado.length && marcados > 0;
    todos.indeterminate = marcados > 0 && marcados < caixasCertificado.length;
}
caixasCertificado.forEach((c) => {
    textoCertificado(c);
    c.addEventListener('change', () => { textoCertificado(c); atualizarTodos(); salvarCertificado([c], c.checked); });
});
document.getElementById('certificado-todos')?.addEventListener('change', (e) => {
    if (!caixasCertificado.length) return;
    caixasCertificado.forEach((c) => { c.checked = e.target.checked; textoCertificado(c); });
    salvarCertificado(caixasCertificado, e.target.checked);
});
atualizarTodos();

// Avisa antes, do lado do usuário, o efeito das situações que mexem nas
// matrículas — o servidor aplica (ou barra) a regra de qualquer forma.
let formSituacao = null;
function confirmarSituacao(nome, comportamento, form) {
    if (comportamento === 'concluida') {
        // Concluir pede a data de conclusão (vai para o certificado).
        formSituacao = form;
        document.getElementById('conclusao-nome').textContent = nome;
        bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-conclusao')).show();
        return false;
    }
    if (comportamento === 'cancelada') {
        return confirm('Mudar para "' + nome + '"? Só é possível se a turma não tiver matrículas ativas.');
    }
    if (TURMA_CONCLUIDA && (comportamento === 'aberta' || comportamento === 'em_andamento')) {
        return confirm('Reabrir a turma em "' + nome + '"? Os alunos concluídos voltam a ficar ativos (cursando).');
    }
    return true;
}
const TURMA_CONCLUIDA = <?= $turmaConcluida ? 'true' : 'false' ?>;
function enviarConclusao() {
    const data = document.getElementById('conclusao-data').value;
    if (!data) { alert('Informe a data de conclusão.'); return; }
    const inicio = document.getElementById('conclusao-inicio');
    if (inicio && !inicio.value) { alert('Informe a data de início da turma.'); return; }
    if (inicio) formSituacao.querySelector('[name="data_inicio"]').value = inicio.value;
    formSituacao.querySelector('[name="data_conclusao"]').value = data;
    formSituacao.submit();
}
</script>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
