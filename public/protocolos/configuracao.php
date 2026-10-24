<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirPermissao('configurar_protocolos', 'Seu perfil não pode configurar os protocolos.');

// Configuração dos protocolos: os temas (Acadêmico, Financeiro...), os
// assuntos que o aluno escolhe dentro de cada tema (e o que cada um pede:
// turma/módulo, data, anexo) e os status (cor, inicial, encerra). Itens em
// uso não são apagados, só desativados.
$pdo = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tipo = in_array(input('tipo'), ['status', 'tema'], true) ? input('tipo') : 'assunto';
    $id = (int) ($_POST['id'] ?? 0);
    $nome = mb_substr(trim(input('nome')), 0, $tipo === 'assunto' ? 100 : 60);
    $ativo = isset($_POST['ativo']) ? 1 : 0;
    $ordem = (int) ($_POST['ordem'] ?? 0);
    if ($nome === '') {
        flash('danger', 'Informe o nome.');
        redirect('/protocolos/configuracao.php');
    }
    if ($tipo === 'tema') {
        if ($id) {
            $pdo->prepare('UPDATE protocolo_temas SET nome = ?, ativo = ?, ordem = ? WHERE id = ?')->execute([$nome, $ativo, $ordem, $id]);
        } else {
            $pdo->prepare('INSERT INTO protocolo_temas (nome, ativo, ordem) VALUES (?, ?, ?)')->execute([$nome, $ativo, $ordem]);
        }
    } elseif ($tipo === 'assunto') {
        $anexo = in_array(input('anexo'), ['nao', 'opcional', 'obrigatorio'], true) ? input('anexo') : 'opcional';
        $valores = [(int) ($_POST['tema_id'] ?? 0) ?: null, $nome, mb_substr(trim(input('instrucao')), 0, 255) ?: null, isset($_POST['pede_turma']) ? 1 : 0, isset($_POST['pede_data']) ? 1 : 0, $anexo, $ativo, $ordem];
        if ($id) {
            $pdo->prepare('UPDATE protocolo_assuntos SET tema_id = ?, nome = ?, instrucao = ?, pede_turma = ?, pede_data = ?, anexo = ?, ativo = ?, ordem = ? WHERE id = ?')->execute([...$valores, $id]);
        } else {
            $pdo->prepare('INSERT INTO protocolo_assuntos (tema_id, nome, instrucao, pede_turma, pede_data, anexo, ativo, ordem) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute($valores);
        }
    } else {
        $cor = preg_match('/^#[0-9a-fA-F]{6}$/', input('cor')) ? input('cor') : '#6c757d';
        $inicial = isset($_POST['inicial']) ? 1 : 0;
        $encerra = $inicial ? 0 : (isset($_POST['encerra']) ? 1 : 0);
        $valores = [$nome, $cor, $inicial, $encerra, $inicial ? 1 : $ativo, $ordem];
        if ($id) {
            $pdo->prepare('UPDATE protocolo_status SET nome = ?, cor = ?, inicial = ?, encerra = ?, ativo = ?, ordem = ? WHERE id = ?')->execute([...$valores, $id]);
        } else {
            $pdo->prepare('INSERT INTO protocolo_status (nome, cor, inicial, encerra, ativo, ordem) VALUES (?, ?, ?, ?, ?, ?)')->execute($valores);
            $id = (int) $pdo->lastInsertId();
        }
        // Um só status inicial.
        if ($inicial) {
            $pdo->prepare('UPDATE protocolo_status SET inicial = 0 WHERE id <> ?')->execute([$id]);
        }
    }
    $rotulo = ['tema' => 'Tema', 'assunto' => 'Assunto', 'status' => 'Status'][$tipo];
    registrarAtividade('protocolos', 'edicao', 'Configuração dos protocolos: ' . mb_strtolower($rotulo) . ' "' . $nome . '" salvo');
    flash('success', $rotulo . ' "' . $nome . '" salvo.');
    redirect('/protocolos/configuracao.php');
}

