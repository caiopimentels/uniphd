<?php

// Rastreabilidade: quem fez o quê e quando, em todos os módulos do sistema.
// Usada tanto para gravar (registrarAtividade) quanto para alimentar a tela
// de auditoria em Configurações > Auditoria.

function modulosAuditoria(): array
{
    return [
        'login'                  => 'Login',
        'alunos'                 => 'Alunos',
        'cursos'                 => 'Cursos',
        'turmas'                 => 'Turmas',
        'professores'            => 'Professores',
        'matriculas'             => 'Matrículas',
        'fornecedores'           => 'Fornecedores',
        'formas_pagamento'       => 'Formas de pagamento',
        'contas_pagar'           => 'Contas a pagar',
        'contas_receber'         => 'Contas a receber',
        'categorias_financeiras' => 'Categorias financeiras',
        'usuarios'               => 'Usuários',
        'perfis'                 => 'Perfis de acesso',
        'configuracoes'          => 'Configurações',
        'portal'                 => 'Portal do aluno',
    ];
}

function operacoesAuditoria(): array
{
    return [
        'criacao' => 'Criação',
        'edicao' => 'Edição',
        'exclusao' => 'Exclusão',
        'cancelamento' => 'Cancelamento',
        'conclusao' => 'Conclusão',
        'matricula' => 'Matrícula',
        'baixa' => 'Baixa',
        'login' => 'Login',
        'logout' => 'Logout',
        'login_falha' => 'Falha no login',
    ];
}

// Monta uma descrição tipo "Tempo de sessão (min): '30' -> '60'"
// comparando os valores antes/depois de um registro — só entram os campos
// que realmente mudaram. $rotulos mapeia campo => label; $sensiveis lista
// campos cujo valor não deve aparecer (ex.: senha), só que mudou.
function descreverAlteracoes(array $antes, array $depois, array $rotulos, array $sensiveis = []): string
{
    $partes = [];

    foreach ($rotulos as $campo => $rotulo) {
        $valorAntes = $antes[$campo] ?? null;
        $valorDepois = $depois[$campo] ?? null;

        if ((string) $valorAntes === (string) $valorDepois) {
            continue;
        }

        if (in_array($campo, $sensiveis, true)) {
            $partes[] = $rotulo . ' alterado(a)';
            continue;
        }

        $partes[] = $rotulo . ': "' . ($valorAntes !== null && $valorAntes !== '' ? $valorAntes : '-') . '" → "' . ($valorDepois !== null && $valorDepois !== '' ? $valorDepois : '-') . '"';
    }

    return $partes ? implode('; ', $partes) : 'nenhum campo alterado';
}

function registrarAtividade(string $modulo, string $operacao, string $descricao): void
{
    registrarAtividadeUsuario(usuarioAtualId(), usuarioAtualNome(), $modulo, $operacao, $descricao);
}

// Registra a atividade de um usuário específico — usado em fazerLogout()
// (a sessão já foi limpa antes de sabermos quem estava nela) e em tentativas
// de login que falharam (não existe usuário logado ainda).
function registrarAtividadeUsuario(?int $usuarioId, string $usuarioNome, string $modulo, string $operacao, string $descricao): void
{
    $stmt = getDB()->prepare(
        'INSERT INTO log_atividades (usuario_id, usuario_nome, modulo, operacao, descricao, ip, user_agent, criado_em)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW())'
    );
    $stmt->execute([
        $usuarioId,
        $usuarioNome !== '' ? $usuarioNome : 'Sistema',
        $modulo,
        $operacao,
        // A coluna tem 255: uma edição que mexe em muitos campos (ex.: dados
        // da empresa) gera descrição maior, e estourar o limite derrubaria a
        // operação inteira só por causa do registro de auditoria.
        mb_strimwidth($descricao, 0, 255, '…', 'UTF-8'),
        $_SERVER['REMOTE_ADDR'] ?? null,
        substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255) ?: null,
    ]);
}

