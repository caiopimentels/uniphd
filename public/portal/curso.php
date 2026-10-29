<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirTelaPortal('meus_cursos');
exigirAluno();

// Área do curso (Meus cursos › Entrar no curso, ?m=matrícula): tudo da
// turma num lugar só — início (próxima aula, progresso, pendências),
// videoaulas, materiais, aulas, pagamentos, certificados e ajuda
// (protocolos). Matrícula ativa, pendente ou concluída.
$pdo = getDB();
$alunoId = (int) alunoAtualId();
$stmt = $pdo->prepare(
    "SELECT m.*, t.nome AS turma_nome, t.data_inicio, t.data_termino, t.local, t.dias_semana, t.hora_inicio, t.hora_fim, t.agenda, t.horario, t.minimo_alunos,
            t.duracao_unidade, t.portal_imagem, c.id AS curso_id, c.nome AS curso_nome, p.nome AS professor_nome
     FROM matriculas m JOIN turmas t ON t.id = m.turma_id JOIN cursos c ON c.id = t.curso_id LEFT JOIN professores p ON p.id = t.professor_id
     WHERE m.id = ? AND m.aluno_id = ? AND m.status IN ('ativa', 'pendente', 'concluida', 'trancada')"
);
$stmt->execute([(int) ($_GET['m'] ?? 0), $alunoId]);
$m = $stmt->fetch();
if (!$m) {
    flash('warning', 'Curso não encontrado.');
    redirect('/portal/meus_cursos.php');
}
$turmaId = (int) $m['turma_id'];
$hoje = date('Y-m-d');

// Videoaulas e materiais (só o liberado). Trancado só vê se a regra deixar.
$veConteudo = $m['status'] !== 'trancada' || !empty(buscarConfiguracoes($pdo)['trancamento_portal']);
$secoesVideo = $veConteudo ? videoaulasDaTurma($pdo, $turmaId, true, $alunoId) : [];
[$vFeitas, $vTotal] = progressoVideoaulas($secoesVideo);
$proximaVideo = null;
foreach (array_merge(...array_values($secoesVideo ?: [[]])) as $v) {
    if (empty($v['concluido_em'])) {
        $proximaVideo = $v;
        break;
    }
}
$materiais = $veConteudo ? materiaisDaTurma($pdo, $turmaId, true) : [];
$stmt = $pdo->prepare('SELECT material_id FROM material_acessos WHERE aluno_id = ?');
$stmt->execute([$alunoId]);
$abertos = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
$materiaisNovos = count(array_filter($materiais, fn ($x) => !in_array((int) $x['id'], $abertos, true)));

// Aulas da turma.
$stmt = $pdo->prepare('SELECT * FROM turma_aulas WHERE turma_id = ? ORDER BY data, hora_inicio');
$stmt->execute([$turmaId]);
$aulas = $stmt->fetchAll();
$proximaAula = null;
foreach ($aulas as $a) {
    if ($a['status'] === 'prevista' && $a['data'] >= $hoje) {
        $proximaAula = $a;
        break;
    }
}

// Pagamentos desta matrícula.
$stmt = $pdo->prepare("SELECT * FROM contas_receber WHERE matricula_id = ? AND status IN ('pendente', 'pago') AND excluido_em IS NULL ORDER BY data_vencimento, id");
$stmt->execute([$m['id']]);
$parcelas = $stmt->fetchAll();
$emAtraso = array_filter($parcelas, fn ($p) => $p['status'] === 'pendente' && $p['data_vencimento'] < $hoje);
$proximaParcela = null;
foreach ($parcelas as $p) {
    if ($p['status'] === 'pendente') {
        $proximaParcela = $p;
        break;
    }
}

