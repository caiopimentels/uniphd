<?php

// Certificado do Itaú (mTLS): emissão de um novo e aviso de vencimento.
//
// Pelo manual do Itaú ("Obter Certificado e Solicitar Access Token"): o
// certificado vale 365 dias e o Itaú orienta emitir um novo 30 dias antes.
// A emissão é POST https://sts.itau.com.br/seguranca/v1/certificado/solicitacao
// com o CSR (texto) no corpo e um "token temporário" (enviado por e-mail pela
// implantação do Itaú, vale 7 dias) como Bearer. A resposta traz na 1ª linha
// "Secret: <novo client_secret>" e, em seguida, o certificado (PEM).
//
// Renovação (sem token temporário): a mesma chamada com o access_token normal
// e o certificado atual — o token temporário do manual é o da primeira
// emissão, quando ainda não há certificado para obter access_token. Se o Itaú
// recusar (ou o certificado já venceu), nada muda e o administrador é avisado
// para pedir o token temporário e usar "Gerar certificado novo".

const ITAU_URL_CERTIFICADO = 'https://sts.itau.com.br/seguranca/v1/certificado/solicitacao';
const DIAS_RENOVACAO_CERTIFICADO = 30;
// Avisos de vencimento (dias que faltam); 0 = venceu.
const AVISOS_VENCIMENTO_CERTIFICADO = [30, 15, 7, 1, 0];

/**
 * Chave privada nova + CSR no formato do manual do Itaú:
 * CN = client_id, OU = nome da empresa, L = cidade (sem acento), ST = UF, C = BR,
 * RSA 2048, SHA-512.
 */
function gerarChaveCsrItau(PDO $pdo, array $conta): array
{
    $config = buscarConfiguracoes($pdo);
    $semAcento = fn (string $t) => trim(preg_replace('/[^A-Za-z0-9 .-]/', '', (string) iconv('UTF-8', 'ASCII//TRANSLIT', $t)));
    $dn = [
        'commonName' => (string) $conta['api_client_id'],
        'organizationalUnitName' => $semAcento($config['empresa_nome_fantasia'] ?: ($config['empresa_razao_social'] ?: ($config['nome_sistema'] ?? 'Empresa'))) ?: 'Empresa',
        'localityName' => $semAcento((string) ($config['empresa_cidade'] ?? '')) ?: 'Sao Paulo',
        'stateOrProvinceName' => strtoupper($semAcento((string) ($config['empresa_uf'] ?? ''))) ?: 'SP',
        'countryName' => 'BR',
    ];
    $chave = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    if (!$chave) {
        throw new RuntimeException('Não foi possível gerar a chave privada (OpenSSL do servidor).');
    }
    $csr = openssl_csr_new($dn, $chave, ['digest_alg' => 'sha512']);
    if (!$csr || !openssl_csr_export($csr, $csrPem) || !openssl_pkey_export($chave, $chavePem)) {
        throw new RuntimeException('Não foi possível gerar o pedido de certificado (CSR).');
    }
    return ['chave' => $chavePem, 'csr' => $csrPem];
}

/**
 * Pede ao Itaú um certificado novo para a conta e instala: certificado,
 * chave e o client_secret novo que vem junto.
 * $tokenTemporario: o do e-mail do Itaú (caminho do manual); null = tenta
 * com o access_token atual e o certificado vigente (renovação automática).
 * Retorna ['ok' => bool, 'mensagem' => string, 'validade' => ?string].
 */
