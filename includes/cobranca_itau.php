<?php

// Cobrança bancária (boleto / boleto+Pix) — por enquanto só monta e valida
// o que seria enviado ao Itaú (API de Cobrança v2, registro de boleto). O
// envio de fato, com credenciais e certificado, é a próxima etapa.

function bancosConhecidos(): array
{
    return [
        '341' => 'Itaú Unibanco',
        '001' => 'Banco do Brasil',
        '237' => 'Bradesco',
        '104' => 'Caixa Econômica Federal',
        '033' => 'Santander',
        '756' => 'Sicoob',
        '748' => 'Sicredi',
        '077' => 'Banco Inter',
        '260' => 'Nubank',
        '336' => 'C6 Bank',
    ];
}

function instrumentosCobranca(): array
{
    return [
        'manual' => 'Manual (só registra a baixa)',
        'boleto' => 'Boleto',
        'boleto_pix' => 'Boleto + Pix (QR Code no boleto)',
        'pix' => 'Pix',
    ];
}

function especiesItau(): array
{
    return [
        '01' => 'DM - Duplicata de Venda Mercantil',
        '02' => 'NP - Nota Promissória',
        '03' => 'NS - Nota de Seguro',
        '04' => 'ME - Mensalidade Escolar',
        '05' => 'RC - Recibo',
        '06' => 'CT - Contrato',
        '08' => 'DS - Duplicata de Prestação de Serviços',
        '09' => 'LC - Letra de Câmbio',
        '15' => 'DD - Documento de Dívida',
        '17' => 'FS - Fatura de Serviço',
        '91' => 'CD - Confissão de Dívida',
        '94' => 'CPS - Conta de Prestação de Serviços',
        '98' => 'TA - Termo de Acordo',
        '99' => 'DV - Diversos',
    ];
}

function tiposJurosItau(): array
{
    return [
        '05' => 'Isento (não cobra juros)',
        '90' => 'Percentual mensal',
        '91' => 'Percentual diário',
        '92' => 'Percentual anual',
    ];
}

function tiposMultaItau(): array
{
    return [
        '03' => 'Isento (não cobra multa)',
        '02' => 'Percentual sobre o valor',
    ];
}

/**
 * id_beneficiario do Itaú = agência (4) + conta (7) + dígito (1), só
 * números. Ex.: agência 1500, conta 52061-? -> "1500" + "0005206" + "1".
 * Retorna null se faltar algum pedaço ou se não couber no formato.
 */
function idBeneficiarioItau(array $conta): ?string
{
    $agencia = apenasDigitos((string) $conta['agencia']);
    $numero = apenasDigitos((string) $conta['conta']);
    $digito = apenasDigitos((string) ($conta['conta_digito'] ?? ''));

    if ($agencia === '' || $numero === '' || strlen($digito) !== 1 || strlen($agencia) > 4 || strlen($numero) > 7) {
        return null;
    }
    return str_pad($agencia, 4, '0', STR_PAD_LEFT) . str_pad($numero, 7, '0', STR_PAD_LEFT) . $digito;
}

// Na carteira 109 o nosso número tem 8 dígitos.
function formatarNossoNumeroItau(int $numero): string
{
    return str_pad((string) $numero, 8, '0', STR_PAD_LEFT);
}

/**
 * Qual conta bancária recebe a cobrança desta parcela: a da forma de
 * pagamento prevista, se ela estiver ligada a uma conta; senão, a conta
 * padrão com integração.
 */
function contaBancariaParaCobranca(PDO $pdo, ?int $formaPagamentoId): ?array
{
    if ($formaPagamentoId) {
        $stmt = $pdo->prepare(
            'SELECT cb.* FROM formas_pagamento fp JOIN contas_bancarias cb ON cb.id = fp.conta_bancaria_id
             WHERE fp.id = ? AND cb.ativo = 1'
        );
        $stmt->execute([$formaPagamentoId]);
        $conta = $stmt->fetch();
        if ($conta) {
            return $conta;
        }
    }

    $conta = $pdo->query(
        "SELECT * FROM contas_bancarias WHERE ativo = 1 AND integracao <> 'nenhuma' ORDER BY padrao DESC, id LIMIT 1"
    )->fetch();
    return $conta ?: null;
}

/**
 * Monta a prévia da cobrança de uma parcela de contas a receber: o JSON no
 * formato do Itaú e a lista do que impede (pendencias) ou merece atenção
 * (avisos) antes de enviar. Não reserva nosso número nem grava nada — o
 * número mostrado é o próximo da sequência da conta.
 */
// Parcela vencida sem boleto: o boleto sai com vencimento daqui a tantos
// dias (o banco não registra boleto vencido). A parcela guarda a data
// original — o atraso continua contando nos relatórios.
const DIAS_BOLETO_PARCELA_VENCIDA = 2;

/**
 * Multa e juros do atraso de uma parcela até hoje, pela configuração da
 * conta bancária: multa (% sobre o valor) uma vez só; juros simples pelos
 * dias de atraso (percentual diário, mensal ÷ 30 ou anual ÷ 360).
 * Retorna ['dias' => int, 'multa' => float, 'juros' => float, 'total' => float].
 */
function encargosAtrasoParcela(array $conta, float $valor, string $vencimento, ?string $hoje = null): array
{
    $hoje ??= date('Y-m-d');
    $dias = max(0, (int) round((strtotime($hoje) - strtotime($vencimento)) / 86400));
    if (!$dias) {
        return ['dias' => 0, 'multa' => 0.0, 'juros' => 0.0, 'total' => 0.0];
    }
    $multa = ($conta['multa_tipo'] ?? '03') === '02' ? round($valor * (float) $conta['multa_percentual'] / 100, 2) : 0.0;
    $taxaDia = match ($conta['juros_tipo'] ?? '05') {
        '91' => (float) $conta['juros_percentual'],
        '90' => (float) $conta['juros_percentual'] / 30,
        '92' => (float) $conta['juros_percentual'] / 360,
        default => 0.0,
    };
    $juros = round($valor * $taxaDia / 100 * $dias, 2);
    return ['dias' => $dias, 'multa' => $multa, 'juros' => $juros, 'total' => round($multa + $juros, 2)];
}

