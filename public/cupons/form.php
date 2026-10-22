<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();
exigirCupons();

$pdo = getDB();
$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
$cupom = ['codigo' => '', 'descricao' => '', 'tipo' => 'valor', 'valor' => '', 'quantidade' => null, 'validade_inicio' => '', 'validade_fim' => '',
    'uma_vez_por_aluno' => 1, 'acumula' => 1, 'ativo' => 1];
$cursosMarcados = [];
$usos = 0;

if ($id) {
    $stmt = $pdo->prepare('SELECT * FROM cupons WHERE id = ?');
    $stmt->execute([$id]);
    $cupom = $stmt->fetch();
    if (!$cupom) {
        flash('danger', 'Cupom não encontrado.');
        redirect('/cupons/listar.php');
    }
    $cursosMarcados = cursosCupom($pdo, $id);
    $usos = usosCupom($pdo, $id);
}

$erros = [];
// Cupom já cadastrado abre só para ver; "Editar" libera os campos.
$editando = !$id || isset($_GET['editar']) || $_SERVER['REQUEST_METHOD'] === 'POST';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $quantidadeTexto = trim((string) ($_POST['quantidade'] ?? ''));
    $cupom = [
        'codigo' => normalizarCodigoCupom(input('codigo')),
        'descricao' => input('descricao'),
        'tipo' => array_key_exists(input('tipo'), tiposCupom()) ? input('tipo') : 'valor',
        'valor' => decimalInput('valor', 0),
        'quantidade' => $quantidadeTexto === '' ? null : (int) $quantidadeTexto,
        'validade_inicio' => input('validade_inicio'),
        'validade_fim' => input('validade_fim'),
        'uma_vez_por_aluno' => isset($_POST['uma_vez_por_aluno']) ? 1 : 0,
        'acumula' => isset($_POST['acumula']) ? 1 : 0,
        'ativo' => isset($_POST['ativo']) ? 1 : 0,
    ];
    $cursosMarcados = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['cursos'] ?? [])))));
    if ($cupom['tipo'] === 'matricula_zero') {
        $cupom['valor'] = 0;
    }

    if (!preg_match('/^[A-Z0-9_-]{3,40}$/', $cupom['codigo'])) {
        $erros[] = 'O código precisa ter de 3 a 40 letras, números, "-" ou "_" (sem espaço nem acento).';
    } else {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM cupons WHERE codigo = ? AND id <> ?');
        $stmt->execute([$cupom['codigo'], $id ?? 0]);
        if ((int) $stmt->fetchColumn() > 0) {
            $erros[] = 'Já existe um cupom com o código ' . $cupom['codigo'] . '.';
        }
    }
    if ($cupom['tipo'] !== 'matricula_zero' && $cupom['valor'] <= 0) {
        $erros[] = 'Informe o valor do desconto.';
    }
    if ($cupom['tipo'] === 'percentual' && $cupom['valor'] > 100) {
        $erros[] = 'O percentual vai até 100%.';
    }
    if ($cupom['quantidade'] !== null && $cupom['quantidade'] < max(1, $usos)) {
        $erros[] = $usos ? 'A quantidade não pode ser menor que os ' . $usos . ' já usados.' : 'A quantidade precisa ser pelo menos 1 (ou deixe vazio para ilimitado).';
    }
    if ($cupom['validade_inicio'] && $cupom['validade_fim'] && $cupom['validade_fim'] < $cupom['validade_inicio']) {
        $erros[] = 'O fim da validade é antes do início.';
    }

    if (!$erros) {
        $campos = [$cupom['codigo'], $cupom['descricao'] ?: null, $cupom['tipo'], $cupom['valor'], $cupom['quantidade'],
            $cupom['validade_inicio'] ?: null, $cupom['validade_fim'] ?: null, $cupom['uma_vez_por_aluno'], $cupom['acumula'], $cupom['ativo']];
        $pdo->beginTransaction();
        if ($id) {
            $pdo->prepare('UPDATE cupons SET codigo=?, descricao=?, tipo=?, valor=?, quantidade=?, validade_inicio=?, validade_fim=?, uma_vez_por_aluno=?, acumula=?, ativo=? WHERE id=?')
                ->execute([...$campos, $id]);
        } else {
            $pdo->prepare('INSERT INTO cupons (codigo, descricao, tipo, valor, quantidade, validade_inicio, validade_fim, uma_vez_por_aluno, acumula, ativo, usuario_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([...$campos, usuarioAtualId()]);
            $id = (int) $pdo->lastInsertId();
        }
        $pdo->prepare('DELETE FROM cupom_cursos WHERE cupom_id = ?')->execute([$id]);
        $stmt = $pdo->prepare('INSERT INTO cupom_cursos (cupom_id, curso_id) VALUES (?, ?)');
        foreach ($cursosMarcados as $cursoId) {
            $stmt->execute([$id, $cursoId]);
        }
        $pdo->commit();
        registrarAtividade('cupons', isset($_GET['id']) ? 'edicao' : 'criacao', 'Cupom ' . $cupom['codigo'] . ': ' . textoDescontoCupom($cupom));
        flash('success', 'Cupom ' . $cupom['codigo'] . ' salvo.');
        redirect('/cupons/form.php?id=' . $id);
    }
}

