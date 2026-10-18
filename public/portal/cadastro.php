<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

$voltar = destinoSeguroPortal($_GET['voltar'] ?? $_POST['voltar'] ?? null);
if (alunoLogado()) {
    redirect($voltar);
}

$pdo = getDB();
$config = buscarConfiguracoes($pdo);
if (!$config['portal_cadastro_aberto']) {
    flash('warning', 'O cadastro pelo portal está fechado no momento. Fale com a secretaria.');
    redirect('/portal/entrar.php');
}

$d = [];
$erros = [];
$aviso = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = lerDadosAlunoPortal();
    $senha = (string) ($_POST['senha'] ?? '');
    $erros = validarDadosAlunoPortal($d, true);
    [$certificacao, $errosCertificacao] = lerDadosCertificacaoAluno($pdo, true, true);
    $d += $certificacao;
    $erros = array_merge($erros, $errosCertificacao);
    if ($erroSenha = validarSenhaNovaAluno($senha, (string) ($_POST['senha_confirmacao'] ?? ''))) {
        $erros[] = $erroSenha;
    }
    if (empty($_POST['aceite'])) {
        $erros[] = 'Confirme que os dados são verdadeiros.';
    }
    if (empty($_POST['termo_certificacao'])) {
        $erros[] = 'É preciso concordar com o uso das informações para fins de certificação.';
    }
    if (!$erros && limiteDeTentativasExcedido($pdo, 'portal_cadastro', enderecoIpAtual(), 10, 10, 60)) {
        $erros[] = 'Muitos cadastros a partir desta conexão. Tente mais tarde.';
    }

    if (!$erros) {
        registrarTentativaAcesso($pdo, 'portal_cadastro', enderecoIpAtual());
        $existente = buscarAlunoPorCpf($pdo, $d['cpf']);
        if ($existente) {
            // Já é aluno (cadastrado pela secretaria ou antes): não cria outro
            // registro nem troca dados — manda o link de senha para o e-mail
            // que já está no cadastro, que é quem pode provar ser o dono.
            if ($existente['senha_hash']) {
                $aviso = 'Este CPF já tem acesso ao portal. Entre com sua senha ou use "esqueci a senha".';
            } elseif ($existente['email']) {
                enviarLinkSenhaAluno($pdo, $existente, 'convite');
                $aviso = 'Você já é nosso aluno! Enviamos um link para criar sua senha no e-mail cadastrado na secretaria ('
                    . preg_replace('/(?<=^.).*(?=@)/u', '***', $existente['email']) . ').';
            } else {
                $aviso = 'Você já é nosso aluno, mas não há e-mail no seu cadastro. Fale com a secretaria para liberar seu acesso.';
            }
        } else {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                'INSERT INTO alunos (nome, cpf, data_nascimento, telefone, email, nome_mae, nome_pai, possui_graduacao, graduacao_id, termo_certificacao_em, senha_hash, ativo, observacao)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, 1, ?)'
            );
            $stmt->execute([$d['nome'], $d['cpf'], $d['data_nascimento'] ?: null, $d['telefone'], $d['email'], $d['nome_mae'], $d['nome_pai'] ?: null,
                $d['possui_graduacao'], $d['graduacao_id'], password_hash($senha, PASSWORD_DEFAULT), 'Cadastro feito pelo portal do aluno.']);
            $alunoId = (int) $pdo->lastInsertId();
            atribuirRaAluno($pdo, $alunoId);
            salvarEnderecoPrincipalAluno($pdo, $alunoId, $d);
            $pdo->commit();

            registrarAtividadeUsuario(null, 'Portal: ' . $d['nome'], 'alunos', 'criacao', 'Aluno se cadastrou pelo portal: ' . $d['nome']);
            registrarHistoricoAluno($pdo, $alunoId, 'cadastro', 'Cadastrou-se pelo portal e concordou com o uso das informações para fins de certificação');
            entrarComoAluno($pdo, ['id' => $alunoId, 'nome' => $d['nome']]);
            flash('success', 'Conta criada! Bem-vindo(a), ' . explode(' ', $d['nome'])[0] . '.');
            redirect($voltar);
        }
    }
}

$tituloPagina = 'Criar conta';
require __DIR__ . '/../../includes/portal_header.php';
?>
<div class="portal-acesso largo">
    <h1 class="portal-titulo">Criar conta</h1>
    <p class="portal-sub">Leva um minuto. Com a conta você se inscreve nos cursos e acompanha tudo por aqui.</p>

    <?php if ($aviso): ?>
        <div class="portal-card text-center">
            <i class="bi bi-envelope-check fs-1 text-success"></i>
            <p class="mt-2 mb-3"><?= e($aviso) ?></p>
            <a href="/portal/entrar.php" class="btn btn-brand">Ir para o login</a>
        </div>
    <?php else: ?>
    <form method="post" class="portal-card" id="form-cadastro">
        <input type="hidden" name="termo_certificacao" value="" id="termo-certificacao">
        <input type="hidden" name="voltar" value="<?= e($voltar) ?>">
        <?php if ($erros): ?>
            <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($erros as $erro): ?><li><?= e($erro) ?></li><?php endforeach; ?></ul></div>
        <?php endif; ?>
        <?php $cpfEditavel = true; require __DIR__ . '/../../includes/portal_form_dados.php'; ?>
        <div class="row g-3 mt-1">
            <div class="col-12"><div class="fw-semibold mt-2">Sua senha</div></div>
            <div class="col-md-6">
                <label class="form-label">Senha * <span class="text-muted small">(mínimo 8 caracteres)</span></label>
                <input type="password" name="senha" class="form-control" required minlength="8" autocomplete="new-password">
            </div>
            <div class="col-md-6">
                <label class="form-label">Repita a senha *</label>
                <input type="password" name="senha_confirmacao" class="form-control" required minlength="8" autocomplete="new-password">
            </div>
            <div class="col-12 form-check ms-2">
                <input type="checkbox" name="aceite" value="1" class="form-check-input" id="aceite" required>
                <label class="form-check-label" for="aceite">Declaro que os dados informados são verdadeiros.</label>
            </div>
        </div>
        <button type="submit" class="btn btn-brand btn-lg w-100 mt-4">Criar minha conta</button>
        <p class="text-center small mt-3 mb-0">Já tem conta? <a href="/portal/entrar.php">Entrar</a></p>
    </form>
    <?php endif; ?>
</div>

<div class="modal fade" id="modal-termo" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-shield-check"></i> Uso das suas informações</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p style="text-align: justify;">Declaro estar ciente de que os dados pessoais fornecidos neste cadastro serão utilizados <strong>exclusivamente para fins de identificação, registro e emissão dos certificados </strong>correspondentes aos cursos por mim realizados.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Voltar</button>
                <button type="button" class="btn btn-brand" id="btn-concordo">Concordo e criar minha conta</button>
            </div>
        </div>
    </div>
</div>
<script>
// Antes de criar a conta, o aluno confirma o uso dos dados para certificação.
(function () {
    const form = document.getElementById('form-cadastro');
    if (!form) return;
    form.addEventListener('submit', (ev) => {
        if (document.getElementById('termo-certificacao').value === '1') return;
        ev.preventDefault();
        if (!form.reportValidity()) return;
        bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-termo')).show();
    });
    document.getElementById('btn-concordo').addEventListener('click', () => {
        document.getElementById('termo-certificacao').value = '1';
        form.requestSubmit ? form.requestSubmit() : form.submit();
    });
})();
</script>
<?php require __DIR__ . '/../../includes/portal_footer.php'; ?>
