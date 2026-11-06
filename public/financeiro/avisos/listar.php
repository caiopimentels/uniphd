<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
exigirPermissao('configurar_avisos_cobranca', 'Seu perfil não pode configurar os avisos automáticos.');

// Avisos automáticos de cobrança, em duas abas: Regras (quantas quiser) e
// Envios (o que está aguardando hoje + o que já saiu, com status e data).
// Quem envia é a rotina "emails" do cron.
$pdo = getDB();
$config = buscarConfiguracoes($pdo);
$aba = ($_GET['aba'] ?? '') === 'envios' ? 'envios' : 'regras';
$avisos = buscarAvisosCobranca($pdo);

$sistemaPronto = $config['smtp_host'] && (trim((string) $config['smtp_remetente_email']) ?: trim((string) $config['smtp_usuario']));
$whatsPronto = whatsappConfigurado(whatsappConfig($pdo));
$usaEmail = (bool) array_filter($avisos, fn ($a) => $a['ativo'] && $a['enviar_email']);
$usaWhats = (bool) array_filter($avisos, fn ($a) => $a['ativo'] && $a['enviar_whatsapp']);

// Envios: o que aguarda hoje (menos o que já deu erro — aparece pela linha
// do erro) e o histórico, numa tabela só.
$statusEnvio = [
    'aguardando' => ['Aguardando', 'warning'],
    'enviado' => ['Enviado', 'success'],
    'erro' => ['Erro', 'danger'],
    'sem_contato' => ['Sem contato', 'secondary'],
];
$filtro = [
    'status' => array_key_exists($_GET['status'] ?? '', $statusEnvio) ? $_GET['status'] : '',
    'aviso' => (int) ($_GET['aviso'] ?? 0) ?: '',
    'canal' => in_array($_GET['canal'] ?? '', ['email', 'whatsapp'], true) ? $_GET['canal'] : '',
    // Período pelo calendário padrão do sistema (filtro_periodo.php).
    'de' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['data_inicio'] ?? '')) ? $_GET['data_inicio'] : '',
    'ate' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['data_fim'] ?? '')) ? $_GET['data_fim'] : '',
    'busca' => trim((string) ($_GET['busca'] ?? '')),
];
$temFiltro = (bool) array_filter($filtro);
$semAcento = fn ($t) => mb_strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', (string) $t) ?: (string) $t);
$hoje = date('Y-m-d');
// Os que aguardam são de hoje: só entram se o filtro deixar.
$mostraAguardando = in_array($filtro['status'], ['', 'aguardando'], true)
    && (!$filtro['de'] || $filtro['de'] <= $hoje) && (!$filtro['ate'] || $filtro['ate'] >= $hoje);
