<?php

function tentarLogin(PDO $pdo, string $email, string $senha): bool
{
    $stmt = $pdo->prepare(
        'SELECT u.*, p.nome AS perfil_nome, p.administrador, p.desconto_max
         FROM usuarios u JOIN perfis p ON p.id = u.perfil_id
         WHERE u.email = ? AND u.ativo = 1'
    );
    $stmt->execute([$email]);
    $usuario = $stmt->fetch();

    if (!$usuario || !password_verify($senha, $usuario['senha'])) {
        return false;
    }

    $stmt = $pdo->prepare(
        'SELECT perm.chave FROM perfil_permissoes pp JOIN permissoes perm ON perm.id = pp.permissao_id WHERE pp.perfil_id = ?'
    );
    $stmt->execute([$usuario['perfil_id']]);
    $permissoes = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Um token novo a cada login "derruba" qualquer sessão anterior dessa
    // conta: a sessão antiga só descobre isso na próxima ação dela.
    $tokenSessao = bin2hex(random_bytes(32));
    $stmt = $pdo->prepare('UPDATE usuarios SET token_sessao = ? WHERE id = ?');
    $stmt->execute([$tokenSessao, $usuario['id']]);

    session_regenerate_id(true);
    $_SESSION['user_id'] = $usuario['id'];
    $_SESSION['user_nome'] = $usuario['nome'];
    $_SESSION['user_perfil_id'] = $usuario['perfil_id'];
    $_SESSION['user_perfil_nome'] = $usuario['perfil_nome'];
    $_SESSION['user_administrador'] = (bool) $usuario['administrador'];
    $_SESSION['user_desconto_max'] = (float) $usuario['desconto_max'];
    $_SESSION['user_permissoes'] = $permissoes;
    $_SESSION['user_tema'] = $usuario['tema'] ?? 'claro';
    $_SESSION['token_sessao'] = $tokenSessao;

    registrarAtividade('login', 'login', 'Login realizado');

    return true;
}

function fazerLogout(string $descricao = 'Logout realizado'): void
{
    if (usuarioLogado()) {
        registrarAtividadeUsuario(usuarioAtualId(), usuarioAtualNome(), 'login', 'logout', $descricao);
    }

    $_SESSION = [];
    session_destroy();
}

function usuarioLogado(): bool
{
    return isset($_SESSION['user_id']);
}

function usuarioAtualId(): ?int
{
    return $_SESSION['user_id'] ?? null;
}

function usuarioAtualNome(): string
{
    return $_SESSION['user_nome'] ?? '';
}

function usuarioAtualTema(): string
{
    return ($_SESSION['user_tema'] ?? 'claro') === 'escuro' ? 'escuro' : 'claro';
}

function usuarioEhAdmin(): bool
{
    return (bool) ($_SESSION['user_administrador'] ?? false);
}

function usuarioTemPermissao(string $chave): bool
{
    return usuarioEhAdmin() || in_array($chave, $_SESSION['user_permissoes'] ?? [], true);
}

// Contas a pagar (lançar, pagar, excluir) e categorias financeiras.
function usuarioTemAcessoFinanceiro(): bool
{
    return usuarioTemPermissao('acesso_financeiro');
}

// Ver as telas do Financeiro (listas e relatórios). Quem lança contas a
// pagar enxerga o financeiro de qualquer forma.
function usuarioPodeVerFinanceiro(): bool
{
    return usuarioTemPermissao('ver_financeiro') || usuarioTemAcessoFinanceiro();
}

function usuarioPodeBaixarReceber(): bool
{
    return usuarioTemPermissao('baixar_receber');
}

function usuarioPodeLancarReceber(): bool
{
    return usuarioTemPermissao('lancar_receber');
}

// Alterar parcela (valor, vencimento, desconto) e o desconto por pontualidade.
function usuarioPodeEditarFinanceiroAluno(): bool
{
    return usuarioTemPermissao('editar_financeiro_aluno');
}

function usuarioPodeExcluirReceber(): bool
{
    return usuarioTemPermissao('excluir_receber');
}

function usuarioPodePagarConta(): bool
{
    return usuarioTemPermissao('pagar_conta');
}

function usuarioPodeExcluirPagar(): bool
{
    return usuarioTemPermissao('excluir_pagar');
}

function usuarioPodeGerenciarCategorias(): bool
{
    return usuarioTemPermissao('gerenciar_categorias');
}

function usuarioPodeCadastrarAluno(): bool
{
    return usuarioTemPermissao('cadastrar_aluno');
}

function usuarioPodeEditarAluno(): bool
{
    return usuarioTemPermissao('editar_aluno');
}