$cursos = $pdo->query('SELECT id, nome, ativo FROM cursos ORDER BY ativo DESC, nome')->fetchAll();

// Quem usou: toda matrícula que levou o cupom (inclusive as canceladas,
// que devolveram o cupom).
$utilizacoes = [];
if ($id) {
    $stmt = $pdo->prepare(
        'SELECT m.id, m.status, m.origem, m.data_matricula, m.pagamento_ate, m.cupom_desconto, m.criado_em,
                a.id AS aluno_id, a.nome AS aluno_nome, t.nome AS turma_nome, c.nome AS curso_nome
         FROM matriculas m
         JOIN alunos a ON a.id = m.aluno_id
         JOIN turmas t ON t.id = m.turma_id
         JOIN cursos c ON c.id = t.curso_id
         WHERE m.cupom_id = ?
         ORDER BY m.criado_em DESC, m.id DESC'
    );
    $stmt->execute([$id]);
    $utilizacoes = $stmt->fetchAll();
}
$contagemUso = ['reservado' => 0, 'usado' => 0, 'liberado' => 0];
foreach ($utilizacoes as $u) {
    $contagemUso[situacaoUsoCupom($u)[0]]++;
}

$pageTitle = $id ? 'Cupom ' . e($cupom['codigo']) : 'Novo cupom';
$pageBreadcrumb = 'Financeiro · Cupons de desconto';
$activeMenu = 'cupons';
if ($editando) {
    $pageAction = '<a href="' . ($id ? '/cupons/form.php?id=' . $id : '/cupons/listar.php') . '" class="btn btn-outline-secondary">Cancelar</a>'
        . '<button type="submit" form="form-principal" class="btn btn-brand"><i class="bi bi-check-lg"></i> Salvar</button>';
} else {
    $pageAction = '<a href="/cupons/listar.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Voltar</a>'
        . '<form action="/cupons/situacao.php" method="post" class="d-inline"><input type="hidden" name="id" value="' . (int) $id . '"><input type="hidden" name="voltar" value="form">'
        . '<button type="submit" class="btn ' . ($cupom['ativo'] ? 'btn-outline-danger' : 'btn-outline-success') . '"><i class="bi ' . ($cupom['ativo'] ? 'bi-toggle-off' : 'bi-toggle-on') . '"></i> ' . ($cupom['ativo'] ? 'Desativar' : 'Ativar') . '</button></form>'
        . '<a href="/cupons/form.php?id=' . (int) $id . '&editar=1" class="btn btn-brand"><i class="bi bi-pencil"></i> Editar</a>';
}
require __DIR__ . '/../../includes/layout_header.php';
?>

