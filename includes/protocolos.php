<?php

// Protocolos: o aluno abre pelo portal (justificativa de ausência, pedido
// de reposição...), anexando um atestado se quiser; a secretaria responde,
// deixa notas internas e muda o status. Assuntos e status vêm da
// configuração (Protocolos › Configurar). O link do aluno usa o código
// aleatório, nunca o número.

// Número do protocolo: data de abertura + sequência (ex.: 2026102400001).
function numeroProtocolo(array $protocolo): string
{
    return (string) (($protocolo['numero'] ?? null) ?: $protocolo['id']);
}

// Assuntos com o tema de cada um, na ordem dos temas (sem tema: no fim).
function assuntosProtocolo(PDO $pdo, bool $soAtivos = true): array
{
    return $pdo->query(
        'SELECT pa.*, pt.nome AS tema_nome FROM protocolo_assuntos pa LEFT JOIN protocolo_temas pt ON pt.id = pa.tema_id'
        . ($soAtivos ? ' WHERE pa.ativo = 1 AND (pt.id IS NULL OR pt.ativo = 1)' : '')
        . ' ORDER BY pt.id IS NULL, pt.ordem, pt.nome, pa.ordem, pa.nome'
    )->fetchAll();
}

function temasProtocolo(PDO $pdo, bool $soAtivos = true): array
{
    return $pdo->query('SELECT * FROM protocolo_temas' . ($soAtivos ? ' WHERE ativo = 1' : '') . ' ORDER BY ordem, nome')->fetchAll();
}

// <option> dos assuntos agrupados por tema (formulários de abertura).
function opcoesAssuntosProtocolo(array $assuntos, int $escolhido): string
{
    $html = '';
    $temaAtual = false;
    foreach ($assuntos as $a) {
        if ($a['tema_nome'] !== $temaAtual) {
            $html .= ($temaAtual !== false ? '</optgroup>' : '') . '<optgroup label="' . e($a['tema_nome'] ?? 'Outros') . '">';
            $temaAtual = $a['tema_nome'];
        }
        $html .= '<option value="' . (int) $a['id'] . '"' . ($escolhido === (int) $a['id'] ? ' selected' : '')
            . ' data-turma="' . (int) $a['pede_turma'] . '" data-data="' . (int) $a['pede_data'] . '" data-anexo="' . e($a['anexo']) . '"'
            . ' data-instrucao="' . e((string) $a['instrucao']) . '">' . e($a['nome']) . '</option>';
    }
    return $html . ($temaAtual !== false ? '</optgroup>' : '');
}

function statusProtocolo(PDO $pdo, bool $soAtivos = true): array
{
    return $pdo->query('SELECT * FROM protocolo_status' . ($soAtivos ? ' WHERE ativo = 1' : '') . ' ORDER BY ordem, nome')->fetchAll();
}

function badgeStatusProtocolo(string $nome, string $cor): string
{
    return '<span class="badge" style="background:' . e($cor) . ';">' . e($nome) . '</span>';
}

// Protocolo com aluno, assunto, status, turma e módulo.
function buscarProtocolo(PDO $pdo, string $campo, $valor): ?array
{
    $coluna = $campo === 'codigo' ? 'p.codigo' : 'p.id';
    $stmt = $pdo->prepare(
        "SELECT p.*, a.nome AS aluno_nome, a.email AS aluno_email, a.cpf AS aluno_cpf,
                pa.nome AS assunto_nome, pt.nome AS tema_nome, ps.nome AS status_nome, ps.cor AS status_cor, ps.encerra AS status_encerra,
                c.nome AS curso_nome, t.id AS turma_id, t.nome AS turma_nome, cm.nome AS modulo_nome
         FROM protocolos p
         JOIN alunos a ON a.id = p.aluno_id
         JOIN protocolo_assuntos pa ON pa.id = p.assunto_id
         LEFT JOIN protocolo_temas pt ON pt.id = pa.tema_id
         JOIN protocolo_status ps ON ps.id = p.status_id
         LEFT JOIN matriculas m ON m.id = p.matricula_id
         LEFT JOIN turmas t ON t.id = m.turma_id
         LEFT JOIN cursos c ON c.id = t.curso_id
         LEFT JOIN curso_modulos cm ON cm.id = p.curso_modulo_id
         WHERE {$coluna} = ?"
    );
    $stmt->execute([$valor]);
    return $stmt->fetch() ?: null;
}

function mensagensProtocolo(PDO $pdo, int $protocoloId, bool $comInternas): array
{
    $stmt = $pdo->prepare('SELECT * FROM protocolo_mensagens WHERE protocolo_id = ?' . ($comInternas ? '' : ' AND interna = 0') . ' ORDER BY id');
    $stmt->execute([$protocoloId]);
    return $stmt->fetchAll();
}