$linhasEnvio = [];
foreach ($mostraAguardando ? avisosPendentes($pdo, true) : [] as $h) {
    $primeira = $h['parcelas'][0];
    if ($h['nova_tentativa'] || ($filtro['aviso'] && (int) $h['aviso']['id'] !== $filtro['aviso']) || ($filtro['canal'] && $h['canal'] !== $filtro['canal'])) {
        continue;
    }
    if ($filtro['busca'] !== '' && !str_contains($semAcento($primeira['aluno_nome'] . ' ' . $primeira['aluno_email'] . ' ' . implode(' ', array_column($h['parcelas'], 'descricao'))), $semAcento($filtro['busca']))) {
        continue;
    }
    $primeira = $h['parcelas'][0];
    $noHorario = avisoNoHorario($h['aviso']);
    $linhasEnvio[] = [
        'status' => 'aguardando',
        'quando' => $noHorario ? date('d/m/Y') : date('d/m/Y') . ' ' . substr($h['aviso']['horario'], 0, 5),
        'quando_obs' => $noHorario ? 'na próxima rodada (até 5 min)' : 'a partir deste horário',
        'aluno_id' => $h['aluno_id'],
        'aluno_nome' => $primeira['aluno_nome'],
        'aviso_nome' => $h['aviso']['nome'],
        'canal' => $h['canal'],
        'parcelas' => implode(', ', array_map(fn ($p) => $p['descricao'] . ' · ' . formatDate($p['data_vencimento']), $h['parcelas'])),
        'para' => $h['canal'] === 'email' ? (string) $primeira['aluno_email'] : telefoneFormatado($primeira['aluno_telefone']),
        'obs' => '',
    ];
}
foreach ($filtro['status'] === 'aguardando' ? [] : ultimosEnviosAvisos($pdo, 500, $filtro) as $en) {
    $linhasEnvio[] = [
        'status' => $en['status'],
        'quando' => formatDateTime($en['enviado_em']),
        'quando_obs' => '',
        'aluno_id' => $en['aluno_id'],
        'aluno_nome' => $en['aluno_nome'],
        'aviso_nome' => $en['aviso_nome'],
        'canal' => $en['canal'],
        'parcelas' => ($en['parcela_descricao'] ?? '-') . ($en['data_vencimento'] ? ' · ' . formatDate($en['data_vencimento']) : ''),
        'para' => (string) $en['destino'],
        'obs' => $en['erro'] ? $en['erro'] . ($en['status'] === 'erro' ? ((int) $en['tentativas'] < 3 ? ' · vai tentar de novo (' . (int) $en['tentativas'] . ' de 3)' : ' · desistiu depois de 3 tentativas') : '') : '',
    ];
}
$aguardando = count(array_filter($linhasEnvio, fn ($l) => $l['status'] === 'aguardando'));

$pageTitle = 'Avisos automáticos';
$pageBreadcrumb = 'Financeiro';
$activeMenu = 'avisos-cobranca';
$pageAction = '<a href="/financeiro/avisos/form.php" class="btn btn-brand"><i class="bi bi-plus-lg"></i> Novo aviso</a>';
require __DIR__ . '/../../../includes/layout_header.php';
?>

<?php if ($usaEmail && !$sistemaPronto): ?>
    <div class="alert alert-warning"><i class="bi bi-exclamation-triangle"></i> Há aviso por e-mail ligado, mas o <strong>e-mail do sistema</strong> não está configurado (Configurações › Sistema). Nada sai por e-mail até configurar.</div>
<?php endif; ?>
<?php if ($usaWhats && !$whatsPronto): ?>
    <div class="alert alert-warning"><i class="bi bi-exclamation-triangle"></i> Há aviso por WhatsApp ligado, mas o <strong>WhatsApp</strong> não está configurado (Secretaria › WhatsApp).</div>
<?php endif; ?>
<?php if (!urlPortalConfigurada()): ?>
    <div class="alert alert-warning"><i class="bi bi-link-45deg"></i> O <strong>endereço do portal</strong> não está configurado (Portal › Site do portal › Menu e rodapé). Sem ele, o link de pagamento das mensagens automáticas não funciona.</div>
<?php endif; ?>

<ul class="nav nav-tabs mb-3">
    <li class="nav-item"><a class="nav-link <?= $aba === 'regras' ? 'active' : '' ?>" href="?aba=regras">Regras <span class="badge text-bg-light"><?= count($avisos) ?></span></a></li>
    <li class="nav-item"><a class="nav-link <?= $aba === 'envios' ? 'active' : '' ?>" href="?aba=envios">Envios
        <?php if ($aguardando): ?><span class="badge text-bg-warning" title="Aguardando envio hoje"><?= $aguardando ?></span><?php endif; ?>
    </a></li>
</ul>