<?php if ($erros): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($erros as $erro): ?><li><?= e($erro) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<?php if (!$editando && !$cupom['ativo']): ?>
    <div class="alert alert-secondary py-2"><i class="bi bi-pause-circle"></i> Cupom <strong>desativado</strong>: não vale em novas matrículas. As matrículas que já usaram continuam com o desconto.</div>
<?php endif; ?>

<form method="post" id="form-principal">
<fieldset <?= $editando ? '' : 'disabled' ?>>
<div class="row g-3" style="max-width: 1000px;">
    <div class="col-lg-7">
        <div class="secao">
            <div class="secao-titulo"><i class="bi bi-ticket-perforated"></i> Cupom</div>
            <div class="row g-3">
                <div class="col-md-5">
                    <label class="form-label">Código *</label>
                    <input type="text" name="codigo" class="form-control font-monospace text-uppercase" required maxlength="40" placeholder="EX.: BEMVINDO10"
                           value="<?= e($cupom['codigo']) ?>" oninput="this.value = this.value.toUpperCase().replace(/\s/g, '')">
                </div>
                <div class="col-md-7">
                    <label class="form-label">Descrição </label>
                    <input type="text" name="descricao" class="form-control" maxlength="150" placeholder="Ex.: Campanha Black Friday" value="<?= e($cupom['descricao'] ?? '') ?>">
                </div>

                <div class="col-md-6">
                    <label class="form-label">Tipo de desconto</label>
                    <select name="tipo" id="cupom-tipo" class="form-select" onchange="atualizarTipoCupom()">
                        <?php foreach (tiposCupom() as $valorTipo => $rotuloTipo): ?>
                            <option value="<?= e($valorTipo) ?>" <?= $cupom['tipo'] === $valorTipo ? 'selected' : '' ?>><?= e($rotuloTipo) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6" id="campo-valor">
                    <label class="form-label" id="rotulo-valor">Desconto</label>
                    <div class="input-group">
                        <span class="input-group-text" id="prefixo-valor">R$</span>
                        <input type="text" name="valor" class="form-control" inputmode="decimal" placeholder="0,00"
                               value="<?= $cupom['valor'] !== '' && (float) $cupom['valor'] > 0 ? e(number_format((float) $cupom['valor'], 2, ',', '')) : '' ?>">
                    </div>
                </div>
                <div class="col-12 small text-muted" id="explica-tipo"></div>

                <div class="col-md-4">
                    <label class="form-label">Quantidade</label>
                    <input type="number" name="quantidade" class="form-control" min="1" placeholder="Ilimitado" value="<?= $cupom['quantidade'] !== null ? (int) $cupom['quantidade'] : '' ?>">
                    <div class="form-text"><?= $id ? 'Já usados: ' . $usos . '. ' : '' ?>Vazio = ilimitado.</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Vale de</label>
                    <input type="date" name="validade_inicio" class="form-control" value="<?= e($cupom['validade_inicio'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">até</label>
                    <input type="date" name="validade_fim" class="form-control" value="<?= e($cupom['validade_fim'] ?? '') ?>">
                    <div class="form-text">Vazio = sem prazo.</div>
                </div>

                <div class="col-12">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" name="uma_vez_por_aluno" id="uma-vez" <?= $cupom['uma_vez_por_aluno'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="uma-vez">Cada aluno só pode usar uma vez</label>
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" name="acumula" id="acumula" <?= $cupom['acumula'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="acumula">Acumula com outros descontos <span class="text-muted small">(bolsa e desconto por pontualidade dados pela secretaria)</span></label>
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" name="ativo" id="ativo" <?= $cupom['ativo'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="ativo">Ativo</label>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="secao">
            <div class="secao-titulo"><i class="bi bi-mortarboard"></i> Vale para os cursos</div>
            <p class="small text-muted mb-2">Nenhum marcado = vale para todos os cursos.</p>
            <?php if (!$cursos): ?><div class="text-muted small">Nenhum curso cadastrado.</div><?php endif; ?>
            <?php foreach ($cursos as $curso): ?>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="cursos[]" value="<?= (int) $curso['id'] ?>" id="curso-<?= (int) $curso['id'] ?>" <?= in_array((int) $curso['id'], $cursosMarcados, true) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="curso-<?= (int) $curso['id'] ?>"><?= e($curso['nome']) ?><?= $curso['ativo'] ? '' : ' <span class="text-muted small">(inativo)</span>' ?></label>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