// Tentativa de login que falhou: não existe usuário autenticado, então
// guarda o e-mail digitado no lugar do nome, pra dar pra ver quem tentou.
function registrarTentativaLoginFalha(string $emailTentado): void
{
    registrarAtividadeUsuario(null, $emailTentado !== '' ? $emailTentado : 'desconhecido', 'login', 'login_falha', 'Tentativa de login falhou para "' . $emailTentado . '"');
}

// Resumo curto e legível do User-Agent (navegador + sistema) para a tela de
// auditoria — não precisa ser perfeito, só ajudar a reconhecer o aparelho.
function resumirUserAgent(?string $userAgent): string
{
    if (!$userAgent) {
        return '-';
    }

    if (preg_match('/iPhone/i', $userAgent)) {
        $sistema = 'iPhone';
    } elseif (preg_match('/iPad/i', $userAgent)) {
        $sistema = 'iPad';
    } elseif (preg_match('/Android/i', $userAgent)) {
        $sistema = 'Android';
    } elseif (preg_match('/Windows/i', $userAgent)) {
        $sistema = 'Windows';
    } elseif (preg_match('/Mac OS X/i', $userAgent)) {
        $sistema = 'Mac';
    } elseif (preg_match('/Linux/i', $userAgent)) {
        $sistema = 'Linux';
    } else {
        $sistema = 'Dispositivo desconhecido';
    }

    if (preg_match('/Edg\//i', $userAgent)) {
        $navegador = 'Edge';
    } elseif (preg_match('/OPR\//i', $userAgent)) {
        $navegador = 'Opera';
    } elseif (preg_match('/Chrome\//i', $userAgent) && !preg_match('/Chromium/i', $userAgent)) {
        $navegador = 'Chrome';
    } elseif (preg_match('/CriOS/i', $userAgent)) {
        $navegador = 'Chrome';
    } elseif (preg_match('/FxiOS/i', $userAgent) || preg_match('/Firefox\//i', $userAgent)) {
        $navegador = 'Firefox';
    } elseif (preg_match('/Safari\//i', $userAgent)) {
        $navegador = 'Safari';
    } else {
        $navegador = '';
    }

    return trim($sistema . ($navegador ? ' · ' . $navegador : ''));
}

function buscarLogAtividades(PDO $pdo, array $filtros, int $pagina = 1, int $porPagina = 50): array
{
    $condicoes = [];
    $parametros = [];

    if (!empty($filtros['data_inicio'])) {
        $condicoes[] = 'criado_em >= ?';
        $parametros[] = $filtros['data_inicio'] . ' 00:00:00';
    }

    if (!empty($filtros['data_fim'])) {
        $condicoes[] = 'criado_em <= ?';
        $parametros[] = $filtros['data_fim'] . ' 23:59:59';
    }

    if (!empty($filtros['modulo'])) {
        $condicoes[] = 'modulo = ?';
        $parametros[] = $filtros['modulo'];
    }

    if (!empty($filtros['usuario_id'])) {
        $condicoes[] = 'usuario_id = ?';
        $parametros[] = $filtros['usuario_id'];
    }

    $where = $condicoes ? 'WHERE ' . implode(' AND ', $condicoes) : '';

    $stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM log_atividades $where");
    $stmtTotal->execute($parametros);
    $total = (int) $stmtTotal->fetchColumn();

    $pagina = max(1, $pagina);
    $offset = ($pagina - 1) * $porPagina;

    $stmt = $pdo->prepare(
        "SELECT * FROM log_atividades $where ORDER BY criado_em DESC LIMIT $porPagina OFFSET $offset"
    );
    $stmt->execute($parametros);

    return [
        'itens' => $stmt->fetchAll(),
        'total' => $total,
        'pagina' => $pagina,
        'por_pagina' => $porPagina,
        'total_paginas' => max(1, (int) ceil($total / $porPagina)),
    ];
}
