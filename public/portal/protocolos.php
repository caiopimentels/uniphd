<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirAluno();

// "Meus protocolos": o aluno abre um protocolo (justificativa de ausência,
// pedido de reposição...) com anexo, e acompanha os que já abriu.
// ?novo=1 abre o formulário; ?matricula=ID já escolhe a turma.
$pdo = getDB();
$alunoId = alunoAtualId();
$assuntos = assuntosProtocolo($pdo);
$matriculas = matriculasParaProtocolo($pdo, $alunoId);
$dados = ['assunto_id' => 0, 'matricula_id' => (int) ($_GET['matricula'] ?? 0), 'curso_modulo_id' => 0, 'data_referencia' => '', 'descricao' => ''];
$erro = '';
// Veio pela turma (Meus cursos): já escolhe o primeiro assunto que pede turma.
if ($dados['matricula_id']) {
    foreach ($assuntos as $a) {
        if ($a['pede_turma']) {
            $dados['assunto_id'] = (int) $a['id'];
            break;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dados = [
        'assunto_id' => (int) ($_POST['assunto_id'] ?? 0),
        'matricula_id' => (int) ($_POST['matricula_id'] ?? 0),
        'curso_modulo_id' => (int) ($_POST['curso_modulo_id'] ?? 0),
        'data_referencia' => (string) ($_POST['data_referencia'] ?? ''),
        'descricao' => (string) ($_POST['descricao'] ?? ''),
    ];
    if (limiteDeTentativasExcedido($pdo, 'portal_protocolo', (string) $alunoId, 10, 10, 60)) {
        $erro = 'Muitos protocolos seguidos. Tente de novo mais tarde.';
    } else {
        registrarTentativaAcesso($pdo, 'portal_protocolo', (string) $alunoId);
        $resultado = abrirProtocolo($pdo, $alunoId, $dados, $_FILES['anexo'] ?? null);
        if ($resultado['ok']) {
            flash('success', $resultado['mensagem']);
            redirect('/portal/protocolo.php?c=' . $resultado['codigo']);
        }
        $erro = $resultado['mensagem'];
    }
}

$stmt = $pdo->prepare(
    "SELECT p.codigo, p.id, p.numero, p.criado_em, p.atualizado_em, p.aguardando, pa.nome AS assunto_nome, ps.nome AS status_nome, ps.cor AS status_cor, ps.encerra,
            c.nome AS curso_nome, t.nome AS turma_nome
     FROM protocolos p JOIN protocolo_assuntos pa ON pa.id = p.assunto_id JOIN protocolo_status ps ON ps.id = p.status_id
     LEFT JOIN matriculas m ON m.id = p.matricula_id LEFT JOIN turmas t ON t.id = m.turma_id LEFT JOIN cursos c ON c.id = t.curso_id
     WHERE p.aluno_id = ? ORDER BY ps.encerra, p.atualizado_em DESC"
);
$stmt->execute([$alunoId]);
$protocolos = $stmt->fetchAll();
$abrirFormulario = $erro !== '' || isset($_GET['novo']) || !$protocolos;

$tituloPagina = 'Meus protocolos';
require __DIR__ . '/../../includes/portal_header.php';
?>
<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
    <div>
        <h1 class="portal-titulo mb-0">Meus protocolos</h1>
        <p class="portal-sub mb-0">Justificativa de falta, pedido de reposição de aula e outros pedidos à secretaria.</p>
    </div>
    <?php if (!$abrirFormulario): ?><a href="?novo=1" class="btn btn-brand"><i class="bi bi-plus-lg"></i> Abrir protocolo</a><?php endif; ?>
</div>

<?php if ($abrirFormulario): ?>
<div class="portal-card mb-3">
    <h2 class="h5 mb-3">Abrir protocolo</h2>
    <?php if ($erro): ?><div class="alert alert-danger py-2"><?= e($erro) ?></div><?php endif; ?>
    <?php if (!$assuntos): ?>
        <p class="text-muted mb-0">No momento não é possível abrir protocolos pelo portal. Fale com a secretaria.</p>
    <?php else: ?>
    <form method="post" enctype="multipart/form-data" id="form-protocolo" onsubmit="this.querySelector('[type=submit]').disabled = true; this.querySelector('[type=submit]').innerHTML = 'Enviando...';">
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Assunto</label>
                <select name="assunto_id" id="pr-assunto" class="form-select" required>
                    <option value="">Escolha...</option>
                    <?= opcoesAssuntosProtocolo($assuntos, (int) $dados['assunto_id']) ?>
                </select>
                <div class="form-text" id="pr-instrucao"></div>
            </div>
            <div class="col-md-6 pr-turma">
                <label class="form-label">Turma</label>
                <select name="matricula_id" id="pr-matricula" class="form-select">
                    <option value="">Escolha...</option>
                    <?php foreach ($matriculas as $m): ?>
                        <option value="<?= (int) $m['id'] ?>" <?= (int) $dados['matricula_id'] === (int) $m['id'] ? 'selected' : '' ?>><?= e($m['curso_nome'] . ' · ' . $m['turma_nome']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if (!$matriculas): ?><div class="form-text text-danger">Você não tem turma ativa.</div><?php endif; ?>
            </div>
            <div class="col-md-6 pr-turma" id="pr-modulo-col">
                <label class="form-label">Módulo <span class="text-muted small">(se for de um módulo)</span></label>
                <select name="curso_modulo_id" id="pr-modulo" class="form-select"></select>
            </div>
            <div class="col-md-6 pr-data">
                <label class="form-label">Data <span class="text-muted small">(da falta ou da aula)</span></label>
                <input type="date" name="data_referencia" class="form-control" value="<?= e($dados['data_referencia']) ?>">
            </div>
            <div class="col-12">
                <label class="form-label">Descreva o que você precisa</label>
                <textarea name="descricao" class="form-control" rows="5" required maxlength="5000"><?= e($dados['descricao']) ?></textarea>
            </div>
            <div class="col-12 pr-anexo">
                <label class="form-label">Anexo <span class="text-muted small" id="pr-anexo-rotulo">(opcional — atestado, declaração...)</span></label>
                <input type="file" name="anexo" class="form-control" accept="application/pdf,image/jpeg,image/png">
                <div class="form-text">PDF ou foto (JPG/PNG), bem legível.</div>
            </div>
        </div>
        <div class="d-flex gap-2 mt-3">
            <button type="submit" class="btn btn-brand"><i class="bi bi-send"></i> Enviar protocolo</button>
            <?php if ($protocolos): ?><a href="/portal/protocolos.php" class="btn btn-outline-secondary">Cancelar</a><?php endif; ?>
        </div>
    </form>
    <script>
    // Mostra só os campos que o assunto pede; módulos da turma escolhida.
    (() => {
        const modulos = <?= json_encode(array_column(array_map(fn ($m) => ['id' => $m['id'], 'modulos' => $m['modulos']], $matriculas), 'modulos', 'id'), JSON_UNESCAPED_UNICODE) ?>;
        const moduloInicial = <?= (int) $dados['curso_modulo_id'] ?>;
        const esc = (t) => String(t).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]);
        const assunto = document.getElementById('pr-assunto');
        const matricula = document.getElementById('pr-matricula');
        const modulo = document.getElementById('pr-modulo');
        const atualizarModulos = () => {
            const lista = modulos[matricula.value] || [];
            modulo.innerHTML = '<option value="">—</option>' + lista.map((m) => '<option value="' + m.id + '"' + (Number(m.id) === moduloInicial ? ' selected' : '') + '>' + esc(m.nome) + '</option>').join('');
            document.getElementById('pr-modulo-col').style.display = lista.length && assunto.selectedOptions[0]?.dataset.turma === '1' ? '' : 'none';
        };
        const atualizar = () => {
            const o = assunto.selectedOptions[0];
            const turma = o && o.dataset.turma === '1';
            document.querySelectorAll('.pr-turma').forEach((el) => { el.style.display = turma ? '' : 'none'; });
            matricula.required = turma;
            document.querySelectorAll('.pr-data').forEach((el) => { el.style.display = o && o.dataset.data === '1' ? '' : 'none'; });
            document.querySelector('[name=data_referencia]').required = !!o && o.dataset.data === '1';
            const anexo = o ? o.dataset.anexo : 'opcional';
            document.querySelector('.pr-anexo').style.display = anexo === 'nao' ? 'none' : '';
            document.querySelector('[name=anexo]').required = anexo === 'obrigatorio';
            document.getElementById('pr-anexo-rotulo').textContent = anexo === 'obrigatorio' ? '(obrigatório)' : '(opcional — atestado, declaração...)';
            document.getElementById('pr-instrucao').textContent = o ? o.dataset.instrucao : '';
            atualizarModulos();
        };
        assunto.addEventListener('change', atualizar);
        matricula.addEventListener('change', atualizarModulos);
        atualizar();
    })();
    </script>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($protocolos): ?>
<div class="portal-card">
    <?php foreach ($protocolos as $p): ?>
        <a href="/portal/protocolo.php?c=<?= e($p['codigo']) ?>" class="lista-linha text-decoration-none text-reset">
            <i class="bi bi-chat-left-text fs-4"></i>
            <div class="principal">
                <div class="t">Nº <?= numeroProtocolo($p) ?> · <?= e($p['assunto_nome']) ?></div>
                <div class="s"><?= $p['turma_nome'] ? e($p['curso_nome'] . ' · ' . $p['turma_nome']) . ' · ' : '' ?>aberto em <?= formatDate($p['criado_em']) ?> · atualizado <?= formatDateTime($p['atualizado_em']) ?></div>
            </div>
            <?php if ($p['aguardando'] === 'aluno' && !$p['encerra']): ?><span class="badge text-bg-warning">Nova resposta</span><?php endif; ?>
            <?= badgeStatusProtocolo($p['status_nome'], $p['status_cor']) ?>
        </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<?php require __DIR__ . '/../../includes/portal_footer.php'; ?>
