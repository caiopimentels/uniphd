<?php

// Atendimento por WhatsApp com IA.
//
// Caminho de uma mensagem: o aluno escreve → a Evolution API (instalada no
// servidor, conectada ao número por QR Code) chama /webhook/whatsapp.php →
// a mensagem é gravada na conversa → se a conversa está com a IA, ela
// responde (Groq ou Gemini, planos gratuitos) usando só o que está no
// sistema sobre cursos e turmas abertas. Quando não sabe, ou a pessoa pede
// uma pessoa, ela passa a conversa para um atendente (Secretaria ›
// WhatsApp) e para de responder ali.
//
// Nesta fase a IA não recebe nenhum dado pessoal ou financeiro: só o
// catálogo de cursos e o histórico da própria conversa.

const WHATSAPP_MARCA_ATENDENTE = '[ATENDENTE]';
const WHATSAPP_ESPERA_SEGUNDOS = 8; // junta mensagens picadas antes de responder

function whatsappConfig(PDO $pdo): array
{
    $cfg = $pdo->query('SELECT * FROM whatsapp_config WHERE id = 1')->fetch() ?: [];
    foreach (['api_chave', 'groq_chave', 'gemini_chave'] as $campo) {
        $cfg[$campo] = descriptografar($cfg[$campo] ?? null);
    }
    return $cfg;
}

function whatsappConfigurado(array $cfg): bool
{
    return !empty($cfg['api_url']) && !empty($cfg['api_chave']) && !empty($cfg['instancia']);
}

// "5577981118760@s.whatsapp.net" → "5577981118760".
function numeroDoJidWhatsapp(string $jid): string
{
    return preg_replace('/\D/', '', explode('@', $jid)[0]);
}

function numeroWhatsappFormatado(string $numero): string
{
    if (preg_match('/^55(\d{2})(\d{4,5})(\d{4})$/', $numero, $m)) {
        return '(' . $m[1] . ') ' . $m[2] . '-' . $m[3];
    }
    return '+' . $numero;
}

// Aluno do cadastro com este telefone (com ou sem o 9 do celular).
function alunoDoNumeroWhatsapp(PDO $pdo, string $numero): ?int
{
    $local = str_starts_with($numero, '55') ? substr($numero, 2) : $numero;
    if (strlen($local) < 10) {
        return null;
    }
    $ddd = substr($local, 0, 2);
    $resto = substr($local, 2);
    $opcoes = array_unique([$ddd . $resto, $ddd . '9' . $resto, strlen($resto) === 9 ? $ddd . substr($resto, 1) : $ddd . $resto]);
    $marcadores = implode(',', array_fill(0, count($opcoes), '?'));
    $stmt = $pdo->prepare("SELECT id FROM alunos WHERE telefone IN ($marcadores) ORDER BY ativo DESC, id DESC LIMIT 1");
    $stmt->execute(array_values($opcoes));
    return ((int) $stmt->fetchColumn()) ?: null;
}

function conversaWhatsapp(PDO $pdo, string $numero, ?string $nome): array
{
    $stmt = $pdo->prepare('SELECT * FROM whatsapp_conversas WHERE numero = ?');
    $stmt->execute([$numero]);
    $conversa = $stmt->fetch();
    if (!$conversa) {
        $pdo->prepare('INSERT IGNORE INTO whatsapp_conversas (numero, nome, aluno_id) VALUES (?, ?, ?)')
            ->execute([$numero, $nome ? mb_substr($nome, 0, 150) : null, alunoDoNumeroWhatsapp($pdo, $numero)]);
        $stmt->execute([$numero]);
        $conversa = $stmt->fetch();
    } elseif ($nome && !$conversa['nome']) {
        $pdo->prepare('UPDATE whatsapp_conversas SET nome = ? WHERE id = ?')->execute([mb_substr($nome, 0, 150), $conversa['id']]);
        $conversa['nome'] = $nome;
    }
    return $conversa;
}