function usuarioPodeAtivarAluno(): bool
{
    return usuarioTemPermissao('ativar_aluno');
}

function usuarioPodeExcluirAluno(): bool
{
    return usuarioTemPermissao('excluir_aluno');
}

function usuarioPodeCriarCurso(): bool
{
    return usuarioTemPermissao('criar_curso');
}

function usuarioPodeEditarCurso(): bool
{
    return usuarioTemPermissao('editar_curso');
}

function usuarioPodeExcluirCurso(): bool
{
    return usuarioTemPermissao('excluir_curso');
}

function usuarioPodeCriarTurma(): bool
{
    return usuarioTemPermissao('criar_turma');
}

function usuarioPodeEditarTurma(): bool
{
    return usuarioTemPermissao('editar_turma');
}

function usuarioPodeExcluirTurma(): bool
{
    return usuarioTemPermissao('excluir_turma');
}

function usuarioPodeConcluirTurma(): bool
{
    return usuarioTemPermissao('concluir_turma');
}

// Cadastrar professores (a chave é de antes da separação por ação).
function usuarioPodeGerenciarProfessores(): bool
{
    return usuarioTemPermissao('gerenciar_professores');
}

function usuarioPodeEditarProfessor(): bool
{
    return usuarioTemPermissao('editar_professor');
}

function usuarioPodeExcluirProfessor(): bool
{
    return usuarioTemPermissao('excluir_professor');
}

function usuarioPodeCancelarMatricula(): bool
{
    return usuarioTemPermissao('cancelar_matricula');
}

/**
 * Perfis que o usuário logado pode dar a outros usuários (cadastrar/editar).
 * Administrador: todos. Os demais: os liberados no perfil dele (Perfis ›
 * "Usuários que este perfil pode cadastrar"), nunca um perfil de administrador.
 * Retorna [id => nome].
 */
function perfisGerenciaveisUsuario(PDO $pdo): array
{
    if (usuarioEhAdmin()) {
        return $pdo->query('SELECT id, nome FROM perfis ORDER BY nome')->fetchAll(PDO::FETCH_KEY_PAIR);
    }
    $stmt = $pdo->prepare(
        'SELECT p.id, p.nome FROM perfil_perfis_gerenciaveis g JOIN perfis p ON p.id = g.perfil_alvo_id
         WHERE g.perfil_id = ? AND p.administrador = 0 ORDER BY p.nome'
    );
    $stmt->execute([(int) ($_SESSION['user_perfil_id'] ?? 0)]);
    return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}

// Entra no menu de Usuários quem pode fazer alguma coisa lá.
function usuarioPodeAcessarUsuarios(): bool
{
    return usuarioEhAdmin() || usuarioTemPermissao('cadastrar_usuario') || usuarioTemPermissao('editar_usuario')
        || usuarioTemPermissao('desativar_usuario');
}

function usuarioPodeLiberarCertificado(): bool
{
    return usuarioTemPermissao('liberar_certificado');
}

function usuarioPodeVerDRE(): bool
{
    return usuarioTemPermissao('ver_dre');
}

function usuarioPodeVerDashboard(): bool
{
    return usuarioTemPermissao('ver_dashboard');
}

function usuarioPodeVerAlunos(): bool
{
    return usuarioTemPermissao('ver_alunos');
}

function usuarioPodeVerCursos(): bool
{
    return usuarioTemPermissao('ver_cursos');
}

function usuarioPodeGerenciarMatriculas(): bool
{
    return usuarioTemPermissao('gerenciar_matriculas');
}

// Quem cuida do financeiro enxerga a situação dos alunos de qualquer forma;
// a permissão própria existe para a secretaria ver "quem deve" sem ganhar
// acesso a contas a pagar, DRE etc.
function usuarioPodeVerInadimplencia(): bool
{
    return usuarioTemPermissao('ver_inadimplencia') || usuarioPodeVerFinanceiro();
}

function usuarioPodeVerFornecedores(): bool
{
    return usuarioTemPermissao('ver_fornecedores');
}

function usuarioPodeVerFormasPagamento(): bool
{
    return usuarioTemPermissao('ver_formas_pagamento');
}

function usuarioDescontoMax(): float
{
    return usuarioEhAdmin() ? 100.0 : (float) ($_SESSION['user_desconto_max'] ?? 0);
}

// Tempo (segundos) de inatividade configurado até deslogar automaticamente.
function sessaoTimeoutSegundos(): int
{
    $config = buscarConfiguracoes(getDB());
    return max(1, (int) ($config['sessao_timeout_minutos'] ?? 30)) * 60;
}