// Turmas em que o aluno pode abrir protocolo (matrículas ativas ou
// concluídas), com os módulos de cada curso.
function matriculasParaProtocolo(PDO $pdo, int $alunoId): array
{
    $stmt = $pdo->prepare(
        "SELECT m.id, t.curso_id, c.nome AS curso_nome, t.nome AS turma_nome FROM matriculas m
         JOIN turmas t ON t.id = m.turma_id JOIN cursos c ON c.id = t.curso_id
         WHERE m.aluno_id = ? AND m.status IN ('ativa', 'concluida') ORDER BY m.status = 'ativa' DESC, m.data_matricula DESC"
    );
    $stmt->execute([$alunoId]);
    $matriculas = $stmt->fetchAll();
    $modulos = $pdo->prepare('SELECT id, nome FROM curso_modulos WHERE curso_id = ? ORDER BY ordem, id');
    foreach ($matriculas as &$m) {
        $modulos->execute([$m['curso_id']]);
        $m['modulos'] = $modulos->fetchAll();
    }
    return $matriculas;
}

/**
 * Guarda o anexo de uma mensagem (PDF, JPG ou PNG) na pasta do aluno.
 * Sem arquivo: ok com anexo null. Retorna ['ok', 'mensagem', 'anexo' => ?array].
 */
function anexoProtocolo(int $alunoId, ?array $arquivo): array
{
    if (!$arquivo || (int) ($arquivo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'mensagem' => '', 'anexo' => null];
    }
    $gravado = processarArquivoAluno($alunoId, $arquivo, true, true, 'Anexo do protocolo');
    return $gravado['ok'] ? ['ok' => true, 'mensagem' => '', 'anexo' => $gravado] : ['ok' => false, 'mensagem' => $gravado['mensagem'], 'anexo' => null];
}

function gravarMensagemProtocolo(PDO $pdo, int $protocoloId, string $autor, string $texto, ?array $anexo = null, bool $interna = false): void
{
    $usuarioId = $autor === 'secretaria' && usuarioLogado() ? usuarioAtualId() : null;
    $nome = match ($autor) {
        'secretaria' => usuarioLogado() ? usuarioAtualNome() : 'Secretaria',
        'aluno' => alunoLogado() ? alunoAtualNome() : 'Aluno',
        default => usuarioLogado() ? usuarioAtualNome() : 'Sistema',
    };
    $pdo->prepare(
        'INSERT INTO protocolo_mensagens (protocolo_id, autor, usuario_id, autor_nome, texto, interna, anexo, anexo_nome, anexo_mime) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([$protocoloId, $autor, $usuarioId, $nome, $texto !== '' ? $texto : null, $interna ? 1 : 0,
        $anexo['arquivo'] ?? null, $anexo['nome_original'] ?? null, $anexo['mime'] ?? null]);
}

/**
 * Abre um protocolo: pelo portal (o aluno logado) ou pela secretaria em
 * nome do aluno ($origem = 'secretaria'). $dados: assunto_id, matricula_id,
 * curso_modulo_id, data_referencia, descricao.
 * Retorna ['ok', 'mensagem', 'codigo', 'id'].
 */