/** Grava uma mensagem; devolve o id (ou null se já existia — mesmo id do WhatsApp). */
function gravarMensagemWhatsapp(PDO $pdo, int $conversaId, string $autor, ?string $texto, string $tipo = 'texto', ?string $idExterno = null, ?string $erro = null): ?int
{
    $usuarioId = $autor === 'atendente' && function_exists('usuarioLogado') && usuarioLogado() ? usuarioAtualId() : null;
    $nome = $usuarioId ? usuarioAtualNome() : null;
    $stmt = $pdo->prepare('INSERT IGNORE INTO whatsapp_mensagens (conversa_id, autor, usuario_id, autor_nome, texto, tipo, id_externo, erro) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$conversaId, $autor, $usuarioId, $nome, $texto, $tipo, $idExterno, $erro ? mb_substr($erro, 0, 255) : null]);
    if (!$stmt->rowCount()) {
        return null;
    }
    $id = (int) $pdo->lastInsertId();
    $pdo->prepare('UPDATE whatsapp_conversas SET ultima_mensagem_em = NOW()' . ($autor === 'cliente' ? ', nao_lidas = nao_lidas + 1' : '') . ' WHERE id = ?')->execute([$conversaId]);
    return $id;
}

// ---------------------------------------------------------------------
// Evolution API
// ---------------------------------------------------------------------

/** Chamada à Evolution API. Retorna ['ok' => bool, 'status' => int, 'dados' => mixed, 'erro' => string]. */
function chamarEvolutionApi(array $cfg, string $metodo, string $caminho, ?array $corpo = null, int $tempo = 20): array
{
    $curl = curl_init(rtrim((string) $cfg['api_url'], '/') . $caminho);
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => $metodo,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $tempo,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_HTTPHEADER => ['apikey: ' . $cfg['api_chave'], 'Content-Type: application/json'],
    ]);
    if ($corpo !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($corpo, JSON_UNESCAPED_UNICODE));
    }
    $resposta = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $erroCurl = curl_error($curl);
    curl_close($curl);
    $dados = is_string($resposta) ? json_decode($resposta, true) : null;
    if ($resposta === false) {
        return ['ok' => false, 'status' => 0, 'dados' => null, 'erro' => 'Não foi possível falar com a Evolution API: ' . $erroCurl];
    }
    $ok = $status >= 200 && $status < 300;
    $mensagem = is_array($dados) ? ($dados['response']['message'] ?? $dados['message'] ?? $dados['error'] ?? '') : '';
    if (is_array($mensagem)) {
        $mensagem = json_encode($mensagem, JSON_UNESCAPED_UNICODE);
    }
    return ['ok' => $ok, 'status' => $status, 'dados' => $dados ?? $resposta,
        'erro' => $ok ? '' : 'Evolution API respondeu ' . $status . ($mensagem !== '' ? ': ' . mb_strimwidth((string) $mensagem, 0, 200, '…') : '')];
}

/** Envia um texto. Retorna ['ok' => bool, 'id' => ?string, 'erro' => string]. */
function enviarTextoWhatsapp(array $cfg, string $numero, string $texto): array
{
    if (!whatsappConfigurado($cfg)) {
        return ['ok' => false, 'id' => null, 'erro' => 'O WhatsApp não está configurado.'];
    }
    $r = chamarEvolutionApi($cfg, 'POST', '/message/sendText/' . rawurlencode($cfg['instancia']), ['number' => $numero, 'text' => $texto]);
    return ['ok' => $r['ok'], 'id' => is_array($r['dados']) ? ($r['dados']['key']['id'] ?? null) : null, 'erro' => $r['erro']];
}

// Situação da conexão do número: open (conectado), connecting, close.
function estadoConexaoWhatsapp(array $cfg): array
{
    if (!whatsappConfigurado($cfg)) {
        return ['estado' => 'sem_config', 'erro' => ''];
    }
    $r = chamarEvolutionApi($cfg, 'GET', '/instance/connectionState/' . rawurlencode($cfg['instancia']), null, 10);
    if (!$r['ok']) {
        return ['estado' => $r['status'] === 404 ? 'sem_instancia' : 'erro', 'erro' => $r['erro']];
    }
    return ['estado' => (string) ($r['dados']['instance']['state'] ?? $r['dados']['state'] ?? 'desconhecido'), 'erro' => ''];
}

// Endereço que a Evolution API chama. No teste local (Evolution no Docker)
// "localhost" não é o computador: usa-se o endereço configurado.
function urlWebhookWhatsapp(array $cfg): string
{
    $caminho = '/webhook/whatsapp.php?token=' . $cfg['webhook_token'];
    $base = rtrim(trim((string) ($cfg['webhook_base'] ?? '')), '/');
    return $base !== '' ? $base . $caminho : urlAbsoluta($caminho);
}

