<?php

// Portal do aluno: acesso (CPF + senha), inscrição online em turmas e a
// confirmação da matrícula pelo pagamento. A sessão é a mesma do sistema,
// mas com chaves próprias (portal_aluno_*): estar logado como secretaria
// não dá acesso ao portal, nem o contrário.

// ---------------------------------------------------------------------
// Acesso
// ---------------------------------------------------------------------

function alunoLogado(): bool
{
    return isset($_SESSION['portal_aluno_id']);
}

function alunoAtualId(): ?int
{
    return $_SESSION['portal_aluno_id'] ?? null;
}

function alunoAtualNome(): string
{
    return $_SESSION['portal_aluno_nome'] ?? '';
}

// Volta para a página pedida depois do login — só caminhos do próprio
// portal, para o parâmetro não virar um redirecionamento para outro site.
function destinoSeguroPortal(?string $destino): string
{
    $destino = (string) $destino;
    return preg_match('#^/portal/[A-Za-z0-9_./?=&%-]*$#', $destino) && !str_contains($destino, '..') ? $destino : '/portal/index.php';
}

function exigirAluno(): void
{
    if (!alunoLogado()) {
        redirect('/portal/entrar.php?voltar=' . urlencode($_SERVER['REQUEST_URI'] ?? '/portal/index.php'));
    }
    // Cadastro desativado pela secretaria derruba o acesso na hora.
    $stmt = getDB()->prepare('SELECT ativo FROM alunos WHERE id = ?');
    $stmt->execute([alunoAtualId()]);
    if (!$stmt->fetchColumn()) {
        sairAluno();
        flash('danger', 'Seu acesso está desativado. Fale com a secretaria.');
        redirect('/portal/entrar.php');
    }
    if (!empty($_SESSION['portal_trocar_senha']) && basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'trocar_senha.php') {
        redirect('/portal/trocar_senha.php');
    }
}

// Grava a senha que o próprio aluno escolheu (encerra a senha provisória).
function salvarSenhaAluno(PDO $pdo, int $alunoId, string $senha): void
{
    $pdo->prepare('UPDATE alunos SET senha_hash = ?, senha_provisoria = 0 WHERE id = ?')->execute([password_hash($senha, PASSWORD_DEFAULT), $alunoId]);
    if (alunoAtualId() === $alunoId) {
        unset($_SESSION['portal_trocar_senha']);
    }
}

// Senha provisória legível (sem 0/O/1/l para não confundir ao ditar).
function gerarSenhaProvisoria(): string
{
    $letras = 'abcdefghjkmnpqrstuvwxyz';
    $numeros = '23456789';
    $senha = '';
    for ($i = 0; $i < 5; $i++) {
        $senha .= $letras[random_int(0, strlen($letras) - 1)];
    }
    for ($i = 0; $i < 3; $i++) {
        $senha .= $numeros[random_int(0, strlen($numeros) - 1)];
    }
    return $senha;
}

function buscarAlunoPorCpf(PDO $pdo, string $cpf): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM alunos WHERE REGEXP_REPLACE(cpf, '[^0-9]', '') = ? LIMIT 1");
    $stmt->execute([apenasDigitos($cpf)]);
    return $stmt->fetch() ?: null;
}

function entrarComoAluno(PDO $pdo, array $aluno): void
{
    session_regenerate_id(true);
    $_SESSION['portal_aluno_id'] = (int) $aluno['id'];
    $_SESSION['portal_aluno_nome'] = $aluno['nome'];
    // Senha criada pela secretaria: até trocar, o portal só abre a tela de troca.
    $stmt = $pdo->prepare('SELECT senha_provisoria FROM alunos WHERE id = ?');
    $stmt->execute([$aluno['id']]);
    $_SESSION['portal_trocar_senha'] = (bool) $stmt->fetchColumn();
    $pdo->prepare('UPDATE alunos SET portal_ultimo_acesso = NOW() WHERE id = ?')->execute([$aluno['id']]);
    registrarHistoricoAluno($pdo, (int) $aluno['id'], 'acesso', 'Entrou no portal do aluno');
}

/**
 * Retorna ['ok' => bool, 'mensagem' => string]. A mensagem de erro é a
 * mesma para CPF inexistente e senha errada (não revela quem tem cadastro).
 */
function tentarLoginAluno(PDO $pdo, string $cpf, string $senha): array
{
    $cpf = apenasDigitos($cpf);
    if (limiteDeTentativasExcedido($pdo, 'portal_login', $cpf, 5, 20, 15)) {
        return ['ok' => false, 'mensagem' => 'Muitas tentativas. Aguarde alguns minutos e tente de novo.'];
    }
    $aluno = buscarAlunoPorCpf($pdo, $cpf);
    if ($aluno && $aluno['ativo'] && !$aluno['senha_hash']) {
        return ['ok' => false, 'mensagem' => 'Você ainda não criou sua senha. Use "Primeiro acesso / esqueci a senha" para receber o link no seu e-mail.'];
    }
    if (!$aluno || !$aluno['ativo'] || !password_verify($senha, (string) $aluno['senha_hash'])) {
        registrarTentativaAcesso($pdo, 'portal_login', $cpf);
        return ['ok' => false, 'mensagem' => 'CPF ou senha inválidos.'];
    }
    limparTentativasAcesso($pdo, 'portal_login', $cpf);
    entrarComoAluno($pdo, $aluno);
    return ['ok' => true, 'mensagem' => ''];
}

function sairAluno(): void
{
    unset($_SESSION['portal_aluno_id'], $_SESSION['portal_aluno_nome'], $_SESSION['portal_trocar_senha']);
    session_regenerate_id(true);
}

function validarSenhaNovaAluno(string $senha, string $confirmacao): ?string
{
    if (mb_strlen($senha) < 8) {
        return 'A senha precisa ter pelo menos 8 caracteres.';
    }
    if ($senha !== $confirmacao) {
        return 'A confirmação não é igual à senha.';
    }
    return null;
}

function urlAbsoluta(string $caminho): string
{
    // Páginas do portal vão no endereço próprio dele, se configurado
    // (Portal do aluno › Menu e rodapé) — links de e-mail, QR Code etc.
    if (str_starts_with($caminho, '/portal') && ($base = urlPortalConfigurada())) {
        return $base . $caminho;
    }
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $caminho;
}

