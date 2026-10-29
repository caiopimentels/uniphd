<?php

// ---------------------------------------------------------------------
// E-mail no sistema (menu E-mail). Cada usuário usa a SUA caixa: o
// e-mail e a senha ficam no cadastro dele; o servidor (IMAP para ler,
// SMTP para enviar) é o mesmo para todos (Configurações › E-mail).
// Também o disparo em massa para alunos, com tags ({nome_aluno}...).
// ---------------------------------------------------------------------

const EMAIL_PASTA_CACHE = '/storage/email_cache';
const EMAIL_PASTA_DISPAROS = '/storage/email_disparos';

function usuarioPodeUsarEmail(): bool
{
    return usuarioTemPermissao('usar_email');
}

function usuarioPodeDispararEmail(): bool
{
    return usuarioTemPermissao('disparar_email');
}

/** Servidor das caixas; sem host próprio, usa o do e-mail do sistema. */
function emailConfig(PDO $pdo): array
{
    $c = buscarConfiguracoes($pdo);
    $imapHost = trim((string) ($c['email_imap_host'] ?? ''));
    $smtpHost = trim((string) ($c['email_smtp_host'] ?? '')) ?: trim((string) ($c['smtp_host'] ?? ''));
    return [
        'imap_host' => $imapHost ?: $smtpHost,
        'imap_porta' => (int) ($c['email_imap_porta'] ?? 993),
        'imap_seguranca' => $c['email_imap_seguranca'] ?? 'ssl',
        'smtp_host' => $smtpHost,
        'smtp_porta' => (int) ($c['email_smtp_host'] ? $c['email_smtp_porta'] : ($c['smtp_porta'] ?? 465)),
        'smtp_seguranca' => $c['email_smtp_host'] ? ($c['email_smtp_seguranca'] ?? 'ssl') : ($c['smtp_seguranca'] ?? 'ssl'),
        'verificar' => (bool) ($c['email_verificar_certificado'] ?? 1),
        'anexo_max_mb' => max(1, (int) ($c['email_anexo_max_mb'] ?? 20)),
        'disparo_por_hora' => max(1, (int) ($c['email_disparo_por_hora'] ?? 150)),
        'disparo_remetente' => $c['email_disparo_remetente'] ?? 'usuario',
    ];
}

/** Caixa do usuário: email, senha, nome, assinatura e se está pronta. */
function caixaUsuario(PDO $pdo, int $usuarioId): array
{
    $stmt = $pdo->prepare('SELECT id, nome, email, email_caixa, email_caixa_senha_cripto, email_remetente_nome, email_assinatura FROM usuarios WHERE id = ?');
    $stmt->execute([$usuarioId]);
    $u = $stmt->fetch() ?: [];
    $email = trim((string) ($u['email_caixa'] ?? '')) ?: trim((string) ($u['email'] ?? ''));
    $senha = descriptografar($u['email_caixa_senha_cripto'] ?? null);
    return [
        'usuario_id' => $usuarioId,
        'email' => strtolower($email),
        'senha' => $senha,
        'nome' => trim((string) ($u['email_remetente_nome'] ?? '')) ?: (string) ($u['nome'] ?? ''),
        'assinatura' => (string) ($u['email_assinatura'] ?? ''),
        'configurada' => $email !== '' && $senha !== null && $senha !== '',
    ];
}

/** Conecta na caixa do usuário (uma conexão por requisição). */
function abrirCaixa(PDO $pdo, ?int $usuarioId = null): ImapCliente
{
    static $abertas = [];
    $usuarioId ??= (int) usuarioAtualId();
    if (isset($abertas[$usuarioId])) {
        return $abertas[$usuarioId];
    }
    $cfg = emailConfig($pdo);
    $caixa = caixaUsuario($pdo, $usuarioId);
    if (!$cfg['imap_host']) {
        throw new RuntimeException('O servidor de e-mail ainda não foi configurado (Configurações › E-mail).');
    }
    if (!$caixa['configurada']) {
        throw new RuntimeException('Sua caixa de e-mail não está configurada: falta a senha do e-mail no seu cadastro.');
    }
    return $abertas[$usuarioId] = ImapCliente::abrir($cfg['imap_host'], $cfg['imap_porta'], $cfg['imap_seguranca'], $caixa['email'], $caixa['senha'], $cfg['verificar']);
}