<?php if ($aba === 'regras'): ?>
    <?php if (!$avisos): ?>
        <div class="card"><div class="card-body text-center py-4">
            <p class="mb-2">Nenhum aviso automático ainda.</p>
            <p class="small text-muted">Exemplo: um aviso quando o boleto for emitido e outro com os lembretes de vencimento — 5 e 2 dias antes e no dia (os dias ficam todos na mesma regra).</p>
            <form method="post" action="/financeiro/avisos/acao.php" class="d-inline">
                <input type="hidden" name="acao" value="sugestao">
                <button type="submit" class="btn btn-outline-secondary"><i class="bi bi-magic"></i> Criar esse exemplo (desligado, para revisar)</button>
            </form>
            <a href="/financeiro/avisos/form.php" class="btn btn-brand ms-1"><i class="bi bi-plus-lg"></i> Criar do zero</a>
        </div></div>
    <?php else: ?>
    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead><tr><th>Aviso</th><th>Quando</th><th>Por onde</th><th class="text-center">Situação</th><th class="text-end">Ações</th></tr></thead>
                <tbody>
                <?php foreach ($avisos as $a): ?>
                    <tr class="cursor-pointer <?= $a['ativo'] ? '' : 'text-muted' ?>" onclick="window.location='/financeiro/avisos/form.php?id=<?= (int) $a['id'] ?>'">
                        <td class="fw-semibold"><?= e($a['nome']) ?></td>
                        <td><?= e(descreverMomentoAviso($a)) ?><?= $a['gatilho'] === 'vencimento' ? '<div class="small text-muted">a partir das ' . e(substr($a['horario'], 0, 5)) . ($a['so_com_boleto'] ? ' · só com boleto emitido' : '') . '</div>' : '' ?></td>
                        <td class="text-nowrap">
                            <?= $a['enviar_email'] ? '<span class="badge text-bg-light border"><i class="bi bi-envelope"></i> E-mail' . ($a['anexar_boleto'] && $a['gatilho'] !== 'pagamento' ? ' + PDF' : '') . '</span> ' : '' ?>
                            <?= $a['enviar_whatsapp'] ? '<span class="badge text-bg-light border"><i class="bi bi-whatsapp"></i> WhatsApp</span>' : '' ?>
                        </td>
                        <td class="text-center"><?= $a['ativo'] ? '<span class="badge text-bg-success">Ligado</span>' : '<span class="badge text-bg-secondary">Desligado</span>' ?></td>
                        <td class="text-end text-nowrap" onclick="event.stopPropagation()">
                            <a href="/financeiro/avisos/form.php?id=<?= (int) $a['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Alterar"><i class="bi bi-pencil"></i></a>
                            <form method="post" action="/financeiro/avisos/acao.php" class="d-inline">
                                <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                                <input type="hidden" name="acao" value="<?= $a['ativo'] ? 'desligar' : 'ligar' ?>">
                                <button type="submit" class="btn btn-sm btn-outline-secondary" title="<?= $a['ativo'] ? 'Desligar' : 'Ligar' ?>"><i class="bi <?= $a['ativo'] ? 'bi-toggle-on' : 'bi-toggle-off' ?>"></i></button>
                            </form>
                            <form method="post" action="/financeiro/avisos/acao.php" class="d-inline">
                                <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                                <input type="hidden" name="acao" value="duplicar">
                                <button type="submit" class="btn btn-sm btn-outline-secondary" title="Duplicar"><i class="bi bi-copy"></i></button>
                            </form>
                            <form method="post" action="/financeiro/avisos/acao.php" class="d-inline" data-confirmar="Excluir o aviso &quot;<?= e($a['nome']) ?>&quot;? O histórico de envios dele também sai.">
                                <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                                <input type="hidden" name="acao" value="excluir">
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Excluir"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

