<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirTelaPortal('meus_cursos');
exigirAluno();

$pdo = getDB();
sincronizarDocumentosAluno($pdo, alunoAtualId());
$tituloPagina = 'Meus cursos';
$telaPortal = 'meus_cursos';
require __DIR__ . '/../../includes/portal_header.php';

$stmt = $pdo->prepare(
    "SELECT m.*, t.nome AS turma_nome, t.data_inicio, t.data_termino, t.local, t.dias_semana, t.hora_inicio, t.hora_fim, t.agenda, t.horario, t.minimo_alunos, t.duracao_unidade,
            c.nome AS curso_nome, cr.descricao AS confirmacao_descricao, cr.valor AS confirmacao_valor, cr.status AS confirmacao_status,
            (SELECT MIN(ta.data) FROM turma_aulas ta WHERE ta.turma_id = t.id AND ta.status = 'prevista' AND ta.data >= CURDATE()) AS proxima_aula
     FROM matriculas m
     JOIN turmas t ON t.id = m.turma_id
     JOIN cursos c ON c.id = t.curso_id
     LEFT JOIN contas_receber cr ON cr.id = m.conta_receber_confirmacao_id
     WHERE m.aluno_id = ? AND (m.status <> 'cancelada' OR m.data_cancelamento >= DATE_SUB(CURDATE(), INTERVAL 30 DAY))
     ORDER BY FIELD(m.status, 'pendente', 'ativa', 'concluida', 'cancelada'), t.data_inicio DESC"
);
$stmt->execute([alunoAtualId()]);
$matriculas = $stmt->fetchAll();
?>
<h1 class="portal-titulo">Meus cursos</h1>
<?php if ($faltamDocumentos = documentosParaEnviar(documentosDoAluno($pdo, alunoAtualId()))): ?>
    <div class="alert alert-warning d-flex flex-wrap align-items-center gap-2">
        <i class="bi bi-file-earmark-arrow-up"></i>
        <div class="flex-grow-1">Você tem <strong><?= $faltamDocumentos ?> documento(s)</strong> para enviar.</div>
        <a href="/portal/documentos.php" class="btn btn-sm btn-warning">Enviar documentos</a>
    </div>
<?php endif; ?>
<p class="portal-sub">Suas matrículas e inscrições.</p>

<?php if (!$matriculas): ?>
    <div class="portal-card portal-vazio"><i class="bi bi-journal-plus"></i>Você ainda não tem cursos. <a href="/portal/turmas.php">Veja as turmas abertas</a>.</div>
<?php endif; ?>

