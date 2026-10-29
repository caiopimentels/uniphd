<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirPermissao('disparar_email', 'Seu perfil não pode disparar e-mails em massa.');

// Um disparo: andamento ao vivo (enquanto a tela está aberta, ela mesma
// vai mandando as levas; fechada, o cron continua), quem recebeu, falhas,
// pausar, retomar, cancelar e reenviar as falhas.
$pdo = getDB();
$stmt = $pdo->prepare('SELECT * FROM email_disparos WHERE id = ?');
$stmt->execute([(int) ($_GET['id'] ?? 0)]);
$disparo = $stmt->fetch();
if (!$disparo) {
    flash('danger', 'Disparo não encontrado.');
    redirect('/email/disparos.php');
}
$stmt = $pdo->prepare("SELECT * FROM email_disparo_destinos WHERE disparo_id = ? ORDER BY FIELD(status, 'erro', 'pendente', 'enviado'), nome");
$stmt->execute([$disparo['id']]);
$destinos = $stmt->fetchAll();
$situacoes = ['enviando' => ['Enviando', 'primary'], 'pausado' => ['Pausado', 'warning'], 'concluido' => ['Concluído', 'success'], 'cancelado' => ['Cancelado', 'secondary']];
$anexos = json_decode((string) $disparo['anexos'], true) ?: [];
$cfg = emailConfig($pdo);

$pageTitle = $disparo['assunto'];
$pageBreadcrumb = 'E-mail · Disparo em massa';
$activeMenu = 'email-disparos';
$pageAction = '<a href="/email/disparos.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Voltar</a>'
    . '<span id="botoes-disparo"></span>';
require __DIR__ . '/../../includes/layout_header.php';
?>
<div class="row g-3">
    <div class="col-lg-7">
        <div class="secao">
            <div class="secao-titulo"><i class="bi bi-activity"></i> Andamento <span class="ms-2 badge" id="situacao"></span></div>
            <div class="progress mb-2" style="height: 14px"><div class="progress-bar bg-success" id="barra-ok"></div><div class="progress-bar bg-danger" id="barra-erro"></div></div>
            <div id="numeros" class="small"></div>
            <div class="small text-muted mt-1" id="ritmo">Ritmo: até <?= (int) $cfg['disparo_por_hora'] ?> por hora. Com esta tela aberta, ela mesma vai enviando; fechada, o envio continua pelo agendador do servidor (a cada 5 minutos).</div>
            <div class="alert alert-warning small py-2 mt-2 d-none" id="aviso-pausa"><i class="bi bi-pause-circle"></i> Pausado. Se pausou sozinho, foi porque o servidor de e-mail recusou (senha ou conexão): confira a caixa de saída e clique em Retomar.</div>
        </div>
        <div class="secao">
            <div class="secao-titulo"><i class="bi bi-people"></i> Destinatários (<?= count($destinos) ?>)</div>
            <div class="d-flex gap-2 mb-2">
                <select class="form-select form-select-sm" style="max-width: 180px" id="filtro" onchange="filtrar()">
                    <option value="">Todos</option><option value="enviado">Enviados</option><option value="pendente">Na fila</option><option value="erro">Com falha</option>
                </select>
                <input type="search" class="form-control form-control-sm" placeholder="Procurar aluno ou e-mail" id="busca" oninput="filtrar()">
            </div>
            <div style="max-height: 480px; overflow-y: auto">
                <table class="table table-sm mb-0 align-middle"><tbody>
                <?php foreach ($destinos as $d): ?>
                    <tr class="linha-destino" data-status="<?= e($d['status']) ?>" data-busca="<?= e(mb_strtolower($d['nome'] . ' ' . $d['email'])) ?>">
                        <td><?= $d['aluno_id'] ? '<a href="/alunos/form.php?id=' . (int) $d['aluno_id'] . '" class="text-decoration-none">' . e($d['nome']) . '</a>' : e($d['nome']) ?><div class="small text-muted"><?= e($d['email']) ?></div></td>
                        <td class="small text-end">
                            <?php if ($d['status'] === 'enviado'): ?><span class="text-success"><i class="bi bi-check2"></i> <?= formatDateTime($d['enviado_em']) ?></span>
                            <?php elseif ($d['status'] === 'erro'): ?><span class="text-danger" title="<?= e($d['erro']) ?>"><i class="bi bi-x-circle"></i> <?= e(mb_strimwidth((string) $d['erro'], 0, 60, '…')) ?></span>
                            <?php else: ?><span class="text-muted">na fila</span><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody></table>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="secao">
            <div class="secao-titulo"><i class="bi bi-envelope-paper"></i> Mensagem</div>
            <dl class="row small mb-2">
                <dt class="col-4">Para</dt><dd class="col-8"><?= e(publicosDisparo()[$disparo['publico']]) ?></dd>
                <dt class="col-4">Sai de</dt><dd class="col-8"><?= $disparo['remetente'] === 'sistema' ? 'E-mail do sistema' : 'Caixa de ' . e($disparo['usuario_nome'] ?? '') ?></dd>
                <dt class="col-4">Criado</dt><dd class="col-8"><?= formatDateTime($disparo['criado_em']) ?> por <?= e($disparo['usuario_nome'] ?? '') ?></dd>
                <?php if ($anexos): ?><dt class="col-4">Anexos</dt><dd class="col-8"><?= e(implode(', ', array_column($anexos, 'nome'))) ?></dd><?php endif; ?>
            </dl>
            <div class="border rounded p-3 bg-white text-dark"><div class="fw-semibold mb-2"><?= e($disparo['assunto']) ?></div><div class="small"><?= limparHtmlContrato($disparo['corpo_html'], true) ?></div></div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../../includes/modal_confirmar.php'; ?>
