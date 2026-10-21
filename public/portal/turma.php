<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirTelaPortal('turma');

$pdo = getDB();
expirarInscricoesPortal($pdo);
$turma = turmasAbertasPortal($pdo, (int) ($_GET['id'] ?? 0))[0] ?? null;
if (!$turma) {
    flash('warning', 'Esta turma não está com inscrições abertas.');
    redirect('/portal/turmas.php');
}

$restam = (int) $turma['vagas_site'];
$modulos = modulosDoCurso($pdo, (int) $turma['curso_id']);
$parcelas = numParcelasPortal($turma);
$minhaMatricula = null;
if (alunoLogado()) {
    $stmt = $pdo->prepare('SELECT * FROM matriculas WHERE turma_id = ? AND aluno_id = ?');
    $stmt->execute([$turma['id'], alunoAtualId()]);
    $minhaMatricula = $stmt->fetch() ?: null;
    if ($minhaMatricula && $minhaMatricula['status'] === 'cancelada') {
        $minhaMatricula = null;
    }
}
$taxa = (float) $turma['portal_taxa_matricula'];
$valorTotal = valorTotalTurma($turma);
$mensalidade = mensalidadeDoTotal($valorTotal, $parcelas);
// Mensalidades no cartão/dinheiro: o aluno paga na secretaria.
$formaMensalidadeSecretaria = formaPagaNaSecretaria($pdo, ($turma['forma_mensalidade_id'] ?? null) ? (int) $turma['forma_mensalidade_id'] : null);
$pagaNaInscricao = $turma['portal_modo_vaga'] !== 'inscricao' && ($taxa > 0 || $mensalidade > 0);
$prazoDias = max(1, (int) $turma['portal_prazo_pagamento_dias']);

$tituloPagina = $turma['curso_nome'];
$telaPortal = 'turma';
require __DIR__ . '/../../includes/portal_header.php';
?>
<a href="/portal/turmas.php" class="small"><i class="bi bi-arrow-left"></i> Todos os cursos</a>