function previaCobrancaItau(PDO $pdo, int $contaReceberId): array
{
    $pendencias = [];
    $avisos = [];

    $stmt = $pdo->prepare(
        'SELECT cr.*, fp.nome AS forma_nome, fp.instrumento AS forma_instrumento
         FROM contas_receber cr
         LEFT JOIN formas_pagamento fp ON fp.id = cr.forma_pagamento_id
         WHERE cr.id = ?'
    );
    $stmt->execute([$contaReceberId]);
    $parcela = $stmt->fetch();
    if (!$parcela) {
        return ['parcela' => null, 'pendencias' => ['Parcela não encontrada.'], 'avisos' => [], 'payload' => null];
    }

    $config = buscarConfiguracoes($pdo);

    // ---- parcela
    if ($parcela['status'] !== 'pendente') {
        $pendencias[] = 'A parcela está "' . contaStatusLabel($parcela['status']) . '" — só parcela pendente pode virar cobrança.';
    }
    if ((float) $parcela['valor'] <= 0) {
        $pendencias[] = 'A parcela não tem valor.';
    }
    $parcela['vencimento_original'] = $parcela['data_vencimento'];
    $parcela['valor_original'] = $parcela['valor'];
    $parcela['encargos'] = null;
    $vencida = $parcela['data_vencimento'] < date('Y-m-d');
    if ($vencida) {
        $parcela['data_vencimento'] = date('Y-m-d', strtotime('+' . DIAS_BOLETO_PARCELA_VENCIDA . ' days'));
        $avisos[] = 'A parcela venceu em ' . formatDate($parcela['vencimento_original']) . ': o boleto vai com vencimento em '
            . formatDate($parcela['data_vencimento']) . ' e sem o desconto por pontualidade.';
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM cobrancas_bancarias WHERE conta_receber_id = ? AND status IN (\'pendente_envio\', \'registrada\')');
    $stmt->execute([$contaReceberId]);
    if ((int) $stmt->fetchColumn() > 0) {
        $pendencias[] = 'Esta parcela já tem uma cobrança gerada no banco.';
    }

    // ---- instrumento
    $instrumento = $parcela['forma_instrumento'] ?? null;
    if ($instrumento === 'pix') {
        $avisos[] = 'A forma de pagamento desta parcela é Pix avulso, que usa outra API do Itaú (Pix Recebimentos). A prévia abaixo é do boleto com Pix (bolecode).';
        $instrumento = 'boleto_pix';
    } elseif (!in_array($instrumento, ['boleto', 'boleto_pix'], true)) {
        $avisos[] = 'A forma de pagamento prevista (' . ($parcela['forma_nome'] ?: 'nenhuma') . ') não gera cobrança no banco. A prévia usa boleto + Pix.';
        $instrumento = 'boleto_pix';
    }

    // ---- conta bancária / beneficiário
    $conta = contaBancariaParaCobranca($pdo, $parcela['forma_pagamento_id'] ? (int) $parcela['forma_pagamento_id'] : null);
    $idBeneficiario = null;
    if (!$conta) {
        $pendencias[] = 'Nenhuma conta bancária com integração cadastrada (Configurações › Contas bancárias).';
    } else {
        if ($conta['integracao'] !== 'itau') {
            $pendencias[] = 'A conta "' . $conta['apelido'] . '" não está com integração Itaú ligada.';
        }
        $idBeneficiario = idBeneficiarioItau($conta);
        if (!$idBeneficiario) {
            $pendencias[] = 'Agência/conta/dígito da conta "' . $conta['apelido'] . '" incompletos — não dá para montar o código do beneficiário.';
        }
        if ((int) $conta['proximo_nosso_numero'] > 99999999) {
            $pendencias[] = 'A sequência de nosso número da conta passou de 8 dígitos.';
        }
        if ($instrumento === 'boleto_pix' && !$conta['chave_pix']) {
            $avisos[] = 'A conta não tem chave Pix cadastrada. Sem ela, o Itaú usa a chave do cadastro do beneficiário; se não houver, o boleto pode sair sem o QR Code do Pix.';
        }
    }

    if (!$config['empresa_cnpj'] || !validarCnpj($config['empresa_cnpj'])) {
        $pendencias[] = 'CNPJ da empresa ausente ou inválido (Configurações › Empresa).';
    }
    if (!$config['empresa_razao_social']) {
        $pendencias[] = 'Razão social da empresa não preenchida (Configurações › Empresa).';
    }

    // ---- pagador (aluno + endereço principal)
    $aluno = null;
    $endereco = null;
    if (!$parcela['aluno_id']) {
        $pendencias[] = 'A parcela não está ligada a um aluno (pagador).';
    } else {
        $stmt = $pdo->prepare('SELECT * FROM alunos WHERE id = ?');
        $stmt->execute([$parcela['aluno_id']]);
        $aluno = $stmt->fetch();

        $stmt = $pdo->prepare('SELECT * FROM aluno_enderecos WHERE aluno_id = ? ORDER BY principal DESC, id LIMIT 1');
        $stmt->execute([$parcela['aluno_id']]);
        $endereco = $stmt->fetch() ?: null;

        if (!$aluno['cpf'] || !validarCpf($aluno['cpf'])) {
            $pendencias[] = 'O aluno ' . $aluno['nome'] . ' está sem CPF válido.';
        }
        if (!$endereco) {
            $pendencias[] = 'O aluno ' . $aluno['nome'] . ' não tem endereço cadastrado.';
        } else {
            $faltando = [];
            foreach (['logradouro' => 'logradouro', 'bairro' => 'bairro', 'cidade' => 'cidade'] as $campo => $rotulo) {
                if (!trim((string) $endereco[$campo])) {
                    $faltando[] = $rotulo;
                }
            }
            if (strlen(apenasDigitos((string) $endereco['cep'])) !== 8) {
                $faltando[] = 'CEP (8 dígitos)';
            }
            if (!preg_match('/^[A-Z]{2}$/', strtoupper((string) $endereco['uf']))) {
                $faltando[] = 'UF';
            }
            if ($faltando) {
                $pendencias[] = 'Endereço do aluno incompleto: ' . implode(', ', $faltando) . '.';
            }
        }
    }

    // ---- desconto por pontualidade (códigos do Itaú: 01 = valor fixo até a
    // data, 02 = percentual até a data)
    // Parcela vencida perdeu o desconto por pontualidade.
    $pontualidade = $vencida ? null : descontoPontualidadeParcela($pdo, $parcela);
    $desconto = blocoDescontoItau($pontualidade);
    if ($pontualidade) {
        if ($pontualidade['tipo'] !== 'valor') {
            $avisos[] = 'Desconto percentual usa o código 02, que a documentação do Itaú descreve como "percentual (mensal)". Confirme no sandbox se ele é aplicado como percentual simples sobre o título.';
        }
        if ($pontualidade['data_limite'] < date('Y-m-d')) {
            $avisos[] = 'O prazo do desconto por pontualidade (' . formatDate($pontualidade['data_limite']) . ') já passou; o banco pode recusar ou ignorar o desconto.';
        }
    }

    // ---- parcela vencida: multa + juros do atraso já entram no valor; ao
    // banco vai só o juros (continua correndo depois do novo vencimento) e a
    // multa isenta — ela já foi cobrada uma vez, aqui.
    if ($vencida && $conta) {
        $encargos = encargosAtrasoParcela($conta, (float) $parcela['valor'], $parcela['vencimento_original']);
        if ($encargos['total'] > 0) {
            $parcela['encargos'] = $encargos;
            $parcela['valor'] = round((float) $parcela['valor'] + $encargos['total'], 2);
            $avisos[] = 'Atraso de ' . $encargos['dias'] . ' dia(s): multa ' . formatMoney($encargos['multa']) . ' + juros ' . formatMoney($encargos['juros'])
                . ' — boleto de ' . formatMoney($parcela['valor']) . '.';
        }
    }

    // ---- payload (montado mesmo com pendências, para dar para conferir)
    $valor = number_format((float) $parcela['valor'], 2, '.', '');
    $diaSeguinte = date('Y-m-d', strtotime($parcela['data_vencimento'] . ' +1 day'));

    $juros = ['codigo_tipo_juros' => $conta['juros_tipo'] ?? '05'];
    if (($conta['juros_tipo'] ?? '05') !== '05') {
        $juros['percentual_juros'] = number_format((float) $conta['juros_percentual'], 5, '.', '');
        $juros['data_juros'] = $diaSeguinte;
    }
    $multa = ['codigo_tipo_multa' => $vencida ? '03' : ($conta['multa_tipo'] ?? '03')];
    if ($multa['codigo_tipo_multa'] !== '03') {
        $multa['percentual_multa'] = number_format((float) $conta['multa_percentual'], 5, '.', '');
        $multa['data_multa'] = $diaSeguinte;
    }

    $logradouro = $endereco
        ? trim(implode(', ', array_filter([$endereco['logradouro'], $endereco['numero'], $endereco['complemento']])))
        : '';

    $payload = [
        'etapa_processo_boleto' => 'efetivacao',
        'beneficiario' => [
            'id_beneficiario' => $idBeneficiario ?? '',
        ],
        'dado_boleto' => [
            'descricao_instrumento_cobranca' => $instrumento,
            'tipo_boleto' => 'a vista',
            'codigo_carteira' => $conta['itau_carteira'] ?? '109',
            'forma_envio' => 'impressao',
            'codigo_especie' => $conta['itau_especie'] ?? '04',
            'valor_abatimento' => '0.00',
            'data_emissao' => date('Y-m-d'),
            'codigo_tipo_vencimento' => 3,
            'valor_total_titulo' => $valor,
            'pagador' => [
                'pessoa' => [
                    'nome_pessoa' => $aluno['nome'] ?? '',
                    'tipo_pessoa' => [
                        'codigo_tipo_pessoa' => 'F',
                        'numero_cadastro_pessoa_fisica' => apenasDigitos((string) ($aluno['cpf'] ?? '')),
                    ],
                ],
                'endereco' => [
                    'nome_logradouro' => $logradouro,
                    'nome_bairro' => $endereco['bairro'] ?? '',
                    'nome_cidade' => $endereco['cidade'] ?? '',
                    'sigla_UF' => strtoupper((string) ($endereco['uf'] ?? '')),
                    'numero_CEP' => apenasDigitos((string) ($endereco['cep'] ?? '')),
                ],
            ],
            'dados_individuais_boleto' => [[
                'numero_nosso_numero' => formatarNossoNumeroItau((int) ($conta['proximo_nosso_numero'] ?? 0)),
                'data_vencimento' => $parcela['data_vencimento'],
                'valor_titulo' => $valor,
            ]],
            'juros' => $juros,
            'multa' => $multa,
            'desconto' => $desconto,
        ],
    ];
    // Chave Pix da conta (opcional na API): vai em dados_qrcode.chave. Sem
    // ela, o Itaú usa a chave do cadastro do beneficiário, se houver.
    if ($instrumento === 'boleto_pix' && !empty($conta['chave_pix'])) {
        $payload['dados_qrcode'] = ['chave' => trim($conta['chave_pix'])];
    }

    return [
        'parcela' => $parcela,
        'aluno' => $aluno,
        'conta' => $conta,
        'instrumento' => $instrumento,
        'pendencias' => $pendencias,
        'avisos' => $avisos,
        'payload' => limparTextoItau($payload),
    ];
}

// ---------------------------------------------------------------------
// API do banco: OAuth 2.0 (client credentials) + certificado (mTLS)
// ---------------------------------------------------------------------

// Caminho do registro de boleto com Pix (bolecode), somado à URL base da
// API cadastrada na conta.
const ITAU_ENDPOINT_BOLETO_PIX = '/boletos-pix';

// API de Cobrança v2 do Itaú (cash_management): registra boleto e bolecode
// em <base>/boletos. A URL base da conta diz qual API a aplicação usa.
function ehApiCobrancaV2Itau(array $conta): bool
{
    return str_contains((string) ($conta['api_url_base'] ?? ''), 'cash_management');
}

function endpointBoletoItau(array $conta): string
{
    return ehApiCobrancaV2Itau($conta) ? '/boletos' : ITAU_ENDPOINT_BOLETO_PIX;
}

/**
 * Ajusta o corpo ao que a Cobrança v2 valida (respostas de erro do banco):
 *  - etapa "simulacao" vira "validacao" (valida sem registrar);
 *  - valores (valor_*) só com números: 17 dígitos, 2 casas decimais
 *    (R$ 1,00 = "00000000000000100");
 *  - percentuais (percentual_*) só com números: 12 dígitos, 5 casas
 *    decimais (1% = "000000100000");
 *  - desconto_expresso obrigatório (false);
 *  - sem desconto, o bloco "desconto" não vai (lista vazia é recusada).
 */
function adaptarCorpoCobrancaV2Itau(array $payload): array
{
    if (($payload['etapa_processo_boleto'] ?? '') === 'simulacao') {
        $payload['etapa_processo_boleto'] = 'validacao';
    }
    $payload['dado_boleto']['desconto_expresso'] = false;
    if (($payload['dado_boleto']['desconto']['codigo_tipo_desconto'] ?? '00') === '00' || empty($payload['dado_boleto']['desconto']['descontos'])) {
        unset($payload['dado_boleto']['desconto']);
    }
    $valores = function ($dados) use (&$valores) {
        foreach ($dados as $chave => $valor) {
            if (is_array($valor)) {
                $dados[$chave] = $valores($valor);
            } elseif (is_string($chave) && str_starts_with($chave, 'valor_') && preg_match('/^\d+(\.\d{1,2})?$/', (string) $valor)) {
                $dados[$chave] = str_pad((string) (int) round((float) $valor * 100), 17, '0', STR_PAD_LEFT);
            } elseif (is_string($chave) && str_starts_with($chave, 'percentual_') && preg_match('/^\d+(\.\d{1,5})?$/', (string) $valor)) {
                $dados[$chave] = str_pad((string) (int) round((float) $valor * 100000), 12, '0', STR_PAD_LEFT);
            }
        }
        return $dados;
    };
    return $valores($payload);
}

// O firewall do Itaú (CloudFront) responde 403 "Request blocked" a
// requisições sem User-Agent — e o cURL do PHP não manda um por padrão.
const ITAU_USER_AGENT = 'UniPHD/1.0';

function pastaCertificadosConta(int $contaId): string
{
    $pasta = caminhoStorage('certificados/conta_' . $contaId);
    if (!is_dir($pasta)) {
        @mkdir($pasta, 0700, true);
    }
    return $pasta;
}

/**
 * Valida e grava o par certificado (.crt/.pem) + chave privada (.key) de
 * uma conta. Confere que a chave abre com a senha informada e que pertence
 * ao certificado — um par trocado só daria erro genérico de TLS lá no banco.
 * Retorna ['ok' => bool, 'mensagem' => string, 'validade' => ?string,
 * 'certificado' => caminho, 'chave' => caminho].
 */
function salvarCertificadoApi(int $contaId, string $certificadoTmp, string $chaveTmp, string $senhaChave): array
{
    $certificadoPem = (string) file_get_contents($certificadoTmp);
    $chavePem = (string) file_get_contents($chaveTmp);

    $x509 = @openssl_x509_read($certificadoPem);
    if (!$x509) {
        return ['ok' => false, 'mensagem' => 'O arquivo do certificado não é um certificado válido (formato PEM: .crt ou .pem).'];
    }
    $chavePrivada = @openssl_pkey_get_private($chavePem, $senhaChave !== '' ? $senhaChave : null);
    if (!$chavePrivada) {
        return ['ok' => false, 'mensagem' => 'Não foi possível abrir a chave privada (.key). Confira o arquivo e a senha da chave.'];
    }
    if (!openssl_x509_check_private_key($x509, $chavePrivada)) {
        return ['ok' => false, 'mensagem' => 'A chave privada não pertence a este certificado. Envie o par correto.'];
    }

    $dados = openssl_x509_parse($x509);
    $validade = isset($dados['validTo_time_t']) ? date('Y-m-d', $dados['validTo_time_t']) : null;
    if ($validade && $validade < date('Y-m-d')) {
        return ['ok' => false, 'mensagem' => 'Este certificado venceu em ' . formatDate($validade) . '.'];
    }

    // Confere a gravação: sem isso a conta ficava "instalada" no banco sem
    // os arquivos existirem (pasta storage/ sem permissão no servidor).
    $pasta = pastaCertificadosConta($contaId);
    if ($problema = problemaPastaUpload($pasta)) {
        return ['ok' => false, 'mensagem' => 'Não foi possível guardar o certificado. ' . $problema];
    }
    $caminhoCertificado = $pasta . '/certificado.crt';
    $caminhoChave = $pasta . '/chave.key';
    if (@file_put_contents($caminhoCertificado, $certificadoPem) === false || @file_put_contents($caminhoChave, $chavePem) === false) {
        return ['ok' => false, 'mensagem' => 'Não foi possível gravar os arquivos do certificado em ' . $pasta . '. Confira a permissão da pasta storage/ no servidor.'];
    }
    @chmod($caminhoCertificado, 0600);
    @chmod($caminhoChave, 0600);

    return ['ok' => true, 'mensagem' => '', 'validade' => $validade, 'certificado' => $caminhoCertificado, 'chave' => $caminhoChave];
}

/**
 * O que falta configurar para a conta conseguir falar com a API. Lista
 * vazia = dá para tentar a conexão.
 */
function temCertificadoApi(array $conta): bool
{
    return $conta['api_certificado_arquivo'] && is_file($conta['api_certificado_arquivo'])
        && $conta['api_chave_arquivo'] && is_file($conta['api_chave_arquivo']);
}

function pendenciasApiConta(array $conta): array
{
    $faltando = [];
    if ($conta['integracao'] !== 'itau') {
        $faltando[] = 'integração Itaú desligada';
    }
    if (!$conta['api_client_id']) {
        $faltando[] = 'client_id';
    }
    if (!$conta['api_client_secret_cripto']) {
        $faltando[] = 'client_secret';
    }
    if (!$conta['api_url_token']) {
        $faltando[] = 'URL do token';
    }
    // O sandbox do Itaú autentica só com client_id/secret; o certificado
    // (mTLS) é exigência de produção.
    if ($conta['api_ambiente'] === 'producao' && !temCertificadoApi($conta)) {
        // Marcado como instalado, mas os arquivos não estão no servidor
        // (gravação falhou ou a pasta storage/ foi trocada).
        $faltando[] = $conta['api_certificado_arquivo']
            ? 'certificado e chave — os arquivos não foram encontrados no servidor em ' . dirname($conta['api_certificado_arquivo'])
                . '; confira a permissão da pasta storage/ e envie os dois arquivos de novo'
            : 'certificado e chave (obrigatórios em produção)';
    }
    return $faltando;
}

// Com certificado instalado ele é sempre enviado (também no sandbox, onde
// é opcional): assim o teste já valida o par antes da produção.
function aplicarCertificadoItau($curl, array $conta): void
{
    if (!temCertificadoApi($conta)) {
        return;
    }
    curl_setopt($curl, CURLOPT_SSLCERT, $conta['api_certificado_arquivo']);
    curl_setopt($curl, CURLOPT_SSLKEY, $conta['api_chave_arquivo']);
    $senhaChave = descriptografar($conta['api_chave_senha_cripto']);
    if ($senhaChave !== null && $senhaChave !== '') {
        curl_setopt($curl, CURLOPT_SSLKEYPASSWD, $senhaChave);
    }
}

/**
 * Pede (ou reaproveita do cache) o access_token OAuth 2.0 da conta. O
 * token é guardado cifrado e renovado um minuto antes de expirar, para não
 * pedir um novo a cada boleto.
 * Retorna ['ok' => bool, 'token' => ?string, 'mensagem' => string].
 */
function obterTokenItau(PDO $pdo, array $conta, bool $forcarNovo = false): array
{
    $faltando = pendenciasApiConta($conta);
    if ($faltando) {
        return ['ok' => false, 'token' => null, 'mensagem' => 'Falta configurar: ' . implode(', ', $faltando) . '.'];
    }

    if (!$forcarNovo && $conta['api_token_expira_em'] && strtotime($conta['api_token_expira_em']) > time() + 60) {
        $tokenCache = descriptografar($conta['api_token_cripto']);
        if ($tokenCache) {
            return ['ok' => true, 'token' => $tokenCache, 'escopos' => escoposTokenItau(['access_token' => $tokenCache]), 'mensagem' => 'Token reaproveitado do cache.'];
        }
    }

    $clientSecret = descriptografar($conta['api_client_secret_cripto']);
    if ($clientSecret === null) {
        return ['ok' => false, 'token' => null, 'mensagem' => 'Não foi possível ler o client_secret salvo. Digite-o de novo na conta bancária.'];
    }

    $curl = curl_init($conta['api_url_token']);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'client_credentials',
            'client_id' => $conta['api_client_id'],
            'client_secret' => $clientSecret,
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
        CURLOPT_USERAGENT => ITAU_USER_AGENT,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    aplicarCertificadoItau($curl, $conta);

    $inicio = microtime(true);
    $resposta = curl_exec($curl);
    $erroCurl = curl_error($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    registrarChamadaBanco($pdo, $conta, 'token', 'POST', $conta['api_url_token'], ['grant_type' => 'client_credentials', 'client_id' => $conta['api_client_id']],
        $resposta === false ? null : $status, $resposta === false ? null : $resposta, null, (int) ((microtime(true) - $inicio) * 1000),
        $resposta === false ? 'Falha de conexão: ' . $erroCurl : '');

    if ($resposta === false) {
        return ['ok' => false, 'token' => null, 'mensagem' => 'Falha de conexão com o banco: ' . $erroCurl];
    }

    $json = json_decode((string) $resposta, true);
    if ($status !== 200 || empty($json['access_token'])) {
        $detalhe = $json['error_description'] ?? $json['message'] ?? $json['error'] ?? mb_substr(trim(strip_tags((string) $resposta)), 0, 150);
        return ['ok' => false, 'token' => null, 'mensagem' => "O banco recusou (HTTP {$status}): {$detalhe}"];
    }

    $expiraEm = date('Y-m-d H:i:s', time() + max(60, (int) ($json['expires_in'] ?? 300)));
    $stmt = $pdo->prepare('UPDATE contas_bancarias SET api_token_cripto = ?, api_token_expira_em = ? WHERE id = ?');
    $stmt->execute([criptografar($json['access_token']), $expiraEm, $conta['id']]);

    $escopos = escoposTokenItau($json);
    return ['ok' => true, 'token' => $json['access_token'], 'escopos' => $escopos,
        'mensagem' => 'Token obtido, válido até ' . formatDateTime($expiraEm) . '.'
            . ($escopos !== null ? ' Permissões (escopos) da aplicação: ' . ($escopos ?: 'nenhuma') . '.' : '')];
}

// Boleto de exemplo da documentação do Itaú. O sandbox não valida dados de
// verdade: só responde aos cenários já mapeados (este é um deles) e devolve
// "Cenário de teste não mapeado" para qualquer outro conteúdo.
const ITAU_EXEMPLO_SANDBOX = [
    'etapa_processo_boleto' => 'efetivacao',
    'beneficiario' => ['id_beneficiario' => '150000052061'],
    'dado_boleto' => [
        'descricao_instrumento_cobranca' => 'boleto_pix',
        'tipo_boleto' => 'a vista',
        'codigo_carteira' => '109',
        'codigo_especie' => '01',
        'valor_total_titulo' => '1234.56',
        'pagador' => [
            'pessoa' => [
                'nome_pessoa' => 'João da Silva',
                'tipo_pessoa' => ['codigo_tipo_pessoa' => 'F', 'numero_cadastro_pessoa_fisica' => '12345678901'],
            ],
            'endereco' => [
                'nome_logradouro' => 'Rua das Flores',
                'nome_bairro' => 'Centro',
                'nome_cidade' => 'São Paulo',
                'sigla_UF' => 'SP',
                'numero_CEP' => '01310100',
            ],
        ],
        'dados_individuais_boleto' => [[
            'numero_nosso_numero' => '12345678',
            'data_vencimento' => '2026-12-31',
            'valor_titulo' => '1234.56',
        ]],
    ],
];

/**
 * POST do JSON no endpoint de boleto + Pix da conta. Só fala com o banco:
 * não grava nada.
 * Retorna ['ok' => bool, 'status' => int, 'mensagem' => string,
 * 'requisicao' => array, 'resposta' => mixed].
 */
/* ---------------- Auditoria das chamadas ao banco ---------------- */

/**
 * Contexto da próxima chamada ao banco (para a auditoria saber a parcela).
 * Sem argumento, só devolve o atual.
 */
function contextoChamadaBanco(?array $novo = null): array
{
    static $contexto = [];
    if ($novo !== null) {
        $contexto = $novo;
    }
    return $contexto;
}

// Esconde segredos e encurta o que é grande demais para ler (imagem do QR).
function mascararParaAuditoria($dados)
{
    if (!is_array($dados)) {
        return $dados;
    }
    foreach ($dados as $chave => $valor) {
        $nome = strtolower((string) $chave);
        if (is_array($valor)) {
            $dados[$chave] = mascararParaAuditoria($valor);
        } elseif (preg_match('/secret|senha|password|access_token|refresh_token/', $nome)) {
            $dados[$chave] = '***';
        } elseif ($nome === 'base64' && is_string($valor) && strlen($valor) > 200) {
            $dados[$chave] = '[imagem do QR Code — ' . strlen($valor) . ' caracteres]';
        }
    }
    return $dados;
}

/**
 * Grava uma chamada do sistema ao banco: o que foi enviado, o que voltou,
 * quem pediu (usuário do sistema, aluno no portal ou rotina automática) e a
 * parcela (contextoChamadaBanco). Nunca derruba a operação: falhou, só loga.
 */
function registrarChamadaBanco(PDO $pdo, array $conta, string $operacao, string $metodo, string $url, $requisicao,
    ?int $status, $resposta, ?string $correlacao, int $duracaoMs, string $mensagem = ''): void
{
    try {
        $usuarioId = function_exists('usuarioLogado') && usuarioLogado() ? usuarioAtualId() : null;
        $alunoId = !$usuarioId && function_exists('alunoLogado') && alunoLogado() ? alunoAtualId() : null;
        $paraTexto = function ($valor) {
            if ($valor === null) {
                return null;
            }
            if ($valor === []) {
                return '{}';
            }
            if (is_string($valor)) {
                $json = json_decode($valor, true);
                $valor = is_array($json) ? $json : $valor;
            }
            return is_array($valor) ? json_encode(mascararParaAuditoria($valor), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : mb_substr($valor, 0, 20000);
        };
        $stmt = $pdo->prepare(
            'INSERT INTO banco_chamadas (conta_bancaria_id, operacao, conta_receber_id, usuario_id, aluno_id, origem, metodo, url, status_http, sucesso,
                                         correlation_id, requisicao, resposta, mensagem, duracao_ms)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $conta['id'] ?? null, $operacao, contextoChamadaBanco()['conta_receber_id'] ?? null, $usuarioId, $alunoId,
            $usuarioId ? 'sistema' : ($alunoId ? 'portal' : 'automatico'), $metodo, mb_substr($url, 0, 255), $status,
            $status !== null && $status >= 200 && $status < 300 ? 1 : 0, $correlacao, $paraTexto($requisicao), $paraTexto($resposta),
            $mensagem !== '' ? mb_strimwidth($mensagem, 0, 255, '…', 'UTF-8') : null, $duracaoMs,
        ]);
    } catch (Throwable $e) {
        error_log('[Auditoria do banco] não gravou a chamada: ' . $e->getMessage());
    }
}

/**
 * Chamadas ao banco de uma conta, para a aba Auditoria. $filtros:
 * data_inicio, data_fim, quem ('u:<id>', 'portal', 'automatico'),
 * operacao, resultado ('ok' / 'erro'), parcela (id da conta a receber).
 * Retorna ['itens', 'total', 'pagina', 'total_paginas'].
 */
function buscarChamadasBanco(PDO $pdo, int $contaId, array $filtros, int $pagina = 1, int $porPagina = 50): array
{
    $condicoes = ['bc.conta_bancaria_id = ?'];
    $parametros = [$contaId];
    if (!empty($filtros['data_inicio'])) {
        $condicoes[] = 'bc.criado_em >= ?';
        $parametros[] = $filtros['data_inicio'] . ' 00:00:00';
    }
    if (!empty($filtros['data_fim'])) {
        $condicoes[] = 'bc.criado_em <= ?';
        $parametros[] = $filtros['data_fim'] . ' 23:59:59';
    }
    $quem = (string) ($filtros['quem'] ?? '');
    if (str_starts_with($quem, 'u:')) {
        $condicoes[] = 'bc.usuario_id = ?';
        $parametros[] = (int) substr($quem, 2);
    } elseif (in_array($quem, ['portal', 'automatico'], true)) {
        $condicoes[] = 'bc.origem = ?';
        $parametros[] = $quem;
    }
    if (!empty($filtros['operacao'])) {
        $condicoes[] = 'bc.operacao = ?';
        $parametros[] = $filtros['operacao'];
    }
    if (($filtros['resultado'] ?? '') === 'ok') {
        $condicoes[] = 'bc.sucesso = 1';
    } elseif (($filtros['resultado'] ?? '') === 'erro') {
        $condicoes[] = 'bc.sucesso = 0';
    }
    if (!empty($filtros['parcela'])) {
        $condicoes[] = 'bc.conta_receber_id = ?';
        $parametros[] = (int) $filtros['parcela'];
    }
    // Busca livre: nome do aluno / descrição da parcela, ou números (CPF,
    // nosso número — que vai na URL das instruções e no corpo do registro).
    $busca = trim((string) ($filtros['busca'] ?? ''));
    if ($busca !== '') {
        $digitos = apenasDigitos($busca);
        $ou = ['ca.nome LIKE ?', 'a.nome LIKE ?', 'cr.descricao LIKE ?'];
        array_push($parametros, '%' . $busca . '%', '%' . $busca . '%', '%' . $busca . '%');
        if (strlen($digitos) >= 3) {
            $ou[] = "REPLACE(REPLACE(ca.cpf, '.', ''), '-', '') LIKE ?";
            $ou[] = 'bc.url LIKE ?';
            $ou[] = 'bc.requisicao LIKE ?';
            array_push($parametros, '%' . $digitos . '%', '%' . $digitos . '%', '%' . $digitos . '%');
        }
        $condicoes[] = '(' . implode(' OR ', $ou) . ')';
    }
    $where = 'WHERE ' . implode(' AND ', $condicoes);
    $juncoes = 'LEFT JOIN usuarios u ON u.id = bc.usuario_id
         LEFT JOIN alunos a ON a.id = bc.aluno_id
         LEFT JOIN contas_receber cr ON cr.id = bc.conta_receber_id
         LEFT JOIN alunos ca ON ca.id = cr.aluno_id';

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM banco_chamadas bc {$juncoes} {$where}");
    $stmt->execute($parametros);
    $total = (int) $stmt->fetchColumn();
    $pagina = max(1, $pagina);
    $offset = ($pagina - 1) * $porPagina;
    $stmt = $pdo->prepare(
        "SELECT bc.*, u.nome AS usuario_nome, a.nome AS aluno_nome, cr.descricao AS parcela_descricao, ca.nome AS parcela_aluno,
                ca.cpf AS parcela_cpf, cr.valor AS parcela_valor, cr.data_vencimento AS parcela_vencimento, cr.status AS parcela_status
         FROM banco_chamadas bc {$juncoes}
         {$where} ORDER BY bc.id DESC LIMIT {$porPagina} OFFSET {$offset}"
    );
    $stmt->execute($parametros);
    $itens = $stmt->fetchAll();

    // Qual boleto cada chamada tocou: o nosso número sai da URL das
    // instruções (/boletos/{id_boleto}/...) ou do corpo do registro.
    $numeros = [];
    foreach ($itens as $i => $item) {
        $itens[$i]['nosso_numero'] = nossoNumeroDaChamadaBanco($item);
        if ($itens[$i]['nosso_numero']) {
            $numeros[] = $itens[$i]['nosso_numero'];
        }
    }
    $boletos = [];
    if ($numeros) {
        $numeros = array_values(array_unique($numeros));
        $stmt = $pdo->prepare('SELECT * FROM cobrancas_bancarias WHERE conta_bancaria_id = ? AND nosso_numero IN (' . implode(',', array_fill(0, count($numeros), '?')) . ')');
        $stmt->execute(array_merge([$contaId], $numeros));
        foreach ($stmt->fetchAll() as $cb) {
            $boletos[(int) $cb['nosso_numero']] = $cb;
        }
    }
    foreach ($itens as $i => $item) {
        $itens[$i]['boleto'] = $item['nosso_numero'] ? ($boletos[$item['nosso_numero']] ?? null) : null;
    }
    return ['itens' => $itens, 'total' => $total, 'pagina' => $pagina, 'total_paginas' => max(1, (int) ceil($total / $porPagina))];
}

function nossoNumeroDaChamadaBanco(array $chamada): ?int
{
    // id_boleto = agência (4) + conta (7) + DAC (1) + carteira (3) + nosso número.
    if (preg_match('~/boletos/(\d{23,})(?:/|$)~', (string) $chamada['url'], $m)) {
        return (int) substr($m[1], 15);
    }
    if (in_array($chamada['operacao'], ['registro_boleto', 'validacao_boleto'], true) && $chamada['requisicao']) {
        $corpo = json_decode($chamada['requisicao'], true);
        $corpo = $corpo['data'] ?? $corpo;
        $numero = $corpo['dado_boleto']['dados_individuais_boleto'][0]['numero_nosso_numero'] ?? null;
        return $numero !== null && (int) $numero > 0 ? (int) $numero : null;
    }
    return null;
}

// Rótulos das operações na tela de auditoria.
function operacoesChamadaBanco(): array
{
    return [
        'registro_boleto' => 'Emissão de boleto + Pix',
        'validacao_boleto' => 'Validação de boleto (sem registrar)',
        'token' => 'Pedido de token',
        'cadastro_webhook' => 'Cadastro do webhook',
        'consulta_webhook' => 'Consulta do webhook',
        'exclusao_webhook' => 'Exclusão do webhook',
        'consulta_pagamento' => 'Consulta de pagamento',
        'cadastro_webhook_pix' => 'Cadastro do aviso de Pix',
        'consulta_webhook_pix' => 'Consulta do aviso de Pix',
        'exclusao_webhook_pix' => 'Exclusão do aviso de Pix',
        'confirmacao_pix' => 'Confirmação de Pix recebido',
        'baixa_boleto' => 'Baixa (cancelamento) do boleto',
        'alteracao_valor' => 'Alteração de valor do boleto',
        'alteracao_vencimento' => 'Alteração de vencimento do boleto',
        'alteracao_desconto' => 'Alteração de desconto do boleto',
    ];
}

/**
 * Escopos (permissões) liberados para a aplicação: vêm no campo "scope" da
 * resposta do token ou, se não vierem, dentro do próprio token (JWT, parte
 * do meio em base64). Só leitura, para diagnóstico — nada é validado aqui.
 * Retorna o texto dos escopos, '' se o token não traz nenhum, ou null se
 * não deu para ler.
 */
function escoposTokenItau(array $respostaToken): ?string
{
    if (isset($respostaToken['scope'])) {
        return trim(is_array($respostaToken['scope']) ? implode(' ', $respostaToken['scope']) : (string) $respostaToken['scope']);
    }
    $partes = explode('.', (string) ($respostaToken['access_token'] ?? ''));
    if (count($partes) !== 3) {
        return null;
    }
    $dados = json_decode((string) base64_decode(strtr($partes[1], '-_', '+/')), true);
    if (!is_array($dados)) {
        return null;
    }
    $escopo = $dados['scope'] ?? $dados['scp'] ?? null;
    if ($escopo === null) {
        return '';
    }
    return trim(is_array($escopo) ? implode(' ', $escopo) : (string) $escopo);
}

/**
 * Identificador de correlação exigido pelo Itaú em toda chamada: UUID v4
 * com hífens (a especificação valida com regex 8-4-4-4-12).
 */
function correlacaoItau(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

/**
 * A API de Boleto com Pix recusa textos com caracteres especiais
 * ([ : < > & ; ' " ` ( ) # * / | ü) e com as palavras http, javascript e
 * alert. Limpa todos os textos do JSON (nomes, endereço...) antes do envio;
 * datas e valores não têm esses caracteres e passam iguais.
 */
function limparTextoItau($valor)
{
    if (is_array($valor)) {
        return array_map('limparTextoItau', $valor);
    }
    if (!is_string($valor)) {
        return $valor;
    }
    $texto = str_replace(['ü', 'Ü'], ['u', 'U'], $valor);
    $texto = preg_replace('/https?|javascript|alert/i', '', $texto);
    $texto = preg_replace('~[\[\]:<>&;\'"`()#*/|,\\\\]~u', ' ', $texto);
    return trim(preg_replace('/\s{2,}/u', ' ', $texto));
}

function enviarBoletoPixItau(PDO $pdo, array $conta, array $payload): array
{
    $payload = limparTextoItau($payload);
    $falha = fn (string $mensagem) => ['ok' => false, 'status' => 0, 'mensagem' => $mensagem, 'requisicao' => $payload, 'resposta' => null];

    if (!$conta['api_url_base']) {
        return $falha('Falta a URL base da API na conta bancária.');
    }
    // Qual API do Itaú a aplicação usa, pela URL base da conta:
    //  - Cobrança v2 (cash_management, escopo cash_management/emissaocobranca.write):
    //    POST <base>/boletos, corpo dentro de "data" com codigo_canal_operacao;
    //  - Boleto com Pix (recebimentos-pix, escopos cob.write/cobv.write):
    //    POST <base>/boletos-pix, corpo solto.
    $endpoint = endpointBoletoItau($conta);
    $corpos = [$payload];
    if (ehApiCobrancaV2Itau($conta)) {
        $comCanal = ['codigo_canal_operacao' => 'API'] + adaptarCorpoCobrancaV2Itau($payload);
        // Se o banco não entender o envelope (400 de corpo/parse), tenta uma
        // vez sem ele — com 400 nada foi registrado, repetir não duplica.
        $corpos = [['data' => $comCanal], $comCanal];
    }

    foreach ($corpos as $indiceCorpo => $corpo) {
        // Token guardado ainda no prazo, mas recusado pelo banco (HTTP 401 —
        // revogado, trocado no portal do Itaú...): pede um novo e tenta de novo
        // uma vez. Com 401 o banco não processou nada, então repetir é seguro.
        for ($tentativa = 1; $tentativa <= 2; $tentativa++) {
            $token = obterTokenItau($pdo, $conta, $tentativa > 1);
            if (!$token['ok']) {
                return $falha('Não foi possível obter o token: ' . $token['mensagem']);
            }

            $curl = curl_init(rtrim($conta['api_url_base'], '/') . $endpoint);
            // Guarda os cabeçalhos da resposta: quando o portão do Itaú (AWS)
            // recusa, o motivo vem em x-amzn-ErrorType, não no corpo.
            $cabecalhosResposta = [];
            $correlacao = correlacaoItau();
            curl_setopt($curl, CURLOPT_HEADERFUNCTION, function ($c, $linha) use (&$cabecalhosResposta) {
                if (str_contains($linha, ':')) {
                    [$nome, $valor] = explode(':', $linha, 2);
                    $cabecalhosResposta[strtolower(trim($nome))] = trim($valor);
                }
                return strlen($linha);
            });
            curl_setopt_array($curl, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($corpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'Authorization: Bearer ' . $token['token'],
                    'x-itau-apikey: ' . $conta['api_client_id'],
                    'x-itau-correlationID: ' . $correlacao,
                ],
                CURLOPT_USERAGENT => ITAU_USER_AGENT,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 10,
            ]);
            aplicarCertificadoItau($curl, $conta);

            $inicio = microtime(true);
            $resposta = curl_exec($curl);
            $erroCurl = curl_error($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            curl_close($curl);
            $etapa = $corpo['data']['etapa_processo_boleto'] ?? $corpo['etapa_processo_boleto'] ?? '';
            registrarChamadaBanco($pdo, $conta, in_array($etapa, ['validacao', 'simulacao'], true) ? 'validacao_boleto' : 'registro_boleto', 'POST',
                rtrim($conta['api_url_base'], '/') . $endpoint, $corpo, $resposta === false ? null : $status, $resposta === false ? null : $resposta,
                $correlacao, (int) ((microtime(true) - $inicio) * 1000), $resposta === false ? 'Falha de conexão: ' . $erroCurl : '');

            if ($resposta === false) {
                return $falha('Falha de conexão com o banco: ' . $erroCurl);
            }
            if ($status !== 401) {
                break;
            }
        }
        $naoEntendeuCorpo = $status === 400 && preg_match('/parse|body|corpo da requisi/i', (string) $resposta);
        if (!$naoEntendeuCorpo || $indiceCorpo === count($corpos) - 1) {
            break;
        }
    }
    $payload = $corpo;

    $json = json_decode((string) $resposta, true);
    $ok = $status >= 200 && $status < 300;
    $mensagem = $ok ? "O banco aceitou (HTTP {$status})." : "O banco recusou (HTTP {$status}).";
    if (!$ok) {
        // Diagnóstico: tipo de erro do portão do Itaú, código da requisição
        // (para chamado no banco), nosso correlationID e as permissões do token.
        $detalhes = array_filter([
            !empty($cabecalhosResposta['x-amzn-errortype']) ? 'tipo: ' . explode(':', $cabecalhosResposta['x-amzn-errortype'])[0] : null,
            !empty($cabecalhosResposta['x-amzn-requestid']) ? 'requisição no banco: ' . $cabecalhosResposta['x-amzn-requestid'] : null,
            'correlationID: ' . $correlacao,
            ($token['escopos'] ?? null) !== null ? 'permissões do token: ' . ($token['escopos'] ?: 'nenhuma') : null,
        ]);
        $mensagem .= ' — ' . implode(' · ', $detalhes);
        if ($status === 403) {
            $mensagem .= ehApiCobrancaV2Itau($conta)
                ? '. A aplicação entrou, mas não tem acesso a esta API: a Cobrança v2 pede a permissão cash_management/emissaocobranca.write.'
                : '. A aplicação entrou, mas não tem acesso a esta API: o Boleto com Pix (recebimentos-pix) pede as permissões cob.write e cobv.write.'
                    . (str_contains((string) ($token['escopos'] ?? ''), 'cash_management/emissaocobranca.write')
                        ? ' Sua aplicação tem a da Cobrança v2: troque a URL base da conta para https://api.itau.com.br/cash_management/v2.' : '');
        }
    }
    return [
        'ok' => $ok,
        'status' => $status,
        'mensagem' => $mensagem,
        'requisicao' => $payload,
        'resposta' => $json ?? mb_substr(trim(strip_tags((string) $resposta)), 0, 500),
    ];
}

/**
 * Manda a cobrança de uma parcela com "etapa_processo_boleto": "simulacao":
 * o banco valida tudo e responde como se fosse registrar, mas não registra.
 * Aqui também nada é gravado nem o nosso número avança.
 */
function simularCobrancaItau(PDO $pdo, int $contaReceberId): array
{
    contextoChamadaBanco(['conta_receber_id' => $contaReceberId]);
    $previa = previaCobrancaItau($pdo, $contaReceberId);
    $falha = fn (string $mensagem) => ['ok' => false, 'status' => 0, 'mensagem' => $mensagem, 'requisicao' => $previa['payload'] ?? null, 'resposta' => null];

    if (!$previa['parcela']) {
        return $falha('Parcela não encontrada.');
    }
    if ($previa['pendencias']) {
        return $falha('Resolva as pendências da prévia antes de simular.');
    }

    $payload = $previa['payload'];
    $payload['etapa_processo_boleto'] = 'simulacao';
    return enviarBoletoPixItau($pdo, $previa['conta'], $payload);
}

/**
 * Envia o boleto de exemplo da documentação para a conta em sandbox — prova
 * que token, URL e cabeçalhos funcionam de ponta a ponta. Recusa produção:
 * lá o exemplo viraria um boleto registrado de verdade.
 */
function testarExemploSandboxItau(PDO $pdo, array $conta): array
{
    if ($conta['api_ambiente'] !== 'sandbox') {
        return ['ok' => false, 'status' => 0, 'mensagem' => 'O boleto de exemplo só pode ser enviado com a conta em Sandbox.', 'requisicao' => null, 'resposta' => null];
    }
    return enviarBoletoPixItau($pdo, $conta, ITAU_EXEMPLO_SANDBOX);
}

/**
 * Tudo que o boleto impresso e o modal do Pix mostram de uma parcela.
 * Com cobrança registrada no banco, valem a linha digitável, o código de
 * barras e o Pix copia-e-cola que o banco devolveu. Sem registro, a linha e
 * o código de barras são calculados localmente só para o desenho (o banco
 * recusa boleto não registrado) e não há Pix — ele é dinâmico, gerado pelo
 * banco junto com o boleto.
 */
function dadosBoletoParcela(PDO $pdo, int $contaReceberId): ?array
{
    $previa = previaCobrancaItau($pdo, $contaReceberId);
    if (!$previa['parcela']) {
        return null;
    }
    $parcela = $previa['parcela'];
    $conta = $previa['conta'];

    $stmt = $pdo->prepare(
        "SELECT * FROM cobrancas_bancarias WHERE conta_receber_id = ? AND status IN ('registrada', 'paga') ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([$contaReceberId]);
    $cobranca = $stmt->fetch() ?: null;
    // Com boleto no banco, valem a data e o valor dele (parcela vencida
    // ganha um boleto com vencimento novo).
    if ($cobranca) {
        $parcela['data_vencimento'] = $cobranca['data_vencimento'];
        $parcela['valor'] = $cobranca['valor'];
    }

    $endereco = null;
    if ($parcela['aluno_id']) {
        $stmt = $pdo->prepare('SELECT * FROM aluno_enderecos WHERE aluno_id = ? ORDER BY principal DESC, id LIMIT 1');
        $stmt->execute([$parcela['aluno_id']]);
        $endereco = $stmt->fetch() ?: null;
    }

    $calculo = null;
    if ($conta) {
        $nossoNumero = $cobranca ? (int) $cobranca['nosso_numero'] : (int) $conta['proximo_nosso_numero'];
        $calculo = calcularBoletoItau(
            $conta['agencia'], $conta['conta'], $conta['itau_carteira'], $nossoNumero,
            $parcela['data_vencimento'], (float) $parcela['valor']
        );
    }

    $pix = $cobranca && pixCopiaEColaValido($cobranca['pix_copia_cola']) ? $cobranca['pix_copia_cola'] : null;

    return [
        'parcela' => $parcela,
        'aluno' => $previa['aluno'],
        'endereco' => $endereco,
        'conta' => $conta,
        'empresa' => buscarConfiguracoes($pdo),
        'registrada' => (bool) $cobranca,
        'cobranca' => $cobranca,
        'linha_digitavel' => $cobranca['linha_digitavel'] ?? ($calculo['linha_digitavel'] ?? null),
        'codigo_barras' => $cobranca['codigo_barras'] ?? ($calculo['codigo_barras'] ?? null),
        'nosso_numero' => $calculo['nosso_numero_formatado'] ?? null,
        'agencia_codigo' => $calculo['agencia_codigo'] ?? null,
        'pix_copia_cola' => $pix,
        // Desconto com o prazo já passado não vale mais: nem aparece.
        // (calculado pelo vencimento original da parcela, não pelo do boleto).
        'desconto' => ($descontoParcela = descontoPontualidadeParcela($pdo, array_merge($parcela, ['data_vencimento' => $parcela['vencimento_original'] ?? $parcela['data_vencimento']])))
            && $descontoParcela['data_limite'] >= date('Y-m-d') ? $descontoParcela : null,
        // As pendências são do REGISTRO; depois de registrada não há o que cobrar.
        'pendencias' => $cobranca ? [] : $previa['pendencias'],
    ];
}

// Desconto por pontualidade no formato do Itaú: 00 = sem desconto,
// 01 = valor fixo até a data, 02 = percentual até a data.
function blocoDescontoItau(?array $pontualidade): array
{
    if (!$pontualidade) {
        return ['codigo_tipo_desconto' => '00'];
    }
    $item = ['data_desconto' => $pontualidade['data_limite']];
    if ($pontualidade['tipo'] === 'valor') {
        $item['valor_desconto'] = number_format($pontualidade['desconto'], 2, '.', '');
        return ['codigo_tipo_desconto' => '01', 'descontos' => [$item]];
    }
    $item['percentual_desconto'] = number_format($pontualidade['percentual'], 5, '.', '');
    return ['codigo_tipo_desconto' => '02', 'descontos' => [$item]];
}

// ---------------------------------------------------------------------
// Instruções sobre um boleto já registrado (Cobrança v2):
// PATCH /boletos/{id_boleto}/{baixa|data_vencimento|valor_nominal|desconto}
// id_boleto = agência (4) + conta (7) + DAC (1) + carteira (3) + nosso
// número (8). O banco responde 204 quando aceita.
// ---------------------------------------------------------------------

function idBoletoItau(array $conta, int $nossoNumero): ?string
{
    $beneficiario = idBeneficiarioItau($conta);
    $carteira = apenasDigitos((string) ($conta['itau_carteira'] ?? '')) ?: '109';
    return $beneficiario ? $beneficiario . str_pad($carteira, 3, '0', STR_PAD_LEFT) . formatarNossoNumeroItau($nossoNumero) : null;
}

// Cobrança registrada (no banco e em aberto) da parcela, com a conta dela.
function cobrancaRegistradaParcela(PDO $pdo, int $contaReceberId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT * FROM cobrancas_bancarias WHERE conta_receber_id = ? AND status = 'registrada' ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([$contaReceberId]);
    $cobranca = $stmt->fetch();
    if (!$cobranca) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM contas_bancarias WHERE id = ?');
    $stmt->execute([$cobranca['conta_bancaria_id']]);
    $cobranca['conta'] = $stmt->fetch() ?: null;
    return $cobranca;
}

function instrucaoBoletoItau(PDO $pdo, array $cobranca, string $instrucao, array $corpo, string $operacao): array
{
    $conta = $cobranca['conta'];
    $idBoleto = $conta ? idBoletoItau($conta, (int) $cobranca['nosso_numero']) : null;
    if (!$idBoleto) {
        return ['ok' => false, 'status' => 0, 'mensagem' => 'Confira agência, conta e dígito da conta bancária.', 'resposta' => null];
    }
    // As instruções ficam na Cobrança v2 (cash_management).
    $base = ehApiCobrancaV2Itau($conta) ? rtrim($conta['api_url_base'], '/') : 'https://api.itau.com.br/cash_management/v2';
    contextoChamadaBanco(['conta_receber_id' => (int) $cobranca['conta_receber_id']]);
    $resultado = chamarApiNotificacoesItau($pdo, $conta, 'PATCH', '/boletos/' . $idBoleto . '/' . $instrucao, $corpo, $base, $operacao);
    // 204 = feito. 200 com "Aguardando aprovação" = o banco guardou a
    // instrução, mas ela só vale depois que alguém da empresa aprovar no
    // Itaú (conforme a configuração da conta).
    $texto = is_array($resultado['resposta']) ? json_encode($resultado['resposta'], JSON_UNESCAPED_UNICODE) : (string) $resultado['resposta'];
    $resultado['aguardando_aprovacao'] = $resultado['ok'] && mb_stripos($texto, 'aguardando aprova') !== false;
    return $resultado;
}

const AVISO_APROVACAO_ITAU = 'Atenção: o Itaú deixou a instrução aguardando aprovação — ela só vale depois de aprovada no internet banking/app do Itaú (área de cobrança).';

/**
 * Pede ao banco a baixa (cancelamento) do boleto da parcela. Sem boleto
 * registrado não há o que fazer (ok). Aceito: a cobrança fica 'baixada'.
 * Retorna ['ok' => bool, 'mensagem' => string, 'havia_boleto' => bool].
 */
function baixarBoletoBancoItau(PDO $pdo, int $contaReceberId): array
{
    $cobranca = cobrancaRegistradaParcela($pdo, $contaReceberId);
    if (!$cobranca) {
        return ['ok' => true, 'mensagem' => '', 'havia_boleto' => false];
    }
    $resultado = instrucaoBoletoItau($pdo, $cobranca, 'baixa', [], 'baixa_boleto');
    if ($resultado['ok']) {
        $pdo->prepare("UPDATE cobrancas_bancarias SET status = 'baixada', mensagem_erro = NULL WHERE id = ?")->execute([$cobranca['id']]);
        return ['ok' => true, 'havia_boleto' => true,
            'mensagem' => $resultado['aguardando_aprovacao'] ? 'Baixa enviada ao banco. ' . AVISO_APROVACAO_ITAU : 'Boleto baixado no banco.'];
    }
    $pdo->prepare('UPDATE cobrancas_bancarias SET mensagem_erro = ? WHERE id = ?')
        ->execute([mb_strimwidth('Baixa: ' . $resultado['mensagem'], 0, 255, '…', 'UTF-8'), $cobranca['id']]);
    return ['ok' => false, 'mensagem' => 'O banco não aceitou a baixa do boleto: ' . $resultado['mensagem'], 'havia_boleto' => true];
}

/**
 * Leva ao boleto registrado as mudanças da parcela. Valor e vencimento vão
 * por instrução (PATCH), uma por vez, parando na primeira recusa; mudam o
 * código de barras, e a linha digitável guardada é recalculada (o cálculo do
 * sistema é o mesmo do Itaú).
 * Desconto: a instrução de desconto do Itaú recusa todos os formatos de
 * corpo testados (e a documentação não traz o dela). Então, mudando o
 * desconto, o boleto antigo é baixado e 'reemitir' volta true: quem chama
 * salva a parcela e registra um boleto novo, já com tudo atualizado.
 * Retorna ['ok' => bool, 'mensagem' => string, 'havia_boleto' => bool, 'reemitir' => bool,
 *          'aceito' => ['valor' => bool, 'vencimento' => bool, 'desconto' => bool]].
 */
function alterarBoletoBancoItau(PDO $pdo, array $antes, array $depois): array
{
    $aceito = ['valor' => false, 'vencimento' => false, 'desconto' => false];
    $cobranca = cobrancaRegistradaParcela($pdo, (int) $antes['id']);
    if (!$cobranca) {
        return ['ok' => true, 'mensagem' => '', 'havia_boleto' => false, 'reemitir' => false, 'aceito' => $aceito];
    }

    if (blocoDescontoItau(descontoPontualidadeParcela($pdo, $antes)) !== blocoDescontoItau(descontoPontualidadeParcela($pdo, $depois))) {
        $baixa = baixarBoletoBancoItau($pdo, (int) $antes['id']);
        if (!$baixa['ok']) {
            return ['ok' => false, 'havia_boleto' => true, 'reemitir' => false, 'aceito' => $aceito, 'mensagem' => $baixa['mensagem']];
        }
        return ['ok' => true, 'havia_boleto' => true, 'reemitir' => true, 'aguardando_aprovacao' => str_contains($baixa['mensagem'], AVISO_APROVACAO_ITAU),
            'aceito' => ['valor' => true, 'vencimento' => true, 'desconto' => true], 'mensagem' => $baixa['mensagem']];
    }
    // Uma instrução por vez; na primeira recusa, para. O que já foi aceito
    // vale (o banco já mudou) e o código de barras acompanha.
    $instrucoes = [];
    if (round((float) $antes['valor'], 2) !== round((float) $depois['valor'], 2)) {
        $instrucoes['valor'] = ['valor_nominal', ['valor_titulo' => number_format((float) $depois['valor'], 2, '.', '')], 'alteracao_valor'];
    }
    if ($antes['data_vencimento'] !== $depois['data_vencimento']) {
        $instrucoes['vencimento'] = ['data_vencimento', ['data_vencimento' => $depois['data_vencimento']], 'alteracao_vencimento'];
    }

    $recusa = null;
    $aguardando = false;
    foreach ($instrucoes as $campo => [$instrucao, $corpo, $operacao]) {
        $r = instrucaoBoletoItau($pdo, $cobranca, $instrucao, $corpo, $operacao);
        if (!$r['ok']) {
            $recusa = "O banco não aceitou a mudança de {$campo}: " . $r['mensagem'];
            break;
        }
        $aceito[$campo] = true;
        $aguardando = $aguardando || $r['aguardando_aprovacao'];
    }

    if ($aceito['valor'] || $aceito['vencimento']) {
        atualizarCodigoBoletoCobranca($pdo, $cobranca,
            $aceito['vencimento'] ? $depois['data_vencimento'] : $antes['data_vencimento'],
            (float) ($aceito['valor'] ? $depois['valor'] : $antes['valor']));
    }
    return ['ok' => $recusa === null, 'havia_boleto' => true, 'reemitir' => false, 'aceito' => $aceito, 'aguardando_aprovacao' => $aguardando,
        'mensagem' => ($recusa ?? 'Boleto alterado também no banco.') . ($aguardando ? ' ' . AVISO_APROVACAO_ITAU : '')];
}

function atualizarCodigoBoletoCobranca(PDO $pdo, array $cobranca, string $vencimento, float $valor): void
{
    $conta = $cobranca['conta'];
    $calculo = calcularBoletoItau($conta['agencia'], $conta['conta'], $conta['itau_carteira'], (int) $cobranca['nosso_numero'], $vencimento, $valor);
    $pdo->prepare('UPDATE cobrancas_bancarias SET valor = ?, data_vencimento = ?, linha_digitavel = ?, codigo_barras = ? WHERE id = ?')
        ->execute([$valor, $vencimento, apenasDigitos($calculo['linha_digitavel']), apenasDigitos($calculo['codigo_barras']), $cobranca['id']]);
}

/**
 * Boletos de parcelas canceladas pelo sistema (matrícula cancelada, turma
 * concluída, inscrição do portal vencida) recebem a baixa no banco. Roda
 * ao fim da requisição que cancelou (agendarBaixaBoletosCancelados) e
 * repete as que falharam, no máximo uma vez por hora cada.
 */
function baixarBoletosCanceladosNoBanco(PDO $pdo, int $limite = 20): void
{
    $stmt = $pdo->query(
        "SELECT cb.conta_receber_id, cb.mensagem_erro, cr.descricao FROM cobrancas_bancarias cb JOIN contas_receber cr ON cr.id = cb.conta_receber_id
         WHERE cb.status = 'registrada' AND cr.status = 'cancelado'
           AND NOT (COALESCE(cb.mensagem_erro, '') LIKE 'Baixa:%' AND cb.atualizado_em > NOW() - INTERVAL 1 HOUR)
         ORDER BY cb.id LIMIT " . max(1, $limite)
    );
    foreach ($stmt->fetchAll() as $linha) {
        $resultado = baixarBoletoBancoItau($pdo, (int) $linha['conta_receber_id']);
        // Avisa no sino só na primeira falha, não a cada nova tentativa.
        if (!$resultado['ok'] && !str_starts_with((string) $linha['mensagem_erro'], 'Baixa:')) {
            criarNotificacao($pdo, 'pagamento', 'Boleto cancelado não foi baixado no banco',
                $linha['descricao'] . ' — ' . $resultado['mensagem'] . ' O sistema tenta de novo; se continuar, baixe no app do Itaú.',
                '/contas_bancarias/auditoria.php?parcela=' . (int) $linha['conta_receber_id'], (int) $linha['conta_receber_id']);
        }
    }
}

// Agenda a baixa no banco para o fim da requisição (depois do commit e,
// com PHP-FPM, depois de a resposta já ter ido para o navegador).
function agendarBaixaBoletosCancelados(): void
{
    static $agendado = false;
    if ($agendado) {
        return;
    }
    $agendado = true;
    register_shutdown_function(function () {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        try {
            baixarBoletosCanceladosNoBanco(getDB());
        } catch (Throwable $e) {
            error_log('[Baixa no banco] ' . $e->getMessage());
        }
    });
}

/**
 * Registra de verdade no Itaú o boleto + Pix de uma parcela. Reserva o
 * nosso número primeiro (trava a sequência da conta), grava a cobrança como
 * 'pendente_envio', envia com "efetivacao" e guarda linha digitável,
 * código de barras e Pix copia-e-cola que o banco devolve. Falhou: a
 * cobrança fica como 'erro' com a resposta do banco — o número reservado
 * não é reaproveitado, que é o seguro (o banco pode tê-lo registrado).
 *
 * $dataLimitePagamento: depois dela o banco não aceita mais o pagamento
 * (usado na reserva de vaga do portal, que expira).
 * Retorna ['ok' => bool, 'mensagem' => string, 'cobranca_id' => ?int].
 */
function registrarCobrancaItau(PDO $pdo, int $contaReceberId, ?int $usuarioId, ?string $dataLimitePagamento = null): array
{
    contextoChamadaBanco(['conta_receber_id' => $contaReceberId]);
    $previa = previaCobrancaItau($pdo, $contaReceberId);
    if (!$previa['parcela']) {
        return ['ok' => false, 'mensagem' => 'Parcela não encontrada.', 'cobranca_id' => null];
    }
    if ($previa['pendencias']) {
        return ['ok' => false, 'mensagem' => implode(' ', $previa['pendencias']), 'cobranca_id' => null];
    }
    $conta = $previa['conta'];

    // Token antes de reservar o nosso número: banco fora do ar ou
    // credencial errada não deve consumir número da sequência.
    $token = obterTokenItau($pdo, $conta);
    if (!$token['ok']) {
        return ['ok' => false, 'mensagem' => 'Não foi possível obter o token: ' . $token['mensagem'], 'cobranca_id' => null];
    }
    // Relê a conta com o token recém-guardado, para o envio reaproveitá-lo.
    $stmt = $pdo->prepare('SELECT * FROM contas_bancarias WHERE id = ?');
    $stmt->execute([$conta['id']]);
    $conta = $stmt->fetch() ?: $conta;

    $pdo->beginTransaction();
    try {
        // Trava a parcela e confere de novo: dois cliques (ou duas abas)
        // não podem registrar dois boletos para a mesma parcela.
        $pdo->prepare('SELECT id FROM contas_receber WHERE id = ? FOR UPDATE')->execute([$contaReceberId]);
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM cobrancas_bancarias WHERE conta_receber_id = ? AND status IN ('pendente_envio', 'registrada', 'paga')");
        $stmt->execute([$contaReceberId]);
        if ((int) $stmt->fetchColumn() > 0) {
            $pdo->rollBack();
            return ['ok' => false, 'mensagem' => 'Esta parcela já tem uma cobrança gerada no banco.', 'cobranca_id' => null];
        }

        $stmt = $pdo->prepare('SELECT proximo_nosso_numero FROM contas_bancarias WHERE id = ? FOR UPDATE');
        $stmt->execute([$conta['id']]);
        $nossoNumero = (int) $stmt->fetchColumn();
        $pdo->prepare('UPDATE contas_bancarias SET proximo_nosso_numero = proximo_nosso_numero + 1 WHERE id = ?')->execute([$conta['id']]);

        $stmt = $pdo->prepare(
            "INSERT INTO cobrancas_bancarias (conta_receber_id, conta_bancaria_id, instrumento, nosso_numero, valor, data_vencimento, status, usuario_id)
             VALUES (?, ?, ?, ?, ?, ?, 'pendente_envio', ?)"
        );
        $stmt->execute([
            $contaReceberId, $conta['id'], $previa['instrumento'], $nossoNumero,
            $previa['parcela']['valor'], $previa['parcela']['data_vencimento'], $usuarioId,
        ]);
        $cobrancaId = (int) $pdo->lastInsertId();
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    $payload = $previa['payload'];
    $payload['dado_boleto']['dados_individuais_boleto'][0]['numero_nosso_numero'] = formatarNossoNumeroItau($nossoNumero);
    if ($dataLimitePagamento) {
        $payload['dado_boleto']['dados_individuais_boleto'][0]['data_limite_pagamento'] = max($dataLimitePagamento, $previa['parcela']['data_vencimento']);
    }

    $envio = enviarBoletoPixItau($pdo, $conta, $payload);
    $json = fn ($v) => $v === null ? null : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if (!$envio['ok']) {
        $detalhe = is_array($envio['resposta']) ? ($envio['resposta']['mensagem'] ?? $envio['resposta']['message'] ?? '') : (string) $envio['resposta'];
        $mensagem = trim($envio['mensagem'] . ' ' . $detalhe);
        $stmt = $pdo->prepare("UPDATE cobrancas_bancarias SET status = 'erro', payload = ?, resposta = ?, mensagem_erro = ? WHERE id = ?");
        $stmt->execute([$json($payload), $json(['status' => $envio['status'], 'corpo' => $envio['resposta']]), mb_strimwidth($mensagem, 0, 255, '…', 'UTF-8'), $cobrancaId]);
        return ['ok' => false, 'mensagem' => $mensagem, 'cobranca_id' => $cobrancaId];
    }

    // A resposta pode vir dentro de "data" (padrão das APIs do Itaú) ou não.
    $resposta = is_array($envio['resposta']) ? ($envio['resposta']['data'] ?? $envio['resposta']) : [];
    $individual = $resposta['dado_boleto']['dados_individuais_boleto'][0] ?? [];
    $linhaDigitavel = $individual['numero_linha_digitavel'] ?? null;
    $codigoBarras = $individual['codigo_barras'] ?? null;
    $pix = $resposta['dados_qrcode']['emv'] ?? null;
    // O aviso de Pix na hora identifica a cobrança pelo txid do QR Code.
    $txid = isset($resposta['dados_qrcode']['txid']) ? mb_substr((string) $resposta['dados_qrcode']['txid'], 0, 35) : null;

    $stmt = $pdo->prepare(
        "UPDATE cobrancas_bancarias SET status = 'registrada', linha_digitavel = ?, codigo_barras = ?, pix_copia_cola = ?, txid = ?, payload = ?, resposta = ?, mensagem_erro = NULL WHERE id = ?"
    );
    $stmt->execute([
        $linhaDigitavel ? apenasDigitos($linhaDigitavel) : null,
        $codigoBarras ? apenasDigitos($codigoBarras) : null,
        $pix, $txid, $json($payload), $json($envio['resposta']), $cobrancaId,
    ]);

    return [
        'ok' => true,
        'mensagem' => 'Boleto registrado no Itaú (nosso número ' . formatarNossoNumeroItau($nossoNumero) . ').'
            . (!$linhaDigitavel || ($previa['instrumento'] === 'boleto_pix' && !$pix) ? ' Atenção: a resposta não trouxe ' . (!$linhaDigitavel ? 'a linha digitável' : 'o Pix') . ' — confira a resposta salva.' : ''),
        'cobranca_id' => $cobrancaId,
    ];
}