// Modo teste: só os números listados recebem resposta automática (aceita o
// número com ou sem 55, DDD e o 9 do celular).
function numeroLiberadoWhatsapp(array $cfg, string $numero): bool
{
    if (empty($cfg['modo_teste'])) {
        return true;
    }
    $final = fn (string $n) => substr(preg_replace('/\D/', '', $n), -8);
    foreach (preg_split('/[\r\n,;]+/', (string) ($cfg['numeros_teste'] ?? '')) as $liberado) {
        if (strlen(preg_replace('/\D/', '', $liberado)) >= 8 && $final($liberado) === $final($numero)) {
            return true;
        }
    }
    return false;
}

/**
 * Conecta o número: cria a instância na Evolution API (se ainda não existe),
 * aponta o webhook para o sistema e devolve o QR Code para ler no celular.
 * Retorna ['ok', 'mensagem', 'qrcode' => ?string (data:image/png;base64,...)].
 */
function conectarWhatsapp(array $cfg): array
{
    $webhook = ['enabled' => true, 'url' => urlWebhookWhatsapp($cfg), 'byEvents' => false, 'base64' => true, 'events' => ['MESSAGES_UPSERT']];
    $estado = estadoConexaoWhatsapp($cfg);
    if ($estado['estado'] === 'sem_instancia') {
        $criar = chamarEvolutionApi($cfg, 'POST', '/instance/create', [
            'instanceName' => $cfg['instancia'], 'integration' => 'WHATSAPP-BAILEYS', 'qrcode' => true, 'webhook' => $webhook,
        ]);
        if (!$criar['ok']) {
            return ['ok' => false, 'mensagem' => $criar['erro'], 'qrcode' => null];
        }
    } elseif ($estado['estado'] === 'erro') {
        return ['ok' => false, 'mensagem' => $estado['erro'], 'qrcode' => null];
    }
    $hook = chamarEvolutionApi($cfg, 'POST', '/webhook/set/' . rawurlencode($cfg['instancia']), ['webhook' => $webhook]);
    if (!$hook['ok']) {
        return ['ok' => false, 'mensagem' => 'Não foi possível configurar o webhook: ' . $hook['erro'], 'qrcode' => null];
    }
    if ($estado['estado'] === 'open') {
        return ['ok' => true, 'mensagem' => 'O número já está conectado. O webhook foi atualizado.', 'qrcode' => null];
    }
    $qr = chamarEvolutionApi($cfg, 'GET', '/instance/connect/' . rawurlencode($cfg['instancia']));
    $imagem = is_array($qr['dados']) ? ($qr['dados']['base64'] ?? $qr['dados']['qrcode']['base64'] ?? null) : null;
    return $imagem
        ? ['ok' => true, 'mensagem' => 'Abra o WhatsApp no celular › Aparelhos conectados › Conectar um aparelho, e leia o QR Code.', 'qrcode' => $imagem]
        : ['ok' => false, 'mensagem' => 'A Evolution API não devolveu o QR Code. ' . $qr['erro'], 'qrcode' => null];
}

// ---------------------------------------------------------------------
// IA (Groq / Gemini — os dois falam o formato da OpenAI)
// ---------------------------------------------------------------------

function provedoresIaWhatsapp(): array
{
    return [
        'groq' => ['nome' => 'Groq', 'url' => 'https://api.groq.com/openai/v1/chat/completions', 'site' => 'https://console.groq.com/keys'],
        'gemini' => ['nome' => 'Google Gemini', 'url' => 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions', 'site' => 'https://aistudio.google.com/apikey'],
    ];
}

/** Uma chamada de chat. Retorna ['ok' => bool, 'texto' => string, 'erro' => string]. */
function chamarIaChat(string $provedor, string $chave, string $modelo, array $mensagens): array
{
    $info = provedoresIaWhatsapp()[$provedor] ?? null;
    if (!$info || $chave === '') {
        return ['ok' => false, 'texto' => '', 'erro' => 'IA ' . $provedor . ' sem chave configurada.'];
    }
    $curl = curl_init($info['url']);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $chave, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['model' => $modelo, 'messages' => $mensagens, 'temperature' => 0.3, 'max_tokens' => 600], JSON_UNESCAPED_UNICODE),
    ]);
    $resposta = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $erroCurl = curl_error($curl);
    curl_close($curl);
    $dados = is_string($resposta) ? json_decode($resposta, true) : null;
    $texto = trim((string) ($dados['choices'][0]['message']['content'] ?? ''));
    if ($status >= 200 && $status < 300 && $texto !== '') {
        return ['ok' => true, 'texto' => $texto, 'erro' => '', 'tokens' => (int) ($dados['usage']['total_tokens'] ?? 0)];
    }
    $detalhe = $resposta === false ? $erroCurl : (string) ($dados['error']['message'] ?? $dados[0]['error']['message'] ?? mb_substr((string) $resposta, 0, 200));
    return ['ok' => false, 'texto' => '', 'erro' => $info['nome'] . ' ' . $modelo . ' (' . $status . '): ' . $detalhe, 'status' => $status];
}

