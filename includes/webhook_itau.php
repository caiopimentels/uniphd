<?php

// Webhook de aviso de pagamento do Itaú (API Boletos v3, notificações de
// baixa operacional e efetiva). O fluxo:
//
//   1. O sistema gera um client_id/client_secret só do webhook e cadastra no
//      Itaú (POST /notificacoes_boletos) a URL do webhook e a URL de token.
//   2. Quando um boleto é pago, o Itaú pede um token em
//      /webhook/itau/token.php (OAuth 2.0 client_credentials, com aquele
//      client_id/secret) e chama /webhook/itau/boletos.php com ele.
//   3. O webhook grava o aviso cru em webhook_eventos e dá baixa na parcela.
//
// Tipos: BAIXA_OPERACIONAL = o pagamento foi feito (o dinheiro ainda não
// caiu); BAIXA_EFETIVA = liquidado, crédito na conta. A parcela é baixada no
// primeiro dos dois que chegar; o segundo só fica registrado.

const ITAU_URL_NOTIFICACOES = [
    'producao' => 'https://boletos.cloud.itau.com.br/boletos/v3',
    'sandbox' => 'https://sandbox.devportal.itau.com.br/itau-ep9-gtw-boletos-boletos-v3-ext-aws/v1',
];

const ITAU_TIPOS_NOTIFICACAO = ['BAIXA_OPERACIONAL', 'BAIXA_EFETIVA'];

// Scope que o Itaú pede ao nosso endpoint de token ao avisar um pagamento
// (o mesmo do exemplo da documentação).
const ITAU_WEBHOOK_SCOPE = 'boletos-notificacao';

// Validade do token que o sistema entrega ao Itaú.
const WEBHOOK_TOKEN_SEGUNDOS = 3600;

function urlApiNotificacoesItau(array $conta): string
{
    return ITAU_URL_NOTIFICACOES[$conta['api_ambiente'] === 'producao' ? 'producao' : 'sandbox'];
}

/**
 * URLs públicas que o Itaú vai chamar, a partir do endereço por onde o
 * administrador está acessando o sistema agora.
 */
function urlsWebhookItau(): array
{
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    $base = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . publicUrl('');

    return [
        'token' => $base . '/webhook/itau/token.php',
        'webhook' => $base . '/webhook/itau/boletos.php',
    ];
}

// O Itaú só chama endereço público com HTTPS.
function pendenciaUrlWebhook(string $url): ?string
{
    $host = (string) parse_url($url, PHP_URL_HOST);
    if (!str_starts_with($url, 'https://')) {
        return 'O sistema está sendo acessado sem HTTPS. O Itaú só chama URLs https:// — acesse pelo endereço público com HTTPS para cadastrar.';
    }
    if ($host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP) || !str_contains($host, '.')) {
        return 'O endereço atual (' . $host . ') não é acessível pela internet. Cadastre a partir do endereço público do sistema.';
    }
    return null;
}

/**
 * Gera (ou troca) o par client_id/client_secret que o Itaú usa para pedir
 * token ao sistema. Trocar invalida os tokens já emitidos — e o cadastro
 * no Itaú precisa ser refeito com o par novo.
 */
function gerarCredenciaisWebhook(PDO $pdo, int $contaId): array
{
    $clientId = 'itau-wh-' . $contaId . '-' . bin2hex(random_bytes(8));
    $clientSecret = bin2hex(random_bytes(32));

    $stmt = $pdo->prepare('UPDATE contas_bancarias SET webhook_client_id = ?, webhook_client_secret_cripto = ? WHERE id = ?');
    $stmt->execute([$clientId, criptografar($clientSecret), $contaId]);
    $pdo->prepare('DELETE FROM webhook_tokens WHERE conta_bancaria_id = ?')->execute([$contaId]);

    return ['client_id' => $clientId, 'client_secret' => $clientSecret];
}

/**
 * Chamada autenticada (token OAuth + certificado) à API de notificações.
 * Retorna ['ok' => bool, 'status' => int, 'mensagem' => string, 'resposta' => mixed].
 */
