<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirAdmin();

// Feriados da cidade (Cadastros auxiliares). Uma parcela que vence em fim de
// semana ou feriado só fica "em atraso" depois do próximo dia útil, como no
// banco. Os nacionais o sistema calcula sozinho (aparecem só para consulta).
$pdo = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = input('acao');
    if ($acao === 'incluir') {
        $data = input('data');
        $nome = mb_substr(input('nome'), 0, 100);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) || $nome === '') {
            flash('danger', 'Informe a data e o nome do feriado.');
        } else {
            $todoAno = isset($_POST['todo_ano']) ? 1 : 0;
            $pdo->prepare('INSERT INTO feriados (data, nome, todo_ano) VALUES (?, ?, ?)')->execute([$data, $nome, $todoAno]);
            registrarAtividade('configuracoes', 'criacao', 'Feriado incluído: ' . $nome . ' (' . ($todoAno ? date('d/m', strtotime($data)) . ', todo ano' : formatDate($data)) . ')');
            flash('success', 'Feriado "' . $nome . '" incluído.');
        }
    } elseif ($acao === 'excluir') {
        $stmt = $pdo->prepare('SELECT * FROM feriados WHERE id = ?');
        $stmt->execute([(int) ($_POST['id'] ?? 0)]);
        if ($feriado = $stmt->fetch()) {
            $pdo->prepare('DELETE FROM feriados WHERE id = ?')->execute([$feriado['id']]);
            registrarAtividade('configuracoes', 'exclusao', 'Feriado excluído: ' . $feriado['nome']);
            flash('success', 'Feriado excluído.');
        }
    }
    redirect('/feriados/listar.php');
}

$feriados = $pdo->query('SELECT * FROM feriados ORDER BY todo_ano DESC, DATE_FORMAT(data, "%m-%d"), data')->fetchAll();
$ano = (int) date('Y');
$nacionais = feriadosNacionais($ano);

$pageTitle = 'Configurações';
$pageBreadcrumb = 'Administração';
$activeMenu = 'configuracoes';
require __DIR__ . '/../../includes/layout_header.php';
$abaAtiva = 'situacoes';
require __DIR__ . '/../../includes/abas_configuracoes.php';
$abaAuxiliar = 'feriados';
require __DIR__ . '/../../includes/abas_cadastros_auxiliares.php';
?>

<p class="text-muted" style="max-width: 900px;">
    Uma parcela que vence em fim de semana ou feriado pode ser paga no <strong>próximo dia útil</strong> sem ficar em atraso, como no banco.
    Os feriados nacionais o sistema já conhece; cadastre aqui só os <strong>da cidade</strong> (padroeira, aniversário da cidade...).
</p>

<form method="post" class="d-flex flex-wrap gap-2 align-items-end mb-3" style="max-width: 900px;">
    <input type="hidden" name="acao" value="incluir">
    <div><label class="form-label small mb-1">Data</label><input type="date" name="data" class="form-control" required></div>
    <div class="flex-grow-1"><label class="form-label small mb-1">Nome</label><input type="text" name="nome" class="form-control" maxlength="100" required placeholder="Ex.: Padroeira da cidade"></div>
    <div class="form-check form-switch mb-2">
        <input type="checkbox" class="form-check-input" role="switch" name="todo_ano" id="todo-ano" checked>
        <label class="form-check-label" for="todo-ano">Repete todo ano</label>
    </div>
    <button type="submit" class="btn btn-brand"><i class="bi bi-plus-lg"></i> Incluir</button>
</form>

<div class="row g-3" style="max-width: 1100px;">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header fw-semibold">Feriados da cidade</div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead><tr><th>Data</th><th>Nome</th><th>Repete</th><th></th></tr></thead>
                    <tbody>
                    <?php if (!$feriados): ?>
                        <tr><td colspan="4" class="text-center text-muted py-4">Nenhum feriado da cidade cadastrado.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($feriados as $f): ?>
                        <tr>
                            <td class="text-nowrap"><?= $f['todo_ano'] ? date('d/m', strtotime($f['data'])) : formatDate($f['data']) ?></td>
                            <td><?= e($f['nome']) ?></td>
                            <td><?= $f['todo_ano'] ? 'Todo ano' : 'Só em ' . date('Y', strtotime($f['data'])) ?></td>
                            <td class="text-end">
                                <form method="post" data-confirmar="<?= e('Excluir o feriado "' . $f['nome'] . '"?') ?>">
                                    <input type="hidden" name="acao" value="excluir"><input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Excluir"><i class="bi bi-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header fw-semibold">Nacionais em <?= $ano ?> <span class="small text-muted fw-normal">(automáticos)</span></div>
            <ul class="list-group list-group-flush small">
                <?php foreach ($nacionais as $d => $nome): ?>
                    <li class="list-group-item d-flex justify-content-between">
                        <span><?= e($nome) ?></span>
                        <span class="text-muted"><?= formatDate($d) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../../includes/modal_confirmar.php'; ?>
<script>
document.querySelectorAll('form[data-confirmar]').forEach((f) => f.addEventListener('submit', async (ev) => {
    if (f.dataset.ok) return;
    ev.preventDefault();
    if (await confirmarNoModal({ titulo: 'Confirmar', html: escapeHtml(f.dataset.confirmar) })) {
        f.dataset.ok = '1';
        f.requestSubmit(ev.submitter);
    }
}));
</script>
<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
