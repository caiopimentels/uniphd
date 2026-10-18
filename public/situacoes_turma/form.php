<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirAdmin();

$pdo = getDB();
$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
$situacao = null;
$turmasUsando = 0;

if ($id) {
    $stmt = $pdo->prepare('SELECT * FROM situacoes_turma WHERE id = ?');
    $stmt->execute([$id]);
    $situacao = $stmt->fetch();
    if (!$situacao) {
        flash('danger', 'Situação não encontrada.');
        redirect('/situacoes_turma/listar.php');
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM turmas WHERE situacao_id = ?');
    $stmt->execute([$id]);
    $turmasUsando = (int) $stmt->fetchColumn();
}

$rotulosComportamento = rotulosComportamentoTurma();
$erros = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome = input('nome');
    $cor = trim($_POST['cor'] ?? '#6c757d');
    $comportamento = array_key_exists(input('comportamento'), $rotulosComportamento) ? input('comportamento') : '';
    $padrao = isset($_POST['padrao']) ? 1 : 0;
    $ativo = isset($_POST['ativo']) ? 1 : 0;

    if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $cor)) {
        $cor = '#6c757d';
    }
    if ($nome === '') {
        $erros[] = 'O nome é obrigatório.';
    }
    if ($comportamento === '') {
        $erros[] = 'Escolha a regra da situação.';
    }
    // Mudar a regra de uma situação já usada mudaria, de uma vez, o que o
    // sistema faz com todas as turmas nela (ex.: turmas "em andamento"
    // passando a ser tratadas como canceladas) sem passar pelas validações.
    if ($id && $turmasUsando > 0 && $comportamento !== $situacao['comportamento']) {
        $erros[] = "Esta situação já é usada por {$turmasUsando} turma(s) e não pode mudar de regra. Crie uma situação nova.";
    }
    if ($padrao && !$ativo) {
        $erros[] = 'Uma situação inativa não pode ser a padrão.';
    }
    if ($padrao && !in_array($comportamento, COMPORTAMENTOS_TURMA_ABERTA, true)) {
        $erros[] = 'A situação padrão de turmas novas precisa aceitar matrícula.';
    }
    if ($id && $situacao['padrao'] && (!$padrao || !$ativo)) {
        $erros[] = 'Esta é a situação padrão. Marque outra como padrão antes de tirar desta.';
    }

    if (!$erros) {
        $operacao = $id ? 'edicao' : 'criacao';
        $pdo->beginTransaction();

        if ($padrao) {
            $pdo->exec('UPDATE situacoes_turma SET padrao = 0');
        }

        if ($id) {
            $stmt = $pdo->prepare('UPDATE situacoes_turma SET nome=?, cor=?, comportamento=?, padrao=?, ativo=? WHERE id=?');
            $stmt->execute([$nome, $cor, $comportamento, $padrao, $ativo, $id]);
        } else {
            $ordem = (int) $pdo->query('SELECT COALESCE(MAX(ordem), 0) + 1 FROM situacoes_turma')->fetchColumn();
            $stmt = $pdo->prepare('INSERT INTO situacoes_turma (nome, cor, comportamento, padrao, ordem, ativo) VALUES (?,?,?,?,?,?)');
            $stmt->execute([$nome, $cor, $comportamento, $padrao, $ordem, $ativo]);
        }

        $pdo->commit();

        registrarAtividade('turmas', $operacao, ($operacao === 'criacao' ? 'Situação de turma criada: ' : 'Situação de turma editada: ') . $nome);
        flash('success', 'Situação salva com sucesso.');
        redirect('/situacoes_turma/listar.php');
    }

    $situacao = ['nome' => $nome, 'cor' => $cor, 'comportamento' => $comportamento, 'padrao' => $padrao, 'ativo' => $ativo] + ($situacao ?? []);
}

$pageTitle = $id ? e($situacao['nome']) : 'Nova situação';
$pageBreadcrumb = 'Configurações · Situações de turma';
$activeMenu = 'configuracoes';
$pageAction = '<a href="/situacoes_turma/listar.php" class="btn btn-outline-secondary">Cancelar</a>'
    . '<button type="submit" form="form-principal" class="btn btn-brand"><i class="bi bi-check-lg"></i> Salvar</button>';
require __DIR__ . '/../../includes/layout_header.php';
$abaAtiva = 'situacoes';
require __DIR__ . '/../../includes/abas_configuracoes.php';
$abaAuxiliar = 'situacoes';
require __DIR__ . '/../../includes/abas_cadastros_auxiliares.php';
?>

<?php if ($erros): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($erros as $erro): ?><li><?= e($erro) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="secao" style="max-width: 640px;">
    <div class="secao-titulo"><i class="bi bi-bookmark-check"></i> Dados da situação</div>
    <form method="post" id="form-principal">
        <div class="row g-3">
            <div class="col-md-8">
                <label class="form-label">Nome *</label>
                <input type="text" name="nome" class="form-control" required maxlength="60" value="<?= e($situacao['nome'] ?? '') ?>" placeholder="Ex.: Inscrições abertas, Em andamento, Formada">
            </div>
            <div class="col-md-4">
                <label class="form-label">Cor</label>
                <input type="color" name="cor" class="form-control form-control-color" value="<?= e($situacao['cor'] ?? '#6c757d') ?>">
            </div>
            <div class="col-12">
                <label class="form-label">Regra *</label>
                <select name="comportamento" class="form-select" required <?= $turmasUsando > 0 ? 'disabled' : '' ?>>
                    <option value="">Selecione...</option>
                    <?php foreach ($rotulosComportamento as $chave => $rotulo): ?>
                        <option value="<?= e($chave) ?>" <?= ($situacao['comportamento'] ?? '') === $chave ? 'selected' : '' ?>><?= e($rotulo) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($turmasUsando > 0): ?>
                    <input type="hidden" name="comportamento" value="<?= e($situacao['comportamento']) ?>">
                    <div class="form-text">Usada por <?= $turmasUsando ?> turma(s): a regra não pode mudar.</div>
                <?php else: ?>
                    <div class="form-text">Define o que o sistema faz com a turma nesta situação (se aceita matrícula, se encerra as matrículas...).</div>
                <?php endif; ?>
            </div>
            <div class="col-12 form-check ps-4">
                <input type="checkbox" name="padrao" class="form-check-input" id="padrao" <?= ($situacao['padrao'] ?? 0) ? 'checked' : '' ?>>
                <label class="form-check-label" for="padrao">Situação padrão para turmas novas</label>
            </div>
            <div class="col-12 form-check ps-4">
                <input type="checkbox" name="ativo" class="form-check-input" id="ativo" <?= ($situacao['ativo'] ?? 1) ? 'checked' : '' ?>>
                <label class="form-check-label" for="ativo">Ativa (aparece para escolher)</label>
            </div>
        </div>
    </form>
</div>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