<div class="row g-4 mt-1">
    <div class="col-lg-7">
        <?php if ($turma['portal_imagem']): ?>
            <div class="capa-turma capa-turma-pagina">
                <img class="fundo" src="<?= e($turma['portal_imagem']) ?>" alt="">
                <img class="arte" src="<?= e($turma['portal_imagem']) ?>" alt="">
            </div>
        <?php endif; ?>
        <h1 class="portal-titulo"><?= e($turma['curso_nome']) ?></h1>
        <p class="portal-sub mb-3"><?= e($turma['nome']) ?></p>
        <?php if (!empty($turma['parceria'])): ?><p class="mb-3"><i class="bi bi-people"></i> <?= e($turma['parceria']) ?></p><?php endif; ?>

        <?php $descricao = trim((string) ($turma['portal_descricao'] ?: $turma['curso_descricao'])); ?>
        <?php if ($descricao): ?><div class="portal-card mb-3" style="white-space: pre-line;"><?= e($descricao) ?></div><?php endif; ?>

        <?php if ($modulos): ?>
            <div class="portal-card mb-3">
                <div class="fw-semibold mb-2"><i class="bi bi-list-ol"></i> Conteúdo do curso</div>
                <?php foreach ($modulos as $i => $modulo): ?>
                    <div class="lista-linha py-2">
                        <span class="etiqueta aberta"><?= $i + 1 ?></span>
                        <div class="principal"><div class="t fw-normal"><?= e($modulo['nome']) ?></div></div>
                        <?php if ($modulo['carga_horaria']): ?><div class="small text-muted"><?= (int) $modulo['carga_horaria'] ?> h</div><?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="portal-card">
            <div class="row g-3">
                <div class="col-6"><div class="small text-muted">Início</div><div class="fw-semibold"><?= $turma['data_inicio'] ? formatDate($turma['data_inicio']) : 'A definir' ?></div><?php if (!$turma['data_inicio'] && $turma['minimo_alunos']): ?><div class="small text-muted"></div><?php endif; ?></div>
                <div class="col-6"><div class="small text-muted">Duração</div><div class="fw-semibold"><?= e(textoDuracaoTurma($turma)) ?></div></div>
                <?php if ($agenda = textoAgendaTurma($turma)): ?><div class="col-6"><div class="small text-muted">Dias e horário</div><div class="fw-semibold"><?= e($agenda) ?></div></div><?php endif; ?>
                <?php if ($turma['local']): ?><div class="col-6"><div class="small text-muted">Local</div><div class="fw-semibold"><?= e($turma['local']) ?></div></div><?php endif; ?>
                <?php if ($turma['carga_horaria']): ?><div class="col-6"><div class="small text-muted">Carga horária</div><div class="fw-semibold"><?= (int) $turma['carga_horaria'] ?> horas</div></div><?php endif; ?>
                <?php if ($turma['professor_nome']): ?><div class="col-6"><div class="small text-muted">Professor(a)</div><div class="fw-semibold"><?= e($turma['professor_nome']) ?></div></div><?php endif; ?>
                <?php if ($turma['portal_inscricoes_ate']): ?><div class="col-6"><div class="small text-muted">Inscrições até</div><div class="fw-semibold"><?= formatDate($turma['portal_inscricoes_ate']) ?></div></div><?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="portal-card" style="position: sticky; top: 80px;">
            <div class="small text-muted">Investimento</div>
            <?php if ($mensalidade > 0): ?>
                <div class="fs-3 fw-bold" style="color: var(--accent-dark);"><?= $parcelas ?>x de <?= formatMoney($mensalidade) ?></div>
                <?php if ($parcelas > 1): ?><div class="small text-muted">total do curso <?= formatMoney($valorTotal) ?></div><?php endif; ?>
                <?php if ($formaMensalidadeSecretaria): ?><div class="small"><i class="bi bi-credit-card"></i> Mensalidades: <?= e(mb_strtolower($formaMensalidadeSecretaria)) ?>, pago na secretaria</div><?php endif; ?>
            <?php else: ?>
                <div class="fs-3 fw-bold" style="color: var(--accent-dark);">Gratuito</div>
            <?php endif; ?>
            <?php if ($taxa > 0): ?><div class="mb-2">+ taxa de matrícula de <strong><?= formatMoney($taxa) ?></strong></div><?php endif; ?>
            <div class="mb-3"><span class="selo-vagas <?= $restam <= 5 ? 'poucas' : '' ?>"><?= $restam > 0 && $restam <= 5 ? '<i class="bi bi-fire"></i> ' : '' ?><?= e(textoVagasRestantes($restam)) ?></span></div>

            <?php if ($minhaMatricula): ?>
                <div class="alert alert-<?= $minhaMatricula['status'] === 'pendente' ? 'warning' : 'success' ?> mb-2">
                    <?= $minhaMatricula['status'] === 'pendente' ? 'Você já se inscreveu — falta o pagamento.' : 'Você já está matriculado nesta turma.' ?>
                </div>
                <a href="<?= $minhaMatricula['status'] === 'pendente' && $minhaMatricula['conta_receber_confirmacao_id'] ? '/portal/pagar.php?id=' . (int) $minhaMatricula['conta_receber_confirmacao_id'] : '/portal/meus_cursos.php' ?>" class="btn btn-brand btn-lg w-100"><?= $minhaMatricula['status'] === 'pendente' ? 'Ir para o pagamento' : 'Ver meus cursos' ?></a>
            <?php elseif (!$restam): ?>
                <button class="btn btn-secondary btn-lg w-100" disabled>Vagas esgotadas</button>
            <?php else: ?>
                <?php if (alunoLogado()): ?>
                    <button type="button" class="btn btn-brand btn-lg w-100" data-bs-toggle="modal" data-bs-target="#modal-inscricao">Quero me inscrever</button>
                <?php else: ?>
                    <form method="post" action="/portal/inscrever.php">
                        <input type="hidden" name="turma_id" value="<?= (int) $turma['id'] ?>">
                        <button type="submit" class="btn btn-brand btn-lg w-100">Inscrever-me</button>
                    </form>
                <?php endif; ?>
                <ul class="small text-muted mt-3 mb-0 ps-3">
                    <?php if ($pagaNaInscricao): ?>
                        <li>Para confirmar a matrícula, pague <?= $taxa > 0 ? 'a taxa de matrícula' : 'a primeira mensalidade' ?> por Pix ou boleto em até <?= $prazoDias ?> dia(s).</li>
                        <li><?= $turma['portal_modo_vaga'] === 'reserva'
                            ? 'Sua vaga fica reservada durante esse prazo.'
                            : 'A vaga é garantida quando o pagamento é confirmado (por ordem de pagamento).' ?></li>
                    <?php else: ?>
                        <li>Sua vaga é garantida na hora da inscrição.</li>
                    <?php endif; ?>
                    <?php if (!alunoLogado()): ?><li>Você vai entrar ou criar sua conta antes de concluir.</li><?php endif; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php if (alunoLogado() && !$minhaMatricula && $restam): ?>
