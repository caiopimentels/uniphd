<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirAdmin();

// Tipos de graduação que o aluno escolhe no cadastro ("Possui graduação? Qual?").
// "Liberado no site" = aparece no cadastro do portal; desligado, só a
// secretaria escolhe. Inativo some das duas listas (quem já escolheu mantém).
$pdo = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = input('acao');
    $id = (int) ($_POST['id'] ?? 0);
    $stmt = $pdo->prepare('SELECT * FROM tipos_graduacao WHERE id = ?');
    $stmt->execute([$id]);
    $tipo = $stmt->fetch();

    if ($acao === 'incluir') {
        $nome = mb_substr(trim(input('nome')), 0, 120);
        if ($nome === '') {
            flash('danger', 'Informe o nome da graduação.');
        } else {
            $pdo->prepare('INSERT INTO tipos_graduacao (nome, no_site, ativo) VALUES (?, ?, 1)')->execute([$nome, isset($_POST['no_site']) ? 1 : 0]);
            registrarAtividade('configuracoes', 'criacao', 'Tipo de graduação incluído: ' . $nome);
            flash('success', 'Graduação "' . $nome . '" incluída.');
        }
    } elseif ($tipo && $acao === 'renomear') {
        $nome = mb_substr(trim(input('nome')), 0, 120);
        if ($nome !== '' && $nome !== $tipo['nome']) {
            $pdo->prepare('UPDATE tipos_graduacao SET nome = ? WHERE id = ?')->execute([$nome, $id]);
            registrarAtividade('configuracoes', 'edicao', 'Tipo de graduação renomeado: ' . $tipo['nome'] . ' → ' . $nome);
            flash('success', 'Nome alterado.');
        }
    } elseif ($tipo && in_array($acao, ['site', 'ativo'], true)) {
        $coluna = $acao === 'site' ? 'no_site' : 'ativo';
        $pdo->prepare("UPDATE tipos_graduacao SET {$coluna} = 1 - {$coluna} WHERE id = ?")->execute([$id]);
        registrarAtividade('configuracoes', 'edicao', 'Tipo de graduação "' . $tipo['nome'] . '": ' . ($acao === 'site' ? 'liberado no site' : 'ativo') . ' = ' . ($tipo[$coluna] ? 'não' : 'sim'));
    } elseif ($tipo && $acao === 'excluir') {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM alunos WHERE graduacao_id = ?');
        $stmt->execute([$id]);
        $usados = (int) $stmt->fetchColumn();
        if ($usados) {
            flash('danger', '"' . $tipo['nome'] . '" está no cadastro de ' . $usados . ' aluno(s) e não pode ser excluída. Desative para não aparecer mais.');
        } else {
            $pdo->prepare('DELETE FROM tipos_graduacao WHERE id = ?')->execute([$id]);
            registrarAtividade('configuracoes', 'exclusao', 'Tipo de graduação excluído: ' . $tipo['nome']);
            flash('success', 'Graduação excluída.');
        }
    }
    redirect('/tipos_graduacao/listar.php');
}

$tipos = $pdo->query(
    'SELECT tg.*, (SELECT COUNT(*) FROM alunos a WHERE a.graduacao_id = tg.id) AS alunos FROM tipos_graduacao tg ORDER BY tg.ativo DESC, tg.ordem, tg.nome'
)->fetchAll();

$pageTitle = 'Configurações';
$pageBreadcrumb = 'Administração';
$activeMenu = 'configuracoes';
require __DIR__ . '/../../includes/layout_header.php';
$abaAtiva = 'situacoes';
require __DIR__ . '/../../includes/abas_configuracoes.php';
$abaAuxiliar = 'graduacao';
require __DIR__ . '/../../includes/abas_cadastros_auxiliares.php';
?>

<p class="text-muted" style="max-width: 900px;">
    As graduações que o aluno escolhe no cadastro ("Possui graduação? Qual?"). <strong>Liberado no site</strong>: aparece no cadastro do portal;
    desligado, só a secretaria escolhe na ficha do aluno. <strong>Inativo</strong>: some das duas listas (quem já escolheu continua com ela).
</p>

<form method="post" class="d-flex flex-wrap gap-2 align-items-end mb-3" style="max-width: 700px;">
    <input type="hidden" name="acao" value="incluir">
    <div class="flex-grow-1">
        <label class="form-label small mb-1">Nova graduação</label>
        <input type="text" name="nome" class="form-control" maxlength="120" required placeholder="Ex.: Biomedicina">
    </div>
    <div class="form-check form-switch mb-2">
        <input type="checkbox" class="form-check-input" role="switch" name="no_site" id="novo-site" checked>
        <label class="form-check-label" for="novo-site">Liberado no site</label>
    </div>
    <button type="submit" class="btn btn-brand"><i class="bi bi-plus-lg"></i> Incluir</button>
</form>

<div class="card" style="max-width: 900px;">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead><tr><th>Graduação</th><th class="text-center">Liberado no site</th><th class="text-center">Ativo</th><th class="text-center">Alunos</th><th></th></tr></thead>
            <tbody>
            <?php if (!$tipos): ?>
                <tr><td colspan="5" class="text-center text-muted py-4">Nenhuma graduação cadastrada. Inclua acima as que os alunos podem escolher.</td></tr>
            <?php endif; ?>
            <?php foreach ($tipos as $t): ?>
                <tr class="<?= $t['ativo'] ? '' : 'text-muted' ?>">
                    <td>
                        <form method="post" class="d-flex gap-2">
                            <input type="hidden" name="acao" value="renomear"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                            <input type="text" name="nome" class="form-control form-control-sm" maxlength="120" value="<?= e($t['nome']) ?>" onchange="this.form.submit()">
                        </form>
                    </td>
                    <td class="text-center">
                        <form method="post"><input type="hidden" name="acao" value="site"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                            <div class="form-check form-switch d-inline-block"><input type="checkbox" class="form-check-input" role="switch" <?= $t['no_site'] ? 'checked' : '' ?> onchange="this.form.submit()" title="Aparece no cadastro do portal"></div>
                        </form>
                    </td>
                    <td class="text-center">
                        <form method="post"><input type="hidden" name="acao" value="ativo"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                            <div class="form-check form-switch d-inline-block"><input type="checkbox" class="form-check-input" role="switch" <?= $t['ativo'] ? 'checked' : '' ?> onchange="this.form.submit()"></div>
                        </form>
                    </td>
                    <td class="text-center"><?= (int) $t['alunos'] ?></td>
                    <td class="text-end">
                        <form method="post" onsubmit="return confirm(<?= e(json_encode('Excluir "' . $t['nome'] . '"?')) ?>);">
                            <input type="hidden" name="acao" value="excluir"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger" title="<?= $t['alunos'] ? 'Está em uso — desative em vez de excluir' : 'Excluir' ?>" <?= $t['alunos'] ? 'disabled' : '' ?>><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