</fieldset>
</form>

<?php if ($id): ?>
<div class="secao mt-3" style="max-width: 1000px;">
    <div class="secao-titulo"><i class="bi bi-people"></i> Quem usou</div>
    <div class="d-flex flex-wrap gap-2 mb-3">
        <span class="badge text-bg-success">Usados: <?= $contagemUso['usado'] ?></span>
        <span class="badge text-bg-warning">Reservados (aguardando pagamento): <?= $contagemUso['reservado'] ?></span>
        <span class="badge text-bg-secondary">Liberados (inscrição cancelada): <?= $contagemUso['liberado'] ?></span>
        <span class="badge text-bg-light border">
            Disponíveis: <?= $cupom['quantidade'] !== null ? max(0, (int) $cupom['quantidade'] - $contagemUso['usado'] - $contagemUso['reservado']) . ' de ' . (int) $cupom['quantidade'] : 'ilimitado' ?>
        </span>
    </div>
    <p class="small text-muted">A vaga do cupom é segurada na hora da inscrição (reservado). Se o aluno não paga no prazo, a inscrição é cancelada e o cupom volta a ficar disponível (liberado).</p>
    <?php if (!$utilizacoes): ?>
        <div class="text-muted">Ninguém usou este cupom ainda.</div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead><tr><th>Aluno</th><th>Curso · turma</th><th>Data</th><th>Onde</th><th class="text-end">Desconto</th><th>Situação</th></tr></thead>
            <tbody>
            <?php foreach ($utilizacoes as $u): [, $rotuloUso, $classeUso, $detalheUso] = situacaoUsoCupom($u); ?>
                <tr class="cursor-pointer" onclick="window.location='/matriculas/visualizar.php?id=<?= (int) $u['id'] ?>'">
                    <td><?= e($u['aluno_nome']) ?></td>
                    <td><?= e($u['curso_nome'] . ' · ' . $u['turma_nome']) ?></td>
                    <td class="text-nowrap"><?= formatDate($u['data_matricula']) ?></td>
                    <td><?= $u['origem'] === 'portal' ? 'Portal' : 'Secretaria' ?></td>
                    <td class="text-end text-nowrap"><?= $cupom['tipo'] === 'matricula_zero' ? 'Matrícula grátis (' . formatMoney($u['cupom_desconto']) . ')' : formatMoney($u['cupom_desconto']) ?></td>
                    <td><span class="badge <?= $classeUso ?>"><?= e($rotuloUso) ?></span><?php if ($detalheUso): ?><div class="small text-muted"><?= e($detalheUso) ?></div><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<script>
// Valor em R$ ou %; "matrícula zero" não tem valor (zera a taxa).
function atualizarTipoCupom() {
    const tipo = document.getElementById('cupom-tipo').value;
    document.getElementById('campo-valor').style.display = tipo === 'matricula_zero' ? 'none' : '';
    document.getElementById('prefixo-valor').textContent = tipo === 'percentual' ? '%' : 'R$';
    document.getElementById('explica-tipo').textContent = {
        valor: 'Sai do valor do curso (dividido nas mensalidades). A taxa de matrícula não muda.',
        percentual: 'Percentual sobre o valor do curso (dividido nas mensalidades). A taxa de matrícula não muda.',
        matricula_zero: 'A taxa de matrícula fica zerada. As mensalidades não mudam.',
    }[tipo];
}
atualizarTipoCupom();
</script>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
