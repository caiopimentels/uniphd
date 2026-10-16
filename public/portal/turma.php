<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

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
$mensalidade = (float) $turma['valor_mensalidade'];
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
            <img src="<?= e($turma['portal_imagem']) ?>" alt="" class="w-100 rounded-4 mb-3" style="aspect-ratio: 16/9; object-fit: cover;">
        <?php endif; ?>
        <h1 class="portal-titulo"><?= e($turma['curso_nome']) ?></h1>
        <p class="portal-sub mb-3"><?= e($turma['nome']) ?></p>

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
                <div class="col-6"><div class="small text-muted">Início</div><div class="fw-semibold"><?= $turma['data_inicio'] ? formatDate($turma['data_inicio']) : 'A definir' ?></div><?php if (!$turma['data_inicio'] && $turma['minimo_alunos']): ?><div class="small text-muted">A turma começa ao fechar <?= (int) $turma['minimo_alunos'] ?> alunos</div><?php endif; ?></div>
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
            <?php else: ?>
                <div class="fs-3 fw-bold" style="color: var(--accent-dark);">Gratuito</div>
            <?php endif; ?>
            <?php if ($taxa > 0): ?><div class="mb-2">+ taxa de matrícula de <strong><?= formatMoney($taxa) ?></strong></div><?php endif; ?>
            <div class="mb-3"><span class="selo-vagas <?= $restam <= 5 ? 'poucas' : '' ?>"><?= $restam > 0 && $restam <= 5 ? '<i class="bi bi-fire"></i> ' : '' ?><?= e(textoVagasRestantes($restam)) ?></span></div>

            <?php if ($minhaMatricula): ?>
                <div class="alert alert-<?= $minhaMatricula['status'] === 'pendente' ? 'warning' : 'success' ?> mb-2">
                    <?= $minhaMatricula['status'] === 'pendente' ? 'Você já se inscreveu — falta o pagamento.' : 'Você já está matriculado nesta turma.' ?>
                </div>
                <a href="/portal/meus_cursos.php" class="btn btn-brand btn-lg w-100"><?= $minhaMatricula['status'] === 'pendente' ? 'Ir para o pagamento' : 'Ver meus cursos' ?></a>
            <?php elseif (!$restam): ?>
                <button class="btn btn-secondary btn-lg w-100" disabled>Vagas esgotadas</button>
            <?php else: ?>
                <form method="post" action="/portal/inscrever.php" onsubmit="this.querySelector('button').disabled = true;">
                    <input type="hidden" name="turma_id" value="<?= (int) $turma['id'] ?>">
                    <button type="submit" class="btn btn-brand btn-lg w-100"><?= alunoLogado() ? 'Quero me inscrever' : 'Inscrever-me' ?></button>
                </form>
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
<?php require __DIR__ . '/../../includes/portal_footer.php'; ?>
