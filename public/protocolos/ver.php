<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirPermissao('ver_protocolos', 'Seu perfil não tem acesso aos protocolos.');

// Um protocolo, para a secretaria: dados, a conversa (com as notas internas
// em destaque) e o quadro para responder, anexar, deixar nota interna e
// mudar o status. Resposta ao aluno (não interna) vai também por e-mail.
$pdo = getDB();
$id = (int) ($_GET['id'] ?? 0);
$protocolo = buscarProtocolo($pdo, 'id', $id);
if (!$protocolo) {
    flash('danger', 'Protocolo não encontrado.');
    redirect('/protocolos/listar.php');
}
$statusLista = statusProtocolo($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $texto = mb_substr(trim((string) ($_POST['texto'] ?? '')), 0, 5000);
    $interna = !empty($_POST['interna']);
    $novoStatus = null;
    foreach ($statusLista as $st) {
        if ((int) $st['id'] === (int) ($_POST['status_id'] ?? 0)) {
            $novoStatus = $st;
        }
    }
    $mudouStatus = $novoStatus && (int) $novoStatus['id'] !== (int) $protocolo['status_id'];
    // Encerrado não recebe mensagem: só pode ser reaberto (status que não encerra).
    if ($protocolo['status_encerra']) {
        if (!$mudouStatus || $novoStatus['encerra']) {
            flash('warning', 'Este protocolo está encerrado. Para escrever de novo, reabra o protocolo.');
            redirect('/protocolos/ver.php?id=' . $id);
        }
        $texto = '';
    }
    $anexo = $protocolo['status_encerra'] ? ['ok' => true, 'anexo' => null] : anexoProtocolo((int) $protocolo['aluno_id'], $_FILES['anexo'] ?? null);
    if (!$anexo['ok']) {
        flash('danger', $anexo['mensagem']);
        redirect('/protocolos/ver.php?id=' . $id);
    }
    if ($texto === '' && !$anexo['anexo'] && !$mudouStatus) {
        flash('warning', 'Escreva a resposta, anexe um arquivo ou mude o status.');
        redirect('/protocolos/ver.php?id=' . $id);
    }

    $respondeu = $texto !== '' || $anexo['anexo'];
    if ($respondeu) {
        gravarMensagemProtocolo($pdo, $id, 'secretaria', $texto, $anexo['anexo'], $interna);
    }
    if ($mudouStatus) {
        gravarMensagemProtocolo($pdo, $id, 'sistema', 'Status alterado para "' . $novoStatus['nome'] . '" por ' . usuarioAtualNome());
    }
    // De quem é a vez: resposta ao aluno passa a vez a ele; nota interna não muda.
    $aguardando = $protocolo['aguardando'];
    if ($respondeu && !$interna) {
        $aguardando = 'aluno';
    } elseif ($aguardando === 'ninguem') {
        $aguardando = 'secretaria'; // reaberto
    }
    $encerra = $mudouStatus ? (bool) $novoStatus['encerra'] : (bool) $protocolo['status_encerra'];
    $pdo->prepare('UPDATE protocolos SET status_id = ?, aguardando = ?, atualizado_em = NOW(), encerrado_em = ? WHERE id = ?')
        ->execute([$mudouStatus ? $novoStatus['id'] : $protocolo['status_id'], $encerra ? 'ninguem' : $aguardando,
            $encerra ? ($protocolo['encerrado_em'] ?: date('Y-m-d H:i:s')) : null, $id]);

    $resumo = trim(($respondeu && !$interna ? 'nova resposta da secretaria' : '') . ($mudouStatus ? ($respondeu && !$interna ? ' e ' : '') . 'status "' . $novoStatus['nome'] . '"' : ''));
    if ($resumo !== '') {
        registrarHistoricoAluno($pdo, (int) $protocolo['aluno_id'], 'protocolo', 'Protocolo nº ' . numeroProtocolo($protocolo) . ': ' . $resumo, $protocolo['matricula_id'] ? (int) $protocolo['matricula_id'] : null);
        avisarAlunoProtocolo($pdo, $protocolo, $resumo . '.');
    }
    registrarAtividade('protocolos', 'edicao', 'Protocolo nº ' . numeroProtocolo($protocolo) . ' (' . $protocolo['aluno_nome'] . '): ' . ($resumo ?: 'nota interna'));
    flash('success', $interna && !$mudouStatus ? 'Nota interna guardada (o aluno não vê).' : 'Protocolo atualizado' . ($resumo !== '' && $protocolo['aluno_email'] ? ' — o aluno foi avisado por e-mail.' : '.'));
    redirect('/protocolos/ver.php?id=' . $id);
}

