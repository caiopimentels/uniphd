<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();
exigirPermissaoAcao('editar_aluno', 'Seu perfil não pode criar o acesso ao portal.');

// A secretaria cria o acesso do aluno ao portal com uma senha provisória
// (gerada ou digitada) e passa para ele; no primeiro acesso o portal pede
// para ele trocar. Serve também para "resetar" a senha de quem esqueceu.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/alunos/listar.php');
}

$pdo = getDB();
$id = (int) ($_POST['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM alunos WHERE id = ?');
$stmt->execute([$id]);
$aluno = $stmt->fetch();
$voltar = '/alunos/form.php?id=' . $id;
if (!$aluno) {
    flash('danger', 'Aluno não encontrado.');
    redirect('/alunos/listar.php');
}
if (!validarCpf((string) $aluno['cpf'])) {
    flash('danger', 'Para entrar no portal o aluno usa o CPF: cadastre um CPF válido antes de criar a senha.');
    redirect($voltar);
}
if (!$aluno['ativo']) {
    flash('danger', 'O aluno está inativo. Ative o cadastro antes de criar o acesso.');
    redirect($voltar);
}

$senha = input('senha') !== '' ? (string) $_POST['senha'] : gerarSenhaProvisoria();
if (mb_strlen($senha) < 6) {
    flash('danger', 'A senha provisória precisa ter pelo menos 6 caracteres.');
    redirect($voltar);
}

$pdo->prepare('UPDATE alunos SET senha_hash = ?, senha_provisoria = 1 WHERE id = ?')->execute([password_hash($senha, PASSWORD_DEFAULT), $id]);
// Links de convite/troca ainda abertos deixam de valer (a senha agora é esta).
$pdo->prepare('UPDATE aluno_tokens_senha SET usado_em = NOW() WHERE aluno_id = ? AND usado_em IS NULL')->execute([$id]);
registrarAtividade('alunos', 'edicao', 'Senha provisória do portal criada para ' . $aluno['nome']);
registrarHistoricoAluno($pdo, $id, 'senha', 'Senha provisória do portal criada');

$cpf = apenasDigitos((string) $aluno['cpf']);
$_SESSION['senha_provisoria_criada'] = [
    'aluno_id' => $id,
    'senha' => $senha,
    'cpf' => substr($cpf, 0, 3) . '.' . substr($cpf, 3, 3) . '.' . substr($cpf, 6, 3) . '-' . substr($cpf, 9),
];
redirect($voltar);
