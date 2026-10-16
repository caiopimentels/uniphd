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
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $caminho;
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

    return enviarEmail($config, $aluno['email'], $aluno['nome'], ($tipo === 'convite' ? 'Seu acesso ao ' : 'Senha do ') . $nomePortal, $corpo);
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

        $valores = [
            'data_matricula' => $hoje,
            'status' => $pendente ? 'pendente' : 'ativa',
            'pagamento_ate' => $prazo,
            'segura_vaga' => $modo === 'reserva' ? 1 : 0,
            'valor_mensalidade' => $turma['valor_mensalidade'],
            'num_parcelas' => numParcelasPortal($turma),
            'primeiro_vencimento' => primeiroVencimentoPortal($turma, $hoje),
            'taxa_matricula' => $turma['portal_taxa_matricula'],
            'forma_pagamento_id' => $turma['portal_forma_pagamento_id'],
        ];

        if ($existente) {
            // Reinscrição: as cobranças da tentativa anterior já estão canceladas.
            $stmt = $pdo->prepare(
                "UPDATE matriculas SET data_matricula = ?, status = ?, origem = 'portal', pagamento_ate = ?, segura_vaga = ?, lista_espera = 0,
                        valor_mensalidade = ?, desconto_percentual = 0, desconto_pontualidade_tipo = 'nenhum', desconto_pontualidade_valor = 0,
                        desconto_pontualidade_dias = 0, num_parcelas = ?, primeiro_vencimento = ?, taxa_matricula = ?, forma_pagamento_id = ?,
                        data_cancelamento = NULL, conta_receber_confirmacao_id = NULL, usuario_id = NULL
                 WHERE id = ?"
            );
            $stmt->execute([...array_values($valores), $existente['id']]);
            $matriculaId = (int) $existente['id'];
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO matriculas (turma_id, aluno_id, origem, data_matricula, status, pagamento_ate, segura_vaga, valor_mensalidade,
                                         num_parcelas, primeiro_vencimento, taxa_matricula, forma_pagamento_id)
                 VALUES (?, ?, 'portal', ?, ?, ?, ?, ?, ?, ?, ?, ?)"
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
    criarNotificacao($pdo, 'matricula', 'Nova inscrição pelo portal', $aluno['nome'] . ' · ' . $turma['curso_nome'] . ' — ' . $turma['nome'], '/matriculas/visualizar.php?id=' . $matriculaId, $matriculaId);

    return [
        'ok' => true,
        'mensagem' => $pendente
            ? 'Inscrição feita! Pague até ' . formatDateTime($prazo) . ' para confirmar sua matrícula.'
            : 'Matrícula confirmada! Bem-vindo(a) à turma.',
        'matricula_id' => $matriculaId,
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
        "SELECT m.id, m.turma_id, a.nome AS aluno_nome, t.nome AS turma_nome
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
            $pdo->prepare("UPDATE contas_receber SET status = 'cancelado' WHERE matricula_id = ? AND status = 'pendente'")->execute([$m['id']]);
            $expiradas++;
        }
        $pdo->commit();
        if ($atualiza->rowCount()) {
            registrarAtividadeUsuario(null, 'Portal', 'matriculas', 'cancelamento', 'Inscrição expirada sem pagamento: ' . $m['aluno_nome'] . ' — ' . $m['turma_nome']);
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
    if ($parcela['data_vencimento'] < date('Y-m-d')) {
        return ['ok' => false, 'mensagem' => 'Esta parcela já venceu. Fale com a secretaria para gerar uma nova cobrança.'];
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
                '/financeiro/cobranca_previa.php?id=' . (int) $parcela['id'], (int) $parcela['id']);
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
        'nome' => trim(input('nome')),
        'cpf' => apenasDigitos(input('cpf')),
        'data_nascimento' => input('data_nascimento'),
        'telefone' => trim(input('telefone')),
        'email' => mb_strtolower(trim(input('email'))),
        'cep' => input('cep'),
        'logradouro' => trim(input('logradouro')),
        'numero' => trim(input('numero')),
        'complemento' => trim(input('complemento')),
        'bairro' => trim(input('bairro')),
        'cidade' => trim(input('cidade')),
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
        mkdir($pasta, 0755, true);
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