$temas = $pdo->query('SELECT pt.*, (SELECT COUNT(*) FROM protocolo_assuntos pa WHERE pa.tema_id = pt.id) AS assuntos FROM protocolo_temas pt ORDER BY pt.ordem, pt.nome')->fetchAll();
$assuntos = $pdo->query(
    'SELECT pa.*, pt.nome AS tema_nome, (SELECT COUNT(*) FROM protocolos p WHERE p.assunto_id = pa.id) AS usos
     FROM protocolo_assuntos pa LEFT JOIN protocolo_temas pt ON pt.id = pa.tema_id ORDER BY pt.id IS NULL, pt.ordem, pa.ordem, pa.nome'
)->fetchAll();
$statusLista = $pdo->query('SELECT ps.*, (SELECT COUNT(*) FROM protocolos p WHERE p.status_id = ps.id) AS usos FROM protocolo_status ps ORDER BY ps.ordem, ps.nome')->fetchAll();
$rotulosAnexo = ['nao' => 'Não pede', 'opcional' => 'Opcional', 'obrigatorio' => 'Obrigatório'];

$pageTitle = 'Configurar protocolos';
$pageBreadcrumb = 'Secretaria · Protocolos';
$activeMenu = 'protocolos';
$pageAction = '<a href="/protocolos/listar.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Protocolos</a>';
require __DIR__ . '/../../includes/layout_header.php';
?>
<div class="secao">
    <div class="secao-titulo"><i class="bi bi-collection"></i> Temas
        <span class="ms-auto"><button type="button" class="btn btn-sm btn-brand" onclick="editarTema(null)"><i class="bi bi-plus-lg"></i> Incluir tema</button></span></div>
    <p class="text-muted small">Agrupam os assuntos (ex.: Acadêmico, Financeiro). No portal, o aluno vê os assuntos separados por tema.</p>
    <div class="card"><div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead><tr><th>Tema</th><th class="text-center">Assuntos</th><th>Situação</th></tr></thead>
            <tbody>
            <?php foreach ($temas as $tm): ?>
                <tr class="cursor-pointer" onclick='editarTema(<?= e(json_encode($tm, JSON_UNESCAPED_UNICODE)) ?>)'>
                    <td><?= e($tm['nome']) ?></td>
                    <td class="text-center"><?= (int) $tm['assuntos'] ?></td>
                    <td><?= $tm['ativo'] ? '<span class="badge text-bg-success">Ativo</span>' : '<span class="badge text-bg-secondary">Inativo</span>' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div></div>
</div>

<div class="secao">
    <div class="secao-titulo"><i class="bi bi-tags"></i> Assuntos
        <span class="ms-auto"><button type="button" class="btn btn-sm btn-brand" onclick="editarAssunto(null)"><i class="bi bi-plus-lg"></i> Incluir assunto</button></span></div>
    <p class="text-muted small">O que o aluno escolhe ao abrir o protocolo no portal, e o que cada assunto pede a ele.</p>
    <div class="card"><div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead><tr><th>Assunto</th><th>Tema</th><th>Pede turma/módulo</th><th>Pede data</th><th>Anexo</th><th class="text-center">Protocolos</th><th>Situação</th></tr></thead>
            <tbody>
            <?php foreach ($assuntos as $a): ?>
                <tr class="cursor-pointer" onclick='editarAssunto(<?= e(json_encode($a, JSON_UNESCAPED_UNICODE)) ?>)'>
                    <td><?= e($a['nome']) ?><?php if ($a['instrucao']): ?><div class="small text-muted"><?= e($a['instrucao']) ?></div><?php endif; ?></td>
                    <td><?= e($a['tema_nome'] ?? '—') ?></td>
                    <td><?= $a['pede_turma'] ? 'Sim' : 'Não' ?></td>
                    <td><?= $a['pede_data'] ? 'Sim' : 'Não' ?></td>
                    <td><?= e($rotulosAnexo[$a['anexo']]) ?></td>
                    <td class="text-center"><?= (int) $a['usos'] ?></td>
                    <td><?= $a['ativo'] ? '<span class="badge text-bg-success">Ativo</span>' : '<span class="badge text-bg-secondary">Inativo</span>' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div></div>
</div>

