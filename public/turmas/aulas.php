<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();
exigirCursos();

$pdo = getDB();
$id = (int) ($_GET['id'] ?? $_POST['turma_id'] ?? 0);
$stmt = $pdo->prepare('SELECT t.*, c.nome AS curso_nome FROM turmas t JOIN cursos c ON c.id = t.curso_id WHERE t.id = ?');
$stmt->execute([$id]);
$turma = $stmt->fetch();
if (!$turma) {
    flash('danger', 'Turma não encontrada.');
    redirect('/turmas/listar.php');
}
$aqui = '/turmas/aulas.php?id=' . $id;

// Ver o calendário é ver_cursos; gerar e ajustar aulas é de quem edita turmas.
$podeEditarAulas = usuarioPodeEditarTurma();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$podeEditarAulas) {
    flash('danger', 'Seu perfil não pode alterar o calendário de aulas.');
    redirect($aqui);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = input('acao');
    $aulaId = (int) ($_POST['aula_id'] ?? 0);
    // Toda ação numa aula confere que ela é desta turma.
    $stmt = $pdo->prepare('SELECT * FROM turma_aulas WHERE id = ? AND turma_id = ?');
    $stmt->execute([$aulaId, $id]);
    $aula = $stmt->fetch();

    if ($acao === 'gerar') {
        $resultado = gerarAulasTurma($pdo, $id);
        if ($resultado['ok']) {
            registrarAtividade('turmas', 'edicao', 'Calendário gerado para a turma ' . $turma['nome'] . ': ' . $resultado['mensagem']);
        }
        flash($resultado['ok'] ? 'success' : 'danger', $resultado['mensagem']);
    } elseif ($acao === 'incluir') {
        $data = input('data');
        if (!$data || strtotime($data) === false) {
            flash('danger', 'Informe a data da aula extra.');
        } else {
            $stmt = $pdo->prepare('INSERT INTO turma_aulas (turma_id, data, hora_inicio, hora_fim, avulsa, tema, observacao) VALUES (?, ?, ?, ?, 1, ?, ?)');
            $stmt->execute([$id, $data, input('hora_inicio') ?: $turma['hora_inicio'], input('hora_fim') ?: $turma['hora_fim'], input('tema') ?: null, input('observacao') ?: null]);
            registrarAtividade('turmas', 'edicao', 'Aula extra em ' . formatDate($data) . ' na turma ' . $turma['nome']);
            flash('success', 'Aula extra incluída em ' . formatDate($data) . '.');
        }
    } elseif ($aula && $acao === 'salvar') {
        $data = input('data') ?: $aula['data'];
        $stmt = $pdo->prepare('UPDATE turma_aulas SET data = ?, hora_inicio = ?, hora_fim = ?, tema = ?, observacao = ? WHERE id = ?');
        $stmt->execute([$data, input('hora_inicio') ?: null, input('hora_fim') ?: null, input('tema') ?: null, input('observacao') ?: null, $aula['id']]);
        registrarAtividade('turmas', 'edicao', 'Aula de ' . formatDate($aula['data']) . ($data !== $aula['data'] ? ' remarcada para ' . formatDate($data) : ' alterada') . ' — turma ' . $turma['nome']);
        flash('success', 'Aula salva.');
    } elseif ($aula && $acao === 'cancelar') {
        $pdo->prepare("UPDATE turma_aulas SET status = IF(status = 'cancelada', 'prevista', 'cancelada') WHERE id = ?")->execute([$aula['id']]);
        registrarAtividade('turmas', 'edicao', 'Aula de ' . formatDate($aula['data']) . ($aula['status'] === 'cancelada' ? ' reativada' : ' cancelada') . ' — turma ' . $turma['nome']);
        flash('success', $aula['status'] === 'cancelada' ? 'Aula reativada.' : 'Aula marcada como cancelada — os alunos veem no calendário.');
    } elseif ($aula && $acao === 'excluir') {
        $pdo->prepare('DELETE FROM turma_aulas WHERE id = ?')->execute([$aula['id']]);
        registrarAtividade('turmas', 'exclusao', 'Aula de ' . formatDate($aula['data']) . ' removida do calendário — turma ' . $turma['nome']);
        flash('success', 'Aula removida do calendário.');
    }
    redirect($aqui . (isset($_POST['mes']) ? '&mes=' . urlencode(input('mes')) : ''));
}