// Certificados e protocolos.
sincronizarDocumentosAluno($pdo, $alunoId);
$documentos = documentosMatricula($pdo, (int) $m['id']);
$stmt = $pdo->prepare("SELECT p.codigo, p.numero, p.id, p.criado_em, p.aguardando, pa.nome AS assunto_nome, ps.nome AS status_nome, ps.cor AS status_cor, ps.encerra
                       FROM protocolos p JOIN protocolo_assuntos pa ON pa.id = p.assunto_id JOIN protocolo_status ps ON ps.id = p.status_id
                       WHERE p.aluno_id = ? AND p.matricula_id = ? ORDER BY ps.encerra, p.atualizado_em DESC");
$stmt->execute([$alunoId, $m['id']]);
$protocolos = $stmt->fetchAll();

$diasSemana = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];
$quandoAula = function (array $a) use ($diasSemana, $hoje): string {
    $dia = $a['data'] === $hoje ? 'Hoje' : ($a['data'] === date('Y-m-d', strtotime('+1 day')) ? 'Amanhã' : $diasSemana[(int) date('w', strtotime($a['data']))] . ', ' . formatDate($a['data']));
    return $dia . ($a['hora_inicio'] ? ' · ' . substr($a['hora_inicio'], 0, 5) . ($a['hora_fim'] ? ' às ' . substr($a['hora_fim'], 0, 5) : '') : '');
};
$statusMatricula = match ($m['status']) {
    'ativa' => ['Cursando', 'paga'],
    'concluida' => ['Concluído', 'aberta'],
    'trancada' => ['Trancado', 'aguardando'],
    default => [$m['lista_espera'] ? 'Lista de espera' : 'Aguardando pagamento', 'aguardando'],
};