<?php else: ?>
    <div class="filtros">
        <form method="get" class="d-flex flex-wrap gap-2 align-items-center">
            <input type="hidden" name="aba" value="envios">
            <input type="search" name="busca" class="form-control filtro-controle" style="width:240px" placeholder="Aluno, e-mail/telefone ou parcela" value="<?= e($filtro['busca']) ?>">
            <select name="status" class="form-select filtro-controle" style="width:170px" onchange="this.form.submit()">
                <option value="">Todos os status</option>
                <?php foreach ($statusEnvio as $chave => [$rotulo]): ?>
                    <option value="<?= e($chave) ?>" <?= $filtro['status'] === $chave ? 'selected' : '' ?>><?= e($rotulo) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="aviso" class="form-select filtro-controle" style="width:200px" onchange="this.form.submit()">
                <option value="">Todos os avisos</option>
                <?php foreach ($avisos as $a): ?>
                    <option value="<?= (int) $a['id'] ?>" <?= (int) $filtro['aviso'] === (int) $a['id'] ? 'selected' : '' ?>><?= e($a['nome']) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="canal" class="form-select filtro-controle" style="width:150px" onchange="this.form.submit()">
                <option value="">Todos os meios</option>
                <option value="email" <?= $filtro['canal'] === 'email' ? 'selected' : '' ?>>E-mail</option>
                <option value="whatsapp" <?= $filtro['canal'] === 'whatsapp' ? 'selected' : '' ?>>WhatsApp</option>
            </select>
            <?php $dataInicio = $filtro['de']; $dataFim = $filtro['ate']; require __DIR__ . '/../../../includes/filtro_periodo.php'; ?>
            <button type="submit" class="btn btn-sm btn-outline-secondary" title="Buscar"><i class="bi bi-search"></i></button>
            <?php if ($temFiltro): ?>
                <a href="?aba=envios" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x-lg"></i> Limpar</a>
            <?php endif; ?>
        </form>
    </div>
    <div class="card">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle" id="tabela-envios">
                <thead><tr><th>Data</th><th>Status</th><th>Aluno</th><th>Aviso</th><th>Meio</th><th>Parcelas</th><th>Para</th></tr></thead>
                <tbody>
                <?php if (!$linhasEnvio): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4"><?= $temFiltro ? 'Nada encontrado com esses filtros.' : 'Nada aguardando hoje e nenhum envio ainda.' ?></td></tr>
                <?php endif; ?>
                <?php foreach ($linhasEnvio as $l): [$rotuloStatus, $corStatus] = $statusEnvio[$l['status']]; ?>
                    <tr data-status="<?= e($l['status']) ?>">
                        <td class="small text-nowrap"><?= e($l['quando']) ?><?= $l['quando_obs'] ? '<div class="text-muted">' . e($l['quando_obs']) . '</div>' : '' ?></td>
                        <td>
                            <span class="badge text-bg-<?= $corStatus ?>"><?= e($rotuloStatus) ?></span>
                            <?php if ($l['obs']): ?><div class="small text-muted"><?= e($l['obs']) ?></div><?php endif; ?>
                        </td>
                        <td><?= $l['aluno_id'] ? '<a href="/alunos/form.php?id=' . (int) $l['aluno_id'] . '&tab=financeiro">' . e($l['aluno_nome']) . '</a>' : '-' ?></td>
                        <td class="small"><?= e($l['aviso_nome']) ?></td>
                        <td class="text-nowrap"><?= $l['canal'] === 'email' ? '<i class="bi bi-envelope"></i> E-mail' : '<i class="bi bi-whatsapp"></i> WhatsApp' ?></td>
                        <td class="small"><?= e($l['parcelas']) ?></td>
                        <td class="small"><?= e($l['para'] ?: '-') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="form-text"><?= count($linhasEnvio) ?> linha(s).</div>
<?php endif; ?>

<?php require __DIR__ . '/../../../includes/modal_confirmar.php'; ?>
<script>
document.querySelectorAll('form[data-confirmar]').forEach((f) => f.addEventListener('submit', async (ev) => {
    if (f.dataset.ok) return;
    ev.preventDefault();
    if (await confirmarNoModal({ titulo: 'Excluir aviso', html: escapeHtml(f.dataset.confirmar), botao: 'Excluir', classeBotao: 'btn-danger' })) {
        f.dataset.ok = '1';
        f.submit();
    }
}));
</script>
<?php require __DIR__ . '/../../../includes/layout_footer.php'; ?>