/** Testa a caixa (entrada e saída) com estes dados, sem salvar nada. */
function testarCaixaEmail(PDO $pdo, string $email, string $senha): array
{
    $cfg = emailConfig($pdo);
    $resultado = [];
    try {
        $c = ImapCliente::abrir($cfg['imap_host'], $cfg['imap_porta'], $cfg['imap_seguranca'], $email, $senha, $cfg['verificar']);
        $status = $c->status('INBOX');
        $c->fechar();
        $resultado[] = ['ok' => true, 'texto' => 'Receber (IMAP): conectado — ' . $status['mensagens'] . ' mensagem(ns) na caixa de entrada, ' . $status['nao_lidas'] . ' não lida(s).'];
    } catch (RuntimeException $e) {
        $resultado[] = ['ok' => false, 'texto' => 'Receber (IMAP): ' . $e->getMessage()];
    }
    $r = testarSmtpCaixa($cfg, $email, $senha);
    $resultado[] = ['ok' => $r['sucesso'], 'texto' => 'Enviar (SMTP): ' . $r['mensagem']];
    return $resultado;
}

// Só conecta e faz login no SMTP (sem enviar nada).
function testarSmtpCaixa(array $cfg, string $email, string $senha): array
{
    if (!$cfg['smtp_host']) {
        return ['sucesso' => false, 'mensagem' => 'servidor de envio não configurado.'];
    }
    $contexto = stream_context_create(['ssl' => ['verify_peer' => $cfg['verificar'], 'verify_peer_name' => $cfg['verificar'], 'allow_self_signed' => !$cfg['verificar']]]);
    $s = @stream_socket_client(($cfg['smtp_seguranca'] === 'ssl' ? 'ssl://' : 'tcp://') . $cfg['smtp_host'] . ':' . $cfg['smtp_porta'], $n, $erro, 15, STREAM_CLIENT_CONNECT, $contexto);
    if (!$s) {
        return ['sucesso' => false, 'mensagem' => 'não conectou em ' . $cfg['smtp_host'] . ':' . $cfg['smtp_porta'] . ' (' . ($erro ?: 'sem resposta') . ').'];
    }
    stream_set_timeout($s, 15);
    $ler = function () use ($s) {
        $r = '';
        while (($l = fgets($s, 1024)) !== false) {
            $r .= $l;
            if (!isset($l[3]) || $l[3] === ' ') {
                break;
            }
        }
        return $r;
    };
    $ler();
    fwrite($s, "EHLO localhost\r\n");
    $recursos = $ler();
    if ($cfg['smtp_seguranca'] === 'tls') {
        fwrite($s, "STARTTLS\r\n");
        $ler();
        if (!@stream_socket_enable_crypto($s, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            return ['sucesso' => false, 'mensagem' => 'falhou ao ativar STARTTLS.'];
        }
        fwrite($s, "EHLO localhost\r\n");
        $recursos = $ler();
    }
    $ok = true;
    if (stripos($recursos, 'AUTH') !== false) {
        fwrite($s, 'AUTH PLAIN ' . base64_encode("\0" . $email . "\0" . $senha) . "\r\n");
        $ok = str_starts_with(trim($ler()), '235');
    }
    fwrite($s, "QUIT\r\n");
    fclose($s);
    return $ok ? ['sucesso' => true, 'mensagem' => 'conectado e autenticado.'] : ['sucesso' => false, 'mensagem' => 'e-mail ou senha recusados.'];
}

/** Pastas da caixa (guardadas na sessão por 5 min). */
function pastasCaixa(PDO $pdo, bool $recarregar = false): array
{
    $chave = 'email_pastas_' . usuarioAtualId();
    if (!$recarregar && isset($_SESSION[$chave]) && $_SESSION[$chave]['ate'] > time()) {
        return $_SESSION[$chave]['pastas'];
    }
    $pastas = array_values(array_filter(abrirCaixa($pdo)->pastas(), fn ($p) => $p['selecionavel']));
    // Duas pastas do mesmo tipo (ex.: "spam" e "Junk"): fica como especial a
    // que o servidor marcou (ou a primeira); a outra aparece com o nome dela.
    $porTipo = [];
    foreach ($pastas as $i => $p) {
        if ($p['especial'] && $p['especial'] !== 'inbox') {
            $porTipo[$p['especial']][] = $i;
        }
    }
    foreach ($porTipo as $indices) {
        if (count($indices) < 2) {
            continue;
        }
        $fica = $indices[0];
        foreach ($indices as $i) {
            if (!empty($pastas[$i]['pelo_servidor'])) {
                $fica = $i;
                break;
            }
        }
        foreach ($indices as $i) {
            if ($i !== $fica) {
                // Ex.: "Spam (Junk)" — deixa claro que é outra pasta do mesmo tipo.
                $pastas[$i]['rotulo'] = $pastas[$i]['rotulo'] . ' (' . ImapCliente::nomeLegivel($pastas[$i]['nome'], $pastas[$i]['delimitador']) . ')';
                $pastas[$i]['especial'] = null;
            }
        }
    }
    // Entrada primeiro, depois as especiais na ordem do Gmail, depois o resto.
    $ordem = ['inbox' => 0, 'enviados' => 1, 'rascunhos' => 2, 'arquivo' => 3, 'spam' => 4, 'lixeira' => 5];
    usort($pastas, fn ($a, $b) => [$ordem[$a['especial']] ?? 9, mb_strtolower($a['rotulo'])] <=> [$ordem[$b['especial']] ?? 9, mb_strtolower($b['rotulo'])]);
    $_SESSION[$chave] = ['ate' => time() + 300, 'pastas' => $pastas];
    return $pastas;
}

function pastaEspecial(array $pastas, string $tipo): ?string
{
    foreach ($pastas as $p) {
        if ($p['especial'] === $tipo) {
            return $p['nome'];
        }
    }
    return null;
}

// Pasta de Enviados / Lixeira; sem ela no servidor, cria (INBOX.Sent ou Sent).
function garantirPastaEspecial(PDO $pdo, string $tipo): string
{
    $pastas = pastasCaixa($pdo);
    if ($nome = pastaEspecial($pastas, $tipo)) {
        return $nome;
    }
    $delimitador = $pastas[0]['delimitador'] ?? '.';
    $comPrefixo = (bool) array_filter($pastas, fn ($p) => stripos($p['nome'], 'INBOX' . $delimitador) === 0);
    $base = ['enviados' => 'Sent', 'lixeira' => 'Trash', 'rascunhos' => 'Drafts'][$tipo] ?? $tipo;
    $nome = $comPrefixo ? 'INBOX' . $delimitador . $base : $base;
    try {
        abrirCaixa($pdo)->criarPasta($nome);
    } catch (RuntimeException $e) {
        // Já existia com outro nome/caixa: segue com o nome escolhido.
    }
    pastasCaixa($pdo, true);
    return $nome;
}

/** Não lidas da caixa de entrada (para o menu), guardado 60 s na sessão. */
function naoLidasEmail(PDO $pdo): ?int
{
    $chave = 'email_nao_lidas_' . usuarioAtualId();
    if (isset($_SESSION[$chave]) && $_SESSION[$chave]['ate'] > time()) {
        return $_SESSION[$chave]['n'];
    }
    try {
        $n = abrirCaixa($pdo)->status('INBOX')['nao_lidas'];
    } catch (Throwable $e) {
        $n = null;
    }
    $_SESSION[$chave] = ['ate' => time() + 60, 'n' => $n];
    return $n;
}

function esquecerNaoLidasEmail(): void
{
    unset($_SESSION['email_nao_lidas_' . usuarioAtualId()]);
}

/**
 * Mensagem inteira (bruta), guardada no disco por 1 h: abrir, baixar um
 * anexo e responder não baixam de novo do servidor.
 */
function mensagemBrutaCaixa(PDO $pdo, string $pasta, int $uid): ?string
{
    $dir = BASE_PATH . EMAIL_PASTA_CACHE . '/' . (int) usuarioAtualId();
    $arquivo = $dir . '/' . md5($pasta) . '_' . $uid . '.eml';
    if (is_file($arquivo) && filemtime($arquivo) > time() - 3600) {
        return file_get_contents($arquivo);
    }
    $caixa = abrirCaixa($pdo);
    $caixa->abrirPasta($pasta);
    $bruto = $caixa->mensagemBruta($uid);
    if ($bruto !== null) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        @file_put_contents($arquivo, $bruto);
        // Limpa as antigas de vez em quando.
        if (random_int(1, 20) === 1) {
            foreach (glob($dir . '/*.eml') ?: [] as $velho) {
                if (filemtime($velho) < time() - 3600) {
                    @unlink($velho);
                }
            }
        }
    }
    return $bruto;
}