<script>
const ID = <?= (int) $disparo['id'] ?>;
const SITUACOES = <?= json_encode($situacoes) ?>;
let estado = { status: <?= json_encode($disparo['status']) ?>, enviados: <?= (int) $disparo['enviados'] ?>, falhas: <?= (int) $disparo['falhas'] ?>, total: <?= (int) $disparo['total'] ?> };
function desenhar() {
    const [rotulo, cor] = SITUACOES[estado.status];
    const s = document.getElementById('situacao');
    s.textContent = rotulo; s.className = 'ms-2 badge text-bg-' + cor;
    document.getElementById('barra-ok').style.width = (estado.total ? estado.enviados / estado.total * 100 : 0) + '%';
    document.getElementById('barra-erro').style.width = (estado.total ? estado.falhas / estado.total * 100 : 0) + '%';
    const fila = estado.total - estado.enviados - estado.falhas;
    document.getElementById('numeros').innerHTML = `<strong class="text-success">${estado.enviados}</strong> enviado(s) · <strong>${fila}</strong> na fila`
        + (estado.falhas ? ` · <strong class="text-danger">${estado.falhas}</strong> com falha` : '') + ` · total ${estado.total}`;
    document.getElementById('aviso-pausa').classList.toggle('d-none', estado.status !== 'pausado');
    let b = '';
    if (estado.status === 'enviando') b += '<button class="btn btn-outline-warning" onclick="agir(\'pausar\')"><i class="bi bi-pause"></i> Pausar</button>';
    if (estado.status === 'pausado') b += '<button class="btn btn-outline-success" onclick="agir(\'retomar\')"><i class="bi bi-play"></i> Retomar</button>';
    if (estado.falhas && estado.status !== 'cancelado') b += '<button class="btn btn-outline-secondary" onclick="agir(\'reenviar_falhas\')"><i class="bi bi-arrow-repeat"></i> Reenviar falhas</button>';
    if (['enviando', 'pausado'].includes(estado.status)) b += '<button class="btn btn-outline-danger" onclick="cancelar()"><i class="bi bi-x-circle"></i> Cancelar</button>';
    document.getElementById('botoes-disparo').innerHTML = b;
}
async function agir(acao) {
    const dados = new FormData();
    dados.append('acao', acao);
    dados.append('id', ID);
    try {
        const r = await (await fetch('/email/disparo_acao.php', { method: 'POST', body: dados })).json();
        if (r.sucesso) {
            const mudouLista = r.enviados !== estado.enviados || r.falhas !== estado.falhas || ['reenviar_falhas'].includes(acao);
            estado = r;
            desenhar();
            if (mudouLista && acao !== 'processar') location.reload();
            return mudouLista;
        }
    } catch (e) {}
    return false;
}
async function cancelar() {
    if (await confirmarNoModal({ titulo: 'Cancelar disparo', html: 'Os e-mails que ainda estão na fila não saem mais. Os já enviados não voltam.', botao: 'Cancelar disparo', classeBotao: 'btn-danger' })) agir('cancelar');
}
function filtrar() {
    const f = document.getElementById('filtro').value;
    const t = document.getElementById('busca').value.trim().toLowerCase();
    document.querySelectorAll('.linha-destino').forEach((l) => { l.style.display = (!f || l.dataset.status === f) && (!t || l.dataset.busca.includes(t)) ? '' : 'none'; });
}
// Com a tela aberta, manda uma leva a cada 20 s (dentro do limite por hora).
let atualizacoes = 0;
async function ciclo() {
    if (estado.status === 'enviando') {
        const mudou = await agir('processar');
        if (mudou && ++atualizacoes % 3 === 0) location.reload(); // lista em dia de tempos em tempos
    }
    setTimeout(ciclo, 20000);
}
desenhar();
setTimeout(ciclo, 1500);
</script>
<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