// Endereço próprio do portal (sem barra no fim) ou null.
function urlPortalConfigurada(): ?string
{
    try {
        $url = trim((string) (buscarConfiguracoes(getDB())['portal_url'] ?? ''));
    } catch (Throwable $e) {
        return null;
    }
    return preg_match('#^https?://[^/\s]+#i', $url) ? rtrim($url, '/') : null;
}

/**
 * Manda por e-mail o link para criar (convite) ou redefinir a senha.
 * Convite vale 7 dias; redefinição, 1 hora. Gera um link novo e invalida
 * os anteriores. Retorna o resultado de enviarEmail().
 */
function enviarLinkSenhaAluno(PDO $pdo, array $aluno, string $tipo): array
{
    if (!$aluno['email']) {
        return ['sucesso' => false, 'mensagem' => 'O aluno não tem e-mail cadastrado.'];
    }
    $pdo->prepare('UPDATE aluno_tokens_senha SET usado_em = NOW() WHERE aluno_id = ? AND usado_em IS NULL')->execute([$aluno['id']]);

    $token = bin2hex(random_bytes(32));
    $horas = $tipo === 'convite' ? 24 * 7 : 1;
    $stmt = $pdo->prepare('INSERT INTO aluno_tokens_senha (aluno_id, token_hash, tipo, expira_em) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? HOUR))');
    $stmt->execute([$aluno['id'], hash('sha256', $token), $tipo, $horas]);

    $config = buscarConfiguracoes($pdo);
    $nomePortal = $config['portal_titulo'] ?: ($config['nome_sistema'] ?? 'Portal do aluno');
    $link = urlAbsoluta('/portal/criar_senha.php?token=' . $token);
    $primeiroNome = explode(' ', trim($aluno['nome']))[0];

    $texto = $tipo === 'convite'
        ? '<p>Olá, ' . e($primeiroNome) . '!</p><p>Seu acesso ao <strong>' . e($nomePortal) . '</strong> está pronto. Por lá você acompanha seus cursos, o calendário de aulas, seus pagamentos e certificados.</p><p>Clique no botão para criar sua senha (o link vale 7 dias):</p>'
        : '<p>Olá, ' . e($primeiroNome) . '!</p><p>Recebemos um pedido para criar ou trocar a senha do seu acesso ao <strong>' . e($nomePortal) . '</strong>. O link vale 1 hora.</p>';
    $corpo = $texto
        . '<p><a href="' . e($link) . '" style="display:inline-block;padding:10px 20px;background:#071f4a;color:#fff;text-decoration:none;border-radius:6px;">Criar minha senha</a></p>'
        . '<p>Ou copie e cole no navegador:<br>' . e($link) . '</p>'
        . '<p>Para entrar, use seu CPF e a senha que você criar.' . ($tipo === 'redefinir' ? ' Se você não pediu isso, ignore este e-mail.' : '') . '</p>';

    $envio = enviarEmail($config, $aluno['email'], $aluno['nome'], ($tipo === 'convite' ? 'Seu acesso ao ' : 'Senha do ') . $nomePortal, $corpo);
    if ($envio['sucesso'] ?? false) {
        registrarHistoricoAluno($pdo, (int) $aluno['id'], 'senha', ($tipo === 'convite' ? 'Convite para criar a senha do portal' : 'Link para trocar a senha do portal') . ' enviado para ' . $aluno['email']);
    }
    return $envio;
}

// Token de link de senha ainda válido → linha do token + dados do aluno.
function buscarTokenSenhaAluno(PDO $pdo, string $token): ?array
{
    if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT t.id AS token_id, t.tipo, a.* FROM aluno_tokens_senha t JOIN alunos a ON a.id = t.aluno_id
         WHERE t.token_hash = ? AND t.usado_em IS NULL AND t.expira_em > NOW() AND a.ativo = 1'
    );
    $stmt->execute([hash('sha256', $token)]);
    return $stmt->fetch() ?: null;
}

// Endereço que serve para o boleto (o banco exige todos estes campos).
function enderecoCompletoAluno(?array $endereco): bool
{
    return $endereco
        && trim((string) $endereco['logradouro']) !== ''
        && trim((string) $endereco['bairro']) !== ''
        && trim((string) $endereco['cidade']) !== ''
        && preg_match('/^[A-Z]{2}$/', strtoupper((string) $endereco['uf']))
        && strlen(apenasDigitos((string) $endereco['cep'])) === 8;
}

function enderecoPrincipalAluno(PDO $pdo, int $alunoId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM aluno_enderecos WHERE aluno_id = ? ORDER BY principal DESC, id LIMIT 1');
    $stmt->execute([$alunoId]);
    return $stmt->fetch() ?: null;
}

// ---------------------------------------------------------------------
// Turmas no portal e inscrição
// ---------------------------------------------------------------------

function modosVagaPortal(): array
{
    return [
        'reserva' => 'Reserva a vaga até o prazo de pagamento',
        'apos_pagamento' => 'Só garante a vaga depois de pagar',
        'inscricao' => 'Garante a vaga na inscrição (paga depois)',
    ];
}

// Turmas divulgadas e com inscrição aberta hoje.
function turmasAbertasPortal(PDO $pdo, ?int $turmaId = null): array
{
    $sql = "SELECT t.*, c.nome AS curso_nome, c.descricao AS curso_descricao, c.carga_horaria, p.nome AS professor_nome,
                   ((SELECT COUNT(*) FROM matriculas m WHERE m.turma_id = t.id AND " . SQL_MATRICULA_OCUPA_VAGA . ") + " . SQL_RESERVAS_TURMA . ") AS ocupadas
            FROM turmas t
            JOIN cursos c ON c.id = t.curso_id
            JOIN situacoes_turma s ON s.id = t.situacao_id
            LEFT JOIN professores p ON p.id = t.professor_id
            WHERE t.portal_publicar = 1 AND c.ativo = 1
              AND s.comportamento IN ('aberta', 'em_andamento')
              AND (t.portal_inscricoes_ate IS NULL OR t.portal_inscricoes_ate >= CURDATE())";
    $parametros = [];
    if ($turmaId) {
        $sql .= ' AND t.id = ?';
        $parametros[] = $turmaId;
    }
    $stmt = $pdo->prepare($sql . ' ORDER BY t.data_inicio IS NULL, t.data_inicio, c.nome');
    $stmt->execute($parametros);
    $turmas = $stmt->fetchAll();
    foreach ($turmas as &$turma) {
        $turma['vagas_site'] = vagasDisponiveisPortal($turma, (int) $turma['ocupadas']);
    }
    unset($turma);
    return $turmas;
}