$tituloPagina = $m['curso_nome'];
$telaPortal = 'meus_cursos';
require __DIR__ . '/../../includes/portal_header.php';
?>
<style>
    .curso-capa { position: relative; border-radius: 18px; overflow: hidden; color: #fff; min-height: 190px; display: flex; align-items: flex-end;
        background: linear-gradient(135deg, var(--accent-dark), var(--accent)); margin-bottom: 18px; }
    .curso-capa img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; }
    .curso-capa::after { content: ''; position: absolute; inset: 0; background: linear-gradient(180deg, rgba(0,0,0,.05) 20%, rgba(0,0,0,.75)); }
    .curso-capa .conteudo { position: relative; z-index: 1; padding: 22px 24px; width: 100%; }
    .curso-capa h1 { font-size: 1.6rem; font-weight: 700; margin: 0 0 4px; }
    .curso-capa .info { display: flex; flex-wrap: wrap; gap: 6px 18px; font-size: 14px; opacity: .95; }
    .curso-abas { display: flex; gap: 4px; overflow-x: auto; border-bottom: 1px solid var(--border); margin-bottom: 18px; scrollbar-width: none; }
    .curso-abas button { border: 0; background: none; padding: 10px 14px; font-weight: 600; color: var(--muted, #64748b); border-bottom: 3px solid transparent; white-space: nowrap; }
    .curso-abas button.ativa { color: var(--accent-dark, var(--accent)); border-bottom-color: var(--accent); }
    .curso-abas .qtd { font-size: 11px; background: var(--accent-soft); color: var(--accent-dark, var(--accent)); border-radius: 10px; padding: 1px 7px; margin-left: 4px; }
    .curso-painel { display: none; }
    .curso-painel.ativo { display: block; }
    .resumo-grade { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 14px; }
    .resumo-card { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; padding: 16px; display: flex; flex-direction: column; gap: 6px; }
    .resumo-card .rotulo { font-size: 12px; text-transform: uppercase; letter-spacing: .05em; color: var(--muted, #64748b); font-weight: 600; display: flex; align-items: center; gap: 6px; }
    .resumo-card .valor { font-size: 1.15rem; font-weight: 700; }
    .resumo-card .acao { margin-top: auto; padding-top: 6px; }
    .linha-lista { display: flex; align-items: center; gap: 12px; padding: 12px 16px; border-bottom: 1px solid var(--border); color: inherit; text-decoration: none; }
    .linha-lista:last-child { border-bottom: 0; }
    a.linha-lista:hover { background: var(--accent-soft); }
    .lista-card { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; overflow: hidden; }
    .lista-titulo { font-weight: 700; margin: 18px 0 8px; display: flex; align-items: center; gap: 8px; }
    .aula-passada { opacity: .55; }
</style>

<a href="/portal/meus_cursos.php" class="small d-inline-block mb-2"><i class="bi bi-arrow-left"></i> Meus cursos</a>
<div class="curso-capa">
    <?php if ($m['portal_imagem']): ?><img src="<?= e($m['portal_imagem']) ?>" alt=""><?php endif; ?>
    <div class="conteudo">
        <span class="etiqueta <?= $statusMatricula[1] ?> mb-2"><?= e($statusMatricula[0]) ?></span>
        <h1><?= e($m['curso_nome']) ?></h1>
        <div class="info">
            <span><i class="bi bi-people"></i> <?= e($m['turma_nome']) ?></span>
            <span><i class="bi bi-calendar-range"></i> <?= e(periodoTurma($m, true)) ?></span>
            <?php if ($agenda = textoAgendaTurma($m)): ?><span><i class="bi bi-clock"></i> <?= e($agenda) ?></span><?php endif; ?>
            <?php if ($m['local']): ?><span><i class="bi bi-geo-alt"></i> <?= e($m['local']) ?></span><?php endif; ?>
            <?php if ($m['professor_nome']): ?><span><i class="bi bi-person-video3"></i> <?= e($m['professor_nome']) ?></span><?php endif; ?>
        </div>
    </div>
</div>

<?php if ($m['status'] === 'pendente' && !$m['lista_espera'] && $m['conta_receber_confirmacao_id']): ?>
    <div class="alert alert-warning d-flex flex-wrap align-items-center gap-2">
        <i class="bi bi-exclamation-triangle"></i><div class="flex-grow-1">Para confirmar sua matrícula, pague a primeira parcela até <strong><?= formatDateTime($m['pagamento_ate']) ?></strong>.</div>
        <a href="/portal/pagar.php?id=<?= (int) $m['conta_receber_confirmacao_id'] ?>" class="btn btn-warning btn-sm"><i class="bi bi-qr-code"></i> Pagar</a>
    </div>
<?php endif; ?>

<?php if ($m['status'] === 'trancada'): ?>
    <div class="alert alert-info"><i class="bi bi-pause-circle"></i> Sua matrícula está <strong>trancada</strong> desde <?= formatDate($m['data_trancamento']) ?><?= $m['trancamento_previsao'] ? ', com volta prevista em ' . formatDate($m['trancamento_previsao']) : '' ?>. Para voltar, fale com a secretaria.</div>
<?php endif; ?>
<nav class="curso-abas" id="abas">
    <button type="button" data-aba="inicio"><i class="bi bi-house"></i> Início</button>
    <button type="button" data-aba="videos"><i class="bi bi-play-btn"></i> Videoaulas<?php if ($vTotal): ?><span class="qtd"><?= $vFeitas ?>/<?= $vTotal ?></span><?php endif; ?></button>
    <button type="button" data-aba="materiais"><i class="bi bi-folder2-open"></i> Materiais<?php if ($materiais): ?><span class="qtd"><?= count($materiais) ?></span><?php endif; ?></button>
    <button type="button" data-aba="aulas"><i class="bi bi-calendar-week"></i> Aulas</button>
    <button type="button" data-aba="pagamentos"><i class="bi bi-wallet2"></i> Pagamentos<?php if ($emAtraso): ?><span class="qtd text-bg-danger">!</span><?php endif; ?></button>
    <button type="button" data-aba="documentos"><i class="bi bi-award"></i> Certificados</button>
    <button type="button" data-aba="ajuda"><i class="bi bi-chat-left-text"></i> Ajuda</button>
</nav>

<!-- Início -->
<section class="curso-painel" data-painel="inicio">
    <div class="resumo-grade">
        <div class="resumo-card">
            <div class="rotulo"><i class="bi bi-calendar-event"></i> Próxima aula</div>
            <?php if ($proximaAula): ?>
                <div class="valor"><?= e($quandoAula($proximaAula)) ?></div>
                <?php if ($proximaAula['tema']): ?><div class="small text-muted"><?= e($proximaAula['tema']) ?></div><?php endif; ?>
                <?php if ($m['local']): ?><div class="small text-muted"><i class="bi bi-geo-alt"></i> <?= e($m['local']) ?></div><?php endif; ?>
            <?php else: ?>
                <div class="text-muted"><?= $m['status'] === 'concluida' ? 'Curso concluído.' : 'Datas a definir.' ?></div>
            <?php endif; ?>
            <div class="acao"><button type="button" class="btn btn-sm btn-outline-brand" onclick="abrirAba('aulas')">Ver todas as aulas</button></div>
        </div>
        <div class="resumo-card">
            <div class="rotulo"><i class="bi bi-play-btn"></i> Videoaulas</div>
            <?php if ($vTotal): ?>
                <div class="valor"><?= $vFeitas ?> de <?= $vTotal ?> assistidas</div>
                <div class="progress" style="height: 7px"><div class="progress-bar bg-success" style="width: <?= $vFeitas / $vTotal * 100 ?>%"></div></div>
                <?php if ($proximaVideo): ?><div class="small text-muted text-truncate">A seguir: <?= e($proximaVideo['titulo']) ?></div><?php endif; ?>
                <div class="acao"><a href="/portal/videoaulas.php?m=<?= (int) $m['id'] ?>" class="btn btn-sm btn-brand"><i class="bi bi-play-fill"></i> <?= $vFeitas ? 'Continuar assistindo' : 'Começar' ?></a></div>
            <?php else: ?>
                <div class="text-muted"><?= $veConteudo ? 'Nenhuma videoaula ainda.' : 'Liberadas quando a matrícula for destrancada.' ?></div>
            <?php endif; ?>
        </div>
        <div class="resumo-card">
            <div class="rotulo"><i class="bi bi-folder2-open"></i> Materiais</div>
            <?php if (!$veConteudo): ?><div class="text-muted">Liberados quando a matrícula for destrancada.</div><?php else: ?>
            <div class="valor"><?= count($materiais) ?> disponíve<?= count($materiais) === 1 ? 'l' : 'is' ?></div><?php endif; ?>
            <?php if ($materiaisNovos): ?><div class="small"><span class="badge text-bg-danger"><?= $materiaisNovos ?> novo(s)</span> que você ainda não abriu</div><?php endif; ?>
            <div class="acao"><button type="button" class="btn btn-sm btn-outline-brand" onclick="abrirAba('materiais')">Ver materiais</button></div>
        </div>
        <div class="resumo-card">
            <div class="rotulo"><i class="bi bi-wallet2"></i> Pagamentos</div>
            <?php if ($emAtraso): ?>
                <div class="valor text-danger"><?= count($emAtraso) ?> em atraso</div>
                <div class="small text-muted"><?= formatMoney(array_sum(array_column($emAtraso, 'valor'))) ?></div>
            <?php elseif ($proximaParcela): ?>
                <div class="valor">Em dia</div>
                <div class="small text-muted">Próxima: <?= formatMoney($proximaParcela['valor']) ?> em <?= formatDate($proximaParcela['data_vencimento']) ?></div>
            <?php else: ?>
                <div class="valor text-success">Tudo pago</div>
            <?php endif; ?>
            <div class="acao"><button type="button" class="btn btn-sm <?= $emAtraso ? 'btn-danger' : 'btn-outline-brand' ?>" onclick="abrirAba('pagamentos')"><?= $emAtraso ? 'Regularizar' : 'Ver parcelas' ?></button></div>
        </div>
    </div>
</section>

<!-- Videoaulas -->
<section class="curso-painel" data-painel="videos">
    <?php if (!$vTotal): ?>
        <div class="portal-card portal-vazio"><i class="bi bi-play-btn"></i>Ainda não há videoaulas neste curso. Quando o professor publicar, elas aparecem aqui.</div>
    <?php else: ?>
    <div class="d-flex flex-wrap align-items-center gap-3 mb-2">
        <div class="flex-grow-1"><div class="progress" style="height: 8px"><div class="progress-bar bg-success" style="width: <?= $vFeitas / $vTotal * 100 ?>%"></div></div></div>
        <a href="/portal/videoaulas.php?m=<?= (int) $m['id'] ?>" class="btn btn-brand"><i class="bi bi-play-fill"></i> <?= $vFeitas ? 'Continuar assistindo' : 'Começar' ?></a>
    </div>
    <?php foreach ($secoesVideo as $secao => $videos): ?>
        <div class="lista-titulo"><i class="bi bi-collection-play"></i> <?= e($secao) ?></div>
        <div class="lista-card">
            <?php foreach ($videos as $v): ?>
                <a class="linha-lista" href="/portal/videoaulas.php?<?= e(http_build_query(['m' => $m['id'], 'v' => $v['codigo']])) ?>">
                    <i class="bi <?= !empty($v['concluido_em']) ? 'bi-check-circle-fill text-success' : 'bi-play-circle text-muted' ?> fs-5"></i>
                    <img src="<?= e(capaYoutube($v['youtube_id'])) ?>" alt="" style="width: 88px; aspect-ratio: 16/9; object-fit: cover; border-radius: 6px" loading="lazy">
                    <span class="flex-grow-1"><?= e($v['titulo']) ?></span>
                    <?php if ($v['duracao_seg']): ?><span class="small text-muted"><?= duracaoLegivel((int) $v['duracao_seg']) ?></span><?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
    <?php endif; ?>
</section>

<!-- Materiais -->
<section class="curso-painel" data-painel="materiais">
    <?php if (!$materiais): ?>
        <div class="portal-card portal-vazio"><i class="bi bi-folder2-open"></i>Ainda não há materiais para este curso.</div>
    <?php endif; ?>
    <?php $porLocal = []; foreach ($materiais as $mt) { $porLocal[$mt['local_nome'] . '|' . $mt['local_icone']][] = $mt; } ?>
    <?php foreach ($porLocal as $chave => $grupo): [$local, $icone] = explode('|', $chave); ?>
        <div class="lista-titulo"><i class="bi <?= e($icone) ?>"></i> <?= e($local) ?></div>
        <div class="lista-card">
            <?php foreach ($grupo as $mt): $ver = in_array($mt['arquivo_tipo'], ['application/pdf', 'image/png', 'image/jpeg'], true); ?>
                <a class="linha-lista" href="/portal/material.php?c=<?= e($mt['codigo']) ?>" target="_blank" rel="noopener">
                    <i class="bi <?= $mt['tipo'] === 'link' ? 'bi-link-45deg text-primary' : iconeArquivo($mt['arquivo_tipo'], $mt['arquivo_nome']) ?> fs-4"></i>
                    <span class="flex-grow-1"><span class="fw-semibold d-block"><?= e($mt['titulo']) ?><?php if (!in_array((int) $mt['id'], $abertos, true)): ?> <span class="badge text-bg-danger">Novo</span><?php endif; ?></span>
                        <span class="small text-muted"><?= $mt['modulo_nome'] ? e($mt['modulo_nome']) . ' · ' : '' ?><?= $mt['tipo'] === 'link' ? e(parse_url($mt['link'], PHP_URL_HOST) ?: 'link') : tamanhoLegivel((int) $mt['tamanho']) ?></span></span>
                    <span class="small text-nowrap" style="color: var(--accent)"><i class="bi <?= $mt['tipo'] === 'link' ? 'bi-box-arrow-up-right' : ($ver ? 'bi-eye' : 'bi-download') ?>"></i> <?= $mt['tipo'] === 'link' ? 'Abrir' : ($ver ? 'Ver' : 'Baixar') ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
</section>

<!-- Aulas -->
<section class="curso-painel" data-painel="aulas">
    <?php
    $linhaAula = function (array $a) use ($quandoAula, $proximaAula, $hoje): void { $passada = $a['data'] < $hoje; ?>
        <div class="linha-lista <?= $passada ? 'aula-passada' : '' ?>">
            <i class="bi <?= $a['status'] === 'cancelada' ? 'bi-x-circle text-danger' : ($passada ? 'bi-check2' : 'bi-calendar-event') ?> fs-5"></i>
            <span class="flex-grow-1"><span class="fw-semibold"><?= e($quandoAula($a)) ?></span><?php if ($a['tema']): ?><span class="small text-muted d-block"><?= e($a['tema']) ?></span><?php endif; ?></span>
            <?php if ($a['status'] === 'cancelada'): ?><span class="etiqueta vencida">Cancelada</span>
            <?php elseif ($proximaAula && $a['id'] === $proximaAula['id']): ?><span class="etiqueta paga">Próxima</span><?php endif; ?>
        </div>
    <?php };
    $futuras = array_filter($aulas, fn ($a) => $a['data'] >= $hoje);
    $passadas = array_reverse(array_filter($aulas, fn ($a) => $a['data'] < $hoje));
    ?>
    <?php if (!$aulas): ?>
        <div class="portal-card portal-vazio"><i class="bi bi-calendar-week"></i>As datas das aulas ainda não foram definidas.</div>
    <?php endif; ?>
    <?php if ($futuras): ?>
        <div class="lista-card"><?php foreach (array_slice($futuras, 0, 8) as $a) { $linhaAula($a); } ?></div>
        <?php if (count($futuras) > 8): ?>
            <details class="mt-3">
                <summary class="small fw-semibold" style="cursor: pointer">Ver as próximas <?= count($futuras) - 8 ?> aulas</summary>
                <div class="lista-card mt-2"><?php foreach (array_slice($futuras, 8) as $a) { $linhaAula($a); } ?></div>
            </details>
        <?php endif; ?>
    <?php elseif ($aulas): ?>
        <div class="text-muted small mb-2">Não há mais aulas previstas.</div>
    <?php endif; ?>
    <?php if ($passadas): ?>
        <details class="mt-3">
            <summary class="small fw-semibold" style="cursor: pointer">Aulas que já passaram (<?= count($passadas) ?>)</summary>
            <div class="lista-card mt-2"><?php foreach ($passadas as $a) { $linhaAula($a); } ?></div>
        </details>
    <?php endif; ?>
</section>

<!-- Pagamentos -->
<section class="curso-painel" data-painel="pagamentos">
    <?php if (!$parcelas): ?>
        <div class="portal-card portal-vazio"><i class="bi bi-wallet2"></i>Nenhuma parcela deste curso.</div>
    <?php else: ?>
        <div class="lista-card">
            <?php foreach ($parcelas as $p): $atrasada = $p['status'] === 'pendente' && $p['data_vencimento'] < $hoje; ?>
                <div class="linha-lista">
                    <i class="bi <?= $p['status'] === 'pago' ? 'bi-check-circle-fill text-success' : ($atrasada ? 'bi-exclamation-circle-fill text-danger' : 'bi-circle text-muted') ?> fs-5"></i>
                    <span class="flex-grow-1"><span class="fw-semibold d-block"><?= e($p['descricao']) ?></span>
                        <span class="small text-muted"><?= $p['status'] === 'pago' ? 'Pago em ' . formatDate($p['data_recebimento']) : 'Vence em ' . formatDate($p['data_vencimento']) ?></span></span>
                    <span class="fw-semibold text-nowrap"><?= formatMoney($p['status'] === 'pago' ? ($p['valor_recebido'] ?? $p['valor']) : $p['valor']) ?></span>
                    <?php if ($p['status'] === 'pendente'): ?><a href="/portal/pagar.php?id=<?= (int) $p['id'] ?>" class="btn btn-sm <?= $atrasada ? 'btn-danger' : 'btn-brand' ?>">Pagar</a><?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<!-- Certificados -->
<section class="curso-painel" data-painel="documentos">
    <?php if (!$documentos): ?>
        <div class="portal-card portal-vazio"><i class="bi bi-award"></i>Este curso não tem certificados ou declarações pelo portal.</div>
    <?php else: $md = matriculaParaDocumentos($pdo, (int) $m['id']); $bloqueios = $md ? bloqueiosDocumentosMatricula($pdo, $md) : []; ?>
        <div class="lista-card">
            <?php foreach ($documentos as $d): $liberado = $d['situacao'] === 'liberado' && !empty($d['emitido']['codigo']); ?>
                <div class="linha-lista">
                    <i class="bi bi-award fs-4 <?= $liberado ? '' : 'text-muted' ?>" style="<?= $liberado ? 'color: var(--accent)' : '' ?>"></i>
                    <span class="flex-grow-1"><span class="fw-semibold d-block"><?= e($d['nome']) ?></span>
                        <span class="small text-muted"><?= $liberado ? 'Código ' . e(formatarCodigoCertificado($d['emitido']['codigo']))
                            : (in_array($d['situacao'], ['pendente', 'bloqueado'], true) ? 'Fica disponível quando as pendências forem resolvidas.' : e($d['texto'])) ?></span></span>
                    <?php if ($liberado): ?>
                        <a href="/portal/certificado.php?c=<?= e($d['emitido']['codigo']) ?>" class="btn btn-sm btn-brand" target="_blank">Abrir</a>
                    <?php elseif (in_array($d['situacao'], ['pendente', 'bloqueado'], true) && $bloqueios): ?>
                        <div class="d-flex flex-column align-items-end gap-1">
                            <?php if (isset($bloqueios['documentacao'])): ?><a href="/portal/documentos.php" class="etiqueta vencida text-decoration-none text-nowrap" title="<?= e($bloqueios['documentacao']) ?>">Documentação</a><?php endif; ?>
                            <?php if (isset($bloqueios['financeiro'])): ?><button type="button" class="etiqueta vencida border-0 text-nowrap" onclick="abrirAba('pagamentos')">Pagamento</button><?php endif; ?>
                        </div>
                    <?php elseif ($d['situacao'] === 'indisponivel'): ?>
                        <span class="etiqueta aberta text-nowrap">Em breve</span>
                    <?php elseif ($d['situacao'] === 'aguardando'): ?>
                        <span class="etiqueta aguardando text-nowrap">Em análise</span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<!-- Ajuda -->
<section class="curso-painel" data-painel="ajuda">
    <div class="d-flex flex-wrap gap-2 mb-3">
        <a href="/portal/protocolos.php?novo=1&matricula=<?= (int) $m['id'] ?>" class="btn btn-brand"><i class="bi bi-plus-lg"></i> Justificar falta / pedir reposição</a>
        <?php if (!empty($configPortal['whatsapp_numero']) && ($linkZapCurso = linkWhatsapp($configPortal['whatsapp_numero']))): ?><a href="<?= e($linkZapCurso) ?>" target="_blank" rel="noopener" class="btn btn-outline-success"><i class="bi bi-whatsapp"></i> Falar com a secretaria</a><?php endif; ?>
    </div>
    <?php if ($protocolos): ?>
        <div class="lista-card">
            <?php foreach ($protocolos as $pr): ?>
                <a class="linha-lista" href="/portal/protocolo.php?c=<?= e($pr['codigo']) ?>">
                    <i class="bi bi-chat-left-text fs-5 text-muted"></i>
                    <span class="flex-grow-1"><span class="fw-semibold d-block"><?= e($pr['assunto_nome']) ?></span><span class="small text-muted">Nº <?= e(numeroProtocolo($pr)) ?> · <?= formatDate($pr['criado_em']) ?></span></span>
                    <?= badgeStatusProtocolo($pr['status_nome'], $pr['status_cor']) ?>
                    <?php if (!$pr['encerra'] && $pr['aguardando'] === 'aluno'): ?><span class="badge text-bg-warning">Responder</span><?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="text-muted small">Nenhum pedido aberto para este curso.</div>
    <?php endif; ?>
</section>

<script>
function abrirAba(aba) {
    if (!document.querySelector(`[data-painel="${aba}"]`)) aba = 'inicio';
    document.querySelectorAll('[data-aba]').forEach((b) => b.classList.toggle('ativa', b.dataset.aba === aba));
    document.querySelectorAll('[data-painel]').forEach((p) => p.classList.toggle('ativo', p.dataset.painel === aba));
    history.replaceState(null, '', '#' + aba);
}
document.querySelectorAll('[data-aba]').forEach((b) => b.addEventListener('click', () => abrirAba(b.dataset.aba)));
abrirAba(location.hash.slice(1) || 'inicio');
</script>
<?php require __DIR__ . '/../../includes/portal_footer.php'; ?>