// Lista de IAs (quantas quiser), na ordem em que são tentadas. Cada modelo tem o seu
// próprio limite gratuito: o primeiro é o de melhor resposta, os últimos
// são os de limite maior. Os nomes mudam com o tempo: "Testar todas" na
// configuração mostra se algum saiu do ar.
// Os do Google terminados em "-latest" sempre apontam para o modelo mais novo.
const WHATSAPP_MODELOS_PADRAO = [
    ['provedor' => 'groq', 'modelo' => 'openai/gpt-oss-120b'],
    ['provedor' => 'gemini', 'modelo' => 'gemini-flash-latest'],
    ['provedor' => 'groq', 'modelo' => 'qwen/qwen3.8-27b'],
    ['provedor' => 'gemini', 'modelo' => 'gemini-flash-lite-latest'],
    ['provedor' => 'groq', 'modelo' => 'openai/gpt-oss-20b'],
];

function modelosIaWhatsapp(array $cfg): array
{
    $lista = json_decode((string) ($cfg['ia_modelos'] ?? ''), true);
    $lista = is_array($lista) ? $lista : WHATSAPP_MODELOS_PADRAO;
    return array_values(array_filter($lista, fn ($m) => isset(provedoresIaWhatsapp()[$m['provedor'] ?? '']) && trim((string) ($m['modelo'] ?? '')) !== ''));
}

// Pausas vigentes: ["groq|modelo" => ['ate' => ..., 'motivo' => ...]].
function pausasIaWhatsapp(PDO $pdo): array
{
    $pausas = [];
    foreach ($pdo->query('SELECT * FROM whatsapp_ia_pausa WHERE ate > NOW()') as $p) {
        $pausas[$p['provedor'] . '|' . $p['modelo']] = $p;
    }
    return $pausas;
}

// Modelo que falhou fica de fora por um tempo: limite do dia → até amanhã;
// limite por minuto ou instabilidade → 1 minuto; modelo inexistente ou chave
// recusada → 1 hora (dá tempo de corrigir na configuração).
function pausarModeloIa(PDO $pdo, string $provedor, string $modelo, int $status, string $erro): void
{
    $diario = preg_match('/per day|daily|\bRPD\b|\bTPD\b|quota|exhausted/i', $erro);
    $ate = match (true) {
        $status === 429 && $diario => date('Y-m-d 00:05:00', strtotime('+1 day')),
        in_array($status, [400, 401, 403, 404], true) => date('Y-m-d H:i:s', strtotime('+1 hour')),
        default => date('Y-m-d H:i:s', strtotime('+1 minute')),
    };
    $pdo->prepare('INSERT INTO whatsapp_ia_pausa (provedor, modelo, ate, motivo) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE ate = VALUES(ate), motivo = VALUES(motivo)')
        ->execute([$provedor, $modelo, $ate, mb_strimwidth($erro, 0, 255, '…')]);
}

// Soma o consumo do dia (tokens, respostas e falhas) de uma IA.
function registrarUsoIaWhatsapp(PDO $pdo, string $provedor, string $modelo, int $tokens, bool $falhou): void
{
    try {
        $pdo->prepare('INSERT INTO whatsapp_uso_ia (data, provedor, modelo, respostas, tokens, falhas) VALUES (CURDATE(), ?, ?, ?, ?, ?)
                       ON DUPLICATE KEY UPDATE respostas = respostas + VALUES(respostas), tokens = tokens + VALUES(tokens), falhas = falhas + VALUES(falhas)')
            ->execute([$provedor, $modelo, $falhou ? 0 : 1, $tokens, $falhou ? 1 : 0]);
    } catch (Throwable $e) {
        error_log('[WhatsApp uso IA] ' . $e->getMessage());
    }
}

// Groq também transcreve áudio de graça (Whisper). Retorna o texto ou null.
function transcreverAudioWhatsapp(array $cfg, string $base64, string $mime): ?string
{
    if (empty($cfg['groq_chave']) || $base64 === '') {
        return null;
    }
    $arquivo = tempnam(sys_get_temp_dir(), 'audio');
    file_put_contents($arquivo, base64_decode($base64));
    $curl = curl_init('https://api.groq.com/openai/v1/audio/transcriptions');
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $cfg['groq_chave']],
        CURLOPT_POSTFIELDS => ['model' => 'whisper-large-v3-turbo', 'language' => 'pt', 'response_format' => 'json',
            'file' => new CURLFile($arquivo, $mime ?: 'audio/ogg', 'audio.ogg')],
    ]);
    $resposta = curl_exec($curl);
    curl_close($curl);
    @unlink($arquivo);
    $texto = trim((string) (json_decode((string) $resposta, true)['text'] ?? ''));
    return $texto !== '' ? $texto : null;
}