// Quantos segundos faltam para a sessão atual expirar por inatividade —
// usado pelo timer regressivo exibido no topo do sistema.
function sessaoSegundosRestantes(): int
{
    $timeout = sessaoTimeoutSegundos();
    $ultimaAtividade = $_SESSION['ultima_atividade'] ?? time();
    return max(0, $timeout - (time() - $ultimaAtividade));
}

function verificarTimeoutSessao(): void
{
    $timeout = sessaoTimeoutSegundos();
    $ultimaAtividade = $_SESSION['ultima_atividade'] ?? time();

    if ((time() - $ultimaAtividade) > $timeout) {
        fazerLogout('Sessão expirada por inatividade');
        flash('danger', 'Sua sessão expirou por inatividade. Entre novamente.');
        redirect('/login.php');
    }

    $_SESSION['ultima_atividade'] = time();
}

// Não permite duas sessões abertas ao mesmo tempo para a mesma conta: quando
// alguém loga de novo (outro aparelho/navegador), o token muda no banco e
// esta sessão passa a não bater mais com ele.
function verificarSessaoUnica(): void
{
    $stmt = getDB()->prepare('SELECT token_sessao FROM usuarios WHERE id = ?');
    $stmt->execute([usuarioAtualId()]);
    $tokenAtual = $stmt->fetchColumn();

    if ($tokenAtual === false || $tokenAtual !== ($_SESSION['token_sessao'] ?? null)) {
        fazerLogout('Sessão encerrada por login em outro dispositivo');
        flash('danger', 'Sua conta foi acessada em outro dispositivo. Entre novamente.');
        redirect('/login.php');
    }
}

function exigirLogin(): void
{
    if (!usuarioLogado()) {
        redirect('/login.php');
    }
    verificarSessaoUnica();
    verificarTimeoutSessao();
}

function exigirAdmin(): void
{
    exigirLogin();
    if (!usuarioEhAdmin()) {
        flash('danger', 'Apenas administradores podem acessar esta página.');
        redirect('/sem_acesso.php');
    }
}

function exigirPermissao(string $chave, string $mensagem): void
{
    exigirLogin();
    if (!usuarioTemPermissao($chave)) {
        flash('danger', $mensagem);
        redirect('/sem_acesso.php');
    }
}

function exigirFinanceiro(): void
{
    exigirPermissao('acesso_financeiro', 'Seu perfil não tem acesso ao financeiro.');
}

function exigirVerFinanceiro(): void
{
    exigirLogin();
    if (!usuarioPodeVerFinanceiro()) {
        flash('danger', 'Seu perfil não tem acesso ao financeiro.');
        redirect('/sem_acesso.php');
    }
}

// Respostas de requisições AJAX recebem JSON; as demais, aviso + redirect.
function exigirPermissaoAcao(string $chave, string $mensagem): void
{
    exigirLogin();
    if (usuarioTemPermissao($chave)) {
        return;
    }
    if (ehRequisicaoAjax()) {
        http_response_code(403);
        responderJson(['sucesso' => false, 'mensagem' => $mensagem]);
    }
    flash('danger', $mensagem);
    redirect('/sem_acesso.php');
}

function exigirDRE(): void
{
    exigirPermissao('ver_dre', 'Seu perfil não tem acesso ao DRE.');
}

function exigirDashboard(): void
{
    exigirPermissao('ver_dashboard', 'Seu perfil não tem acesso ao Dashboard.');
}

function exigirAlunos(): void
{
    exigirPermissao('ver_alunos', 'Seu perfil não tem acesso a Alunos.');
}

function exigirCursos(): void
{
    exigirPermissao('ver_cursos', 'Seu perfil não tem acesso a Cursos e turmas.');
}

function exigirMatriculas(): void
{
    exigirPermissao('gerenciar_matriculas', 'Seu perfil não pode gerenciar matrículas.');
}

function exigirInadimplencia(): void
{
    exigirLogin();
    if (!usuarioPodeVerInadimplencia()) {
        flash('danger', 'Seu perfil não tem acesso à situação financeira dos alunos.');
        redirect('/sem_acesso.php');
    }
}

function exigirFornecedores(): void
{
    exigirPermissao('ver_fornecedores', 'Seu perfil não tem acesso a Fornecedores.');
}

function exigirFormasPagamento(): void
{
    exigirPermissao('ver_formas_pagamento', 'Seu perfil não tem acesso a Formas de pagamento.');
}

function usuarioPodeGerenciarPortal(): bool
{
    return usuarioTemPermissao('gerenciar_portal');
}

function exigirPortal(): void
{
    exigirPermissao('gerenciar_portal', 'Seu perfil não pode editar o portal do aluno.');
}