function abrirProtocolo(PDO $pdo, int $alunoId, array $dados, ?array $arquivo, string $origem = 'portal'): array
{
    $origem = $origem === 'secretaria' ? 'secretaria' : 'portal';
    $falha = fn (string $m) => ['ok' => false, 'mensagem' => $m, 'codigo' => null, 'id' => null];
    $stmt = $pdo->prepare('SELECT * FROM protocolo_assuntos WHERE id = ? AND ativo = 1');
    $stmt->execute([(int) ($dados['assunto_id'] ?? 0)]);
    $assunto = $stmt->fetch();
    if (!$assunto) {
        return $falha('Escolha o assunto.');
    }
    $descricao = trim((string) ($dados['descricao'] ?? ''));
    if (mb_strlen($descricao) < 5) {
        return $falha('Escreva o que você precisa.');
    }
    $matriculaId = null;
    $moduloId = null;
    if ($assunto['pede_turma']) {
        $matricula = null;
        foreach (matriculasParaProtocolo($pdo, $alunoId) as $m) {
            if ((int) $m['id'] === (int) ($dados['matricula_id'] ?? 0)) {
                $matricula = $m;
            }
        }
        if (!$matricula) {
            return $falha('Escolha a turma.');
        }
        $matriculaId = (int) $matricula['id'];
        $moduloId = (int) ($dados['curso_modulo_id'] ?? 0) ?: null;
        if ($moduloId && !in_array($moduloId, array_map('intval', array_column($matricula['modulos'], 'id')), true)) {
            $moduloId = null;
        }
    }
    $data = $assunto['pede_data'] && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($dados['data_referencia'] ?? '')) ? $dados['data_referencia'] : null;
    if ($assunto['pede_data'] && !$data) {
        return $falha('Informe a data.');
    }
    $temArquivo = $arquivo && (int) ($arquivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    if ($assunto['anexo'] === 'obrigatorio' && !$temArquivo) {
        return $falha('Anexe o documento (PDF ou foto).');
    }
    $anexo = $assunto['anexo'] === 'nao' ? ['ok' => true, 'anexo' => null] : anexoProtocolo($alunoId, $arquivo);
    if (!$anexo['ok']) {
        return $falha($anexo['mensagem']);
    }
    $statusInicial = (int) $pdo->query('SELECT id FROM protocolo_status WHERE ativo = 1 ORDER BY inicial DESC, encerra, ordem LIMIT 1')->fetchColumn();
    do {
        $codigo = strtoupper(substr(bin2hex(random_bytes(8)), 0, 12));
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM protocolos WHERE codigo = ?');
        $stmt->execute([$codigo]);
    } while ((int) $stmt->fetchColumn() > 0);

    $pdo->prepare(
        "INSERT INTO protocolos (codigo, aluno_id, assunto_id, status_id, matricula_id, curso_modulo_id, data_referencia, descricao, origem, aguardando)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'secretaria')"
    )->execute([$codigo, $alunoId, $assunto['id'], $statusInicial, $matriculaId, $moduloId, $data, mb_substr($descricao, 0, 5000), $origem]);
    $id = (int) $pdo->lastInsertId();
    $numero = date('Ymd') . str_pad((string) $id, 5, '0', STR_PAD_LEFT);
    $pdo->prepare('UPDATE protocolos SET numero = ? WHERE id = ?')->execute([$numero, $id]);
    if ($origem === 'secretaria') {
        gravarMensagemProtocolo($pdo, $id, 'sistema', 'Protocolo aberto pela secretaria (' . usuarioAtualNome() . ') em nome do aluno');
    }
    if ($anexo['anexo']) {
        // Aberto pela secretaria: o anexo (ex.: atestado entregue no balcão) sai em nome dela.
        gravarMensagemProtocolo($pdo, $id, $origem === 'secretaria' ? 'secretaria' : 'aluno', '', $anexo['anexo']);
    }
    registrarHistoricoAluno($pdo, $alunoId, 'protocolo', 'Protocolo nº ' . $numero . ' aberto' . ($origem === 'secretaria' ? ' pela secretaria' : ' pelo aluno no portal') . ': ' . $assunto['nome'], $matriculaId);
    if ($origem === 'portal') {
        criarNotificacao($pdo, 'protocolo', 'Novo protocolo: ' . $assunto['nome'], alunoAtualNome() . ' abriu o protocolo nº ' . $numero . '.',
            '/protocolos/ver.php?id=' . $id, $id);
    }
    return ['ok' => true, 'mensagem' => 'Protocolo nº ' . $numero . ' aberto.' . ($origem === 'portal' ? ' Você acompanha a resposta por aqui.' : ''), 'codigo' => $codigo, 'id' => $id, 'numero' => $numero];
}

// Aviso por e-mail ao aluno quando a secretaria responde (falha em silêncio).
function avisarAlunoProtocolo(PDO $pdo, array $protocolo, string $resumo): void
{
    if (!$protocolo['aluno_email']) {
        return;
    }
    try {
        $config = buscarConfiguracoes($pdo);
        $nomePortal = $config['portal_titulo'] ?: ($config['nome_sistema'] ?? 'Portal do aluno');
        $link = urlAbsoluta('/portal/protocolo.php?c=' . $protocolo['codigo']);
        $corpo = '<p>Olá, ' . e(explode(' ', trim($protocolo['aluno_nome']))[0]) . '!</p>'
            . '<p>Seu protocolo nº <strong>' . numeroProtocolo($protocolo) . '</strong> (' . e($protocolo['assunto_nome']) . ') foi atualizado: ' . e($resumo) . '</p>'
            . '<p><a href="' . e($link) . '" style="display:inline-block;padding:10px 20px;background:#071f4a;color:#fff;text-decoration:none;border-radius:6px;">Ver o protocolo</a></p>';
        enviarEmail($config, $protocolo['aluno_email'], $protocolo['aluno_nome'], 'Protocolo nº ' . numeroProtocolo($protocolo) . ' — ' . $nomePortal, $corpo);
    } catch (Throwable $e) {
        error_log('[Protocolo e-mail] ' . $e->getMessage());
    }
}

// Quantos protocolos esperam resposta da secretaria (menu e aba do aluno).
function protocolosAguardandoSecretaria(PDO $pdo, ?int $alunoId = null): int
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM protocolos p JOIN protocolo_status ps ON ps.id = p.status_id
                           WHERE p.aguardando = 'secretaria' AND ps.encerra = 0" . ($alunoId ? ' AND p.aluno_id = ?' : ''));
    $stmt->execute($alunoId ? [$alunoId] : []);
    return (int) $stmt->fetchColumn();
}