/**
 * Envia pela caixa do usuário e grava a cópia em Enviados.
 * $dados: para, cc, cco (listas de e-mails/"Nome <email>"), assunto, html,
 * anexos [['nome','tipo','conteudo']], responde_a, referencias.
 */
function enviarEmailUsuario(PDO $pdo, int $usuarioId, array $dados): array
{
    $cfg = emailConfig($pdo);
    $caixa = caixaUsuario($pdo, $usuarioId);
    if (!$caixa['configurada']) {
        return ['sucesso' => false, 'mensagem' => 'Sua caixa de e-mail não está configurada (falta a senha do e-mail no seu cadastro).'];
    }
    $lista = fn ($v) => EmailMime::enderecos(is_array($v) ? implode(', ', $v) : (string) $v);
    $para = $lista($dados['para'] ?? '');
    $cc = $lista($dados['cc'] ?? '');
    $cco = $lista($dados['cco'] ?? '');
    $todos = array_column([...$para, ...$cc, ...$cco], 'email');
    foreach ($todos as $email) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['sucesso' => false, 'mensagem' => 'Endereço inválido: ' . $email];
        }
    }
    if (!$todos) {
        return ['sucesso' => false, 'mensagem' => 'Informe pelo menos um destinatário.'];
    }
    $dados['html'] = imagensLocaisEmbutidas((string) ($dados['html'] ?? ''));
    $mensagem = EmailMime::montar([
        'de' => ['nome' => $caixa['nome'], 'email' => $caixa['email']],
        'para' => $para,
        'cc' => $cc,
        'assunto' => (string) ($dados['assunto'] ?? ''),
        'html' => (string) ($dados['html'] ?? ''),
        'anexos' => $dados['anexos'] ?? [],
        'responde_a' => $dados['responde_a'] ?? null,
        'referencias' => $dados['referencias'] ?? null,
    ]);
    $r = enviarMensagemSmtp(
        ['host' => $cfg['smtp_host'], 'porta' => $cfg['smtp_porta'], 'seguranca' => $cfg['smtp_seguranca'], 'usuario' => $caixa['email'], 'senha' => $caixa['senha'], 'verificar' => $cfg['verificar']],
        $caixa['email'],
        $todos,
        $mensagem['bruto']
    );
    if (!$r['sucesso']) {
        return $r;
    }
    // Cópia em Enviados (sem ela o e-mail não aparece na pasta). Falhar
    // aqui não desfaz o envio.
    try {
        abrirCaixa($pdo, $usuarioId)->gravar(garantirPastaEspecial($pdo, 'enviados'), $mensagem['bruto']);
    } catch (Throwable $e) {
        $r['mensagem'] .= ' (não foi possível guardar a cópia em Enviados)';
    }
    return $r;
}

