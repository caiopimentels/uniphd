<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirAluno();

$pdo = getDB();
$voltar = isset($_GET['voltar']) || isset($_POST['voltar']) ? destinoSeguroPortal($_GET['voltar'] ?? $_POST['voltar']) : null;
$stmt = $pdo->prepare('SELECT * FROM alunos WHERE id = ?');
$stmt->execute([alunoAtualId()]);
$aluno = $stmt->fetch();
$endereco = enderecoPrincipalAluno($pdo, (int) $aluno['id']) ?? [];
// O CPF identifica o login: só dá para preencher se ainda não houver um válido.
$cpfEditavel = !validarCpf((string) $aluno['cpf']);

$d = array_merge($aluno, array_intersect_key($endereco, array_flip(['cep', 'logradouro', 'numero', 'complemento', 'bairro', 'cidade', 'uf'])));
$erros = [];
$errosSenha = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && input('acao') === 'senha') {
    $nova = (string) ($_POST['senha'] ?? '');
    if (!password_verify((string) ($_POST['senha_atual'] ?? ''), (string) $aluno['senha_hash'])) {
        $errosSenha[] = 'A senha atual não confere.';
    } elseif ($erro = validarSenhaNovaAluno($nova, (string) ($_POST['senha_confirmacao'] ?? ''))) {
        $errosSenha[] = $erro;
    } else {
        salvarSenhaAluno($pdo, (int) $aluno['id'], $nova);
        flash('success', 'Senha alterada.');
        redirect('/portal/conta.php');
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $novo = lerDadosAlunoPortal();
    if (!$cpfEditavel) {
        $novo['cpf'] = apenasDigitos((string) $aluno['cpf']);
    }
    $erros = validarDadosAlunoPortal($novo, $cpfEditavel);
    if (!$erros && $cpfEditavel && ($outro = buscarAlunoPorCpf($pdo, $novo['cpf'])) && (int) $outro['id'] !== (int) $aluno['id']) {
        $erros[] = 'Este CPF já está em outro cadastro. Fale com a secretaria.';
    }
    if (!$erros) {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('UPDATE alunos SET nome = ?, cpf = ?, data_nascimento = ?, telefone = ?, email = ? WHERE id = ?');
        $stmt->execute([$novo['nome'], $novo['cpf'], $novo['data_nascimento'] ?: null, $novo['telefone'], $novo['email'], $aluno['id']]);
        salvarEnderecoPrincipalAluno($pdo, (int) $aluno['id'], $novo);
        $pdo->commit();
        $_SESSION['portal_aluno_nome'] = $novo['nome'];
        registrarAtividadeUsuario(null, 'Portal: ' . $novo['nome'], 'alunos', 'edicao', 'Aluno atualizou os próprios dados pelo portal');
        flash('success', 'Dados atualizados.');
        redirect($voltar ?? '/portal/conta.php');
    }
    $d = $novo + $d;
}

$tituloPagina = 'Meus dados';
require __DIR__ . '/../../includes/portal_header.php';
?>
<div class="portal-acesso largo">
    <h1 class="portal-titulo">Meus dados</h1>
    <p class="portal-sub">Mantenha seu contato e endereço em dia — o endereço vai impresso no boleto.</p>

    <form method="post" class="portal-card">
        <?php if ($voltar): ?><input type="hidden" name="voltar" value="<?= e($voltar) ?>"><?php endif; ?>
        <?php if ($erros): ?>
            <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($erros as $erro): ?><li><?= e($erro) ?></li><?php endforeach; ?></ul></div>
        <?php endif; ?>
        <?php require __DIR__ . '/../../includes/portal_form_dados.php'; ?>
        <button type="submit" class="btn btn-brand mt-4"><?= $voltar ? 'Salvar e continuar' : 'Salvar' ?></button>
    </form>

    <form method="post" class="portal-card">
        <input type="hidden" name="acao" value="senha">
        <div class="fw-semibold mb-2">Trocar senha</div>
        <?php if ($errosSenha): ?><div class="alert alert-danger py-2"><?= e(implode(' ', $errosSenha)) ?></div><?php endif; ?>
        <div class="row g-3">
            <div class="col-md-4"><label class="form-label">Senha atual</label><input type="password" name="senha_atual" class="form-control" required autocomplete="current-password"></div>
            <div class="col-md-4"><label class="form-label">Nova senha</label><input type="password" name="senha" class="form-control" required minlength="8" autocomplete="new-password"></div>
            <div class="col-md-4"><label class="form-label">Repita a nova</label><input type="password" name="senha_confirmacao" class="form-control" required minlength="8" autocomplete="new-password"></div>
        </div>
        <button type="submit" class="btn btn-outline-brand mt-3">Trocar senha</button>
    </form>
</div>
<?php require __DIR__ . '/../../includes/portal_footer.php'; ?>
