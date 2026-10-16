<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
exigirFinanceiro();

$pdo = getDB();
$fornecedores = $pdo->query('SELECT id, nome, documento FROM fornecedores WHERE ativo = 1 ORDER BY nome')->fetchAll();
$fornecedoresJson = array_map(fn ($f) => ['id' => (int) $f['id'], 'nome' => $f['nome'], 'documento' => $f['documento']], $fornecedores);
$categorias = $pdo->query("SELECT id, nome FROM categorias_financeiras WHERE tipo = 'despesa' AND ativo = 1 ORDER BY nome")->fetchAll();

// Despesa de turma (professor, palestrante, material...): entra no resultado
// da turma. Vindo da tela da turma (?turma_id=), já chega escolhida e volta para lá.
$turmaEscolhida = (int) ($_POST['turma_id'] ?? $_GET['turma_id'] ?? 0) ?: null;
$turmasLista = $pdo->query(
    "SELECT t.id, t.nome, c.nome AS curso_nome, s.comportamento
     FROM turmas t JOIN cursos c ON c.id = t.curso_id JOIN situacoes_turma s ON s.id = t.situacao_id
     ORDER BY s.comportamento = 'cancelada', t.data_inicio IS NULL DESC, t.data_inicio DESC"
)->fetchAll();
$turmasJson = array_map(fn ($t) => ['id' => (int) $t['id'], 'nome' => $t['nome'], 'curso_nome' => $t['curso_nome'],
    'situacao' => $t['comportamento'] === 'concluida' ? 'concluída' : ($t['comportamento'] === 'cancelada' ? 'cancelada' : 'em aberto')], $turmasLista);
$voltar = match ($turmaEscolhida ? ($_GET['voltar'] ?? $_POST['voltar'] ?? '') : '') {
    'turma' => '/turmas/visualizar.php?id=' . $turmaEscolhida,
    'resultado' => '/financeiro/turma_resultado.php?id=' . $turmaEscolhida,
    default => '/financeiro/contas_pagar/listar.php',
};

$erros = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $descricao = input('descricao');
    $fornecedorId = (int) ($_POST['fornecedor_id'] ?? 0) ?: null;
    $turmaId = $turmaEscolhida && in_array($turmaEscolhida, array_column($turmasJson, 'id'), true) ? $turmaEscolhida : null;
    $categoriaId = (int) ($_POST['categoria_id'] ?? 0) ?: null;
    $valor = decimalInput('valor', 0);
    $dataEmissao = input('data_emissao') ?: date('Y-m-d');
    $dataVencimento = input('data_vencimento') ?: date('Y-m-d');
    $repeticoes = max(1, min(60, (int) ($_POST['repeticoes'] ?? 1)));

    if ($descricao === '') {
        $erros[] = 'A descrição é obrigatória.';
    }
    if ($valor <= 0) {
        $erros[] = 'Informe um valor maior que zero.';
    }

    if (!$erros) {
        $stmt = $pdo->prepare(
            'INSERT INTO contas_pagar (fornecedor_id, turma_id, categoria_id, descricao, valor, data_emissao, data_vencimento, status, usuario_id)
             VALUES (?,?,?,?,?,?,?,?,?)'
        );

        // Despesa fixa (aluguel, salário...) lançada de uma vez: cada mês
        // avança emissão e vencimento juntos, para o DRE (que conta pela
        // emissão) distribuir a despesa mês a mês.
        $pdo->beginTransaction();
        for ($i = 0; $i < $repeticoes; $i++) {
            $descricaoParcela = $repeticoes > 1 ? $descricao . ' (' . ($i + 1) . '/' . $repeticoes . ')' : $descricao;
            $stmt->execute([
                $fornecedorId, $turmaId, $categoriaId, $descricaoParcela, $valor,
                somarMeses($dataEmissao, $i), somarMeses($dataVencimento, $i), 'pendente', usuarioAtualId(),
            ]);
        }
        $pdo->commit();

        registrarAtividade('contas_pagar', 'criacao', 'Conta a pagar criada: ' . $descricao . ' - ' . formatMoney($valor) . ($repeticoes > 1 ? " ({$repeticoes} meses)" : ''));
        flash('success', $repeticoes > 1 ? "{$repeticoes} lançamentos mensais criados." : 'Lançamento criado com sucesso.');
        redirect($voltar);
    }
}