function solicitarCertificadoItau(PDO $pdo, array $conta, ?string $tokenTemporario = null): array
{
    $falha = fn (string $m) => ['ok' => false, 'mensagem' => $m, 'validade' => null];
    if ($conta['integracao'] !== 'itau' || ($conta['api_ambiente'] ?? '') !== 'producao') {
        return $falha('A emissão de certificado é só para conta Itaú em produção.');
    }
    if (!$conta['api_client_id']) {
        return $falha('Informe o client_id da conta antes.');
    }
    $pasta = pastaCertificadosConta((int) $conta['id']);
    if ($problema = problemaPastaUpload($pasta)) {
        return $falha('Não dá para gravar o certificado novo: ' . $problema);
    }

    if ($tokenTemporario === null) {
        $token = obterTokenItau($pdo, $conta, true);
        if (!$token['ok']) {
            return $falha('Não foi possível obter o token para renovar: ' . $token['mensagem']);
        }
        $bearer = $token['token'];
    } else {
        $bearer = trim($tokenTemporario);
        if ($bearer === '') {
            return $falha('Cole o token temporário enviado pelo Itaú.');
        }
    }

    try {
        $par = gerarChaveCsrItau($pdo, $conta);
    } catch (Throwable $e) {
        return $falha($e->getMessage());
    }

    $correlacao = correlacaoItau();
    $curl = curl_init(ITAU_URL_CERTIFICADO);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $par['csr'],
        CURLOPT_HTTPHEADER => ['Content-Type: text/plain', 'Authorization: Bearer ' . $bearer, 'x-itau-correlationID: ' . $correlacao],
        CURLOPT_USERAGENT => ITAU_USER_AGENT,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 15,
    ]);
    // Renovação automática vai com o certificado atual; com o token
    // temporário (como no manual) o certificado pode já ter vencido.
    if ($tokenTemporario === null) {
        aplicarCertificadoItau($curl, $conta);
    }
    $inicio = microtime(true);
    $resposta = curl_exec($curl);
    $erroCurl = curl_error($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    $corpo = $resposta === false ? '' : (string) $resposta;

    // Antes de tudo: guarda a resposta e a chave nova cifradas. Se algo
    // falhar daqui em diante, o secret novo (que o Itaú só mostra uma vez)
    // não se perde — dá para recuperar pelo arquivo.
    if ($status >= 200 && $status < 300 && $corpo !== '') {
        @file_put_contents($pasta . '/emissao_' . date('Ymd_His') . '.cifrado', criptografar(json_encode(['resposta' => $corpo, 'chave' => $par['chave']])));
    }
    $semSegredo = preg_replace('/(Secret\s*:\s*)\S+/i', '$1***', $corpo);
    registrarChamadaBanco($pdo, $conta, 'emissao_certificado', 'POST', ITAU_URL_CERTIFICADO,
        $tokenTemporario === null ? 'CSR (renovação automática)' : 'CSR (com token temporário)', $resposta === false ? null : $status,
        mb_substr((string) $semSegredo, 0, 2000), $correlacao, (int) ((microtime(true) - $inicio) * 1000),
        $resposta === false ? 'Falha de conexão: ' . $erroCurl : '');

    if ($resposta === false) {
        return $falha('Falha de conexão com o Itaú: ' . $erroCurl);
    }
    if ($status < 200 || $status >= 300) {
        $json = json_decode($corpo, true);
        $detalhe = is_array($json) ? ($json['message'] ?? $json['mensagem'] ?? $json['error_description'] ?? '') : mb_substr(trim(strip_tags($corpo)), 0, 160);
        return $falha("O Itaú recusou a emissão (HTTP {$status})" . ($detalhe ? ': ' . $detalhe : '') . '.');
    }
    if (!preg_match('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $corpo, $m)) {
        return $falha('O Itaú respondeu, mas sem o certificado. A resposta ficou guardada (cifrada) na pasta do certificado.');
    }
    $certificadoPem = $m[0] . "\n";
    $secret = preg_match('/Secret\s*:\s*(\S+)/i', $corpo, $s) ? $s[1] : null;

    $x509 = @openssl_x509_read($certificadoPem);
    if (!$x509 || !openssl_x509_check_private_key($x509, $par['chave'])) {
        return $falha('O certificado devolvido pelo Itaú não confere com a chave gerada. A resposta ficou guardada (cifrada) na pasta do certificado.');
    }
    $info = openssl_x509_parse($x509);
    $validade = isset($info['validTo_time_t']) ? date('Y-m-d', $info['validTo_time_t']) : null;

    // Troca os arquivos guardando os antigos ao lado.
    $sufixo = '.ate-' . date('Ymd_His');
    $caminhoCertificado = $pasta . '/certificado.crt';
    $caminhoChave = $pasta . '/chave.key';
    foreach ([$caminhoCertificado, $caminhoChave] as $arquivo) {
        if (is_file($arquivo)) {
            @rename($arquivo, $arquivo . $sufixo);
        }
    }
    if (@file_put_contents($caminhoCertificado, $certificadoPem) === false || @file_put_contents($caminhoChave, $par['chave']) === false) {
        return $falha('Não foi possível gravar o certificado novo em ' . $pasta . '. A resposta ficou guardada (cifrada) na mesma pasta.');
    }
    @chmod($caminhoCertificado, 0600);
    @chmod($caminhoChave, 0600);

    $pdo->prepare(
        'UPDATE contas_bancarias SET api_certificado_arquivo = ?, api_chave_arquivo = ?, api_certificado_validade = ?, api_chave_senha_cripto = NULL,
                api_client_secret_cripto = COALESCE(?, api_client_secret_cripto), api_token_cripto = NULL, api_token_expira_em = NULL,
                api_certificado_renovacao_em = NOW(), api_certificado_renovacao_erro = NULL, api_certificado_aviso = NULL,
                api_ultimo_teste_ok = NULL, api_ultimo_teste_mensagem = NULL
         WHERE id = ?'
    )->execute([$caminhoCertificado, $caminhoChave, $validade, $secret !== null ? criptografar($secret) : null, $conta['id']]);

    // Confere na hora se o par novo funciona.
    $stmt = $pdo->prepare('SELECT * FROM contas_bancarias WHERE id = ?');
    $stmt->execute([$conta['id']]);
    $teste = obterTokenItau($pdo, $stmt->fetch(), true);

    return [
        'ok' => true,
        'validade' => $validade,
        'mensagem' => 'Certificado novo instalado' . ($validade ? ', válido até ' . formatDate($validade) : '') . '.'
            . ($secret !== null ? ' O client_secret também foi atualizado.' : '')
            . ($teste['ok'] ? ' Teste com o Itaú: ok.' : ' Atenção: o teste com o Itaú falhou (' . $teste['mensagem'] . ').'),
    ];
}

// Aviso aos administradores: no sino e por e-mail para cada usuário
// administrador ativo (perfil administrador) que tenha e-mail cadastrado.
function avisarAdministradorCertificado(PDO $pdo, array $conta, string $titulo, string $texto): void
{
    criarNotificacao($pdo, 'sistema', $titulo, $conta['apelido'] . ' — ' . $texto, '/contas_bancarias/form.php?id=' . (int) $conta['id'], (int) $conta['id']);
    try {
        $config = buscarConfiguracoes($pdo);
        $administradores = $pdo->query(
            "SELECT u.nome, u.email FROM usuarios u JOIN perfis p ON p.id = u.perfil_id
             WHERE u.ativo = 1 AND p.administrador = 1 AND u.email IS NOT NULL AND u.email <> ''"
        )->fetchAll();
        $corpo = '<p><strong>' . e($titulo) . '</strong></p><p>' . e($conta['apelido']) . ' — ' . e($texto) . '</p>'
            . '<p>No sistema: Configurações › Contas bancárias › ' . e($conta['apelido']) . ' › Certificado.</p>';
        foreach ($administradores as $admin) {
            enviarEmail($config, $admin['email'], $admin['nome'], $titulo . ' — ' . ($config['nome_sistema'] ?? 'Sistema'), $corpo);
        }
    } catch (Throwable $e) {
        error_log('[Certificado Itaú] aviso por e-mail: ' . $e->getMessage());
    }
}

/**
 * Rotina diária: avisa o vencimento (30, 15, 7, 1 dia e vencido) e, a partir
 * de 30 dias antes, tenta renovar sozinho uma vez por dia. Falhou: avisa o
 * administrador (no sino e por e-mail) — de novo só se o motivo mudar.
 */
function verificarCertificadosItau(PDO $pdo): void
{
    $contas = $pdo->query("SELECT * FROM contas_bancarias WHERE integracao = 'itau' AND ativo = 1 AND api_ambiente = 'producao' AND api_certificado_validade IS NOT NULL")->fetchAll();
    if (!$contas) {
        registrarAtividadeSistema('rotinas', 'verificacao', 'Verificação do certificado do Itaú: nenhuma conta Itaú em produção com certificado instalado.');
    }
    foreach ($contas as $conta) {
        $dias = (int) floor((strtotime($conta['api_certificado_validade']) - strtotime(date('Y-m-d'))) / 86400);
        // Registro na auditoria a cada vez que a rotina passa, para constar
        // que ela está rodando.
        registrarAtividadeSistema('rotinas', 'verificacao', 'Verificação do certificado do Itaú — ' . $conta['apelido'] . ': '
            . ($dias < 0 ? 'VENCIDO em ' . formatDate($conta['api_certificado_validade'])
                : 'válido até ' . formatDate($conta['api_certificado_validade']) . ' (' . $dias . ' dia(s))'
                    . ($dias <= DIAS_RENOVACAO_CERTIFICADO ? '; tentando renovar' : '; tudo certo')));

        if ($dias <= DIAS_RENOVACAO_CERTIFICADO && $dias >= 0
            && (!$conta['api_certificado_renovacao_em'] || substr((string) $conta['api_certificado_renovacao_em'], 0, 10) < date('Y-m-d'))) {
            $pdo->prepare('UPDATE contas_bancarias SET api_certificado_renovacao_em = NOW() WHERE id = ?')->execute([$conta['id']]);
            $resultado = solicitarCertificadoItau($pdo, $conta, null);
            registrarAtividadeSistema('rotinas', 'verificacao', 'Renovação automática do certificado do Itaú — ' . $conta['apelido'] . ': '
                . ($resultado['ok'] ? 'renovado' : 'não renovou. ' . $resultado['mensagem']));
            if ($resultado['ok']) {
                criarNotificacao($pdo, 'sistema', 'Certificado do Itaú renovado', $conta['apelido'] . ' — ' . $resultado['mensagem'], '/contas_bancarias/form.php?id=' . (int) $conta['id'], (int) $conta['id']);
                continue;
            }
            $erro = mb_strimwidth($resultado['mensagem'], 0, 255, '…', 'UTF-8');
            if ($erro !== (string) $conta['api_certificado_renovacao_erro']) {
                avisarAdministradorCertificado($pdo, $conta, 'Certificado do Itaú não foi renovado sozinho',
                    'vence em ' . formatDate($conta['api_certificado_validade']) . '. Motivo: ' . $resultado['mensagem']
                    . ' Peça ao Itaú (implantação) o token temporário e use "Gerar certificado novo" na conta bancária.');
            }
            $pdo->prepare('UPDATE contas_bancarias SET api_certificado_renovacao_erro = ? WHERE id = ?')->execute([$erro, $conta['id']]);
        }

        // Aviso de vencimento: cada marco uma vez só.
        foreach (AVISOS_VENCIMENTO_CERTIFICADO as $marco) {
            if ($dias <= $marco && ($conta['api_certificado_aviso'] === null || (int) $conta['api_certificado_aviso'] > $marco)) {
                avisarAdministradorCertificado($pdo, $conta,
                    $dias < 0 ? 'Certificado do Itaú VENCIDO' : 'Certificado do Itaú vence em ' . $dias . ' dia(s)',
                    $dias < 0
                        ? 'venceu em ' . formatDate($conta['api_certificado_validade']) . '. Boletos e Pix não são gerados até instalar um novo.'
                        : 'válido até ' . formatDate($conta['api_certificado_validade']) . '. O sistema tenta renovar sozinho; se não conseguir, use "Gerar certificado novo" com o token temporário do Itaú.');
                $pdo->prepare('UPDATE contas_bancarias SET api_certificado_aviso = ? WHERE id = ?')->execute([$marco, $conta['id']]);
                break;
            }
        }
    }
}