<div class="secao">
    <div class="secao-titulo"><i class="bi bi-flag"></i> Status
        <span class="ms-auto"><button type="button" class="btn btn-sm btn-brand" onclick="editarStatus(null)"><i class="bi bi-plus-lg"></i> Incluir status</button></span></div>
    <p class="text-muted small">O <strong>inicial</strong> é o do protocolo recém-aberto. Os que <strong>encerram</strong> finalizam o protocolo: o aluno não responde mais nele.</p>
    <div class="card"><div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead><tr><th>Status</th><th>Regra</th><th class="text-center">Protocolos</th><th>Situação</th></tr></thead>
            <tbody>
            <?php foreach ($statusLista as $st): ?>
                <tr class="cursor-pointer" onclick='editarStatus(<?= e(json_encode($st, JSON_UNESCAPED_UNICODE)) ?>)'>
                    <td><?= badgeStatusProtocolo($st['nome'], $st['cor']) ?></td>
                    <td class="small"><?= $st['inicial'] ? 'Inicial (protocolo novo)' : ($st['encerra'] ? 'Encerra o protocolo' : 'Em andamento') ?></td>
                    <td class="text-center"><?= (int) $st['usos'] ?></td>
                    <td><?= $st['ativo'] ? '<span class="badge text-bg-success">Ativo</span>' : '<span class="badge text-bg-secondary">Inativo</span>' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div></div>
</div>

<div class="modal fade" id="modal-assunto" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="post">
            <input type="hidden" name="tipo" value="assunto">
            <input type="hidden" name="id" id="as-id">
            <div class="modal-header"><h5 class="modal-title" id="as-titulo">Assunto</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="row g-2 mb-3">
                    <div class="col-7"><label class="form-label">Nome *</label><input type="text" name="nome" id="as-nome" class="form-control" maxlength="100" required></div>
                    <div class="col-5"><label class="form-label">Tema</label>
                        <select name="tema_id" id="as-tema" class="form-select"><option value="">—</option>
                            <?php foreach ($temas as $tm): ?><option value="<?= (int) $tm['id'] ?>"><?= e($tm['nome']) ?></option><?php endforeach; ?>
                        </select></div>
                </div>
                <div class="mb-3"><label class="form-label">Orientação para o aluno</label><input type="text" name="instrucao" id="as-instrucao" class="form-control" maxlength="255" placeholder="Ex.: anexe o atestado médico"></div>
                <div class="form-check"><input type="checkbox" class="form-check-input" name="pede_turma" id="as-turma"><label class="form-check-label" for="as-turma">Pede a turma (e o módulo)</label></div>
                <div class="form-check mb-3"><input type="checkbox" class="form-check-input" name="pede_data" id="as-data"><label class="form-check-label" for="as-data">Pede a data (ex.: da falta)</label></div>
                <div class="row g-2">
                    <div class="col-7"><label class="form-label">Anexo</label>
                        <select name="anexo" id="as-anexo" class="form-select"><?php foreach ($rotulosAnexo as $v => $r): ?><option value="<?= $v ?>"><?= $r ?></option><?php endforeach; ?></select></div>
                    <div class="col-5"><label class="form-label">Ordem</label><input type="number" name="ordem" id="as-ordem" class="form-control"></div>
                </div>
                <div class="form-check form-switch mt-3"><input type="checkbox" class="form-check-input" name="ativo" id="as-ativo"><label class="form-check-label" for="as-ativo">Ativo (aparece para o aluno)</label></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-brand"><i class="bi bi-check-lg"></i> Salvar</button></div>
        </form>
    </div>
</div>

<div class="modal fade" id="modal-tema" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="post">
            <input type="hidden" name="tipo" value="tema">
            <input type="hidden" name="id" id="tm-id">
            <div class="modal-header"><h5 class="modal-title" id="tm-titulo">Tema</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="row g-2">
                    <div class="col-8"><label class="form-label">Nome *</label><input type="text" name="nome" id="tm-nome" class="form-control" maxlength="60" required placeholder="Ex.: Financeiro"></div>
                    <div class="col-4"><label class="form-label">Ordem</label><input type="number" name="ordem" id="tm-ordem" class="form-control"></div>
                </div>
                <div class="form-check form-switch mt-3"><input type="checkbox" class="form-check-input" name="ativo" id="tm-ativo"><label class="form-check-label" for="tm-ativo">Ativo (inativo esconde os assuntos dele do aluno)</label></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-brand"><i class="bi bi-check-lg"></i> Salvar</button></div>
        </form>
    </div>