/**
 * O que a IA sabe: a instituição e as turmas com inscrição aberta no portal
 * (curso, datas, horários, valores, vagas e link). Nada de dado pessoal.
 */
function contextoCursosWhatsapp(PDO $pdo): string
{
    $config = buscarConfiguracoes($pdo);
    $linhas = [];
    $linhas[] = 'Instituição: ' . ($config['empresa_nome_fantasia'] ?? '') . ($config['nome_sistema'] ? ' (' . $config['nome_sistema'] . ')' : '');
    $endereco = trim(implode(', ', array_filter([$config['empresa_logradouro'] ?? '', $config['empresa_numero'] ?? '', $config['empresa_bairro'] ?? '', $config['empresa_cidade'] ?? '', $config['empresa_uf'] ?? ''])));
    if ($endereco !== '') {
        $linhas[] = 'Endereço: ' . $endereco;
    }
    $contato = array_filter([$config['empresa_telefone'] ?? '', $config['empresa_email'] ?? '']);
    if ($contato) {
        $linhas[] = 'Contato da secretaria: ' . implode(' · ', $contato);
    }
    $linhas[] = 'Portal do aluno (inscrições online): ' . urlAbsoluta('/portal/turmas.php');
    $linhas[] = '';
    $turmas = turmasAbertasPortal($pdo);
    if (!$turmas) {
        $linhas[] = 'No momento não há turmas com inscrição aberta.';
    }
    foreach ($turmas as $t) {
        $modalidades = modalidadesPortal($t);
        $valores = [];
        if (in_array('prazo', $modalidades, true)) {
            $valores[] = valorTotalTurma($t) > 0
                ? numParcelasPortal($t) . 'x de ' . formatMoney(mensalidadeDoTotal(valorTotalTurma($t), numParcelasPortal($t))) . ' (total ' . formatMoney(valorTotalTurma($t)) . ')'
                : 'gratuito';
        }
        if (in_array('avista', $modalidades, true) && valorAvistaTurma($t) !== null) {
            $valores[] = 'à vista ' . formatMoney(valorAvistaTurma($t));
        }
        $modulos = array_column(modulosDoCurso($pdo, (int) $t['curso_id']), 'nome');
        $linhas[] = '## ' . $t['curso_nome'] . ' — turma ' . $t['nome'];
        $linhas[] = '- Início: ' . ($t['data_inicio'] ? formatDate($t['data_inicio']) : 'a definir (começa quando atingir o mínimo de alunos)') . ' · duração: ' . textoDuracaoTurma($t);
        if ($agenda = textoAgendaTurma($t)) {
            $linhas[] = '- Dias e horário: ' . $agenda;
        }
        if ($t['local']) {
            $linhas[] = '- Local: ' . $t['local'];
        }
        if ($t['carga_horaria']) {
            $linhas[] = '- Carga horária: ' . (int) $t['carga_horaria'] . ' horas';
        }
        $linhas[] = '- Investimento: ' . ($valores ? implode(' ou ', $valores) : 'consultar a secretaria')
            . ((float) $t['portal_taxa_matricula'] > 0 ? ' + taxa de matrícula de ' . formatMoney($t['portal_taxa_matricula']) : '');
        $linhas[] = '- Vagas restantes: ' . (int) $t['vagas_site'];
        if ($t['portal_inscricoes_ate']) {
            $linhas[] = '- Inscrições até: ' . formatDate($t['portal_inscricoes_ate']);
        }
        if ($modulos) {
            $linhas[] = '- Conteúdo (módulos): ' . implode('; ', $modulos);
        }
        $descricao = trim((string) ($t['portal_descricao'] ?: $t['curso_descricao']));
        if ($descricao !== '') {
            $linhas[] = '- Sobre o curso: ' . mb_strimwidth(preg_replace('/\s+/', ' ', $descricao), 0, 900, '…');
        }
        $linhas[] = '- Link para se inscrever: ' . urlAbsoluta('/portal/turma.php?id=' . (int) $t['id']);
        $linhas[] = '';
    }
    return implode("\n", $linhas);
}

