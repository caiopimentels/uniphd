<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
exigirPermissaoAcao('lancar_receber', 'Seu perfil não pode lançar cobranças.');

$pdo = getDB();
$alunos = $pdo->query('SELECT id, nome, cpf FROM alunos WHERE ativo = 1 ORDER BY nome')->fetchAll();
$alunosJson = array_map(fn ($a) => ['id' => (int) $a['id'], 'nome' => $a['nome'], 'cpf' => $a['cpf']], $alunos);
// Vindo do cadastro do aluno (?aluno_id=), o aluno já chega escolhido.
$alunoEscolhido = (int) ($_POST['aluno_id'] ?? $_GET['aluno_id'] ?? 0) ?: '';
$categorias = $pdo->query("SELECT id, nome FROM categorias_financeiras WHERE tipo = 'receita' AND ativo = 1 ORDER BY nome")->fetchAll();
$formasPagamento = $pdo->query("SELECT id, nome, instrumento = 'manual' AS na_secretaria FROM formas_pagamento WHERE ativo = 1 ORDER BY instrumento = 'manual', nome")->fetchAll();

$erros = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $descricao = input('descricao');
    $alunoId = (int) ($_POST['aluno_id'] ?? 0) ?: null;
    $categoriaId = (int) ($_POST['categoria_id'] ?? 0) ?: null;
    $valor = decimalInput('valor', 0);
    $dataEmissao = input('data_emissao') ?: date('Y-m-d');
    $dataVencimento = input('data_vencimento') ?: date('Y-m-d');
    $formaPagamentoId = (int) ($_POST['forma_pagamento_id'] ?? 0) ?: null;

    if ($descricao === '') {
        $erros[] = 'A descrição é obrigatória.';
    }
    if ($valor <= 0) {
        $erros[] = 'Informe um valor maior que zero.';
    }

    if (!$erros) {
        $stmt = $pdo->prepare(
            'INSERT INTO contas_receber (aluno_id, categoria_id, descricao, valor, data_emissao, data_vencimento, forma_pagamento_id, status, usuario_id)
             VALUES (?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([$alunoId, $categoriaId, $descricao, $valor, $dataEmissao, $dataVencimento, $formaPagamentoId, 'pendente', usuarioAtualId()]);
        registrarAtividade('contas_receber', 'criacao', 'Conta a receber criada: ' . $descricao . ' - ' . formatMoney($valor));
        flash('success', 'Lançamento criado com sucesso.');
        redirect('/financeiro/contas_receber/listar.php');
    }
}

$pageTitle = 'Novo lançamento a receber';
$pageBreadcrumb = 'Financeiro · Contas a receber';
$pageAction = '<a href="/financeiro/contas_receber/listar.php" class="btn btn-outline-secondary">Cancelar</a>'
    . '<button type="submit" form="form-principal" class="btn btn-brand"><i class="bi bi-check-lg"></i> Salvar</button>';
$activeMenu = 'contas-receber';
require __DIR__ . '/../../../includes/layout_header.php';
?>

<?php if ($erros): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($erros as $erro): ?><li><?= e($erro) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="secao" style="max-width: 640px;">
    <div class="secao-titulo"><i class="bi bi-arrow-up-circle"></i> Dados do lançamento</div>
        <form method="post" id="form-principal">
            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label">Descrição *</label>
                    <input type="text" name="descricao" class="form-control" required value="<?= e($_POST['descricao'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Aluno (opcional)</label>
                    <div class="typeahead">
                        <input type="text" class="form-control" id="aluno-busca" autocomplete="off" placeholder="Pesquise pelo nome ou CPF...">
                        <div class="typeahead-lista d-none" id="aluno-lista"></div>
                    </div>
                    <input type="hidden" name="aluno_id" id="aluno_id" value="<?= e((string) $alunoEscolhido) ?>">
                    <div class="form-text">Com aluno, o lançamento aparece no portal dele, em Pagamentos.</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Categoria</label>
                    <select name="categoria_id" class="form-select">
                        <option value="">-</option>
                        <?php foreach ($categorias as $c): ?>
                            <option value="<?= (int) $c['id'] ?>"><?= e($c['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label">Forma de pagamento</label>
                    <select name="forma_pagamento_id" class="form-select">
                        <option value="">-</option>
                        <?php foreach ($formasPagamento as $fp): ?>
                            <option value="<?= (int) $fp['id'] ?>" <?= (int) ($_POST['forma_pagamento_id'] ?? 0) === (int) $fp['id'] ? 'selected' : '' ?>><?= e($fp['nome']) ?><?= $fp['na_secretaria'] ? ' (na secretaria)' : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Pix gera só QR Code; Boleto só boleto; Boleto + Pix os dois; cartão/dinheiro: pago na secretaria.</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Valor *</label>
                    <input type="text" name="valor" class="form-control" required value="<?= e($_POST['valor'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Emissão</label>
                    <input type="date" name="data_emissao" class="form-control" value="<?= date('Y-m-d') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Vencimento *</label>
                    <input type="date" name="data_vencimento" class="form-control" required value="<?= date('Y-m-d') ?>">
                </div>
            </div>
        </form>
</div>

<script src="/assets/js/busca.js"></script>
<script>
const alunos = <?= json_encode($alunosJson, JSON_UNESCAPED_UNICODE) ?>;
montarBusca({
    campo: 'aluno-busca', lista: 'aluno-lista', oculto: 'aluno_id',
    itens: () => alunos,
    combina: (a, termo, digitos) => normalizar(a.nome).includes(termo) || (digitos && String(a.cpf || '').replace(/\D/g, '').includes(digitos)),
    titulo: (a) => a.nome,
    detalhe: (a) => a.cpf ? 'CPF ' + a.cpf : 'Sem CPF cadastrado',
    vazio: 'Nenhum aluno encontrado.',
});
// Texto digitado sem escolher da lista não vale: avisa antes de salvar.
document.getElementById('form-principal').addEventListener('submit', (ev) => {
    if (document.getElementById('aluno-busca').value.trim() && !document.getElementById('aluno_id').value) {
        ev.preventDefault();
        alert('Escolha o aluno na lista (ou apague o nome para lançar sem aluno).');
    }
});
</script>
<?php require __DIR__ . '/../../../includes/layout_footer.php'; ?>
