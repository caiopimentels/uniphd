<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirAluno();

// Um protocolo do aluno (?c=CODIGO): a conversa com a secretaria (sem as
// notas internas) e o campo para responder, com anexo. Encerrado: só leitura.
$pdo = getDB();
$protocolo = buscarProtocolo($pdo, 'codigo', strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($_GET['c'] ?? ''))));
if (!$protocolo || (int) $protocolo['aluno_id'] !== alunoAtualId()) {
    flash('warning', 'Protocolo não encontrado.');
    redirect('/portal/protocolos.php');
}
$id = (int) $protocolo['id'];
$voltar = '/portal/protocolo.php?c=' . $protocolo['codigo'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $texto = trim((string) ($_POST['texto'] ?? ''));
    if ($protocolo['status_encerra']) {
        flash('warning', 'Este protocolo está encerrado. Se precisar, abra um novo.');
    } elseif (limiteDeTentativasExcedido($pdo, 'portal_protocolo_resposta', (string) alunoAtualId(), 30, 30, 60)) {
        flash('danger', 'Muitas mensagens seguidas. Tente de novo mais tarde.');
    } else {
        registrarTentativaAcesso($pdo, 'portal_protocolo_resposta', (string) alunoAtualId());
        $anexo = anexoProtocolo(alunoAtualId(), $_FILES['anexo'] ?? null);
        if (!$anexo['ok']) {
            flash('danger', $anexo['mensagem']);
        } elseif ($texto === '' && !$anexo['anexo']) {
            flash('warning', 'Escreva a mensagem ou anexe um arquivo.');
        } else {
            gravarMensagemProtocolo($pdo, $id, 'aluno', mb_substr($texto, 0, 5000), $anexo['anexo']);
            $pdo->prepare("UPDATE protocolos SET aguardando = 'secretaria', atualizado_em = NOW() WHERE id = ?")->execute([$id]);
            criarNotificacao($pdo, 'protocolo', 'Resposta no protocolo nº ' . numeroProtocolo($protocolo), alunoAtualNome() . ' respondeu (' . $protocolo['assunto_nome'] . ').',
                '/protocolos/ver.php?id=' . $id, $id);
            flash('success', 'Mensagem enviada à secretaria.');
        }
    }
    redirect($voltar);
}

$mensagens = mensagensProtocolo($pdo, $id, false);

$tituloPagina = 'Protocolo nº ' . numeroProtocolo($protocolo);
require __DIR__ . '/../../includes/portal_header.php';
?>
<a href="/portal/protocolos.php" class="small"><i class="bi bi-arrow-left"></i> Meus protocolos</a>
<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mt-2 mb-3">
    <h1 class="portal-titulo mb-0">Protocolo nº <?= numeroProtocolo($protocolo) ?></h1>
    <?= badgeStatusProtocolo($protocolo['status_nome'], $protocolo['status_cor']) ?>
</div>

<div class="portal-card mb-3">
    <div class="row g-3 small">
        <div class="col-sm-6"><div class="text-muted">Assunto</div><div class="fw-semibold"><?= e(($protocolo['tema_nome'] ? $protocolo['tema_nome'] . ' · ' : '') . $protocolo['assunto_nome']) ?></div></div>
        <div class="col-sm-6"><div class="text-muted">Aberto em</div><div class="fw-semibold"><?= formatDateTime($protocolo['criado_em']) ?></div></div>
        <?php if ($protocolo['turma_nome']): ?><div class="col-sm-6"><div class="text-muted">Turma</div><div class="fw-semibold"><?= e($protocolo['curso_nome'] . ' · ' . $protocolo['turma_nome']) ?></div></div><?php endif; ?>
        <?php if ($protocolo['modulo_nome']): ?><div class="col-sm-6"><div class="text-muted">Módulo</div><div class="fw-semibold"><?= e($protocolo['modulo_nome']) ?></div></div><?php endif; ?>
        <?php if ($protocolo['data_referencia']): ?><div class="col-sm-6"><div class="text-muted">Data</div><div class="fw-semibold"><?= formatDate($protocolo['data_referencia']) ?></div></div><?php endif; ?>
    </div>
    <hr>
    <div style="white-space: pre-line;"><?= e($protocolo['descricao']) ?></div>
</div>

<div class="conversa-protocolo" id="conversa-protocolo">
<?php foreach ($mensagens as $msg): $minha = $msg['autor'] === 'aluno'; ?>
    <?php if ($msg['autor'] === 'sistema'): ?>
        <div class="text-center small text-muted my-2"><i class="bi bi-info-circle"></i> <?= e((string) $msg['texto']) ?> · <?= formatDateTime($msg['criado_em']) ?></div>
    <?php else: ?>
        <div class="d-flex <?= $minha ? 'justify-content-end' : '' ?> mb-2">
            <div class="portal-card py-2 px-3" style="max-width: 85%; <?= $minha ? 'background: #f1f5ff;' : '' ?>">
                <div class="small text-muted mb-1"><?= $minha ? 'Você' : 'Secretaria' . ($msg['autor_nome'] ? ' (' . e($msg['autor_nome']) . ')' : '') ?> · <?= formatDateTime($msg['criado_em']) ?></div>
                <?php if ($msg['texto']): ?><div style="white-space: pre-line;"><?= e($msg['texto']) ?></div><?php endif; ?>
                <?php if ($msg['anexo']): ?>
                    <div class="mt-1"><a href="/protocolos/anexo.php?m=<?= (int) $msg['id'] ?>&c=<?= e($protocolo['codigo']) ?>" target="_blank"><i class="bi bi-paperclip"></i> <?= e($msg['anexo_nome'] ?: 'Anexo') ?></a></div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
<?php endforeach; ?>
</div>
<style>.conversa-protocolo { max-height: 360px; overflow-y: auto; padding: 10px 12px; border: 1px solid var(--bs-border-color, #dee2e6); border-radius: 10px; background: rgba(0, 0, 0, .015); }</style>
<script>// Conversa longa rola dentro da caixa; abre mostrando as últimas mensagens.
(() => { const c = document.getElementById('conversa-protocolo'); const fim = () => { c.scrollTop = c.scrollHeight; }; fim(); window.addEventListener('load', fim); })();</script>

<?php if ($protocolo['status_encerra']): ?>
    <div class="alert alert-secondary mt-3">Este protocolo foi encerrado<?= $protocolo['encerrado_em'] ? ' em ' . formatDateTime($protocolo['encerrado_em']) : '' ?>. Se precisar, <a href="/portal/protocolos.php?novo=1">abra um novo</a>.</div>
<?php else: ?>
    <div class="portal-card mt-3">
        <form method="post" enctype="multipart/form-data" onsubmit="this.querySelector('[type=submit]').disabled = true;">
            <label class="form-label">Responder à secretaria</label>
            <textarea name="texto" class="form-control mb-2" rows="3" maxlength="5000"></textarea>
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <input type="file" name="anexo" class="form-control form-control-sm" style="max-width: 340px;" accept="application/pdf,image/jpeg,image/png">
                <button type="submit" class="btn btn-brand ms-auto"><i class="bi bi-send"></i> Enviar</button>
            </div>
        </form>
    </div>
<?php endif; ?>
<?php require __DIR__ . '/../../includes/portal_footer.php'; ?>