// Quantas vagas o site oferece agora: as que sobram depois das matrículas e
// das reservas da secretaria ($ocupadas já soma as duas).
function vagasDisponiveisPortal(array $turma, int $ocupadas): int
{
    return max(0, (int) $turma['vagas'] - $ocupadas);
}

// "3 vagas restantes" / "Última vaga restante!" / "Vagas esgotadas"
function textoVagasRestantes(int $restam): string
{
    return match (true) {
        $restam <= 0 => 'Vagas esgotadas',
        $restam === 1 => 'Última vaga restante!',
        default => "{$restam} vagas restantes",
    };
}

function numParcelasPortal(array $turma): int
{
    return max(1, (int) ($turma['portal_num_parcelas'] ?: parcelasPadraoTurma($turma)));
}

// Valor do curso (sem a matrícula); turma antiga sem ele: mensalidade × parcelas.
function valorTotalTurma(array $turma): float
{
    return isset($turma['valor_total']) && $turma['valor_total'] !== null
        ? (float) $turma['valor_total']
        : (float) $turma['valor_mensalidade'] * numParcelasPortal($turma);
}

/**
 * Primeira mensalidade: no dia de vencimento da turma, no mês de início
 * das aulas — ou no primeiro mês seguinte em que essa data ainda não
 * passou.
 */
function primeiroVencimentoPortal(array $turma, string $hoje): string
{
    $dia = min(28, max(1, (int) $turma['portal_dia_vencimento']));
    // Curso em dias (fim de semana, 7 dias): uma cobrança no início.
    if (($turma['duracao_unidade'] ?? 'mes') === 'dia') {
        return max($turma['data_inicio'] ?: $hoje, $hoje);
    }
    // Turma sem data definida: conta a partir de hoje.
    $data = date('Y-m-', strtotime($turma['data_inicio'] ?: $hoje)) . str_pad((string) $dia, 2, '0', STR_PAD_LEFT);
    while ($data < $hoje) {
        $data = somarMeses($data, 1);
    }
    return $data;
}

/**
 * Inscreve o aluno logado numa turma do portal: cria a matrícula (pendente
 * até pagar, conforme o modo de vaga da turma), gera taxa + mensalidades
 * e marca qual parcela confirma a matrícula. Inscrição cancelada antes
 * (desistência, prazo vencido) é reaproveitada — a tabela só admite uma
 * matrícula por aluno na turma.
 * Retorna ['ok' => bool, 'mensagem' => string, 'matricula_id' => ?int].
 */