<?php foreach ($matriculas as $m): ?>
    <div class="portal-card">
        <div class="d-flex flex-wrap justify-content-between gap-2">
            <div>
                <div class="fw-semibold fs-5"><?= e($m['curso_nome']) ?></div>
                <div class="text-muted small"><?= e($m['turma_nome']) ?> · <?= e(periodoTurma($m, true)) ?></div>
            </div>
            <div>
                <?php if ($m['status'] === 'pendente'): ?>
                    <span class="etiqueta aguardando"><?= $m['lista_espera'] ? 'Lista de espera' : 'Aguardando pagamento' ?></span>
                <?php elseif ($m['status'] === 'ativa'): ?>
                    <span class="etiqueta paga">Matriculado</span>
                <?php elseif ($m['status'] === 'concluida'): ?>
                    <span class="etiqueta aberta">Concluído</span>
                <?php else: ?>
                    <span class="etiqueta vencida">Cancelada</span>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($m['status'] === 'pendente' && !$m['lista_espera']): ?>
            <div class="alert alert-warning mt-3 mb-0">
                <div class="mb-2">
                    Para confirmar sua matrícula, pague <strong><?= e($m['confirmacao_descricao'] ?: 'a primeira parcela') ?></strong>
                    <?php if ($m['confirmacao_valor']): ?>(<?= formatMoney($m['confirmacao_valor']) ?>)<?php endif; ?>
                    até <strong><?= formatDateTime($m['pagamento_ate']) ?></strong>.
                    <?= $m['segura_vaga'] ? 'Sua vaga está reservada até lá.' : 'A vaga é garantida quando o pagamento for confirmado.' ?>
                </div>
                <?php if ($m['conta_receber_confirmacao_id'] && $m['confirmacao_status'] === 'pendente'): ?>
                    <a href="/portal/pagar.php?id=<?= (int) $m['conta_receber_confirmacao_id'] ?>" class="btn btn-warning"><i class="bi bi-qr-code"></i> Pagar com Pix ou boleto</a>
                <?php elseif ($m['confirmacao_status'] === 'pago'): ?>
                    <span class="small">Pagamento recebido — confirmando sua vaga.</span>
                <?php endif; ?>
                <div class="small mt-2 text-muted">Pagou pelo Pix? A confirmação costuma chegar em poucos minutos.</div>
            </div>
        <?php elseif ($m['status'] === 'pendente'): ?>
            <div class="alert alert-info mt-3 mb-0">Recebemos seu pagamento, mas a turma lotou antes. A secretaria vai entrar em contato com você.</div>
        <?php elseif ($m['status'] === 'ativa'): ?>
            <div class="d-flex flex-wrap gap-3 mt-3 small">
                <?php if ($agenda = textoAgendaTurma($m)): ?><span><i class="bi bi-clock"></i> <?= e($agenda) ?></span><?php endif; ?>
                <?php if ($m['local']): ?><span><i class="bi bi-geo-alt"></i> <?= e($m['local']) ?></span><?php endif; ?>
                <?php if ($m['proxima_aula']): ?><span><i class="bi bi-calendar-event"></i> Próxima aula: <?= formatDate($m['proxima_aula']) ?></span><?php endif; ?>
            </div>
            <div class="mt-3 d-flex gap-2 flex-wrap">
                <a href="/portal/calendario.php" class="btn btn-sm btn-outline-brand"><i class="bi bi-calendar-week"></i> Calendário</a>
                <a href="/portal/financeiro.php" class="btn btn-sm btn-outline-brand"><i class="bi bi-wallet2"></i> Pagamentos</a>
                <a href="/portal/protocolos.php?novo=1&matricula=<?= (int) $m['id'] ?>" class="btn btn-sm btn-outline-brand"><i class="bi bi-chat-left-text"></i> Justificar falta / pedir reposição</a>
                <?php if (array_filter(documentosMatricula($pdo, (int) $m['id']), fn ($d) => $d['situacao'] === 'liberado')): ?><a href="/portal/certificados.php" class="btn btn-sm btn-outline-brand"><i class="bi bi-award"></i> Declarações</a><?php endif; ?>
            </div>
        <?php elseif ($m['status'] === 'concluida'): ?>
            <?php $docs = documentosMatricula($pdo, (int) $m['id']); $pendente = array_filter($docs, fn ($d) => $d['situacao'] === 'pendente'); ?>
            <?php if ($pendente): ?>
                <div class="small mt-3 text-danger"><i class="bi bi-exclamation-circle"></i> Certificado bloqueado: <?= e(reset($pendente)['texto']) ?>. <a href="/portal/certificados.php">Ver detalhes</a></div>
            <?php elseif (array_filter($docs, fn ($d) => $d['situacao'] === 'liberado')): ?>
                <a href="/portal/certificados.php" class="btn btn-sm btn-brand mt-3"><i class="bi bi-award"></i> Ver certificados</a>
            <?php elseif ($docs): ?>
                <div class="small text-muted mt-3"><i class="bi bi-hourglass-split"></i> Seu certificado está em análise pela secretaria. Em caso de dúvida, fale com a gente.</div>
            <?php endif; ?>
        <?php elseif ($m['observacao']): ?>
            <div class="small text-muted mt-2"><?= e($m['observacao']) ?></div>
        <?php endif; ?>
    </div>
<?php endforeach; ?>
<?php require __DIR__ . '/../../includes/portal_footer.php'; ?>