/**
 * Imagens do próprio sistema (/uploads/portal/..., ex.: logo da assinatura)
 * viram data: — o EmailMime::montar as manda embutidas (quem recebe não
 * enxergaria um endereço relativo).
 */
function imagensLocaisEmbutidas(string $html): string
{
    return preg_replace_callback('#src="(/uploads/portal/[\w.-]+)"#', function ($m) {
        $arquivo = BASE_PATH . '/public' . $m[1];
        $tipo = is_file($arquivo) ? (new finfo(FILEINFO_MIME_TYPE))->file($arquivo) : '';
        if (!in_array($tipo, ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true) || filesize($arquivo) > 5 * 1024 * 1024) {
            return 'src="' . e(urlAbsoluta($m[1])) . '"';
        }
        return 'src="data:' . $tipo . ';base64,' . base64_encode(file_get_contents($arquivo)) . '"';
    }, $html) ?? $html;
}

// ---------------------------------------------------------------------
// HTML recebido: mostrado num iframe isolado, sem script; imagens de fora
// só quando o usuário pede (rastreio de leitura).
// ---------------------------------------------------------------------

/** HTML da mensagem pronto para o iframe: cid: vira link do anexo. */
function htmlMensagemRecebida(array $mensagem, string $urlParte): string
{
    $html = $mensagem['html'];
    if ($html === null) {
        $texto = e((string) $mensagem['texto']);
        // Links clicáveis no texto puro.
        $texto = preg_replace('~(https?://[^\s<]+)~', '<a href="$1">$1</a>', $texto);
        $html = '<div style="white-space: pre-wrap; font-family: inherit">' . $texto . '</div>';
    }
    foreach ($mensagem['anexos'] as $a) {
        if ($a['cid']) {
            $html = str_ireplace('cid:' . $a['cid'], $urlParte . rawurlencode($a['id']), $html);
        }
    }
    // Sem scripts nem formulários (o iframe também bloqueia, isto é reforço).
    $html = preg_replace('#<(script|iframe|object|embed|form)\b[^>]*>.*?</\1>#is', '', $html);
    $html = preg_replace('#<(script|iframe|object|embed|meta|base)\b[^>]*>#i', '', $html);
    $html = preg_replace('#\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html);
    return $html;
}

/** Imagens externas no HTML? (para mostrar o botão "Mostrar imagens"). */
function temImagensExternas(?string $html): bool
{
    return $html !== null && (preg_match('#<img[^>]+src=["\']?https?://#i', $html) || preg_match('#url\(\s*["\']?https?://#i', $html));
}

// ---------------------------------------------------------------------
// Tags (disparo em massa)
// ---------------------------------------------------------------------

function tagsEmail(): array
{
    return [
        '{nome_aluno}' => 'Nome completo do aluno',
        '{primeiro_nome}' => 'Primeiro nome do aluno',
        '{email_aluno}' => 'E-mail do aluno',
        '{cpf}' => 'CPF do aluno',
        '{curso}' => 'Curso da turma',
        '{turma}' => 'Nome da turma',
        '{data_inicio_turma}' => 'Início da turma',
        '{horario_turma}' => 'Dias e horário das aulas',
        '{link_portal}' => 'Endereço do portal do aluno',
        '{empresa}' => 'Nome da empresa',
        '{telefone_empresa}' => 'Telefone da empresa',
        '{data_hoje}' => 'Data de hoje',
        '{valor_em_atraso}' => 'Total em atraso do aluno',
        '{parcelas_em_atraso}' => 'Quantas parcelas estão em atraso',
        '{vencimento_mais_antigo}' => 'Vencimento da parcela atrasada mais antiga',
        '{link_pagamento}' => 'Página do portal para pagar (boleto e Pix)',
    ];
}

/** Valores das tags para um aluno (e a turma de origem, se houver). */
function valoresTagsEmail(PDO $pdo, ?int $alunoId, ?int $turmaId, array $config): array
{
    static $turmas = [];
    $aluno = ['nome' => '', 'email' => '', 'cpf' => ''];
    if ($alunoId) {
        $stmt = $pdo->prepare('SELECT nome, email, cpf FROM alunos WHERE id = ?');
        $stmt->execute([$alunoId]);
        $aluno = $stmt->fetch() ?: $aluno;
    }
    $turma = null;
    if ($turmaId) {
        if (!array_key_exists($turmaId, $turmas)) {
            $stmt = $pdo->prepare('SELECT t.*, c.nome AS curso_nome FROM turmas t JOIN cursos c ON c.id = t.curso_id WHERE t.id = ?');
            $stmt->execute([$turmaId]);
            $turmas[$turmaId] = $stmt->fetch() ?: null;
        }
        $turma = $turmas[$turmaId];
    }
    $nome = trim((string) $aluno['nome']);
    $primeiro = mb_convert_case(mb_strtolower(explode(' ', $nome)[0] ?? ''), MB_CASE_TITLE);
    return [
        '{nome_aluno}' => $nome,
        '{primeiro_nome}' => $primeiro,
        '{email_aluno}' => (string) $aluno['email'],
        '{cpf}' => $aluno['cpf'] ? cpfFormatado($aluno['cpf']) : '',
        '{curso}' => (string) ($turma['curso_nome'] ?? ''),
        '{turma}' => (string) ($turma['nome'] ?? ''),
        '{data_inicio_turma}' => !empty($turma['data_inicio']) ? formatDate($turma['data_inicio']) : (($turma ?? null) ? 'a definir' : ''),
        '{horario_turma}' => $turma ? (string) textoAgendaTurma($turma) : '',
        '{link_portal}' => urlAbsoluta('/portal/'),
        '{empresa}' => (string) ($config['empresa_nome_fantasia'] ?: ($config['empresa_razao_social'] ?: nomeSistema())),
        '{telefone_empresa}' => (string) ($config['empresa_telefone'] ?? ''),
        '{data_hoje}' => date('d/m/Y'),
    ] + valoresTagsCobranca($pdo, $alunoId);
}

/** Tags da cobrança (inadimplência) de um aluno: valor, parcelas e vencimento em atraso. */
function valoresTagsCobranca(PDO $pdo, ?int $alunoId): array
{
    $atraso = ['parcelas' => 0, 'valor' => 0, 'mais_antigo' => null];
    if ($alunoId) {
        $stmt = $pdo->prepare("SELECT COUNT(*) AS parcelas, COALESCE(SUM(valor), 0) AS valor, MIN(data_vencimento) AS mais_antigo
                               FROM contas_receber WHERE aluno_id = ? AND status = 'pendente' AND data_vencimento < CURDATE() AND excluido_em IS NULL");
        $stmt->execute([$alunoId]);
        $atraso = $stmt->fetch() ?: $atraso;
    }
    return [
        '{valor_em_atraso}' => formatMoney((float) $atraso['valor']),
        '{parcelas_em_atraso}' => (string) (int) $atraso['parcelas'],
        '{vencimento_mais_antigo}' => $atraso['mais_antigo'] ? formatDate($atraso['mais_antigo']) : '',
        '{link_pagamento}' => urlAbsoluta('/portal/financeiro.php'),
    ];
}

// Troca as tags; no HTML, o valor vai escapado.
function aplicarTagsEmail(string $texto, array $valores, bool $html = true): string
{
    return strtr($texto, $html ? array_map(fn ($v) => e($v), $valores) : $valores);
}

// ---------------------------------------------------------------------
// Disparo em massa
// ---------------------------------------------------------------------

function publicosDisparo(): array
{
    return [
        'turma' => 'Alunos de turma(s)',
        'curso' => 'Alunos de curso(s)',
        'andamento' => 'Todos os alunos com turma em andamento',
        'todos' => 'Todos os alunos cadastrados (ativos)',
        'alunos' => 'Alunos escolhidos',
    ];
}

/**
 * Quem recebe: [['aluno_id', 'turma_id', 'nome', 'email']], um por e-mail
 * (o mesmo e-mail em dois alunos/turmas recebe uma vez só). Turma em
 * andamento = matrícula ativa ou pendente.
 */
function destinatariosDisparo(PDO $pdo, string $publico, array $ids): array
{
    $ids = array_values(array_filter(array_map('intval', $ids)));
    $marcas = $ids ? implode(',', array_fill(0, count($ids), '?')) : '0';
    $comEmail = "a.ativo = 1 AND a.email IS NOT NULL AND a.email <> ''";
    $andamento = "m.status IN ('ativa', 'pendente')";
    [$sql, $params] = match ($publico) {
        'turma' => ["SELECT a.id AS aluno_id, m.turma_id, a.nome, a.email FROM matriculas m JOIN alunos a ON a.id = m.aluno_id
                     WHERE $andamento AND m.turma_id IN ($marcas) AND $comEmail ORDER BY a.nome", $ids],
        'curso' => ["SELECT a.id AS aluno_id, m.turma_id, a.nome, a.email FROM matriculas m JOIN turmas t ON t.id = m.turma_id JOIN alunos a ON a.id = m.aluno_id
                     WHERE $andamento AND t.curso_id IN ($marcas) AND $comEmail ORDER BY a.nome", $ids],
        'andamento' => ["SELECT a.id AS aluno_id, m.turma_id, a.nome, a.email FROM matriculas m JOIN alunos a ON a.id = m.aluno_id
                     WHERE $andamento AND $comEmail ORDER BY a.nome", []],
        'alunos' => ["SELECT a.id AS aluno_id,
                        (SELECT m.turma_id FROM matriculas m WHERE m.aluno_id = a.id AND $andamento ORDER BY m.id DESC LIMIT 1) AS turma_id,
                        a.nome, a.email FROM alunos a WHERE a.id IN ($marcas) AND $comEmail ORDER BY a.nome", $ids],
        default => ["SELECT a.id AS aluno_id,
                        (SELECT m.turma_id FROM matriculas m WHERE m.aluno_id = a.id AND $andamento ORDER BY m.id DESC LIMIT 1) AS turma_id,
                        a.nome, a.email FROM alunos a WHERE $comEmail ORDER BY a.nome", []],
    };
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $porEmail = [];
    foreach ($stmt as $linha) {
        $email = strtolower(trim($linha['email']));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) && !isset($porEmail[$email])) {
            $porEmail[$email] = ['aluno_id' => (int) $linha['aluno_id'], 'turma_id' => $linha['turma_id'] ? (int) $linha['turma_id'] : null, 'nome' => $linha['nome'], 'email' => $email];
        }
    }
    return array_values($porEmail);
}

/** Enviados na última hora (todos os disparos): para respeitar o limite do provedor. */
function enviadosUltimaHora(PDO $pdo): int
{
    return (int) $pdo->query("SELECT COUNT(*) FROM email_disparo_destinos WHERE status = 'enviado' AND enviado_em >= NOW() - INTERVAL 1 HOUR")->fetchColumn();
}

/**
 * Envia a próxima leva da fila (no máximo $leva, dentro do limite por
 * hora). Chamado pela tela do disparo (enquanto aberta) e pelo cron.
 * Uma execução por vez (GET_LOCK). Retorna quantos enviou.
 */
function processarDisparosEmail(PDO $pdo, int $leva = 10, ?int $soDisparo = null): int
{
    if ((int) $pdo->query("SELECT GET_LOCK('email_disparos', 0)")->fetchColumn() !== 1) {
        return 0;
    }
    try {
        $cfg = emailConfig($pdo);
        $config = buscarConfiguracoes($pdo);
        $restante = min($leva, max(0, $cfg['disparo_por_hora'] - enviadosUltimaHora($pdo)));
        $enviados = 0;
        $sql = "SELECT * FROM email_disparos WHERE status = 'enviando'" . ($soDisparo ? ' AND id = ' . (int) $soDisparo : '') . ' ORDER BY id';
        foreach ($pdo->query($sql)->fetchAll() as $disparo) {
            if ($restante <= 0) {
                break;
            }
            $servidor = servidorDisparoEmail($pdo, $disparo, $cfg, $config);
            if (!$servidor['ok']) {
                $pdo->prepare("UPDATE email_disparos SET status = 'pausado' WHERE id = ?")->execute([$disparo['id']]);
                continue;
            }
            $anexos = anexosDisparo($disparo);
            $stmt = $pdo->prepare("SELECT * FROM email_disparo_destinos WHERE disparo_id = ? AND status = 'pendente' ORDER BY id LIMIT " . (int) $restante);
            $stmt->execute([$disparo['id']]);
            foreach ($stmt->fetchAll() as $d) {
                $valores = valoresTagsEmail($pdo, $d['aluno_id'] ? (int) $d['aluno_id'] : null, $d['turma_id'] ? (int) $d['turma_id'] : null, $config);
                $valores['{nome_aluno}'] = $valores['{nome_aluno}'] ?: $d['nome'];
                $mensagem = EmailMime::montar([
                    'de' => $servidor['de'],
                    'para' => [['nome' => $d['nome'], 'email' => $d['email']]],
                    'assunto' => aplicarTagsEmail($disparo['assunto'], $valores, false),
                    'html' => imagensLocaisEmbutidas(aplicarTagsEmail($disparo['corpo_html'], $valores)),
                    'anexos' => $anexos,
                ]);
                $r = enviarMensagemSmtp($servidor['smtp'], $servidor['de']['email'], [$d['email']], $mensagem['bruto']);
                $pdo->prepare('UPDATE email_disparo_destinos SET status = ?, erro = ?, enviado_em = NOW() WHERE id = ?')
                    ->execute([$r['sucesso'] ? 'enviado' : 'erro', $r['sucesso'] ? null : mb_substr($r['mensagem'], 0, 250), $d['id']]);
                $pdo->prepare('UPDATE email_disparos SET ' . ($r['sucesso'] ? 'enviados = enviados + 1' : 'falhas = falhas + 1') . ' WHERE id = ?')->execute([$disparo['id']]);
                $enviados++;
                $restante--;
                // Senha recusada / servidor fora: para o disparo em vez de errar todos.
                if (!$r['sucesso'] && preg_match('/senha|conectar|STARTTLS|não respondeu/i', $r['mensagem'])) {
                    $pdo->prepare("UPDATE email_disparos SET status = 'pausado' WHERE id = ?")->execute([$disparo['id']]);
                    break;
                }
            }
            $pendentes = $pdo->prepare("SELECT COUNT(*) FROM email_disparo_destinos WHERE disparo_id = ? AND status = 'pendente'");
            $pendentes->execute([$disparo['id']]);
            if ((int) $pendentes->fetchColumn() === 0) {
                $pdo->prepare("UPDATE email_disparos SET status = 'concluido', concluido_em = NOW() WHERE id = ? AND status = 'enviando'")->execute([$disparo['id']]);
            }
        }
        return $enviados;
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('email_disparos')");
    }
}

// De onde sai o disparo: e-mail do sistema (Configurações › Sistema) ou a
// caixa de quem criou o disparo.
function servidorDisparoEmail(PDO $pdo, array $disparo, array $cfg, array $config): array
{
    if ($disparo['remetente'] === 'sistema') {
        $email = trim((string) ($config['smtp_remetente_email'] ?? '')) ?: trim((string) ($config['smtp_usuario'] ?? ''));
        return [
            'ok' => $config['smtp_host'] && $email,
            'de' => ['nome' => (string) ($config['smtp_remetente_nome'] ?: nomeSistema()), 'email' => $email],
            'smtp' => ['host' => $config['smtp_host'], 'porta' => (int) $config['smtp_porta'], 'seguranca' => $config['smtp_seguranca'], 'usuario' => $config['smtp_usuario'], 'senha' => $config['smtp_senha'], 'verificar' => $cfg['verificar']],
        ];
    }
    $caixa = caixaUsuario($pdo, (int) $disparo['usuario_id']);
    return [
        'ok' => $caixa['configurada'] && $cfg['smtp_host'],
        'de' => ['nome' => $caixa['nome'], 'email' => $caixa['email']],
        'smtp' => ['host' => $cfg['smtp_host'], 'porta' => $cfg['smtp_porta'], 'seguranca' => $cfg['smtp_seguranca'], 'usuario' => $caixa['email'], 'senha' => $caixa['senha'], 'verificar' => $cfg['verificar']],
    ];
}

function anexosDisparo(array $disparo): array
{
    $anexos = [];
    foreach (json_decode((string) $disparo['anexos'], true) ?: [] as $a) {
        $caminho = BASE_PATH . EMAIL_PASTA_DISPAROS . '/' . (int) $disparo['id'] . '/' . basename((string) $a['arquivo']);
        if (is_file($caminho)) {
            $anexos[] = ['nome' => $a['nome'], 'tipo' => $a['tipo'], 'conteudo' => file_get_contents($caminho)];
        }
    }
    return $anexos;
}

/**
 * Arquivos enviados num formulário (campo múltiplo) em anexos, conferindo
 * o limite total. Retorna [anexos, erro].
 */
function anexosDoFormulario(array $arquivos, int $maxMb): array
{
    $anexos = [];
    $total = 0;
    foreach ((array) ($arquivos['name'] ?? []) as $i => $nome) {
        $erro = $arquivos['error'][$i] ?? UPLOAD_ERR_NO_FILE;
        if ($erro === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($erro !== UPLOAD_ERR_OK) {
            return [[], 'O anexo "' . $nome . '" não chegou (grande demais para o servidor?).'];
        }
        $total += (int) $arquivos['size'][$i];
        if ($total > $maxMb * 1024 * 1024) {
            return [[], "Os anexos passam de {$maxMb} MB no total."];
        }
        $tipo = (new finfo(FILEINFO_MIME_TYPE))->file($arquivos['tmp_name'][$i]) ?: 'application/octet-stream';
        // Não deixa o sistema espalhar arquivo perigoso (programa, macro, vírus...).
        if ($motivo = motivoArquivoPerigoso((string) $nome, (string) file_get_contents($arquivos['tmp_name'][$i]))) {
            return [[], 'O anexo "' . $nome . '" foi bloqueado por segurança: ' . $motivo];
        }
        $anexos[] = ['nome' => trim(preg_replace('/[\x00-\x1f\/\\\\]+/', '_', basename((string) $nome))) ?: 'anexo', 'tipo' => $tipo, 'conteudo' => file_get_contents($arquivos['tmp_name'][$i])];
    }
    return [$anexos, null];
}

// ---------------------------------------------------------------------
// Cadastro da caixa (Usuários › usuário e E-mail › Minha caixa)
// ---------------------------------------------------------------------

/** Lê e grava os campos da caixa do formulário. Retorna erros (vazio = salvou). */
function salvarCaixaEmailUsuario(PDO $pdo, int $usuarioId, array $post): array
{
    $email = strtolower(trim((string) ($post['email_caixa'] ?? '')));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['O e-mail da caixa é inválido.'];
    }
    // Imagem colada na assinatura (logo) vira arquivo; no envio vai embutida.
    $assinatura = limparHtmlContrato(salvarImagensColadasAviso((string) ($post['email_assinatura'] ?? '')), true);
    $campos = ['email_caixa = ?', 'email_remetente_nome = ?', 'email_assinatura = ?'];
    $valores = [$email ?: null, trim((string) ($post['email_remetente_nome'] ?? '')) ?: null, trim(strip_tags($assinatura, '<img>')) !== '' ? $assinatura : null];
    if (!empty($post['email_caixa_remover_senha'])) {
        $campos[] = 'email_caixa_senha_cripto = NULL';
    } elseif ((string) ($post['email_caixa_senha'] ?? '') !== '') {
        $campos[] = 'email_caixa_senha_cripto = ?';
        $valores[] = criptografar((string) $post['email_caixa_senha']);
    }
    $valores[] = $usuarioId;
    $pdo->prepare('UPDATE usuarios SET ' . implode(', ', $campos) . ' WHERE id = ?')->execute($valores);
    unset($_SESSION['email_pastas_' . $usuarioId], $_SESSION['email_nao_lidas_' . $usuarioId]);
    return [];
}