function promptSistemaWhatsapp(PDO $pdo, array $cfg): string
{
    $hoje = dataPorExtenso(date('Y-m-d'));
    $marca = WHATSAPP_MARCA_ATENDENTE;
    $contexto = contextoCursosWhatsapp($pdo);
    return <<<TXT
Você é o atendente virtual da instituição, conversando pelo WhatsApp com pessoas interessadas nos cursos. Hoje é {$hoje}.

Como responder:
- Português do Brasil, simpático, direto e curto (estilo WhatsApp: no máximo uns 5 parágrafos curtos). Pode usar poucos emojis.
- Use SOMENTE as informações abaixo. Nunca invente cursos, preços, datas, vagas, descontos, formas de pagamento ou regras. Se a informação não estiver abaixo, diga que vai verificar com a equipe.
- Para se inscrever, mande o link da turma. Se a pessoa quiser saber de todos os cursos, resuma e ofereça detalhes.
- Não peça CPF, documentos nem dados pessoais.
- Se você já respondeu antes nesta conversa, não se apresente nem cumprimente de novo: continue a conversa de onde parou. Responda tudo o que a pessoa escreveu numa resposta só, sem repetir o que já disse.
- Responda exatamente {$marca} (só isso, sem mais nada) quando: a pessoa pedir para falar com uma pessoa/atendente; o assunto for pagamento, boleto, certificado, documentos, notas, faltas, reclamação ou qualquer coisa sobre a matrícula/situação dela; ou você não tiver a resposta nas informações abaixo.

Informações dos cursos e da instituição:
{$contexto}
TXT
        . (trim((string) ($cfg['ia_instrucoes'] ?? '')) !== '' ? "\n\nOrientações e informações da instituição (siga e use também):\n" . trim((string) $cfg['ia_instrucoes']) : '')
        . (trim((string) ($cfg['ia_proibido'] ?? '')) !== '' ? "\n\nAssuntos que você NÃO deve responder (nesses casos responda só {$marca}):\n" . trim((string) $cfg['ia_proibido']) : '');
}

/**
 * Gera a resposta da IA para a conversa (o histórico recente vai junto).
 * Tenta o provedor escolhido e, se falhar (limite do plano gratuito, fora do
 * ar), o outro. Retorna ['ok', 'texto', 'transferir' => bool, 'erro'].
 */