$pageTitle = 'Novo lançamento a pagar';
$pageBreadcrumb = 'Financeiro · Contas a pagar';
$pageAction = '<a href="' . e($voltar) . '" class="btn btn-outline-secondary">Cancelar</a>'
    . '<button type="submit" form="form-principal" class="btn btn-brand"><i class="bi bi-check-lg"></i> Salvar</button>';
$activeMenu = 'contas-pagar';
require __DIR__ . '/../../../includes/layout_header.php';
?>

<?php if ($erros): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($erros as $erro): ?><li><?= e($erro) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="secao" style="max-width: 640px;">
    <div class="secao-titulo"><i class="bi bi-arrow-down-circle"></i> Dados do lançamento</div>
        <form method="post" id="form-principal">
            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label">Descrição *</label>
                    <input type="text" name="descricao" class="form-control" required value="<?= e($_POST['descricao'] ?? '') ?>">
                </div>
                <div class="col-12">
                    <label class="form-label">Turma (opcional)</label>
                    <div class="typeahead">
                        <input type="text" class="form-control" id="turma-busca" autocomplete="off" placeholder="Pesquise pelo curso ou nome da turma...">
                        <div class="typeahead-lista d-none" id="turma-lista"></div>
                    </div>
                    <input type="hidden" name="turma_id" id="turma_id" value="<?= (int) $turmaEscolhida ?: '' ?>">
                    <input type="hidden" name="voltar" value="<?= e($_GET['voltar'] ?? $_POST['voltar'] ?? '') ?>">
                    <div class="form-text">Ligada a uma turma, a despesa entra no resultado dela (Relatórios › Resultado por turma).</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Fornecedor (opcional)</label>
                    <div class="d-flex gap-2 align-items-start">
                        <div class="typeahead flex-grow-1">
                            <input type="text" class="form-control" id="fornecedor-busca" autocomplete="off" placeholder="Pesquise o fornecedor...">
                            <div class="typeahead-lista d-none" id="fornecedor-lista"></div>
                        </div>
                        <button type="button" class="btn btn-outline-secondary" onclick="abrirNovoFornecedor()" title="Cadastrar fornecedor novo"><i class="bi bi-plus-lg"></i></button>
                    </div>
                    <input type="hidden" name="fornecedor_id" id="fornecedor_id" value="<?= (int) ($_POST['fornecedor_id'] ?? 0) ?: '' ?>">
                    <div class="form-text">Quem recebe: professor, palestrante, loja... Não achou? Clique em <i class="bi bi-plus-lg"></i>.</div>
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
                <div class="col-md-4">
                    <label class="form-label">Valor *</label>
                    <input type="text" name="valor" class="form-control" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Emissão</label>
                    <input type="date" name="data_emissao" class="form-control" value="<?= date('Y-m-d') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Vencimento *</label>
                    <input type="date" name="data_vencimento" class="form-control" required value="<?= date('Y-m-d') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Repetir por (meses)</label>
                    <input type="number" name="repeticoes" class="form-control" min="1" max="60" value="1">
                    <div class="form-text">Para despesas fixas, como aluguel. 1 = lançamento único.</div>
                </div>
            </div>
        </form>
</div>