function chamarApiNotificacoesItau(PDO $pdo, array $conta, string $metodo, string $caminho, ?array $corpo = null,
    ?string $urlBase = null, ?string $operacao = null): array
{
    $urlBase ??= urlApiNotificacoesItau($conta);
    $operacao ??= ['POST' => 'cadastro_webhook', 'DELETE' => 'exclusao_webhook'][$metodo] ?? 'consulta_webhook';
    // Token recusado (401) antes de vencer: pede um novo e repete uma vez.
    for ($tentativa = 1; $tentativa <= 2; $tentativa++) {
        $token = obterTokenItau($pdo, $conta, $tentativa > 1);
        if (!$token['ok']) {
            return ['ok' => false, 'status' => 0, 'mensagem' => 'Não foi possível obter o token: ' . $token['mensagem'], 'resposta' => null];
        }

        $cabecalhos = [
            'Accept: application/json',
            'Authorization: Bearer ' . $token['token'],
            'x-itau-apikey: ' . $conta['api_client_id'],
            'x-itau-correlationID: ' . ($correlacao = correlacaoItau()),
        ];

        $curl = curl_init($urlBase . $caminho);
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $metodo,
            CURLOPT_USERAGENT => ITAU_USER_AGENT,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        if ($corpo !== null) {
            $cabecalhos[] = 'Content-Type: application/json';
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($corpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        curl_setopt($curl, CURLOPT_HTTPHEADER, $cabecalhos);
        aplicarCertificadoItau($curl, $conta);

        $inicio = microtime(true);
        $resposta = curl_exec($curl);
        $erroCurl = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        registrarChamadaBanco($pdo, $conta, $operacao, $metodo,
            $urlBase . $caminho, $corpo, $resposta === false ? null : $status, $resposta === false ? null : $resposta,
            $correlacao, (int) ((microtime(true) - $inicio) * 1000), $resposta === false ? 'Falha de conexão: ' . $erroCurl : '');

        if ($resposta === false) {
            return ['ok' => false, 'status' => 0, 'mensagem' => 'Falha de conexão com o banco: ' . $erroCurl, 'resposta' => null];
        }
        if ($status !== 401) {
            break;
        }
    }

    $json = json_decode((string) $resposta, true);
    $ok = $status >= 200 && $status < 300;
    $mensagem = $ok ? "O banco aceitou (HTTP {$status})." : "O banco recusou (HTTP {$status})";
    if (!$ok && is_array($json)) {
        $detalhes = array_map(
            fn ($c) => ($c['campo'] ?? '') . ': ' . ($c['mensagem'] ?? $c['mensagen'] ?? ''),
            is_array($json['campos'] ?? null) ? $json['campos'] : []
        );
        $mensagem .= ': ' . ($json['mensagem'] ?? $json['message'] ?? '') . ($detalhes ? ' — ' . implode('; ', $detalhes) : '');
    }

    return [
        'ok' => $ok,
        'status' => $status,
        'mensagem' => $mensagem,
        'resposta' => $json ?? mb_substr(trim(strip_tags((string) $resposta)), 0, 500),
    ];
}

/**
 * Cadastra no Itaú os dois tipos de notificação (baixa operacional e
 * efetiva) apontando para o webhook deste sistema.
 */
function cadastrarWebhookItau(PDO $pdo, array $conta, array $urls, float $valorMinimo = 0.01): array
{
    $clientSecret = descriptografar($conta['webhook_client_secret_cripto']);
    if (!$conta['webhook_client_id'] || $clientSecret === null) {
        return ['ok' => false, 'status' => 0, 'mensagem' => 'Gere as credenciais do webhook antes de cadastrar.', 'resposta' => null];
    }

    $idBeneficiario = idBeneficiarioItau($conta);
    if ($idBeneficiario === null) {
        return ['ok' => false, 'status' => 0, 'mensagem' => 'Confira agência, conta e dígito da conta bancária: o Itaú identifica o beneficiário por eles (4 + 7 + 1 dígitos).', 'resposta' => null];
    }

    // Formato do exemplo de cadastro com sucesso da coleção oficial do
    // Itaú (boletos v3 › notificacoes_boletos): tudo dentro de "data", com
    // o id do beneficiário numérico e o scope que o banco pede ao nosso
    // endpoint de token (token.php aceita qualquer scope).
    $corpo = ['data' => [
        'id_beneficiario' => (int) $idBeneficiario,
        'webhook_url' => $urls['webhook'],
        'webhook_client_id' => $conta['webhook_client_id'],
        'webhook_client_secret' => $clientSecret,
        'webhook_oauth_url' => $urls['token'],
        'webhook_oauth_scope' => ITAU_WEBHOOK_SCOPE,
        'valor_minimo' => round($valorMinimo, 2),
        'tipos_notificacoes' => ITAU_TIPOS_NOTIFICACAO,
    ]];

    $resultado = chamarApiNotificacoesItau($pdo, $conta, 'POST', '/notificacoes_boletos', $corpo);
    if ($resultado['ok']) {
        $pdo->prepare('UPDATE contas_bancarias SET webhook_cadastrado_em = NOW() WHERE id = ?')->execute([$conta['id']]);
    }
    return $resultado;
}

// O que está cadastrado no Itaú para o beneficiário desta conta.
function listarWebhooksItau(PDO $pdo, array $conta): array
{
    $resultado = chamarApiNotificacoesItau($pdo, $conta, 'GET', '/notificacoes_boletos?id_beneficiario=' . urlencode((string) idBeneficiarioItau($conta)));
    $resultado['cadastros'] = $resultado['ok'] && is_array($resultado['resposta']['data'] ?? null) ? $resultado['resposta']['data'] : [];
    return $resultado;
}

function excluirWebhookItau(PDO $pdo, array $conta, string $idNotificacao): array
{
    return chamarApiNotificacoesItau($pdo, $conta, 'DELETE', '/notificacoes_boletos/' . rawurlencode($idNotificacao));
}

// ---------------------------------------------------------------------
// Lado servidor: o que o Itaú chama
// ---------------------------------------------------------------------

/**
 * Confere client_id/client_secret e emite um token de acesso ao webhook.
 * Retorna ['conta_bancaria_id' => int, 'resposta' => corpo OAuth], ou null
 * para credencial inválida.
 */
function emitirTokenWebhook(PDO $pdo, string $clientId, string $clientSecret): ?array
{
    if ($clientId === '' || $clientSecret === '') {
        return null;
    }
    $stmt = $pdo->prepare("SELECT id, webhook_client_secret_cripto FROM contas_bancarias WHERE webhook_client_id = ? AND ativo = 1 AND integracao = 'itau'");
    $stmt->execute([$clientId]);
    $conta = $stmt->fetch();
    $secretSalvo = $conta ? descriptografar($conta['webhook_client_secret_cripto']) : null;
    if ($secretSalvo === null || !hash_equals($secretSalvo, $clientSecret)) {
        return null;
    }

    $pdo->exec('DELETE FROM webhook_tokens WHERE expira_em < NOW()');

    $token = bin2hex(random_bytes(32));
    $stmt = $pdo->prepare('INSERT INTO webhook_tokens (token_hash, conta_bancaria_id, expira_em) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))');
    $stmt->execute([hash('sha256', $token), $conta['id'], WEBHOOK_TOKEN_SEGUNDOS]);

    return [
        'conta_bancaria_id' => (int) $conta['id'],
        'resposta' => ['access_token' => $token, 'token_type' => 'Bearer', 'expires_in' => WEBHOOK_TOKEN_SEGUNDOS],
    ];
}

// Conta bancária dona do token, ou null se o token não vale.
function contaPorTokenWebhook(PDO $pdo, string $token): ?array
{
    if ($token === '') {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT cb.* FROM webhook_tokens wt JOIN contas_bancarias cb ON cb.id = wt.conta_bancaria_id
         WHERE wt.token_hash = ? AND wt.expira_em > NOW()'
    );
    $stmt->execute([hash('sha256', $token)]);
    return $stmt->fetch() ?: null;
}

// O servidor (Apache com PHP-FPM/CGI) às vezes não repassa o Authorization
// para $_SERVER — por isso as alternativas.
function cabecalhoAuthorization(): string
{
    $valor = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($valor === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $nome => $conteudo) {
            if (strtolower($nome) === 'authorization') {
                $valor = $conteudo;
            }
        }
    }
    return trim((string) $valor);
}

/**
 * Procura a primeira das chaves em qualquer nível do aviso. A documentação
 * do Itaú não traz o corpo exato da notificação, então a leitura aceita os
 * nomes de campo que a API de boletos usa nas outras rotas.
 */
function campoNotificacao(array $dados, array $chaves)
{
    // O Itaú manda os nomes em camelCase ("numeroNossoNumero"); aqui eles
    // estão em snake_case. Compara sem "_" e sem maiúsculas.
    $normalizar = fn ($nome) => strtolower(str_replace('_', '', (string) $nome));
    $porNome = [];
    foreach ($dados as $nome => $valor) {
        $porNome[$normalizar($nome)] ??= $valor;
    }
    foreach ($chaves as $chave) {
        $valor = $porNome[$normalizar($chave)] ?? null;
        if ($valor !== null && $valor !== '' && !is_array($valor)) {
            return $valor;
        }
    }
    foreach ($dados as $valor) {
        if (is_array($valor)) {
            $achado = campoNotificacao($valor, $chaves);
            if ($achado !== null) {
                return $achado;
            }
        }
    }
    return null;
}

/**
 * Um POST pode trazer um aviso só, uma lista, ou a lista dentro de
 * "boletos" (formato do manual do Itaú) ou de "data".
 */
function separarNotificacoes(array $corpo): array
{
    if (array_is_list($corpo)) {
        return $corpo;
    }
    foreach (['boletos', 'data'] as $envelope) {
        if (isset($corpo[$envelope]) && is_array($corpo[$envelope])) {
            return array_is_list($corpo[$envelope]) ? $corpo[$envelope] : [$corpo[$envelope]];
        }
    }
    return [$corpo];
}

// "1.234,56", "1234.56" ou 1234.56 -> 1234.56
function valorNotificacao($valor): ?float
{
    if ($valor === null) {
        return null;
    }
    if (is_int($valor) || is_float($valor)) {
        return (float) $valor;
    }
    $texto = trim((string) $valor);
    if (str_contains($texto, ',')) {
        $texto = str_replace(['.', ','], ['', '.'], $texto);
    }
    return is_numeric($texto) ? (float) $texto : null;
}

function dataNotificacao($valor): ?string
{
    if ($valor === null) {
        return null;
    }
    $timestamp = strtotime(substr((string) $valor, 0, 10));
    return $timestamp ? date('Y-m-d', $timestamp) : null;
}

/**
 * Processa um aviso: acha a cobrança pelo nosso número e dá baixa na
 * parcela. Grava o resultado no evento já registrado. Nunca lança exceção
 * por regra de negócio — só por falha de banco de dados (aí o webhook
 * responde 500 e o Itaú tenta de novo).
 */
function processarNotificacaoBoletoItau(PDO $pdo, array $conta, array $aviso, int $eventoId): array
{
    $tipo = strtoupper((string) (campoNotificacao($aviso, ['tipo_notificacao', 'tipo_baixa', 'tipo_movimentacao']) ?? ''));
    $nossoNumeroBruto = apenasDigitos((string) (campoNotificacao($aviso, ['numero_nosso_numero', 'nosso_numero']) ?? ''));
    $nossoNumero = $nossoNumeroBruto !== '' ? (int) substr($nossoNumeroBruto, -8) : null;
    $valorPago = valorNotificacao(campoNotificacao($aviso, [
        'valor_pago_total_cobranca', 'valor_pago', 'valor_liquidado', 'valor_total_pago', 'valor_pagamento', 'valor_recebido',
    ]));
    $dataPagamento = dataNotificacao(campoNotificacao($aviso, [
        'data_pagamento', 'data_liquidacao', 'data_inclusao_pagamento', 'data_baixa', 'data_credito', 'data_movimentacao',
    ]));
    $idBeneficiario = apenasDigitos((string) (campoNotificacao($aviso, ['id_beneficiario']) ?? ''));

    $resultado = function (string $status, string $mensagem, ?array $cobranca = null) use ($pdo, $eventoId, $tipo, $nossoNumero, $valorPago, $dataPagamento) {
        $stmt = $pdo->prepare(
            'UPDATE webhook_eventos SET status = ?, mensagem = ?, tipo_notificacao = ?, nosso_numero = ?, valor_pago = ?,
                    data_pagamento = ?, cobranca_id = ?, conta_receber_id = ? WHERE id = ?'
        );
        $stmt->execute([
            $status, mb_strimwidth($mensagem, 0, 255, '…', 'UTF-8'), $tipo ?: null, $nossoNumero, $valorPago,
            $dataPagamento, $cobranca['id'] ?? null, $cobranca['conta_receber_id'] ?? null, $eventoId,
        ]);
        return ['status' => $status, 'mensagem' => $mensagem];
    };

    if ($idBeneficiario !== '' && $idBeneficiario !== idBeneficiarioItau($conta)) {
        return $resultado('ignorado', "Aviso de outro beneficiário ({$idBeneficiario}).");
    }
    if (!$nossoNumero) {
        return $resultado('erro', 'Aviso sem nosso número — confira o payload.');
    }

    $stmt = $pdo->prepare('SELECT * FROM cobrancas_bancarias WHERE conta_bancaria_id = ? AND nosso_numero = ?');
    $stmt->execute([$conta['id'], $nossoNumero]);
    $cobranca = $stmt->fetch();
    if (!$cobranca) {
        criarNotificacao($pdo, 'pagamento', 'Pagamento de boleto não identificado',
            'Nosso número ' . formatarNossoNumeroItau($nossoNumero) . ($valorPago ? ' · ' . formatMoney($valorPago) : '') . ' — nenhuma cobrança no sistema.');
        return $resultado('nao_encontrado', 'Nenhuma cobrança com o nosso número ' . formatarNossoNumeroItau($nossoNumero) . '.');
    }

    $baixa = baixarCobrancaPeloBanco($pdo, $cobranca, $valorPago, $dataPagamento, $tipo ?: 'webhook', 'Itaú (webhook)');
    return $resultado($baixa['status'], $baixa['mensagem'], $cobranca);
}

/**
 * Baixa a parcela de uma cobrança que o banco diz estar paga — usada pelo
 * aviso do webhook e pela consulta manual de pagamento. Retorna
 * ['status' => 'processado'|'ignorado', 'mensagem' => string].
 */
function baixarCobrancaPeloBanco(PDO $pdo, array $cobranca, ?float $valorPago, ?string $dataPagamento, string $tipo, string $autor): array
{
    $resultado = fn (string $status, string $mensagem) => ['status' => $status, 'mensagem' => $mensagem];

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'SELECT cr.*, a.nome AS aluno_nome FROM contas_receber cr LEFT JOIN alunos a ON a.id = cr.aluno_id WHERE cr.id = ? FOR UPDATE'
        );
        $stmt->execute([$cobranca['conta_receber_id']]);
        $parcela = $stmt->fetch();

        $pdo->prepare("UPDATE cobrancas_bancarias SET status = 'paga' WHERE id = ?")->execute([$cobranca['id']]);

        if ($parcela['status'] === 'pago') {
            $pdo->commit();
            return $resultado('ignorado', 'Parcela já estava baixada' . ($tipo ? " ({$tipo} registrado)" : '') . '.');
        }

        $link = $parcela['aluno_id'] ? '/alunos/form.php?id=' . (int) $parcela['aluno_id'] . '&tab=financeiro' : '/financeiro/contas_receber/listar.php';

        if ($parcela['status'] === 'cancelado') {
            $pdo->commit();
            criarNotificacao($pdo, 'pagamento', 'Boleto pago de parcela cancelada',
                ($parcela['aluno_nome'] ?: $parcela['descricao']) . ' pagou ' . formatMoney($valorPago ?? $cobranca['valor']) . '. Verifique a devolução ou reative a parcela.', $link, (int) $parcela['id']);
            return $resultado('ignorado', 'Pagamento de parcela cancelada — não baixada; avisado no sino.');
        }

        $valorRecebido = $valorPago ?? (float) $cobranca['valor'];
        $dataRecebimento = $dataPagamento ?? date('Y-m-d');

        $stmt = $pdo->prepare("UPDATE contas_receber SET status = 'pago', data_recebimento = ?, valor_recebido = ?, baixa_origem = 'banco' WHERE id = ?");
        $stmt->execute([$dataRecebimento, $valorRecebido, $parcela['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    confirmarMatriculaPorPagamento($pdo, (int) $parcela['id']);

    $descricao = 'Conta a receber recebida pelo banco (' . $tipo . '): ' . $parcela['descricao'] . ' - ' . formatMoney($valorRecebido);
    $usuarioId = function_exists('usuarioLogado') && usuarioLogado() ? usuarioAtualId() : null;
    registrarAtividadeUsuario($usuarioId, $autor, 'contas_receber', 'baixa', $descricao);
    criarNotificacao($pdo, 'pagamento', 'Pagamento recebido',
        ($parcela['aluno_nome'] ?: $parcela['descricao']) . ' · ' . formatMoney($valorRecebido), $link, (int) $parcela['id']);

    return $resultado('processado', 'Parcela baixada: ' . formatMoney($valorRecebido) . ' em ' . formatDate($dataRecebimento)
        . ($valorPago === null ? ' (aviso sem valor pago — usado o valor da cobrança)' : '') . '.');
}

/**
 * Consulta manual: pergunta ao Itaú se o boleto da parcela foi pago, sem
 * esperar o webhook. Usa as movimentações da carteira ("francesa") da
 * Cobrança v2 — GET /francesas/{agência+conta+DAC}/movimentacoes?data=…
 * &nosso_numero=… — dia a dia, de hoje até a emissão (no máximo 31 dias).
 * Achou a liquidação: baixa a parcela como o webhook faria.
 * Retorna ['ok' => bool, 'pago' => bool, 'mensagem' => string].
 */
function consultarPagamentoItau(PDO $pdo, int $contaReceberId): array
{
    $stmt = $pdo->prepare(
        "SELECT * FROM cobrancas_bancarias WHERE conta_receber_id = ? AND status IN ('registrada', 'paga') ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([$contaReceberId]);
    $cobranca = $stmt->fetch();
    if (!$cobranca) {
        return ['ok' => false, 'pago' => false, 'mensagem' => 'Esta parcela não tem boleto registrado no banco.'];
    }
    $stmt = $pdo->prepare('SELECT * FROM contas_bancarias WHERE id = ?');
    $stmt->execute([$cobranca['conta_bancaria_id']]);
    $conta = $stmt->fetch();
    $idFrancesa = $conta ? idBeneficiarioItau($conta) : null;
    if (!$idFrancesa) {
        return ['ok' => false, 'pago' => false, 'mensagem' => 'Confira agência, conta e dígito da conta bancária.'];
    }
    $stmt = $pdo->prepare('SELECT status FROM contas_receber WHERE id = ?');
    $stmt->execute([$contaReceberId]);
    if ($stmt->fetchColumn() === 'pago') {
        return ['ok' => true, 'pago' => true, 'mensagem' => 'Esta parcela já está baixada.'];
    }
    contextoChamadaBanco(['conta_receber_id' => $contaReceberId]);

    // As movimentações ficam na API de Cobrança v2 (cash_management).
    $urlBase = ehApiCobrancaV2Itau($conta) ? rtrim($conta['api_url_base'], '/') : 'https://api.itau.com.br/cash_management/v2';
    $nossoNumero = (int) $cobranca['nosso_numero'];
    $dia = new DateTimeImmutable('today');
    $inicio = max(new DateTimeImmutable(substr($cobranca['criado_em'], 0, 10)), $dia->modify('-31 days'));
    $consultados = 0;

    for (; $dia >= $inicio; $dia = $dia->modify('-1 day')) {
        $consultados++;
        $resposta = chamarApiNotificacoesItau($pdo, $conta, 'GET', '/francesas/' . $idFrancesa . '/movimentacoes?' . http_build_query([
            'data' => $dia->format('Y-m-d'), 'nosso_numero' => formatarNossoNumeroItau($nossoNumero),
        ]), null, $urlBase, 'consulta_pagamento');
        if (!$resposta['ok'] && $resposta['status'] !== 404) {
            return ['ok' => false, 'pago' => false, 'mensagem' => $resposta['mensagem']];
        }
        foreach ((is_array($resposta['resposta']) ? ($resposta['resposta']['data'] ?? []) : []) as $mov) {
            $numero = (int) substr(apenasDigitos((string) ($mov['nosso_numero'] ?? '')), -8);
            $liquidacao = str_starts_with(strtolower((string) ($mov['tipo_movimentacao'] ?? '')), 'liquida');
            if ($numero !== $nossoNumero || !$liquidacao) {
                continue;
            }
            $valor = valorNotificacao($mov['valor_liquido_lancado'] ?? null) ?: valorNotificacao($mov['valor_titulo'] ?? null);
            $data = dataNotificacao($mov['data_movimentacao'] ?? null) ?? $dia->format('Y-m-d');
            $baixa = baixarCobrancaPeloBanco($pdo, $cobranca, $valor, $data, 'consulta manual', usuarioLogado() ? ($_SESSION['user_nome'] ?? 'Usuário') : 'Consulta ao banco');
            return ['ok' => true, 'pago' => true, 'mensagem' => 'O banco confirma o pagamento. ' . $baixa['mensagem']];
        }
    }

    return ['ok' => true, 'pago' => false, 'mensagem' => "O banco ainda não mostra pagamento deste boleto (consultados {$consultados} dia(s)). "
        . 'O Itaú costuma lançar o pagamento nas movimentações até o dia útil seguinte — tente de novo mais tarde.'];
}

/**
 * Registro bruto das chamadas recebidas (tela Webhook › Registro), com os
 * avisos de pagamento que cada uma gerou.
 */
function buscarRequisicoesWebhook(PDO $pdo, array $filtros, int $pagina = 1, int $porPagina = 50): array
{
    $condicoes = [];
    $parametros = [];
    if (!empty($filtros['data_inicio'])) {
        $condicoes[] = 'wr.criado_em >= ?';
        $parametros[] = $filtros['data_inicio'] . ' 00:00:00';
    }
    if (!empty($filtros['data_fim'])) {
        $condicoes[] = 'wr.criado_em <= ?';
        $parametros[] = $filtros['data_fim'] . ' 23:59:59';
    }
    if (!empty($filtros['endpoint'])) {
        $condicoes[] = 'wr.endpoint = ?';
        $parametros[] = $filtros['endpoint'];
    }
    if (!empty($filtros['conta_bancaria_id'])) {
        $condicoes[] = 'wr.conta_bancaria_id = ?';
        $parametros[] = (int) $filtros['conta_bancaria_id'];
    }
    if (($filtros['resultado'] ?? '') === 'ok') {
        $condicoes[] = 'wr.status_http BETWEEN 200 AND 299';
    } elseif (($filtros['resultado'] ?? '') === 'falha') {
        $condicoes[] = '(wr.status_http IS NULL OR wr.status_http NOT BETWEEN 200 AND 299)';
    }
    if (!empty($filtros['busca'])) {
        // Nosso número, CPF, valor... qualquer coisa que esteja no corpo.
        $condicoes[] = 'wr.corpo LIKE ?';
        $parametros[] = '%' . $filtros['busca'] . '%';
    }
    $where = $condicoes ? 'WHERE ' . implode(' AND ', $condicoes) : '';

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM webhook_requisicoes wr {$where}");
    $stmt->execute($parametros);
    $total = (int) $stmt->fetchColumn();

    $pagina = max(1, $pagina);
    $offset = ($pagina - 1) * $porPagina;
    $stmt = $pdo->prepare(
        "SELECT wr.*, cb.apelido AS conta_apelido FROM webhook_requisicoes wr
         LEFT JOIN contas_bancarias cb ON cb.id = wr.conta_bancaria_id
         {$where} ORDER BY wr.id DESC LIMIT {$porPagina} OFFSET {$offset}"
    );
    $stmt->execute($parametros);
    $itens = $stmt->fetchAll();

    $eventos = [];
    if ($itens) {
        $ids = array_column($itens, 'id');
        $stmt = $pdo->prepare(
            'SELECT we.*, cr.descricao AS parcela_descricao FROM webhook_eventos we
             LEFT JOIN contas_receber cr ON cr.id = we.conta_receber_id
             WHERE we.requisicao_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY we.id'
        );
        $stmt->execute($ids);
        foreach ($stmt->fetchAll() as $evento) {
            $eventos[$evento['requisicao_id']][] = $evento;
        }
    }

    return [
        'itens' => $itens,
        'eventos' => $eventos,
        'total' => $total,
        'pagina' => $pagina,
        'total_paginas' => max(1, (int) ceil($total / $porPagina)),
    ];
}

function statusEventoWebhook(): array
{
    return [
        'processado' => ['success', 'Baixado'],
        'ignorado' => ['secondary', 'Ignorado'],
        'nao_encontrado' => ['warning', 'Não encontrado'],
        'erro' => ['danger', 'Erro'],
        'recebido' => ['info', 'Recebido'],
    ];
}

// JSON indentado para leitura; texto que não é JSON volta como veio.
function formatarJsonLeitura(?string $texto): string
{
    if ($texto === null || $texto === '') {
        return '';
    }
    $json = json_decode($texto, true);
    return $json === null ? $texto : json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