function respostaIaWhatsapp(PDO $pdo, array $cfg, int $conversaId, ?string $perguntaTeste = null): array
{
    $mensagens = [['role' => 'system', 'content' => promptSistemaWhatsapp($pdo, $cfg)]];
    if ($perguntaTeste !== null) {
        $mensagens[] = ['role' => 'user', 'content' => $perguntaTeste];
    } else {
        // Só o recente: as últimas 10 mensagens das últimas 24 horas (gasta menos tokens).
        $stmt = $pdo->prepare("SELECT autor, texto FROM whatsapp_mensagens WHERE conversa_id = ? AND texto IS NOT NULL AND autor <> 'sistema'
                               AND criado_em >= NOW() - INTERVAL 24 HOUR ORDER BY id DESC LIMIT 10");
        $stmt->execute([$conversaId]);
        foreach (array_reverse($stmt->fetchAll()) as $m) {
            $papel = $m['autor'] === 'cliente' ? 'user' : 'assistant';
            // Mensagens seguidas do mesmo lado viram uma só.
            if ($mensagens && end($mensagens)['role'] === $papel) {
                $mensagens[array_key_last($mensagens)]['content'] .= "\n" . $m['texto'];
            } else {
                $mensagens[] = ['role' => $papel, 'content' => (string) $m['texto']];
            }
        }
    }
    // Desce pela lista: pula o que está sem chave ou pausado (bateu o limite).
    $pausas = pausasIaWhatsapp($pdo);
    $erros = [];
    foreach (modelosIaWhatsapp($cfg) as $item) {
        ['provedor' => $provedor, 'modelo' => $modelo] = $item;
        $chave = (string) ($cfg[$provedor . '_chave'] ?? '');
        if ($chave === '' || isset($pausas[$provedor . '|' . $modelo])) {
            continue;
        }
        $r = chamarIaChat($provedor, $chave, $modelo, $mensagens);
        registrarUsoIaWhatsapp($pdo, $provedor, $modelo, (int) ($r['tokens'] ?? 0), !$r['ok']);
        if ($r['ok']) {
            $transferir = str_contains($r['texto'], WHATSAPP_MARCA_ATENDENTE);
            return ['ok' => true, 'texto' => trim(str_replace(WHATSAPP_MARCA_ATENDENTE, '', $r['texto'])), 'transferir' => $transferir, 'erro' => '',
                'provedor' => $provedor, 'modelo' => $modelo];
        }
        pausarModeloIa($pdo, $provedor, $modelo, (int) ($r['status'] ?? 0), $r['erro']);
        $erros[] = $r['erro'];
    }
    return ['ok' => false, 'texto' => '', 'transferir' => true, 'erro' => $erros ? implode(' | ', $erros) : 'Nenhuma IA configurada.'];
}

// Números que recebem o aviso "cliente precisa de ajuda" (só dígitos, com 55).
function numerosAvisoWhatsapp(array $cfg): array
{
    $numeros = [];
    foreach (preg_split('/[\r\n,;]+/', (string) ($cfg['aviso_numeros'] ?? '')) as $n) {
        $n = preg_replace('/\D/', '', $n);
        if (strlen($n) >= 10) {
            $numeros[] = strlen($n) <= 11 ? '55' . $n : $n;
        }
    }
    return array_values(array_unique($numeros));
}

// O número é um dos de aviso da equipe? (a IA não conversa com eles)
function ehNumeroAvisoWhatsapp(array $cfg, string $numero): bool
{
    $final = fn (string $n) => substr($n, -8);
    foreach (numerosAvisoWhatsapp($cfg) as $aviso) {
        if ($final($aviso) === $final($numero)) {
            return true;
        }
    }
    return false;
}

/**
 * Manda para os números da equipe: "Cliente X precisa de ajuda". No máximo
 * um aviso por conversa a cada 30 minutos. Retorna quantos foram enviados.
 */
function avisarEquipeWhatsapp(PDO $pdo, array $cfg, array $conversa, string $motivo, bool $forcar = false): int
{
    $numeros = numerosAvisoWhatsapp($cfg);
    if (!$numeros || (!$forcar && $conversa['ultimo_aviso_em'] && strtotime($conversa['ultimo_aviso_em']) > time() - 1800)) {
        return 0;
    }
    $stmt = $pdo->prepare("SELECT texto FROM whatsapp_mensagens WHERE conversa_id = ? AND autor = 'cliente' ORDER BY id DESC LIMIT 1");
    $stmt->execute([$conversa['id']]);
    $ultima = (string) $stmt->fetchColumn();
    $texto = "🔔 *Atendimento pelo WhatsApp*\n"
        . 'Cliente ' . ($conversa['nome'] ? $conversa['nome'] . ' ' : '') . numeroWhatsappFormatado($conversa['numero']) . " precisa de ajuda da equipe.\n"
        . ($ultima !== '' ? 'Última mensagem: "' . mb_strimwidth($ultima, 0, 200, '…') . "\"\n" : '')
        . 'Motivo: ' . $motivo . "\n\nResponda pelo WhatsApp da escola ou em Secretaria › WhatsApp no sistema.";
    $enviados = 0;
    foreach ($numeros as $numero) {
        $enviados += enviarTextoWhatsapp($cfg, $numero, $texto)['ok'] ? 1 : 0;
    }
    $pdo->prepare('UPDATE whatsapp_conversas SET ultimo_aviso_em = NOW() WHERE id = ?')->execute([$conversa['id']]);
    gravarMensagemWhatsapp($pdo, (int) $conversa['id'], 'sistema', 'Equipe avisada por WhatsApp (' . $enviados . ' de ' . count($numeros) . ' número(s))');
    return $enviados;
}

/**
 * A IA não sabe responder: avisa o cliente, a equipe (sino e, se houver,
 * os números de aviso por WhatsApp) e, conforme a configuração, para de
 * responder nessa conversa ou continua com as outras dúvidas até alguém da
 * equipe assumir. Já aguardando a equipe: não avisa a equipe de novo.
 */
function transferirConversaWhatsapp(PDO $pdo, array $cfg, array $conversa, string $motivo): void
{
    $envio = enviarTextoWhatsapp($cfg, $conversa['numero'], $cfg['mensagem_transferencia']);
    gravarMensagemWhatsapp($pdo, (int) $conversa['id'], 'ia', $cfg['mensagem_transferencia'], 'texto', $envio['id'], $envio['ok'] ? null : $envio['erro']);
    if (!$conversa['aguardando']) {
        criarNotificacao($pdo, 'whatsapp', 'WhatsApp: atendimento pedido', ($conversa['nome'] ?: numeroWhatsappFormatado($conversa['numero'])) . ' precisa de um atendente.',
            '/whatsapp/conversas.php?id=' . (int) $conversa['id'], (int) $conversa['id']);
    }
    $continua = !empty($cfg['ia_continua_apos_aviso']);
    gravarMensagemWhatsapp($pdo, (int) $conversa['id'], 'sistema', 'Equipe chamada (' . $motivo . ')' . ($continua ? ' — a IA segue respondendo as outras dúvidas' : ' — a IA parou de responder nesta conversa'));
    $pdo->prepare('UPDATE whatsapp_conversas SET modo = ?, aguardando = 1 WHERE id = ?')->execute([$continua ? 'ia' : 'humano', $conversa['id']]);
    avisarEquipeWhatsapp($pdo, $cfg, $conversa, $motivo);
}

/**
 * Depois de gravar uma mensagem do cliente: espera alguns segundos (a
 * pessoa costuma mandar várias seguidas), e só a última da sequência gera
 * a resposta. Conversa com atendente (modo humano) ou IA desligada: nada.
 */
function responderConversaWhatsapp(PDO $pdo, int $conversaId, int $mensagemId): void
{
    sleep(WHATSAPP_ESPERA_SEGUNDOS);
    // Ainda é a última mensagem do cliente? Se chegou outra, a resposta dela cobre tudo.
    $continuaUltima = function () use ($pdo, $conversaId, $mensagemId): bool {
        $stmt = $pdo->prepare("SELECT MAX(id) FROM whatsapp_mensagens WHERE conversa_id = ? AND autor = 'cliente'");
        $stmt->execute([$conversaId]);
        return (int) $stmt->fetchColumn() === $mensagemId;
    };
    if (!$continuaUltima()) {
        return;
    }
    $cfg = whatsappConfig($pdo);
    $stmt = $pdo->prepare('SELECT * FROM whatsapp_conversas WHERE id = ?');
    $stmt->execute([$conversaId]);
    $conversa = $stmt->fetch();
    if (!$conversa || !$cfg['ia_ativa'] || $conversa['modo'] !== 'ia' || !numeroLiberadoWhatsapp($cfg, $conversa['numero']) || ehNumeroAvisoWhatsapp($cfg, $conversa['numero'])) {
        return;
    }
    $resposta = respostaIaWhatsapp($pdo, $cfg, $conversaId);
    // A pessoa mandou outra mensagem enquanto a IA escrevia: descarta esta
    // resposta (a da mensagem nova já vai considerar tudo) — evita resposta dobrada.
    if (!$continuaUltima()) {
        return;
    }
    if (!$resposta['ok']) {
        error_log('[WhatsApp IA] ' . $resposta['erro']);
        transferirConversaWhatsapp($pdo, $cfg, $conversa, 'a IA não respondeu: ' . mb_strimwidth($resposta['erro'], 0, 150, '…'));
        return;
    }
    if ($resposta['texto'] !== '') {
        $envio = enviarTextoWhatsapp($cfg, $conversa['numero'], $resposta['texto']);
        gravarMensagemWhatsapp($pdo, $conversaId, 'ia', $resposta['texto'], 'texto', $envio['id'], $envio['ok'] ? null : $envio['erro']);
    }
    if ($resposta['transferir']) {
        transferirConversaWhatsapp($pdo, $cfg, $conversa, 'a IA não soube responder ou a pessoa pediu um atendente');
    }
}

function conversasAguardandoWhatsapp(PDO $pdo): int
{
    try {
        return (int) $pdo->query('SELECT COUNT(*) FROM whatsapp_conversas WHERE aguardando = 1')->fetchColumn();
    } catch (Throwable $e) {
        return 0; // tabela ainda não criada (migração pendente)
    }
}