<div class="modal fade" id="modal-novo-fornecedor" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-truck"></i> Novo fornecedor</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-danger d-none py-2"></div>
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label">Nome *</label>
                        <input type="text" class="form-control" name="nome" maxlength="150" placeholder="Ex.: Professora Ana Souza, Gráfica Central">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">CPF / CNPJ</label>
                        <input type="text" class="form-control" name="documento" maxlength="20">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Telefone</label>
                        <input type="text" class="form-control" name="telefone" maxlength="20">
                    </div>
                    <div class="col-12">
                        <label class="form-label">E-mail</label>
                        <input type="email" class="form-control" name="email" maxlength="150">
                    </div>
                </div>
                <div class="form-text mt-2">Os demais dados (endereço...) podem ser completados em Financeiro › Fornecedores.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-brand" onclick="salvarNovoFornecedor()"><i class="bi bi-check-lg"></i> Cadastrar</button>
            </div>
        </div>
    </div>
</div>

<script src="/assets/js/busca.js"></script>
<script>
const fornecedores = <?= json_encode($fornecedoresJson, JSON_UNESCAPED_UNICODE) ?>;
const buscaFornecedor = montarBusca({
    campo: 'fornecedor-busca', lista: 'fornecedor-lista', oculto: 'fornecedor_id',
    itens: () => fornecedores,
    combina: (f, termo, digitos) => normalizar(f.nome).includes(termo) || (digitos && String(f.documento || '').replace(/\D/g, '').includes(digitos)),
    titulo: (f) => f.nome,
    detalhe: (f) => f.documento || '',
    vazio: 'Nenhum fornecedor encontrado. Use o botão <i class="bi bi-plus-lg"></i> para cadastrar.',
});
function abrirNovoFornecedor() {
    const modal = document.getElementById('modal-novo-fornecedor');
    modal.querySelector('.alert-danger').classList.add('d-none');
    modal.querySelector('[name="nome"]').value = document.getElementById('fornecedor_id').value ? '' : document.getElementById('fornecedor-busca').value;
    bootstrap.Modal.getOrCreateInstance(modal).show();
}
function salvarNovoFornecedor() {
    const modal = document.getElementById('modal-novo-fornecedor');
    const erro = modal.querySelector('.alert-danger');
    const dados = new URLSearchParams();
    ['nome', 'documento', 'telefone', 'email'].forEach((n) => dados.append(n, modal.querySelector('[name="' + n + '"]').value));
    fetch('/fornecedores/criar_rapido.php', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: dados })
        .then((r) => r.json())
        .then((r) => {
            if (!r.sucesso) { erro.textContent = r.mensagem || 'Não foi possível cadastrar.'; erro.classList.remove('d-none'); return; }
            fornecedores.push(r.fornecedor);
            buscaFornecedor.escolher(r.fornecedor.id);
            bootstrap.Modal.getOrCreateInstance(modal).hide();
        })
        .catch(() => { erro.textContent = 'Não foi possível cadastrar. Tente de novo.'; erro.classList.remove('d-none'); });
}
const turmas = <?= json_encode($turmasJson, JSON_UNESCAPED_UNICODE) ?>;
montarBusca({
    campo: 'turma-busca', lista: 'turma-lista', oculto: 'turma_id',
    itens: () => turmas,
    combina: (t, termo) => normalizar(t.curso_nome + ' ' + t.nome).includes(termo),
    titulo: (t) => t.curso_nome + ' · ' + t.nome,
    detalhe: (t) => 'Turma ' + t.situacao,
    vazio: 'Nenhuma turma encontrada.',
});
document.getElementById('form-principal').addEventListener('submit', (ev) => {
    if (document.getElementById('turma-busca').value.trim() && !document.getElementById('turma_id').value) {
        ev.preventDefault();
        alert('Escolha a turma na lista (ou apague o texto para lançar sem turma).');
    } else if (document.getElementById('fornecedor-busca').value.trim() && !document.getElementById('fornecedor_id').value) {
        ev.preventDefault();
        alert('Escolha o fornecedor na lista, cadastre com o botão + ou apague o texto.');
    }
});
</script>
<?php require __DIR__ . '/../../../includes/layout_footer.php'; ?>
