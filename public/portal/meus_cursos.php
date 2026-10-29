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
    "SELECT m.*, t.nome AS turma_nome, t.data_inicio, t.data_termino, t.local, t.dias_semana, t.hora_inicio, t.hora_fim, t.agenda, t.horario, t.minimo_alunos, t.duracao_unidade, t.portal_imagem,
            c.nome AS curso_nome, cr.descricao AS confirmacao_descricao, cr.valor AS confirmacao_valor, cr.status AS confirmacao_status,
            (SELECT MIN(ta.data) FROM turma_aulas ta WHERE ta.turma_id = t.id AND ta.status = 'prevista' AND ta.data >= CURDATE()) AS proxima_aula
     FROM matriculas m
     JOIN turmas t ON t.id = m.turma_id
     JOIN cursos c ON c.id = t.curso_id
     LEFT JOIN contas_receber cr ON cr.id = m.conta_receber_confirmacao_id
     WHERE m.aluno_id = ? AND (m.status <> 'cancelada' OR m.data_cancelamento >= DATE_SUB(CURDATE(), INTERVAL 30 DAY))
     ORDER BY FIELD(m.status, 'pendente', 'ativa', 'trancada', 'concluida', 'cancelada'), t.data_inicio DESC"
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

<style>
    .cursos-grade { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 18px; }
    .curso-card { background: var(--surface); border: 1px solid var(--border); border-radius: 16px; overflow: hidden; display: flex; flex-direction: column;
        color: inherit; text-decoration: none; transition: transform .15s, box-shadow .15s; }
    a.curso-card:hover { transform: translateY(-3px); box-shadow: 0 10px 28px rgba(0,0,0,.10); color: inherit; }
    .curso-card .capa { position: relative; aspect-ratio: 16/7; background: linear-gradient(135deg, var(--accent-dark, var(--accent)), var(--accent)); display: flex; align-items: center; justify-content: center; }
    .curso-card .capa img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; }
    .curso-card .capa > i { font-size: 2.6rem; color: rgba(255,255,255,.75); }
    .curso-card .capa .etiqueta { position: absolute; top: 12px; left: 12px; }
    .curso-card .corpo { padding: 16px 18px; display: flex; flex-direction: column; gap: 8px; flex-grow: 1; }
    .curso-card .nome { font-weight: 700; font-size: 1.1rem; line-height: 1.25; }
    .curso-card .meta { font-size: 13px; color: var(--muted, #64748b); display: flex; flex-direction: column; gap: 3px; }
    .curso-card .rodape { margin-top: auto; padding-top: 6px; display: flex; align-items: center; gap: 10px; }
    .curso-card .entrar { font-weight: 600; color: var(--accent-dark, var(--accent)); margin-left: auto; white-space: nowrap; }
    .curso-cancelado { opacity: .65; }
</style>

<div class="cursos-grade">
<?php foreach ($matriculas as $m):
    $entra = $m['status'] !== 'cancelada';
    [$vFeitas, $vTotal] = $entra ? progressoVideoaulas(videoaulasDaTurma($pdo, (int) $m['turma_id'], true, (int) alunoAtualId())) : [0, 0];
    $etiqueta = match ($m['status']) {
        'pendente' => [$m['lista_espera'] ? 'Lista de espera' : 'Aguardando pagamento', 'aguardando'],
        'ativa' => ['Cursando', 'paga'],
        'concluida' => ['Concluído', 'aberta'],
        'trancada' => ['Trancado', 'aguardando'],
        default => ['Cancelada', 'vencida'],
    };
    $tag = $entra ? 'a href="/portal/curso.php?m=' . (int) $m['id'] . '"' : 'div';
?>
    <<?= $tag ?> class="curso-card <?= $entra ? '' : 'curso-cancelado' ?>">
        <div class="capa">
            <?php if ($m['portal_imagem']): ?><img src="<?= e($m['portal_imagem']) ?>" alt="" loading="lazy"><?php else: ?><i class="bi bi-mortarboard"></i><?php endif; ?>
            <span class="etiqueta <?= $etiqueta[1] ?>"><?= e($etiqueta[0]) ?></span>
        </div>
        <div class="corpo">
            <div class="nome"><?= e($m['curso_nome']) ?></div>
            <div class="meta">
                <span><i class="bi bi-people"></i> <?= e($m['turma_nome']) ?> · <?= e(periodoTurma($m, true)) ?></span>
                <?php if ($m['status'] === 'ativa' && $m['proxima_aula']): ?><span><i class="bi bi-calendar-event"></i> Próxima aula: <?= $m['proxima_aula'] === date('Y-m-d') ? '<strong>hoje</strong>' : formatDate($m['proxima_aula']) ?></span><?php endif; ?>
                <?php if ($m['status'] === 'ativa' && $m['local']): ?><span><i class="bi bi-geo-alt"></i> <?= e($m['local']) ?></span><?php endif; ?>
            </div>
            <?php if ($m['status'] === 'pendente' && !$m['lista_espera'] && $m['confirmacao_status'] === 'pendente'): ?>
                <div class="small text-warning-emphasis"><i class="bi bi-exclamation-triangle"></i> Pague <?= $m['confirmacao_valor'] ? formatMoney($m['confirmacao_valor']) : 'a 1ª parcela' ?> até <?= formatDateTime($m['pagamento_ate']) ?> para confirmar.</div>
            <?php elseif ($m['status'] === 'pendente' && $m['lista_espera']): ?>
                <div class="small text-muted">A turma lotou; a secretaria vai falar com você.</div>
            <?php elseif ($m['status'] === 'cancelada' && $m['observacao']): ?>
                <div class="small text-muted"><?= e($m['observacao']) ?></div>
            <?php endif; ?>
            <?php if ($vTotal): ?>
                <div>
                    <div class="d-flex justify-content-between small text-muted mb-1"><span><i class="bi bi-play-btn"></i> Videoaulas</span><span><?= $vFeitas ?>/<?= $vTotal ?></span></div>
                    <div class="progress" style="height: 6px"><div class="progress-bar bg-success" style="width: <?= $vFeitas / $vTotal * 100 ?>%"></div></div>
                </div>
            <?php endif; ?>
            <?php if ($entra): ?><div class="rodape"><span class="entrar">Entrar no curso <i class="bi bi-arrow-right"></i></span></div><?php endif; ?>
        </div>
    </<?= $entra ? 'a' : 'div' ?>>
<?php endforeach; ?>
</div>
<?php require __DIR__ . '/../../includes/portal_footer.php'; ?>