function inscreverAlunoPortal(PDO $pdo, int $alunoId, int $turmaId): array
{
    $falha = fn (string $m) => ['ok' => false, 'mensagem' => $m, 'matricula_id' => null];

    $stmt = $pdo->prepare('SELECT * FROM alunos WHERE id = ?');
    $stmt->execute([$alunoId]);
    $aluno = $stmt->fetch();
    if (!$aluno || !$aluno['ativo']) {
        return $falha('Seu cadastro está inativo. Fale com a secretaria.');
    }
    // Com bloqueio de inadimplente ligado (bloquear ou só com autorização),
    // quem tem parcela vencida não se inscreve sozinho pelo site.
    if (regraMatriculaInadimplente($pdo) !== 'permitir' && valorAtrasoAluno($pdo, $alunoId) > 0) {
        return $falha('Encontramos pendências financeiras no seu cadastro, por isso a inscrição não pode ser feita pelo site. Entre em contato com a escola para regularizar.');
    }

    $pdo->beginTransaction();
    try {
        // Trava a turma: duas inscrições na última vaga não passam juntas.
        $stmt = $pdo->prepare('SELECT * FROM turmas WHERE id = ? FOR UPDATE');
        $stmt->execute([$turmaId]);
        $stmt->fetch();
        $turma = turmasAbertasPortal($pdo, $turmaId)[0] ?? null;
        if (!$turma) {
            $pdo->rollBack();
            return $falha('Esta turma não está mais com inscrições abertas.');
        }

        $stmt = $pdo->prepare('SELECT * FROM matriculas WHERE turma_id = ? AND aluno_id = ?');
        $stmt->execute([$turmaId, $alunoId]);
        $existente = $stmt->fetch();
        if ($existente && $existente['status'] !== 'cancelada') {
            $pdo->rollBack();
            return $existente['status'] === 'pendente'
                ? ['ok' => true, 'mensagem' => 'Você já está inscrito nesta turma — falta só o pagamento.', 'matricula_id' => (int) $existente['id']]
                : $falha('Você já tem matrícula nesta turma (' . mb_strtolower(matriculaStatusLabel($existente['status'])) . ').');
        }

        // Se a secretaria reservou vaga para este aluno, a inscrição usa ela.
        $stmt = $pdo->prepare('SELECT id FROM turma_reservas WHERE turma_id = ? AND aluno_id = ? ORDER BY id LIMIT 1');
        $stmt->execute([$turmaId, $alunoId]);
        $reservaId = (int) $stmt->fetchColumn();

        if (vagasDisponiveisPortal($turma, vagasOcupadasTurma($pdo, $turmaId, 0, $reservaId)) <= 0) {
            $pdo->rollBack();
            return $falha('As vagas desta turma acabaram.');
        }

        $hoje = date('Y-m-d');
        $modo = $turma['portal_modo_vaga'];
        $pendente = $modo !== 'inscricao';
        $prazo = $pendente ? date('Y-m-d 23:59:59', strtotime('+' . max(1, (int) $turma['portal_prazo_pagamento_dias']) . ' days')) : null;

        $numParcelas = numParcelasPortal($turma);
        $valorTotal = valorTotalTurma($turma);
        $valores = [
            'data_matricula' => $hoje,
            'status' => $pendente ? 'pendente' : 'ativa',
            'pagamento_ate' => $prazo,
            'segura_vaga' => $modo === 'reserva' ? 1 : 0,
            'valor_mensalidade' => mensalidadeDoTotal($valorTotal, $numParcelas),
            'valor_total' => $valorTotal,
            'num_parcelas' => $numParcelas,
            'primeiro_vencimento' => primeiroVencimentoPortal($turma, $hoje),
            'taxa_matricula' => $turma['portal_taxa_matricula'],
            'forma_pagamento_id' => $turma['portal_forma_pagamento_id'],
            'forma_mensalidade_id' => $turma['forma_mensalidade_id'] ?: $turma['portal_forma_pagamento_id'],
        ];

        if ($existente) {
            // Reinscrição: as cobranças da tentativa anterior já estão canceladas.
            $stmt = $pdo->prepare(
                "UPDATE matriculas SET data_matricula = ?, status = ?, origem = 'portal', pagamento_ate = ?, segura_vaga = ?, lista_espera = 0,
                        valor_mensalidade = ?, valor_total = ?, desconto_percentual = 0, desconto_pontualidade_tipo = 'nenhum', desconto_pontualidade_valor = 0,
                        desconto_pontualidade_dias = 0, num_parcelas = ?, primeiro_vencimento = ?, taxa_matricula = ?, forma_pagamento_id = ?, forma_mensalidade_id = ?,
                        data_cancelamento = NULL, conta_receber_confirmacao_id = NULL, usuario_id = NULL
                 WHERE id = ?"
            );
            $stmt->execute([...array_values($valores), $existente['id']]);
            $matriculaId = (int) $existente['id'];
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO matriculas (turma_id, aluno_id, origem, data_matricula, status, pagamento_ate, segura_vaga, valor_mensalidade,
                                         valor_total, num_parcelas, primeiro_vencimento, taxa_matricula, forma_pagamento_id, forma_mensalidade_id)
                 VALUES (?, ?, 'portal', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([$turmaId, $alunoId, ...array_values($valores)]);
            $matriculaId = (int) $pdo->lastInsertId();
        }

        // No modo "só após pagar" a inscrição não ocupa vaga: a reserva
        // continua até o pagamento (confirmarMatriculaPorPagamento).
        if ($reservaId && $modo !== 'apos_pagamento') {
            $pdo->prepare('DELETE FROM turma_reservas WHERE id = ?')->execute([$reservaId]);
        }

        $stmt = $pdo->prepare('SELECT * FROM matriculas WHERE id = ?');
        $stmt->execute([$matriculaId]);
        $matricula = $stmt->fetch();
        gerarCobrancasMatricula($pdo, $matricula, $turma['nome'], null);

        // A parcela que confirma é a primeira: a taxa (vence hoje) ou, sem
        // taxa, a primeira mensalidade — que passa a vencer no fim do prazo.
        $stmt = $pdo->prepare("SELECT id FROM contas_receber WHERE matricula_id = ? AND status = 'pendente' ORDER BY data_vencimento, id LIMIT 1");
        $stmt->execute([$matriculaId]);
        $confirmacaoId = (int) $stmt->fetchColumn() ?: null;

        if ($pendente && !$confirmacaoId) {
            // Curso gratuito: não há o que pagar, a matrícula já vale.
            $pdo->prepare("UPDATE matriculas SET status = 'ativa', pagamento_ate = NULL, segura_vaga = 0 WHERE id = ?")->execute([$matriculaId]);
            $pendente = false;
        } elseif ($pendente) {
            $pdo->prepare('UPDATE contas_receber SET data_vencimento = LEAST(data_vencimento, ?) WHERE id = ?')->execute([substr($prazo, 0, 10), $confirmacaoId]);
            $pdo->prepare('UPDATE matriculas SET conta_receber_confirmacao_id = ? WHERE id = ?')->execute([$confirmacaoId, $matriculaId]);
        }

        $pdo->commit();
        if (!$pendente) {
            avisarMinimoTurma($pdo, $turmaId);
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    registrarAtividadeUsuario(null, 'Portal: ' . $aluno['nome'], 'matriculas', 'matricula', 'Inscrição pelo portal na turma ' . $turma['nome'] . ($pendente ? ' (aguardando pagamento)' : ''));
    registrarHistoricoAluno($pdo, $alunoId, 'inscricao', 'Turma ' . $turma['nome'] . ($pendente ? ' — aguardando pagamento' : ''), $matriculaId);
    criarNotificacao($pdo, 'matricula', 'Nova inscrição pelo portal', $aluno['nome'] . ' · ' . $turma['curso_nome'] . ' — ' . $turma['nome'], '/matriculas/visualizar.php?id=' . $matriculaId, $matriculaId);

    return [
        'ok' => true,
        'mensagem' => $pendente
            ? 'Inscrição feita! Pague até ' . formatDateTime($prazo) . ' para confirmar sua matrícula.'
            : 'Matrícula confirmada! Bem-vindo(a) à turma.',
        'matricula_id' => $matriculaId,
        // Parcela que confirma a vaga (a inscrição leva direto para pagá-la).
        'confirmacao_id' => $pendente ? $confirmacaoId : null,
    ];
}

/**
 * Inscrições que passaram do prazo sem pagar: cancela a matrícula e as
 * cobranças em aberto dela, liberando a vaga. Roda "preguiçoso" — nas
 * páginas que mostram vagas — para não depender de agendador no servidor.
 */
function expirarInscricoesPortal(PDO $pdo): int
{
    $stmt = $pdo->query(
        "SELECT m.id, m.turma_id, m.aluno_id, a.nome AS aluno_nome, t.nome AS turma_nome
         FROM matriculas m JOIN alunos a ON a.id = m.aluno_id JOIN turmas t ON t.id = m.turma_id
         WHERE m.status = 'pendente' AND m.lista_espera = 0 AND m.pagamento_ate < NOW()"
    );
    $expiradas = 0;
    foreach ($stmt->fetchAll() as $m) {
        $pdo->beginTransaction();
        $atualiza = $pdo->prepare(
            "UPDATE matriculas SET status = 'cancelada', segura_vaga = 0, data_cancelamento = CURDATE(),
                    observacao = 'Inscrição pelo portal expirada sem pagamento.'
             WHERE id = ? AND status = 'pendente'"
        );
        $atualiza->execute([$m['id']]);
        if ($atualiza->rowCount()) {
            $abertas = $pdo->prepare("SELECT id FROM contas_receber WHERE matricula_id = ? AND status = 'pendente'");
            $abertas->execute([$m['id']]);
            $pdo->prepare("UPDATE contas_receber SET status = 'cancelado' WHERE matricula_id = ? AND status = 'pendente'")->execute([$m['id']]);
            foreach ($abertas->fetchAll(PDO::FETCH_COLUMN) as $parcelaId) {
                registrarHistoricoParcela($pdo, (int) $parcelaId, 'cancelamento', 'Inscrição pelo portal expirou sem pagamento');
            }
            agendarBaixaBoletosCancelados();
            $expiradas++;
        }
        $pdo->commit();
        if ($atualiza->rowCount()) {
            registrarAtividadeUsuario(null, 'Portal', 'matriculas', 'cancelamento', 'Inscrição expirada sem pagamento: ' . $m['aluno_nome'] . ' — ' . $m['turma_nome']);
            registrarHistoricoAluno($pdo, (int) $m['aluno_id'], 'cancelamento', 'Turma ' . $m['turma_nome'] . ' — inscrição do portal expirou sem pagamento', (int) $m['id']);
        }
    }
    return $expiradas;
}

/**
 * Chamada depois de qualquer baixa de parcela (manual ou pelo banco): se
 * ela é a que confirma uma inscrição do portal, a matrícula vira ativa —
 * ou, no modo "só depois de pagar" com a turma já cheia, entra na lista de
 * espera e a secretaria é avisada.
 */
function confirmarMatriculaPorPagamento(PDO $pdo, int $contaReceberId): ?string
{
    $stmt = $pdo->prepare(
        "SELECT m.*, a.nome AS aluno_nome, t.nome AS turma_nome, t.vagas
         FROM matriculas m JOIN alunos a ON a.id = m.aluno_id JOIN turmas t ON t.id = m.turma_id
         WHERE m.conta_receber_confirmacao_id = ? AND m.status = 'pendente'"
    );
    $stmt->execute([$contaReceberId]);
    $matricula = $stmt->fetch();
    if (!$matricula) {
        return null;
    }

    $pdo->beginTransaction();
    $pdo->prepare('SELECT id FROM turmas WHERE id = ? FOR UPDATE')->execute([$matricula['turma_id']]);
    // Mesma conta do site: quem paga depois não toma vaga reservada para
    // outra pessoa — mas usa a que foi reservada para ele.
    $stmt = $pdo->prepare('SELECT id FROM turma_reservas WHERE turma_id = ? AND aluno_id = ? ORDER BY id LIMIT 1');
    $stmt->execute([$matricula['turma_id'], $matricula['aluno_id']]);
    $reservaId = (int) $stmt->fetchColumn();
    $stmt = $pdo->prepare('SELECT * FROM turmas WHERE id = ?');
    $stmt->execute([$matricula['turma_id']]);
    $lotada = vagasDisponiveisPortal($stmt->fetch(), vagasOcupadasTurma($pdo, (int) $matricula['turma_id'], (int) $matricula['id'], $reservaId)) <= 0;
    if ($lotada && !$matricula['segura_vaga']) {
        $pdo->prepare('UPDATE matriculas SET lista_espera = 1 WHERE id = ?')->execute([$matricula['id']]);
        $pdo->commit();
        criarNotificacao($pdo, 'matricula', 'Pagou, mas a turma lotou',
            $matricula['aluno_nome'] . ' pagou a inscrição em ' . $matricula['turma_nome'] . ', que já está cheia. Abra uma vaga ou devolva o valor.',
            '/matriculas/visualizar.php?id=' . $matricula['id'], (int) $matricula['id']);
        return 'lista_espera';
    }
    $pdo->prepare("UPDATE matriculas SET status = 'ativa', segura_vaga = 0, lista_espera = 0, pagamento_ate = NULL WHERE id = ?")->execute([$matricula['id']]);
    if ($reservaId) {
        $pdo->prepare('DELETE FROM turma_reservas WHERE id = ?')->execute([$reservaId]);
    }
    $pdo->commit();
    avisarMinimoTurma($pdo, (int) $matricula['turma_id']);

    registrarAtividadeUsuario(null, 'Portal', 'matriculas', 'matricula', 'Matrícula confirmada pelo pagamento: ' . $matricula['aluno_nome'] . ' — ' . $matricula['turma_nome']);
    registrarHistoricoAluno($pdo, (int) $matricula['aluno_id'], 'confirmacao', 'Turma ' . $matricula['turma_nome'] . ' — confirmada pelo pagamento', (int) $matricula['id']);
    criarNotificacao($pdo, 'matricula', 'Matrícula confirmada', $matricula['aluno_nome'] . ' pagou e entrou na turma ' . $matricula['turma_nome'] . '.',
        '/matriculas/visualizar.php?id=' . $matricula['id'], (int) $matricula['id']);
    return 'confirmada';
}

// ---------------------------------------------------------------------
// Área do aluno
// ---------------------------------------------------------------------

// Parcela do aluno logado (ou null se não for dele).
function parcelaDoAluno(PDO $pdo, int $alunoId, int $contaReceberId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM contas_receber WHERE id = ? AND aluno_id = ?');
    $stmt->execute([$contaReceberId, $alunoId]);
    return $stmt->fetch() ?: null;
}

/**
 * Garante que a parcela tem boleto+Pix registrado no banco, registrando na
 * hora se preciso. Retorna ['ok' => bool, 'mensagem' => string].
 */
function garantirCobrancaParcelaPortal(PDO $pdo, array $parcela): array
{
    $stmt = $pdo->prepare("SELECT status FROM cobrancas_bancarias WHERE conta_receber_id = ? AND status IN ('pendente_envio', 'registrada', 'paga') ORDER BY id DESC LIMIT 1");
    $stmt->execute([$parcela['id']]);
    $statusCobranca = $stmt->fetchColumn();
    if ($statusCobranca === 'pendente_envio') {
        return ['ok' => false, 'mensagem' => 'Estamos gerando seu pagamento junto ao banco. Atualize a página em alguns segundos.'];
    }
    if ($statusCobranca) {
        return ['ok' => true, 'mensagem' => ''];
    }
    if ($parcela['status'] !== 'pendente') {
        return ['ok' => false, 'mensagem' => 'Esta parcela não está em aberto.'];
    }

    // Falhou há pouco: não insiste a cada recarga da página (cada tentativa
    // que chega ao banco consome um nosso número).
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM cobrancas_bancarias WHERE conta_receber_id = ? AND status = 'erro' AND criado_em > DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
    $stmt->execute([$parcela['id']]);
    if ((int) $stmt->fetchColumn() > 0 || !empty($_SESSION['portal_falha_cobranca'][$parcela['id']]) && $_SESSION['portal_falha_cobranca'][$parcela['id']] > time() - 600) {
        return ['ok' => false, 'mensagem' => 'Não conseguimos gerar o pagamento agora. A secretaria já foi avisada — tente novamente em alguns minutos.'];
    }

    // Inscrição pendente: o banco não aceita o pagamento depois do prazo.
    $stmt = $pdo->prepare("SELECT pagamento_ate FROM matriculas WHERE conta_receber_confirmacao_id = ? AND status = 'pendente'");
    $stmt->execute([$parcela['id']]);
    $limite = $stmt->fetchColumn();

    $resultado = registrarCobrancaItau($pdo, (int) $parcela['id'], null, $limite ? substr($limite, 0, 10) : null);
    if (!$resultado['ok']) {
        error_log('[Portal] boleto não registrado para a parcela ' . $parcela['id'] . ': ' . $resultado['mensagem']);
        // Dados do aluno incompletos ele mesmo resolve; o resto é com a secretaria.
        $stmt = $pdo->prepare('SELECT * FROM alunos WHERE id = ?');
        $stmt->execute([$parcela['aluno_id']]);
        $aluno = $stmt->fetch();
        if (!validarCpf((string) $aluno['cpf']) || !enderecoCompletoAluno(enderecoPrincipalAluno($pdo, (int) $aluno['id']))) {
            return ['ok' => false, 'mensagem' => 'Para gerar o boleto, complete seu CPF e endereço em "Meus dados".'];
        }
        $_SESSION['portal_falha_cobranca'][$parcela['id']] = time();
        // Um alerta por parcela por dia basta para a secretaria agir.
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM notificacoes WHERE tipo = 'pagamento' AND referencia_id = ? AND titulo = 'Boleto do portal não foi gerado' AND criado_em >= CURDATE()");
        $stmt->execute([$parcela['id']]);
        if (!(int) $stmt->fetchColumn()) {
            criarNotificacao($pdo, 'pagamento', 'Boleto do portal não foi gerado',
                $aluno['nome'] . ' · ' . $parcela['descricao'] . ': ' . mb_strimwidth($resultado['mensagem'], 0, 150, '…', 'UTF-8'),
                '/contas_bancarias/auditoria.php?parcela=' . (int) $parcela['id'], (int) $parcela['id']);
        }
        return ['ok' => false, 'mensagem' => 'Não conseguimos gerar o pagamento agora. A secretaria já foi avisada — tente novamente mais tarde.'];
    }
    return ['ok' => true, 'mensagem' => ''];
}

// ---------------------------------------------------------------------
// Cadastro / dados do aluno (portal)
// ---------------------------------------------------------------------

function lerDadosAlunoPortal(): array
{
    return [
        'nome' => formatarNomePessoa(input('nome')),
        'cpf' => apenasDigitos(input('cpf')),
        'data_nascimento' => input('data_nascimento'),
        'telefone' => apenasDigitos(input('telefone')),
        'email' => mb_strtolower(trim(input('email'))),
        'cep' => input('cep'),
        // Endereço na mesma regra do nome (Rua da Paz); a UF fica maiúscula.
        'logradouro' => formatarNomePessoa(input('logradouro')),
        'numero' => trim(input('numero')),
        'complemento' => formatarNomePessoa(input('complemento')),
        'bairro' => formatarNomePessoa(input('bairro')),
        'cidade' => formatarNomePessoa(input('cidade')),
        'uf' => strtoupper(trim(input('uf'))),
    ];
}

function validarDadosAlunoPortal(array $d, bool $validarCpf): array
{
    $erros = [];
    if (mb_strlen($d['nome']) < 5 || !str_contains($d['nome'], ' ')) {
        $erros[] = 'Informe seu nome completo.';
    }
    if ($validarCpf && !validarCpf($d['cpf'])) {
        $erros[] = 'CPF inválido.';
    }
    if (!filter_var($d['email'], FILTER_VALIDATE_EMAIL)) {
        $erros[] = 'E-mail inválido.';
    }
    if (strlen(apenasDigitos($d['telefone'])) < 10) {
        $erros[] = 'Informe o celular com DDD.';
    }
    if ($d['data_nascimento'] !== '' && (strtotime($d['data_nascimento']) === false || $d['data_nascimento'] > date('Y-m-d'))) {
        $erros[] = 'Data de nascimento inválida.';
    }
    if (!enderecoCompletoAluno($d)) {
        $erros[] = 'Preencha o endereço completo (CEP, rua, bairro, cidade e UF).';
    }
    return $erros;
}

// Grava o endereço como principal do aluno (atualiza o principal que existir).
function salvarEnderecoPrincipalAluno(PDO $pdo, int $alunoId, array $d): void
{
    $campos = [$d['cep'], $d['logradouro'], $d['numero'] ?: null, $d['complemento'] ?: null, $d['bairro'], $d['cidade'], $d['uf']];
    $endereco = enderecoPrincipalAluno($pdo, $alunoId);
    if ($endereco) {
        $stmt = $pdo->prepare('UPDATE aluno_enderecos SET cep = ?, logradouro = ?, numero = ?, complemento = ?, bairro = ?, cidade = ?, uf = ?, principal = 1 WHERE id = ?');
        $stmt->execute([...$campos, $endereco['id']]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO aluno_enderecos (aluno_id, apelido, cep, logradouro, numero, complemento, bairro, cidade, uf, principal) VALUES (?, 'Principal', ?, ?, ?, ?, ?, ?, ?, 1)");
        $stmt->execute([$alunoId, ...$campos]);
    }
}

// ---------------------------------------------------------------------
// Imagens e links do portal (banners, capas de turma, certificado)
// ---------------------------------------------------------------------

// Link de banner: endereço completo, do próprio site ou WhatsApp/e-mail.
function linkBannerValido(string $link): bool
{
    return $link === '' || preg_match('#^(https?://|/|mailto:|tel:)#i', $link);
}

function pastaUploadsPortal(): string
{
    $pasta = BASE_PATH . '/public/uploads/portal';
    if (!is_dir($pasta)) {
        @mkdir($pasta, 0775, true);
    }
    return $pasta;
}

/**
 * Salva uma imagem enviada (banner, capa de turma, fundo de certificado)
 * em public/uploads/portal. Confere que é imagem de verdade pelo conteúdo
 * — não pela extensão — e limita o tamanho.
 * Retorna ['ok' => bool, 'mensagem' => string, 'url' => ?string].
 */
function salvarImagemPortal(array $arquivo, string $prefixo, int $maxMb = 5): array
{
    if (($arquivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $mensagem = in_array($arquivo['error'] ?? null, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            ? "A imagem passa do limite do servidor. Use uma de até {$maxMb} MB." : 'Não foi possível receber a imagem.';
        return ['ok' => false, 'mensagem' => $mensagem, 'url' => null];
    }
    if ($arquivo['size'] > $maxMb * 1024 * 1024) {
        return ['ok' => false, 'mensagem' => "A imagem tem mais de {$maxMb} MB. Diminua e envie de novo.", 'url' => null];
    }
    $tipos = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($arquivo['tmp_name']);
    if (!isset($tipos[$mime]) || !@getimagesize($arquivo['tmp_name'])) {
        return ['ok' => false, 'mensagem' => 'O arquivo precisa ser uma imagem JPG, PNG, WEBP ou GIF.', 'url' => null];
    }
    $nome = $prefixo . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.' . $tipos[$mime];
    if ($problema = problemaPastaUpload(pastaUploadsPortal())) {
        return ['ok' => false, 'mensagem' => 'Não foi possível gravar a imagem. ' . $problema, 'url' => null];
    }
    if (!move_uploaded_file($arquivo['tmp_name'], pastaUploadsPortal() . '/' . $nome)) {
        return ['ok' => false, 'mensagem' => 'Não foi possível gravar a imagem no servidor.', 'url' => null];
    }
    return ['ok' => true, 'mensagem' => '', 'url' => '/uploads/portal/' . $nome];
}

// Apaga do disco uma imagem que saiu de uso (só dentro de uploads/portal).
function apagarImagemPortal(?string $url): void
{
    if ($url && str_starts_with($url, '/uploads/portal/') && !str_contains($url, '..')) {
        @unlink(BASE_PATH . '/public' . $url);
    }
}

// Cópia física de uma imagem de uploads/portal (bloco duplicado): cada
// bloco fica com o seu arquivo, e excluir um não apaga a imagem do outro.
function copiarImagemPortal(string $url): string
{
    if (!str_starts_with($url, '/uploads/portal/') || str_contains($url, '..')) {
        return $url;
    }
    $novo = '/uploads/portal/copia-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.' . pathinfo($url, PATHINFO_EXTENSION);
    return @copy(BASE_PATH . '/public' . $url, BASE_PATH . '/public' . $novo) ? $novo : $url;
}

// ---------------------------------------------------------------------
// Histórico do aluno (aba Dados gerais › Acesso ao portal)
// ---------------------------------------------------------------------

/**
 * Grava um acontecimento do aluno. Quem fez sai da sessão: usuário do
 * sistema, o próprio aluno no portal ou "Sistema". Nunca derruba a
 * operação principal.
 */
function registrarHistoricoAluno(PDO $pdo, int $alunoId, string $tipo, string $descricao, ?int $matriculaId = null): void
{
    try {
        $usuarioId = function_exists('usuarioLogado') && usuarioLogado() ? usuarioAtualId() : null;
        if ($usuarioId) {
            $quem = usuarioAtualNome();
        } elseif (alunoLogado() && alunoAtualId() === $alunoId) {
            $quem = 'O próprio aluno (portal)';
        } else {
            $quem = 'Sistema';
        }
        $pdo->prepare('INSERT INTO aluno_historico (aluno_id, matricula_id, usuario_id, quem, tipo, descricao, ip) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$alunoId, $matriculaId, $usuarioId, $quem, $tipo, mb_strimwidth($descricao, 0, 255, '…', 'UTF-8'), substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null]);
    } catch (Throwable $e) {
        error_log('[Histórico aluno] ' . $e->getMessage());
    }
}

/**
 * Linha do tempo do aluno, mais recente primeiro: o histórico gravado mais
 * o cadastro e as matrículas antigas (de antes do histórico existir).
 * Cada item: ['quando', 'quem', 'titulo', 'detalhe', 'icone', 'cor'].
 */
function historicoAluno(PDO $pdo, int $alunoId, int $limite = 200): array
{
    $estilos = [
        'cadastro' => ['Cadastro', 'bi-person-plus', 'secondary'],
        'edicao' => ['Cadastro alterado', 'bi-pencil', 'secondary'],
        'situacao' => ['Situação do aluno', 'bi-toggle-on', 'secondary'],
        'acesso' => ['Entrou no portal', 'bi-box-arrow-in-right', 'primary'],
        'senha' => ['Senha do portal', 'bi-key', 'secondary'],
        'inscricao' => ['Inscrição pelo portal', 'bi-globe2', 'primary'],
        'matricula' => ['Matrícula', 'bi-mortarboard', 'success'],
        'confirmacao' => ['Matrícula confirmada', 'bi-check-circle', 'success'],
        'cancelamento' => ['Matrícula cancelada', 'bi-x-circle', 'danger'],
        'conclusao' => ['Matrícula concluída', 'bi-award', 'success'],
        'reativacao' => ['Matrícula reativada', 'bi-arrow-counterclockwise', 'warning'],
        'exclusao' => ['Matrícula excluída', 'bi-trash', 'danger'],
        'documento' => ['Documento', 'bi-file-earmark-check', 'primary'],
    ];
    $item = fn ($quando, $quem, $titulo, $detalhe, $icone, $cor) => compact('quando', 'quem', 'titulo', 'detalhe', 'icone', 'cor');
    $itens = [];

    $stmt = $pdo->prepare('SELECT * FROM aluno_historico WHERE aluno_id = ? ORDER BY id DESC LIMIT ' . max(1, $limite));
    $stmt->execute([$alunoId]);
    $matriculasNoHistorico = [];
    $temCadastro = false;
    foreach ($stmt->fetchAll() as $h) {
        [$titulo, $icone, $cor] = $estilos[$h['tipo']] ?? [ucfirst($h['tipo']), 'bi-dot', 'secondary'];
        if (in_array($h['tipo'], ['matricula', 'inscricao'], true) && $h['matricula_id']) {
            $matriculasNoHistorico[(int) $h['matricula_id']] = true;
        }
        $temCadastro = $temCadastro || $h['tipo'] === 'cadastro';
        $itens[] = $item($h['criado_em'], $h['quem'], $titulo, $h['descricao'], $icone, $cor);
    }

    // De antes do histórico: cadastro e matrículas (a tabela já guarda quando,
    // por onde e quem).
    $stmt = $pdo->prepare('SELECT criado_em FROM alunos WHERE id = ?');
    $stmt->execute([$alunoId]);
    $criado = $stmt->fetchColumn();
    if ($criado && !$temCadastro) {
        $itens[] = $item($criado, '-', 'Cadastro', 'Aluno cadastrado', 'bi-person-plus', 'secondary');
    }
    $stmt = $pdo->prepare(
        'SELECT m.id, m.criado_em, m.origem, t.nome AS turma_nome, u.nome AS usuario_nome FROM matriculas m
         JOIN turmas t ON t.id = m.turma_id LEFT JOIN usuarios u ON u.id = m.usuario_id WHERE m.aluno_id = ?'
    );
    $stmt->execute([$alunoId]);
    foreach ($stmt->fetchAll() as $m) {
        if (isset($matriculasNoHistorico[(int) $m['id']])) {
            continue;
        }
        $portal = $m['origem'] === 'portal';
        $itens[] = $item($m['criado_em'], $portal ? 'O próprio aluno (portal)' : ($m['usuario_nome'] ?: '-'),
            $portal ? 'Inscrição pelo portal' : 'Matrícula', 'Turma ' . $m['turma_nome'], $portal ? 'bi-globe2' : 'bi-mortarboard', $portal ? 'primary' : 'success');
    }

    usort($itens, fn ($a, $b) => strcmp((string) $b['quando'], (string) $a['quando']));
    return array_slice($itens, 0, $limite);
}

// ---------------------------------------------------------------------
// Graduação do aluno (lista em Configurações › Cadastros auxiliares)
// ---------------------------------------------------------------------

// Tipos de graduação: $soSite = só os liberados no site (portal).
function tiposGraduacao(PDO $pdo, bool $soSite = false, ?int $incluirId = null): array
{
    $sql = 'SELECT * FROM tipos_graduacao WHERE (ativo = 1' . ($soSite ? ' AND no_site = 1' : '') . ')';
    $params = [];
    if ($incluirId) {
        // O que o aluno já escolheu continua aparecendo, mesmo desligado depois.
        $sql .= ' OR id = ?';
        $params[] = $incluirId;
    }
    $stmt = $pdo->prepare($sql . ' ORDER BY ordem, nome');
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Lê e confere mãe, pai e graduação do formulário (portal ou sistema).
 * Retorna [dados, erros]. $exigir: mãe e "possui graduação" obrigatórios.
 * $graduacaoAtual: a que já está no cadastro (continua valendo mesmo se foi
 * desligada depois); qualquer outra precisa estar ativa (e, no site, liberada).
 */
function lerDadosCertificacaoAluno(PDO $pdo, bool $exigir, bool $soSite, ?int $graduacaoAtual = null): array
{
    $possui = $_POST['possui_graduacao'] ?? '';
    $d = [
        'nome_mae' => mb_substr(formatarNomePessoa(input('nome_mae')), 0, 150),
        'nome_pai' => mb_substr(formatarNomePessoa(input('nome_pai')), 0, 150),
        'possui_graduacao' => $possui === '1' ? 1 : ($possui === '0' ? 0 : null),
        'graduacao_id' => (int) ($_POST['graduacao_id'] ?? 0) ?: null,
    ];
    $erros = [];
    if ($exigir && $d['nome_mae'] === '') {
        $erros[] = 'Informe o nome da mãe.';
    }
    if ($exigir && $d['possui_graduacao'] === null) {
        $erros[] = 'Diga se você possui graduação.';
    }
    if ($d['possui_graduacao'] !== 1) {
        $d['graduacao_id'] = null;
    } elseif (!$d['graduacao_id']) {
        $erros[] = 'Escolha qual é a sua graduação.';
    } else {
        $validos = array_map('intval', array_column(tiposGraduacao($pdo, $soSite, $graduacaoAtual), 'id'));
        if (!in_array($d['graduacao_id'], $validos, true)) {
            $erros[] = 'Graduação inválida.';
        }
    }
    return [$d, $erros];
}

// ---------------------------------------------------------------------
// RA do aluno (RA + 7 dígitos)
// ---------------------------------------------------------------------

function raValido(string $ra): bool
{
    return (bool) preg_match('/^RA\d{7}$/', $ra);
}

// Próximo RA livre: o maior número já usado + 1.
function proximoRaAluno(PDO $pdo): string
{
    $maior = (int) $pdo->query("SELECT MAX(CAST(SUBSTRING(ra, 3) AS UNSIGNED)) FROM alunos WHERE ra REGEXP '^RA[0-9]{7}$'")->fetchColumn();
    return 'RA' . str_pad((string) ($maior + 1), 7, '0', STR_PAD_LEFT);
}

// Dá RA a um aluno recém-criado que ficou sem (portal, cadastro rápido).
// Tenta de novo se outro cadastro pegar o mesmo número no mesmo instante.
function atribuirRaAluno(PDO $pdo, int $alunoId): string
{
    for ($tentativa = 0; $tentativa < 5; $tentativa++) {
        $ra = proximoRaAluno($pdo);
        try {
            $pdo->prepare('UPDATE alunos SET ra = ? WHERE id = ? AND ra IS NULL')->execute([$ra, $alunoId]);
            return $ra;
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }
        }
    }
    throw new RuntimeException('Não foi possível gerar o RA do aluno.');
}