</div>

<div class="modal fade" id="modal-status" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="post">
            <input type="hidden" name="tipo" value="status">
            <input type="hidden" name="id" id="st-id">
            <div class="modal-header"><h5 class="modal-title" id="st-titulo">Status</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="row g-2 mb-3">
                    <div class="col-8"><label class="form-label">Nome *</label><input type="text" name="nome" id="st-nome" class="form-control" maxlength="60" required></div>
                    <div class="col-4"><label class="form-label">Cor</label><input type="color" name="cor" id="st-cor" class="form-control form-control-color w-100"></div>
                </div>
                <div class="form-check"><input type="checkbox" class="form-check-input" name="inicial" id="st-inicial"><label class="form-check-label" for="st-inicial">Inicial (protocolo recém-aberto)</label></div>
                <div class="form-check mb-3"><input type="checkbox" class="form-check-input" name="encerra" id="st-encerra"><label class="form-check-label" for="st-encerra">Encerra o protocolo</label></div>
                <div class="row g-2">
                    <div class="col-5"><label class="form-label">Ordem</label><input type="number" name="ordem" id="st-ordem" class="form-control"></div>
                </div>
                <div class="form-check form-switch mt-3"><input type="checkbox" class="form-check-input" name="ativo" id="st-ativo"><label class="form-check-label" for="st-ativo">Ativo</label></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-brand"><i class="bi bi-check-lg"></i> Salvar</button></div>
        </form>
    </div>
</div>

<script>
function editarTema(t) {
    document.getElementById('tm-titulo').textContent = t ? 'Editar tema' : 'Novo tema';
    document.getElementById('tm-id').value = t ? t.id : '';
    document.getElementById('tm-nome').value = t ? t.nome : '';
    document.getElementById('tm-ordem').value = t ? t.ordem : <?= count($temas) + 1 ?>;
    document.getElementById('tm-ativo').checked = t ? t.ativo == 1 : true;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-tema')).show();
}
function editarAssunto(a) {
    document.getElementById('as-titulo').textContent = a ? 'Editar assunto' : 'Novo assunto';
    document.getElementById('as-id').value = a ? a.id : '';
    document.getElementById('as-nome').value = a ? a.nome : '';
    document.getElementById('as-tema').value = a ? (a.tema_id || '') : '';
    document.getElementById('as-instrucao').value = a ? (a.instrucao || '') : '';
    document.getElementById('as-turma').checked = a ? a.pede_turma == 1 : false;
    document.getElementById('as-data').checked = a ? a.pede_data == 1 : false;
    document.getElementById('as-anexo').value = a ? a.anexo : 'opcional';
    document.getElementById('as-ordem').value = a ? a.ordem : <?= count($assuntos) + 1 ?>;
    document.getElementById('as-ativo').checked = a ? a.ativo == 1 : true;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-assunto')).show();
}
function editarStatus(s) {
    document.getElementById('st-titulo').textContent = s ? 'Editar status' : 'Novo status';
    document.getElementById('st-id').value = s ? s.id : '';
    document.getElementById('st-nome').value = s ? s.nome : '';
    document.getElementById('st-cor').value = s ? s.cor : '#6c757d';
    document.getElementById('st-inicial').checked = s ? s.inicial == 1 : false;
    document.getElementById('st-encerra').checked = s ? s.encerra == 1 : false;
    document.getElementById('st-ordem').value = s ? s.ordem : <?= count($statusLista) + 1 ?>;
    document.getElementById('st-ativo').checked = s ? s.ativo == 1 : true;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-status')).show();
}
// Inicial e "encerra" não combinam.
document.getElementById('st-inicial').addEventListener('change', (ev) => { if (ev.target.checked) document.getElementById('st-encerra').checked = false; });
document.getElementById('st-encerra').addEventListener('change', (ev) => { if (ev.target.checked) document.getElementById('st-inicial').checked = false; });
</script>
<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