$aulas = buscarAulas($pdo, [$id]);
$porMes = [];
foreach ($aulas as $aula) {
    $porMes[substr($aula['data'], 0, 7)][] = $aula;
}
$meses = [1 => 'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
$diasCurtos = [1 => 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb', 'dom'];
$previstas = count(array_filter($aulas, fn ($a) => $a['status'] === 'prevista'));
$agenda = textoAgendaTurma($turma);

$pageTitle = 'Calendário de aulas';
$pageBreadcrumb = 'Acadêmico · Turmas · ' . $turma['curso_nome'] . ' · ' . $turma['nome'];
$activeMenu = 'turmas';
$pageAction = '<a href="/turmas/visualizar.php?id=' . $id . '" class="btn btn-outline-secondary">Voltar à turma</a>';
require __DIR__ . '/../../includes/layout_header.php';
?>

<div class="secao" style="max-width: 1000px;">
    <div class="d-flex flex-wrap align-items-center gap-3">
        <div>
            <div class="fw-semibold"><?= $agenda ? e($agenda) : 'Dias e horário ainda não definidos' ?></div>
            <div class="small text-muted"><?= e(periodoTurma($turma, true)) ?> · <?= $previstas ?> aula(s) prevista(s)</div>
        </div>
        <div class="ms-auto d-flex gap-2 flex-wrap">
            <?php if (!diasSemanaTurma($turma['dias_semana'])): ?>
                <a href="/turmas/form.php?id=<?= $id ?>" class="btn btn-brand"><i class="bi bi-pencil"></i> Definir dias e horário</a>
            <?php else: ?>
                <form method="post" onsubmit="return <?= $aulas ? "confirm('Refazer o calendário a partir de hoje? As aulas futuras geradas antes (inclusive as que você cancelou ou remarcou) são refeitas pelos dias e horário da turma. Aulas extras e aulas passadas ficam como estão.')" : 'true' ?>;">
                    <input type="hidden" name="turma_id" value="<?= $id ?>">
                    <input type="hidden" name="acao" value="gerar">
                    <button type="submit" class="btn <?= $aulas ? 'btn-outline-secondary' : 'btn-brand' ?>"><i class="bi bi-magic"></i> <?= $aulas ? 'Refazer calendário' : 'Gerar calendário' ?></button>
                </form>
            <?php endif; ?>
            <button type="button" class="btn btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#form-extra"><i class="bi bi-plus-lg"></i> Aula extra</button>
        </div>
    </div>

    <form method="post" id="form-extra" class="collapse mt-3">
        <input type="hidden" name="turma_id" value="<?= $id ?>">
        <input type="hidden" name="acao" value="incluir">
        <div class="row g-2 align-items-end">
            <div class="col-md-2"><label class="form-label small">Data *</label><input type="date" name="data" class="form-control form-control-sm" required></div>
            <div class="col-md-2"><label class="form-label small">Início</label><input type="time" name="hora_inicio" class="form-control form-control-sm" value="<?= e(substr((string) $turma['hora_inicio'], 0, 5)) ?>"></div>
            <div class="col-md-2"><label class="form-label small">Fim</label><input type="time" name="hora_fim" class="form-control form-control-sm" value="<?= e(substr((string) $turma['hora_fim'], 0, 5)) ?>"></div>
            <div class="col-md-3"><label class="form-label small">Tema</label><input type="text" name="tema" class="form-control form-control-sm" maxlength="150" placeholder="Ex.: Reposição"></div>
            <div class="col-md-3"><button type="submit" class="btn btn-sm btn-brand w-100">Incluir aula</button></div>
        </div>
    </form>
</div>

<?php if (!$aulas): ?>
    <div class="card"><div class="card-body text-center text-muted py-5">
        <i class="bi bi-calendar3 fs-1 d-block mb-2"></i>
        Nenhuma aula no calendário. <?= diasSemanaTurma($turma['dias_semana']) ? 'Clique em "Gerar calendário".' : 'Defina os dias e o horário na turma e gere o calendário.' ?>
    </div></div>
<?php endif; ?>

<?php foreach ($porMes as $mes => $aulasMes): ?>
<div class="card mb-3" style="max-width: 1000px;">
    <div class="card-header bg-transparent fw-semibold"><?= $meses[(int) substr($mes, 5, 2)] ?> de <?= substr($mes, 0, 4) ?></div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <tbody>
            <?php foreach ($aulasMes as $aula): $cancelada = $aula['status'] === 'cancelada'; $passou = $aula['data'] < date('Y-m-d'); ?>
                <?php $f = 'aula-' . (int) $aula['id']; ?>
                <tr class="<?= $cancelada ? 'table-light text-muted' : '' ?>">
                    <td style="width: 150px;">
                        <form method="post" id="<?= $f ?>">
                            <input type="hidden" name="turma_id" value="<?= $id ?>">
                            <input type="hidden" name="aula_id" value="<?= (int) $aula['id'] ?>">
                        </form>
                        <input type="date" name="data" form="<?= $f ?>" class="form-control form-control-sm" value="<?= e($aula['data']) ?>">
                        <div class="small text-muted"><?= $diasCurtos[(int) date('N', strtotime($aula['data']))] ?><?= $aula['avulsa'] ? ' · extra' : '' ?><?= $passou ? ' · já passou' : '' ?></div>
                    </td>
                    <td style="width: 115px;"><input type="time" name="hora_inicio" form="<?= $f ?>" class="form-control form-control-sm" value="<?= e(substr((string) $aula['hora_inicio'], 0, 5)) ?>"></td>
                    <td style="width: 115px;"><input type="time" name="hora_fim" form="<?= $f ?>" class="form-control form-control-sm" value="<?= e(substr((string) $aula['hora_fim'], 0, 5)) ?>"></td>
                    <td><input type="text" name="tema" form="<?= $f ?>" class="form-control form-control-sm" maxlength="150" placeholder="Tema da aula" value="<?= e($aula['tema']) ?>"></td>
                    <td><input type="text" name="observacao" form="<?= $f ?>" class="form-control form-control-sm" maxlength="255" placeholder="Recado ao aluno" value="<?= e($aula['observacao']) ?>"></td>
                    <td class="text-end text-nowrap">
                        <?php if ($cancelada): ?><span class="badge text-bg-secondary me-1">Cancelada</span><?php endif; ?>
                        <button type="submit" form="<?= $f ?>" name="acao" value="salvar" class="btn btn-sm btn-outline-secondary" title="Salvar"><i class="bi bi-check-lg"></i></button>
                        <button type="submit" form="<?= $f ?>" name="acao" value="cancelar" class="btn btn-sm btn-outline-<?= $cancelada ? 'success' : 'warning' ?>" title="<?= $cancelada ? 'Reativar' : 'Cancelar aula' ?>"><i class="bi bi-<?= $cancelada ? 'arrow-counterclockwise' : 'slash-circle' ?>"></i></button>
                        <button type="submit" form="<?= $f ?>" name="acao" value="excluir" class="btn btn-sm btn-outline-danger" title="Remover do calendário" onclick="return confirm('Remover esta aula do calendário?');"><i class="bi bi-trash"></i></button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endforeach; ?>

<?php if (!$podeEditarAulas): ?>
<script>
// Só consulta: some com os botões e trava os campos das aulas.
document.querySelectorAll('.pagina form').forEach((f) => {
    f.querySelectorAll('input, select, textarea').forEach((el) => { el.disabled = true; });
});
document.querySelectorAll('.pagina button[type="submit"], .pagina [data-bs-target="#form-extra"], #form-extra').forEach((el) => { el.remove(); });
document.querySelectorAll('.pagina input[form], .pagina select[form]').forEach((el) => { el.disabled = true; });
</script>
<?php endif; ?>
<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