<?php
    $primeiroVencimento = primeiroVencimentoPortal($turma, date('Y-m-d'));
    $prazoPagamento = date('d/m/Y', strtotime('+' . $prazoDias . ' days'));
?>
<div class="modal fade" id="modal-inscricao" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="post" action="/portal/inscrever.php" onsubmit="this.querySelector('[type=submit]').disabled = true; this.querySelector('[type=submit]').innerHTML = 'Inscrevendo...';">
            <input type="hidden" name="turma_id" value="<?= (int) $turma['id'] ?>">
            <div class="modal-header">
                <h5 class="modal-title">Confirme sua inscrição</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="fw-semibold fs-5"><?= e($turma['curso_nome']) ?></div>
                <div class="text-muted mb-3"><?= e($turma['nome']) ?></div>
                <div class="small mb-3">
                    <div><i class="bi bi-calendar-event"></i> Início: <?= $turma['data_inicio'] ? formatDate($turma['data_inicio']) : 'a definir' ?></div>
                    <?php if ($agenda = textoAgendaTurma($turma)): ?><div><i class="bi bi-clock"></i> <?= e($agenda) ?></div><?php endif; ?>
                    <?php if ($turma['local']): ?><div><i class="bi bi-geo-alt"></i> <?= e($turma['local']) ?></div><?php endif; ?>
                </div>
                <div class="border rounded p-3 mb-3">
                    <?php if ($taxa > 0): ?>
                        <div class="d-flex justify-content-between"><span>Matrícula <span class="text-muted small">(paga agora)</span></span><strong><?= formatMoney($taxa) ?></strong></div>
                    <?php endif; ?>
                    <?php if ($mensalidade > 0): ?>
                        <div class="d-flex justify-content-between mt-1"><span>Curso <span class="text-muted small">(<?= $parcelas ?>x de <?= formatMoney($mensalidade) ?>)</span></span><strong><?= formatMoney($valorTotal) ?></strong></div>
                        <div class="small text-muted">1ª mensalidade em <?= formatDate($primeiroVencimento) ?><?= $formaMensalidadeSecretaria ? ' · ' . e(mb_strtolower($formaMensalidadeSecretaria)) . ', pago na secretaria' : '' ?></div>
                    <?php endif; ?>
                    <?php if ($taxa <= 0 && $mensalidade <= 0): ?><div class="fw-semibold">Curso gratuito</div><?php endif; ?>
                </div>
                <?php if ($pagaNaInscricao): ?>
                    <div class="alert alert-info small mb-0">
                        <i class="bi bi-qr-code"></i>
                        Ao confirmar, você vai direto para o pagamento <?= $taxa > 0 ? 'da matrícula' : 'da 1ª mensalidade' ?> por <strong>Pix ou boleto</strong>.
                        Pague até <strong><?= $prazoPagamento ?></strong> —
                        <?= $turma['portal_modo_vaga'] === 'reserva' ? 'sua vaga fica reservada até lá.' : 'a vaga é garantida quando o pagamento for confirmado.' ?>
                    </div>
                <?php else: ?>
                    <div class="alert alert-success small mb-0"><i class="bi bi-check-circle"></i> Sua vaga é garantida na hora da inscrição.</div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Voltar</button>
                <button type="submit" class="btn btn-brand"><?= $pagaNaInscricao ? 'Confirmar e ir para o pagamento' : 'Confirmar inscrição' ?></button>
            </div>
        </form>
    </div>
</div>
<?php if (isset($_GET['inscrever'])): ?>
<script>
// Voltou do login / dos dados: abre a confirmação para não precisar clicar de novo.
document.addEventListener('DOMContentLoaded', () => bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-inscricao')).show());
</script>
<?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/../../includes/portal_footer.php'; ?>