$mensagens = mensagensProtocolo($pdo, $id, true);
$stmt = $pdo->prepare('SELECT COUNT(*) FROM protocolos WHERE aluno_id = ?');
$stmt->execute([$protocolo['aluno_id']]);
$totalDoAluno = (int) $stmt->fetchColumn();

$pageTitle = 'Protocolo nº ' . numeroProtocolo($protocolo);
$pageBreadcrumb = 'Secretaria · Protocolos';
$activeMenu = 'protocolos';
$pageAction = '<a href="/protocolos/listar.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Protocolos</a>';
require __DIR__ . '/../../includes/layout_header.php';
?>
<div class="row g-4">
<div class="col-lg-8">
    <div class="card mb-3"><div class="card-body">
        <div class="d-flex flex-wrap justify-content-between gap-2 mb-3">
            <div>
                <div class="fs-5 fw-semibold"><?= e($protocolo['assunto_nome']) ?><?php if ($protocolo['tema_nome']): ?> <span class="badge text-bg-light border fw-normal fs-6"><?= e($protocolo['tema_nome']) ?></span><?php endif; ?></div>
                <div class="small text-muted">Aberto em <?= formatDateTime($protocolo['criado_em']) ?> <?= $protocolo['origem'] === 'portal' ? 'pelo portal' : 'pela secretaria' ?></div>
            </div>
            <div><?= badgeStatusProtocolo($protocolo['status_nome'], $protocolo['status_cor']) ?></div>
        </div>
        <div style="white-space: pre-line;"><?= e($protocolo['descricao']) ?></div>
    </div></div>

    <div class="conversa-protocolo" id="conversa-protocolo">
    <?php foreach ($mensagens as $msg): ?>
        <?php if ($msg['autor'] === 'sistema'): ?>
            <div class="text-center small text-muted my-2"><i class="bi bi-info-circle"></i> <?= e((string) $msg['texto']) ?> · <?= formatDateTime($msg['criado_em']) ?></div>
        <?php else: $daSecretaria = $msg['autor'] === 'secretaria'; ?>
            <div class="d-flex <?= $daSecretaria ? 'justify-content-end' : '' ?> mb-2">
                <div class="card" style="max-width: 85%; <?= $msg['interna'] ? 'background:#fff8e1; border-color:#f3d37a;' : ($daSecretaria ? 'background:#f1f5ff;' : '') ?>">
                    <div class="card-body py-2 px-3">
                        <div class="small text-muted mb-1">
                            <?= $daSecretaria ? e($msg['autor_nome'] ?: 'Secretaria') : '<i class="bi bi-person"></i> ' . e($protocolo['aluno_nome']) ?> · <?= formatDateTime($msg['criado_em']) ?>
                            <?php if ($msg['interna']): ?><span class="badge text-bg-warning ms-1">Nota interna</span><?php endif; ?>
                        </div>
                        <?php if ($msg['texto']): ?><div style="white-space: pre-line;"><?= e($msg['texto']) ?></div><?php endif; ?>
                        <?php if ($msg['anexo']): ?><div class="mt-1"><a href="/protocolos/anexo.php?m=<?= (int) $msg['id'] ?>" target="_blank"><i class="bi bi-paperclip"></i> <?= e($msg['anexo_nome'] ?: 'Anexo') ?></a></div><?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    <?php endforeach; ?>
    </div>
    <style>.conversa-protocolo { max-height: 360px; overflow-y: auto; padding: 10px 12px; border: 1px solid var(--bs-border-color, #dee2e6); border-radius: 10px; background: rgba(0, 0, 0, .015); }</style>
    <script>// Conversa longa rola dentro da caixa; abre mostrando as últimas mensagens.
    (() => { const c = document.getElementById('conversa-protocolo'); const fim = () => { c.scrollTop = c.scrollHeight; }; fim(); window.addEventListener('load', fim); })();</script>

    <?php if ($protocolo['status_encerra']): ?>
    <div class="card mt-3"><div class="card-body d-flex flex-wrap align-items-center gap-2">
        <div class="flex-grow-1"><i class="bi bi-lock"></i> Protocolo encerrado<?= $protocolo['encerrado_em'] ? ' em ' . formatDateTime($protocolo['encerrado_em']) : '' ?>. Não recebe mais mensagens.</div>
        <form method="post" class="d-flex gap-2 align-items-center">
            <select name="status_id" class="form-select form-select-sm" style="width: auto;">
                <?php foreach ($statusLista as $st): if ($st['encerra']) continue; ?>
                    <option value="<?= (int) $st['id'] ?>"><?= e($st['nome']) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-sm btn-outline-secondary text-nowrap"><i class="bi bi-unlock"></i> Reabrir</button>
        </form>
    </div></div>
    <?php else: ?>
    <div class="card mt-3"><div class="card-body">
        <form method="post" enctype="multipart/form-data" onsubmit="this.querySelector('[type=submit]').disabled = true;">
            <label class="form-label fw-semibold">Responder</label>
            <textarea name="texto" class="form-control mb-2" rows="4" maxlength="5000" placeholder="Resposta para o aluno (ou nota interna)"></textarea>
            <div class="row g-2 align-items-end">
                <div class="col-md-5">
                    <label class="form-label small mb-0">Anexo (opcional)</label>
                    <input type="file" name="anexo" class="form-control form-control-sm" accept="application/pdf,image/jpeg,image/png">
                </div>
                <div class="col-md-4">
                    <label class="form-label small mb-0">Status</label>
                    <select name="status_id" class="form-select form-select-sm">
                        <?php foreach ($statusLista as $st): ?>
                            <option value="<?= (int) $st['id'] ?>" <?= (int) $st['id'] === (int) $protocolo['status_id'] ? 'selected' : '' ?>><?= e($st['nome']) ?><?= $st['encerra'] ? ' (encerra)' : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 text-end">
                    <button type="submit" class="btn btn-brand w-100"><i class="bi bi-send"></i> Salvar</button>
                </div>
            </div>
            <div class="form-check mt-2">
                <input type="checkbox" class="form-check-input" name="interna" id="interna" value="1">
                <label class="form-check-label small" for="interna">Nota interna (o aluno não vê; não manda e-mail)</label>
            </div>
        </form>
    </div></div>
    <?php endif; ?>
</div>

<div class="col-lg-4">
    <div class="card"><div class="card-body">
        <div class="kpi-rotulo">Aluno</div>
        <div class="mb-2"><a href="/alunos/form.php?id=<?= (int) $protocolo['aluno_id'] ?>&tab=protocolos"><?= e($protocolo['aluno_nome']) ?></a>
            <?php if ($protocolo['aluno_cpf']): ?><div class="small text-muted"><?= e(cpfFormatado($protocolo['aluno_cpf'])) ?></div><?php endif; ?>
            <?php if ($totalDoAluno > 1): ?><div class="small"><a href="/alunos/form.php?id=<?= (int) $protocolo['aluno_id'] ?>&tab=protocolos"><?= $totalDoAluno ?> protocolos deste aluno</a></div><?php endif; ?>
        </div>
        <?php if ($protocolo['turma_nome']): ?>
            <div class="kpi-rotulo">Turma</div>
            <div class="mb-2"><a href="/turmas/visualizar.php?id=<?= (int) $protocolo['turma_id'] ?>"><?= e($protocolo['curso_nome'] . ' · ' . $protocolo['turma_nome']) ?></a></div>
        <?php endif; ?>
        <?php if ($protocolo['modulo_nome']): ?><div class="kpi-rotulo">Módulo</div><div class="mb-2"><?= e($protocolo['modulo_nome']) ?></div><?php endif; ?>
        <?php if ($protocolo['data_referencia']): ?><div class="kpi-rotulo">Data informada</div><div class="mb-2"><?= formatDate($protocolo['data_referencia']) ?></div><?php endif; ?>
        <div class="kpi-rotulo">Situação</div>
        <div><?= $protocolo['status_encerra'] ? 'Encerrado' . ($protocolo['encerrado_em'] ? ' em ' . formatDateTime($protocolo['encerrado_em']) : '')
            : ($protocolo['aguardando'] === 'secretaria' ? '<span class="badge text-bg-warning">Aguardando a secretaria</span>' : 'Aguardando o aluno') ?></div>
    </div></div>
</div>
</div>
<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
