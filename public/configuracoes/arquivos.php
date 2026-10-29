<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirAdmin();

// Configurações › Arquivos: onde ficam os materiais dos cursos/turmas
// (no servidor ou num FTP, ex.: TurboCloud), tamanho máximo e os "locais"
// (pastas) em que os materiais são organizados.
$pdo = getDB();
$config = buscarConfiguracoes($pdo);
$erros = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = (string) ($_POST['acao'] ?? 'salvar');
    $ftp = [
        'destino' => input('arquivos_destino') === 'ftp' ? 'ftp' : 'local',
        'host' => strtolower(trim(preg_replace('#^(ftps?://)#i', '', input('ftp_host')), '/ ')),
        'porta' => max(1, min(65535, (int) ($_POST['ftp_porta'] ?? 21))),
        'seguranca' => input('ftp_seguranca') === 'ftp' ? 'ftp' : 'ftps',
        'verificar' => isset($_POST['ftp_verificar_certificado']),
        'usuario' => input('ftp_usuario'),
        'senha' => (string) ($_POST['ftp_senha'] ?? '') !== '' ? (string) $_POST['ftp_senha'] : (string) (descriptografar($config['ftp_senha_cripto'] ?? null) ?? ''),
        'pasta' => '/' . trim(preg_replace('#[^A-Za-z0-9_./-]#', '', input('ftp_pasta', '/materiais')), '/'),
        'max_mb' => max(1, min(2048, (int) ($_POST['material_max_mb'] ?? 10))),
        'limite_gb' => max(1, min(10000, (int) ($_POST['material_limite_gb'] ?? 15))),
    ];
    if ($ftp['host'] !== '' && !preg_match('/^[a-z0-9.-]+$/', $ftp['host'])) {
        $erros[] = 'Endereço do FTP: só o nome do servidor (ex.: ftp.seudominio.com.br), sem barra.';
    }
    if ($acao === 'testar') {
        responderJson($erros ? ['ok' => false, 'mensagem' => $erros[0]] : testarFtpMateriais($ftp));
    }
    if ($acao === 'locais') {
        // Locais (pastas): editar os existentes e acrescentar um novo.
        foreach ((array) ($_POST['local'] ?? []) as $id => $l) {
            $nome = trim((string) ($l['nome'] ?? ''));
            if ($nome !== '') {
                $pdo->prepare('UPDATE material_locais SET nome = ?, icone = ?, ordem = ?, ativo = ? WHERE id = ?')
                    ->execute([mb_substr($nome, 0, 80), preg_match('/^bi-[a-z0-9-]+$/', (string) ($l['icone'] ?? '')) ? $l['icone'] : 'bi-folder', (int) ($l['ordem'] ?? 0), isset($l['ativo']) ? 1 : 0, (int) $id]);
            }
        }
        if (($novo = trim((string) ($_POST['novo_local'] ?? ''))) !== '') {
            $pdo->prepare('INSERT INTO material_locais (nome, icone, ordem) VALUES (?, ?, (SELECT COALESCE(MAX(m.ordem), 0) + 1 FROM material_locais m))')->execute([mb_substr($novo, 0, 80), 'bi-folder']);
        }
        registrarAtividade('configuracoes', 'edicao', 'Locais dos materiais atualizados');
        flash('success', 'Locais salvos.');
        redirect('/configuracoes/arquivos.php');
    }
    if ($ftp['destino'] === 'ftp' && (!$ftp['host'] || !$ftp['usuario'] || $ftp['senha'] === '')) {
        $erros[] = 'Para guardar no FTP, preencha o endereço, o usuário e a senha.';
    }
    if (!$erros) {
        $pdo->prepare('UPDATE configuracoes SET arquivos_destino = ?, ftp_host = ?, ftp_porta = ?, ftp_seguranca = ?, ftp_verificar_certificado = ?, ftp_usuario = ?, ftp_pasta = ?, material_max_mb = ?, material_limite_gb = ?'
            . ((string) ($_POST['ftp_senha'] ?? '') !== '' ? ', ftp_senha_cripto = ?' : '') . ' WHERE id = 1')
            ->execute(array_merge([$ftp['destino'], $ftp['host'] ?: null, $ftp['porta'], $ftp['seguranca'], $ftp['verificar'] ? 1 : 0, $ftp['usuario'] ?: null, $ftp['pasta'], $ftp['max_mb'], $ftp['limite_gb']],
                (string) ($_POST['ftp_senha'] ?? '') !== '' ? [criptografar((string) $_POST['ftp_senha'])] : []));
        registrarAtividade('configuracoes', 'edicao', 'Arquivos dos materiais: guardar ' . ($ftp['destino'] === 'ftp' ? 'no FTP ' . $ftp['host'] : 'no servidor') . ', até ' . $ftp['max_mb'] . ' MB');
        flash('success', 'Configurações de arquivos salvas.' . ($ftp['destino'] === 'ftp' ? ' Os arquivos novos vão para o FTP (os antigos continuam onde estão).' : ''));
        redirect('/configuracoes/arquivos.php');
    }
}

$ftp = ftpMateriais($pdo);
$espaco = espacoMateriais($pdo);
$cota = cotaMateriais($pdo);
$locais = locaisMaterial($pdo, false);
$icones = ['bi-folder' => 'Pasta', 'bi-book' => 'Livro', 'bi-easel' => 'Quadro', 'bi-journal-text' => 'Caderno', 'bi-pencil-square' => 'Lápis', 'bi-play-btn' => 'Vídeo',
    'bi-file-earmark-pdf' => 'PDF', 'bi-mic' => 'Áudio', 'bi-image' => 'Imagem', 'bi-link-45deg' => 'Link', 'bi-clipboard-check' => 'Prova', 'bi-award' => 'Certificado'];
$limitePhp = min(array_map(function ($v) {
    $n = (int) $v;
    return match (strtoupper(substr(trim((string) $v), -1))) { 'G' => $n * 1024, 'M' => $n, 'K' => max(1, intdiv($n, 1024)), default => max(1, intdiv($n, 1048576)) };
}, [ini_get('upload_max_filesize'), ini_get('post_max_size')]));

$pageTitle = 'Configurações';
$pageBreadcrumb = 'Administração';
$activeMenu = 'configuracoes';
$pageAction = '<button type="submit" form="form-arquivos" class="btn btn-brand"><i class="bi bi-check-lg"></i> Salvar</button>';
require __DIR__ . '/../../includes/layout_header.php';
$abaAtiva = 'arquivos';
require __DIR__ . '/../../includes/abas_configuracoes.php';
?>
<?php if ($erros): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($erros as $erro): ?><li><?= e($erro) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php $av = situacaoAntivirus(); $f = 'form="form-arquivos"'; ?>
<style>
    .arq-card { background: var(--bs-body-bg); border: 1px solid var(--bs-border-color); border-radius: 14px; padding: 18px 20px; margin-bottom: 16px; }
    .arq-card h6 { font-weight: 600; margin-bottom: 4px; display: flex; align-items: center; gap: 8px; }
    .arq-card .sub { font-size: 13px; color: var(--bs-secondary-color); margin-bottom: 14px; }
    .arq-opcoes { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    @media (max-width: 700px) { .arq-opcoes { grid-template-columns: 1fr; } }
    .arq-opcao { border: 2px solid var(--bs-border-color); border-radius: 12px; padding: 14px 16px; cursor: pointer; display: flex; gap: 12px; margin: 0; }
    .arq-opcao input { display: none; }
    .arq-opcao .icone { font-size: 1.6rem; color: var(--bs-secondary-color); }
    .arq-opcao:has(input:checked) { border-color: var(--accent); background: var(--accent-soft); }
    .arq-opcao:has(input:checked) .icone { color: var(--accent-dark, var(--accent)); }
    .arq-uso { font-size: 1.6rem; font-weight: 650; letter-spacing: -.02em; }
    .arq-protecoes li { margin-bottom: 4px; }
    .arq-locais .linha { display: grid; grid-template-columns: 1fr 170px 70px 40px; gap: 8px; align-items: center; margin-bottom: 6px; }
    @media (max-width: 700px) { .arq-locais .linha { grid-template-columns: 1fr 120px; } }
</style>

<form method="post" id="form-arquivos"><input type="hidden" name="acao" value="salvar"></form>

<div class="row g-3">
    <div class="col-xl-8">
        <div class="arq-card">
            <h6><i class="bi bi-hdd-stack"></i> Onde guardar os arquivos</h6>
            <div class="sub">Apostilas, slides e demais materiais que a secretaria e os professores enviam para os alunos. Ao trocar, só os arquivos novos vão para o novo lugar.</div>
            <div class="arq-opcoes">
                <label class="arq-opcao"><input <?= $f ?> type="radio" name="arquivos_destino" value="local" <?= $ftp['destino'] !== 'ftp' ? 'checked' : '' ?> onchange="mostrarFtp()">
                    <i class="bi bi-server icone"></i>
                    <span><strong class="d-block">No próprio servidor</strong><span class="small text-muted">Simples, sem configurar nada. Ocupa o espaço do servidor do sistema.</span></span></label>
                <label class="arq-opcao"><input <?= $f ?> type="radio" name="arquivos_destino" value="ftp" <?= $ftp['destino'] === 'ftp' ? 'checked' : '' ?> onchange="mostrarFtp()">
                    <i class="bi bi-cloud-arrow-up icone"></i>
                    <span><strong class="d-block">Num FTP (TurboCloud)</strong><span class="small text-muted">O servidor do sistema não guarda nada; busca no FTP na hora do download.</span></span></label>
            </div>
        </div>

        <div class="arq-card" id="bloco-ftp">
            <h6><i class="bi bi-plug"></i> Acesso ao FTP</h6>
            <div class="sub">No painel do TurboCloud: "Contas FTP". Use uma pasta <strong>fora</strong> do public_html, para ninguém abrir os arquivos direto pelo site.</div>
            <div class="row g-3">
                <div class="col-md-6"><label class="form-label">Endereço</label><input <?= $f ?> type="text" name="ftp_host" class="form-control" value="<?= e($ftp['host']) ?>" placeholder="ftp.seudominio.com.br"></div>
                <div class="col-md-2 col-4"><label class="form-label">Porta</label><input <?= $f ?> type="number" name="ftp_porta" class="form-control" value="<?= (int) $ftp['porta'] ?>"></div>
                <div class="col-md-4 col-8"><label class="form-label">Segurança</label><select <?= $f ?> name="ftp_seguranca" class="form-select">
                    <option value="ftps" <?= $ftp['seguranca'] === 'ftps' ? 'selected' : '' ?>>FTPS (criptografado)</option>
                    <option value="ftp" <?= $ftp['seguranca'] === 'ftp' ? 'selected' : '' ?>>FTP simples</option></select></div>
                <div class="col-md-6"><label class="form-label">Usuário</label><input <?= $f ?> type="text" name="ftp_usuario" class="form-control" value="<?= e($ftp['usuario']) ?>" autocomplete="off"></div>
                <div class="col-md-6"><label class="form-label">Senha</label><input <?= $f ?> type="password" name="ftp_senha" class="form-control" autocomplete="new-password" placeholder="<?= $ftp['senha'] !== '' ? 'Guardada — em branco para manter' : '' ?>"></div>
                <div class="col-md-6"><label class="form-label">Pasta</label><input <?= $f ?> type="text" name="ftp_pasta" class="form-control" value="<?= e($ftp['pasta']) ?>"><div class="form-text">Criada sozinha se não existir.</div></div>
                <div class="col-md-6"><label class="form-label d-block">&nbsp;</label>
                    <div class="form-check form-switch"><input <?= $f ?> class="form-check-input" type="checkbox" name="ftp_verificar_certificado" id="ftp-verificar" <?= $ftp['verificar'] ? 'checked' : '' ?>><label class="form-check-label" for="ftp-verificar">Conferir o certificado</label></div>
                    <div class="form-text mt-0">Deixe desligado se der erro de certificado (comum em hospedagem compartilhada).</div></div>
            </div>
            <div class="d-flex align-items-center gap-2 mt-3 pt-3 border-top">
                <button type="button" class="btn btn-outline-secondary" onclick="testarFtp(this)"><i class="bi bi-plug"></i> Testar FTP</button>
                <span id="resultado-ftp" class="small"></span>
            </div>
        </div>

        <form method="post" class="arq-card arq-locais">
            <input type="hidden" name="acao" value="locais">
            <div class="d-flex align-items-start gap-2">
                <div class="flex-grow-1"><h6><i class="bi bi-folder2-open"></i> Locais (pastas)</h6>
                    <div class="sub">Como os materiais ficam organizados — no curso, na turma e no portal do aluno. Desligado some das opções; o que já está nele continua.</div></div>
                <button type="submit" class="btn btn-sm btn-outline-secondary text-nowrap"><i class="bi bi-check-lg"></i> Salvar locais</button>
            </div>
            <div class="linha small text-muted fw-semibold"><span>Nome</span><span>Ícone</span><span class="d-none d-md-block">Ordem</span><span class="d-none d-md-block text-center">Ativo</span></div>
            <?php foreach ($locais as $l): ?>
                <div class="linha">
                    <input type="text" name="local[<?= (int) $l['id'] ?>][nome]" class="form-control form-control-sm" maxlength="80" value="<?= e($l['nome']) ?>">
                    <div class="input-group input-group-sm"><span class="input-group-text"><i class="bi <?= e($l['icone']) ?>"></i></span><select name="local[<?= (int) $l['id'] ?>][icone]" class="form-select form-select-sm">
                        <?php foreach ($icones as $ic => $rotulo): ?><option value="<?= $ic ?>" <?= $l['icone'] === $ic ? 'selected' : '' ?>><?= $rotulo ?></option><?php endforeach; ?></select></div>
                    <input type="number" name="local[<?= (int) $l['id'] ?>][ordem]" class="form-control form-control-sm d-none d-md-block" value="<?= (int) $l['ordem'] ?>">
                    <div class="text-center d-none d-md-block"><input type="checkbox" class="form-check-input" name="local[<?= (int) $l['id'] ?>][ativo]" <?= $l['ativo'] ? 'checked' : '' ?>></div>
                </div>
            <?php endforeach; ?>
            <div class="input-group input-group-sm mt-2">
                <span class="input-group-text"><i class="bi bi-plus-lg"></i></span>
                <input type="text" name="novo_local" class="form-control" maxlength="80" placeholder="Novo local (ex.: Provas anteriores)">
            </div>
        </form>
    </div>

    <div class="col-xl-4">
        <div class="arq-card">
            <h6><i class="bi bi-pie-chart"></i> Espaço</h6>
            <div class="arq-uso mt-2"><?= tamanhoLegivel($cota['usado']) ?> <span class="fs-6 fw-normal text-muted">de <?= tamanhoLegivel($cota['limite']) ?></span></div>
            <div class="progress my-2" style="height: 8px"><div class="progress-bar <?= $cota['pct'] >= 90 ? 'bg-danger' : ($cota['pct'] >= 75 ? 'bg-warning' : 'bg-success') ?>" style="width: <?= max(1, $cota['pct']) ?>%"></div></div>
            <div class="small text-muted mb-3"><?= (int) $espaco['quantos'] ?> arquivo(s) · <?= tamanhoLegivel($espaco['local']) ?> no servidor<?= $espaco['ftp'] ? ' · ' . tamanhoLegivel($espaco['ftp']) . ' no FTP' : '' ?></div>
            <div class="row g-2">
                <div class="col-6"><label class="form-label small mb-1">Espaço total</label><div class="input-group input-group-sm"><input <?= $f ?> type="number" name="material_limite_gb" class="form-control" min="1" max="10000" value="<?= (int) $ftp['limite_gb'] ?>"><span class="input-group-text">GB</span></div></div>
                <div class="col-6"><label class="form-label small mb-1">Cada arquivo até</label><div class="input-group input-group-sm"><input <?= $f ?> type="number" name="material_max_mb" class="form-control" min="1" max="2048" value="<?= (int) $ftp['max_mb'] ?>"><span class="input-group-text">MB</span></div></div>
            </div>
            <div class="form-text">Chegando no espaço total, o sistema não aceita arquivos novos. Vídeos: prefira o link (YouTube "não listado", Drive).</div>
            <?php if ($limitePhp < $ftp['max_mb']): ?>
                <div class="alert alert-warning small py-2 mt-3 mb-0"><i class="bi bi-exclamation-triangle"></i> O PHP deste servidor aceita só <strong><?= (int) $limitePhp ?> MB</strong> por envio — menos que os <?= (int) $ftp['max_mb'] ?> MB escolhidos. Aumente o limite do PHP (docs/materiais.md) e reinicie o servidor.</div>
            <?php endif; ?>
        </div>

        <div class="arq-card">
            <h6><i class="bi <?= $av['ativo'] ? 'bi-shield-check text-success' : 'bi-shield-exclamation text-warning' ?>"></i> Segurança</h6>
            <div class="small mb-2"><strong>Antivírus:</strong> <?= e($av['texto']) ?></div>
            <ul class="small text-muted ps-3 mb-0 arq-protecoes">
                <li>Arquivo conferido pelo conteúdo, não só pelo nome.</li>
                <li>Bloqueia programas, scripts, Office com macro e PDF com código.</li>
                <li>O sistema não abre nem executa nada: só guarda e entrega.</li>
            </ul>
        </div>
    </div>
</div>

<script>
function mostrarFtp() {
    document.getElementById('bloco-ftp').style.display = document.querySelector('[name=arquivos_destino]:checked').value === 'ftp' ? '' : 'none';
}
mostrarFtp();
async function testarFtp(botao) {
    botao.disabled = true;
    const area = document.getElementById('resultado-ftp');
    area.className = 'small ms-2 text-muted';
    area.textContent = 'Conectando...';
    const dados = new FormData(document.getElementById('form-arquivos'));
    dados.set('acao', 'testar');
    try {
        const r = await (await fetch('/configuracoes/arquivos.php', { method: 'POST', body: dados })).json();
        area.className = 'small ms-2 ' + (r.ok ? 'text-success' : 'text-danger');
        area.innerHTML = `<i class="bi ${r.ok ? 'bi-check-circle' : 'bi-x-circle'}"></i> ` + escapeHtml(r.mensagem);
    } catch (e) {
        area.className = 'small ms-2 text-danger';
        area.textContent = 'Não foi possível testar agora.';
    }
    botao.disabled = false;
}
</script>
<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
