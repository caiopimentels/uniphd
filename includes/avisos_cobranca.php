<?php

/**
 * Avisos automáticos de cobrança (Financeiro › Avisos automáticos).
 *
 * Cada regra (tabela cobranca_avisos) diz QUANDO avisar, POR ONDE (e-mail
 * e/ou WhatsApp) e O QUE dizer (texto com tags). Pode haver quantas regras
 * quiser — ex.: "boleto emitido" e "lembretes de vencimento" (5 e 2 dias
 * antes e no dia, tudo na mesma regra).
 *
 * Gatilhos:
 *  - emissao:    boleto/Pix registrado no banco pela secretaria (os que o
 *                próprio aluno gera no portal não contam — ele já está com
 *                o boleto). Sai logo, a qualquer hora.
 *  - vencimento: lista de dias em relação ao vencimento ("-5,-2,0,3" = 5 e
 *                2 dias antes, no dia e 3 dias depois, se ainda não pagou).
 *                Sai a partir do horário da regra, só no dia certo (não
 *                manda atrasado). {quando_vence} diz "vence em 5 dias",
 *                "vence hoje" ou "venceu há 3 dias".
 *  - pagamento:  pagamento confirmado (pelo banco ou baixa manual).
 *
 * Quem envia é a rotina "emails" do cron, a cada 5 minutos. Várias parcelas
 * do mesmo aluno na mesma regra saem numa mensagem só (ex.: a secretaria
 * emitiu 6 boletos de uma vez). Cada envio fica em cobranca_avisos_envios,
 * o que impede repetir; erro tenta de novo até 3 vezes.
 */

/** Gatilhos para a tela: chave => rótulo. */
function gatilhosAvisoCobranca(): array
{
    return [
        'emissao' => 'Quando o boleto/Pix for emitido',
        'vencimento' => 'Lembretes de vencimento (antes, no dia ou depois)',
        'pagamento' => 'Quando o pagamento for confirmado',
    ];
}

/** Dias da regra de vencimento, em ordem: -5 = 5 dias antes, 0 = no dia, 3 = 3 dias depois. */
function diasDoAviso(array $aviso): array
{
    $dias = array_unique(array_map('intval', array_filter(explode(',', (string) $aviso['dias']), fn ($d) => trim($d) !== '')));
    sort($dias);
    return array_values(array_filter($dias, fn ($d) => $d >= -365 && $d <= 365));
}

/** "5 e 2 dias antes" a partir de [5, 2]. */
function juntarDiasAviso(array $numeros): string
{
    $ultimo = array_pop($numeros);
    $texto = $numeros ? implode(', ', $numeros) . ' e ' . $ultimo : (string) $ultimo;
    return $texto . ($ultimo === 1 && !$numeros ? ' dia' : ' dias');
}

/** "5 e 2 dias antes, no dia e 3 dias depois do vencimento", "Quando o boleto for emitido"... */
function descreverMomentoAviso(array $aviso): string
{
    if ($aviso['gatilho'] === 'emissao') {
        return 'Quando o boleto/Pix for emitido';
    }
    if ($aviso['gatilho'] === 'pagamento') {
        return 'Quando o pagamento for confirmado';
    }
    $dias = diasDoAviso($aviso);
    $antes = array_map('abs', array_reverse(array_filter($dias, fn ($d) => $d < 0)));
    rsort($antes);
    $depois = array_values(array_filter($dias, fn ($d) => $d > 0));
    $partes = array_filter([
        $antes ? juntarDiasAviso($antes) . ' antes' : '',
        in_array(0, $dias, true) ? 'no dia' : '',
        $depois ? juntarDiasAviso($depois) . ' depois' : '',
    ]);
    if (!$partes) {
        return 'Nenhum dia escolhido';
    }
    $ultimo = array_pop($partes);
    return ucfirst(($partes ? implode(', ', $partes) . ' e ' : '') . $ultimo . ' do vencimento');
}

/** "vence em 5 dias", "vence amanhã", "vence hoje", "venceu há 3 dias" (dias = hoje - vencimento). */
function quandoVenceAviso(int $diasDesdeVencimento): string
{
    $n = abs($diasDesdeVencimento);
    return match (true) {
        $diasDesdeVencimento === 0 => 'vence hoje',
        $diasDesdeVencimento === -1 => 'vence amanhã',
        $diasDesdeVencimento < 0 => 'vence em ' . $n . ' dias',
        $diasDesdeVencimento === 1 => 'venceu ontem',
        default => 'venceu há ' . $n . ' dias',
    };
}

/** Tags próprias dos avisos (além das de tagsEmail()). */
function tagsAvisoCobranca(): array
{
    return [
        '{parcelas}' => 'Lista das parcelas do aviso (descrição, vencimento, valor, linha digitável e Pix)',
        '{valor_total}' => 'Soma das parcelas do aviso',
        '{vencimento}' => 'Vencimento (o mais próximo, se forem várias)',
        '{quando_vence}' => '"vence em 5 dias", "vence hoje" ou "venceu há 3 dias" (lembretes de vencimento)',
        '{descricao_parcela}' => 'Descrição da parcela',
        '{linha_digitavel}' => 'Linha digitável do boleto',
        '{pix_copia_cola}' => 'Código Pix copia e cola',
        '{data_pagamento}' => 'Data do pagamento (aviso de pagamento)',
    ];
}

/** Textos prontos para cada gatilho da tela (o usuário muda à vontade). */
function modeloAvisoCobranca(string $gatilho): array
{
    $assinatura = '<p>Qualquer dúvida, é só responder este e-mail.</p><p>Atenciosamente,<br>{empresa}</p>';
    $porGatilho = [
        'emissao' => ['{primeiro_nome}, seu boleto da {empresa}', '<p>Olá, <strong>{primeiro_nome}</strong>!</p><p>Seu boleto já está disponível:</p><p>{parcelas}</p><p>Você também pode pagar pelo portal do aluno, com boleto ou Pix: <a href="{link_pagamento}">{link_pagamento}</a></p>',
            "Olá, {primeiro_nome}! Aqui é da {empresa}. 😊\n\nSeu boleto já está disponível:\n\n{parcelas}\n\nTambém dá para pagar pelo portal: {link_pagamento}"],
        'vencimento' => ['{primeiro_nome}, sua parcela {quando_vence}', '<p>Olá, <strong>{primeiro_nome}</strong>!</p><p>Passando para lembrar que sua parcela <strong>{quando_vence}</strong> ({vencimento}):</p><p>{parcelas}</p><p>Pague pelo portal do aluno, com boleto ou Pix: <a href="{link_pagamento}">{link_pagamento}</a></p><p>Se já pagou, pode desconsiderar.</p>',
            "Olá, {primeiro_nome}! Aqui é da {empresa}. 😊\n\nLembrete: sua parcela {quando_vence} ({vencimento}).\n\n{parcelas}\n\nPague pelo portal: {link_pagamento}\n\nSe já pagou, pode desconsiderar."],
        'pagamento' => ['Recebemos seu pagamento, {primeiro_nome}!', '<p>Olá, <strong>{primeiro_nome}</strong>!</p><p>Confirmamos o pagamento recebido em {data_pagamento}:</p><p>{parcelas}</p><p>Obrigado!</p>',
            "Olá, {primeiro_nome}! Aqui é da {empresa}. 😊\n\nConfirmamos seu pagamento de {valor_total} em {data_pagamento}. Obrigado!"],
    ];
    [$assunto, $html, $whatsapp] = $porGatilho[$gatilho] ?? $porGatilho['vencimento'];
    return ['email_assunto' => $assunto, 'email_html' => $html . $assinatura, 'whatsapp_texto' => $whatsapp];
}

/** Regras gravadas, ativas primeiro, na ordem em que acontecem. */
function buscarAvisosCobranca(PDO $pdo, bool $soAtivos = false): array
{
    return $pdo->query(
        'SELECT * FROM cobranca_avisos' . ($soAtivos ? ' WHERE ativo = 1' : '') . "
          ORDER BY ativo DESC, FIELD(gatilho, 'emissao', 'vencimento', 'pagamento'), id"
    )->fetchAll();
}

/**
 * Parcelas que a regra pega hoje (sem olhar o que já foi enviado). Cada
 * linha: a parcela, o aluno, a referência do aviso e o boleto/Pix mais
 * recente registrado (linha digitável e Pix).
 */
function parcelasDoAviso(PDO $pdo, array $aviso): array
{
    $boleto = "LEFT JOIN cobrancas_bancarias cb ON cb.id = (SELECT MAX(c2.id) FROM cobrancas_bancarias c2 WHERE c2.conta_receber_id = cr.id AND c2.status = 'registrada')";
    $campos = 'cr.id, cr.aluno_id, cr.matricula_id, cr.descricao, cr.valor, cr.valor_recebido, cr.data_vencimento, cr.data_recebimento,
               a.nome AS aluno_nome, a.email AS aluno_email, a.telefone AS aluno_telefone, m.turma_id';
    $base = 'FROM contas_receber cr JOIN alunos a ON a.id = cr.aluno_id LEFT JOIN matriculas m ON m.id = cr.matricula_id';

    if ($aviso['gatilho'] === 'emissao') {
        // Emitidos pela secretaria depois que a regra foi ligada, nos últimos
        // 3 dias, há pelo menos 3 minutos (uma emissão em massa termina antes).
        $stmt = $pdo->prepare(
            "SELECT $campos, cb.id AS cobranca_id, cb.linha_digitavel, cb.pix_copia_cola, CONCAT('cb:', cb.id) AS referencia
             $base JOIN cobrancas_bancarias cb ON cb.conta_receber_id = cr.id
             WHERE cb.status = 'registrada' AND cb.usuario_id IS NOT NULL
               AND cb.criado_em >= ? AND cb.criado_em >= NOW() - INTERVAL 3 DAY AND cb.criado_em <= NOW() - INTERVAL 3 MINUTE
               AND cr.status = 'pendente' AND cr.excluido_em IS NULL AND a.ativo = 1
             ORDER BY cr.aluno_id, cr.data_vencimento, cr.id"
        );
        $stmt->execute([$aviso['ativado_em']]);
        return $stmt->fetchAll();
    }

    if ($aviso['gatilho'] === 'pagamento') {
        $stmt = $pdo->prepare(
            "SELECT $campos, NULL AS cobranca_id, NULL AS linha_digitavel, NULL AS pix_copia_cola, CONCAT('pago:', cr.data_recebimento) AS referencia
             $base
             WHERE cr.status = 'pago' AND cr.excluido_em IS NULL AND a.ativo = 1
               AND cr.data_recebimento >= DATE(?) AND cr.data_recebimento >= CURDATE() - INTERVAL 3 DAY
             ORDER BY cr.aluno_id, cr.data_vencimento, cr.id"
        );
        $stmt->execute([$aviso['ativado_em']]);
        return $stmt->fetchAll();
    }

    // Vencimento + um dos dias da regra = hoje, ainda em aberto. A referência
    // leva o dia ("2026-11-10:-5"): o de 5 dias e o de 2 dias são avisos diferentes.
    $dias = diasDoAviso($aviso);
    if (!$dias) {
        return [];
    }
    $datas = implode(',', array_map(fn ($d) => "'" . date('Y-m-d', strtotime(-$d . ' days')) . "'", $dias));
    $stmt = $pdo->query(
        "SELECT $campos, cb.id AS cobranca_id, cb.linha_digitavel, cb.pix_copia_cola,
                DATEDIFF(CURDATE(), cr.data_vencimento) AS dias_vencimento,
                CONCAT(DATE_FORMAT(cr.data_vencimento, '%Y-%m-%d'), ':', DATEDIFF(CURDATE(), cr.data_vencimento)) AS referencia
         $base $boleto
         WHERE cr.status = 'pendente' AND cr.excluido_em IS NULL AND a.ativo = 1
           AND cr.data_vencimento IN ($datas)"
        . ($aviso['so_com_boleto'] ? ' AND cb.id IS NOT NULL' : '') . '
         ORDER BY cr.aluno_id, cr.data_vencimento, cr.id'
    );
    return $stmt->fetchAll();
}

/** A regra já pode sair agora? (lembretes de vencimento esperam o horário). */
function avisoNoHorario(array $aviso): bool
{
    return $aviso['gatilho'] !== 'vencimento' || date('H:i:s') >= $aviso['horario'];
}

/** Meios ligados na regra. */
function canaisDoAviso(array $aviso): array
{
    return array_keys(array_filter(['email' => (bool) $aviso['enviar_email'], 'whatsapp' => (bool) $aviso['enviar_whatsapp']]));
}

/**
 * O que falta enviar hoje: [['aviso' => regra, 'canal' => ..., 'aluno_id' => ...,
 * 'parcelas' => [...], 'nova_tentativa' => bool]], uma mensagem por aluno. $ignorarHorario: para a
 * tela "o que sai hoje" mostrar também o que ainda vai sair mais tarde.
 */
function avisosPendentes(PDO $pdo, bool $ignorarHorario = false): array
{
    $jaFeito = $pdo->prepare(
        "SELECT status, tentativas FROM cobranca_avisos_envios WHERE aviso_id = ? AND conta_receber_id = ? AND canal = ? AND referencia = ?"
    );
    $mensagens = [];
    foreach (buscarAvisosCobranca($pdo, true) as $aviso) {
        if (!$ignorarHorario && !avisoNoHorario($aviso)) {
            continue;
        }
        $parcelas = parcelasDoAviso($pdo, $aviso);
        foreach (canaisDoAviso($aviso) as $canal) {
            foreach ($parcelas as $p) {
                $jaFeito->execute([$aviso['id'], $p['id'], $canal, $p['referencia']]);
                $feito = $jaFeito->fetch();
                if ($feito && ($feito['status'] !== 'erro' || (int) $feito['tentativas'] >= 3)) {
                    continue;
                }
                // Uma mensagem por aluno (e por prazo: "vence hoje" e "vence em 5 dias" não se misturam).
                $chave = $aviso['id'] . '-' . $canal . '-' . $p['aluno_id'] . '-' . ($p['dias_vencimento'] ?? '');
                $mensagens[$chave] ??= ['aviso' => $aviso, 'canal' => $canal, 'aluno_id' => (int) $p['aluno_id'], 'parcelas' => [], 'nova_tentativa' => false];
                $mensagens[$chave]['parcelas'][] = $p;
                // Já deu erro antes: a tela mostra pela linha do erro ("vai tentar de novo").
                $mensagens[$chave]['nova_tentativa'] = $mensagens[$chave]['nova_tentativa'] || (bool) $feito;
            }
        }
    }
    return array_values($mensagens);
}

/** Valores das tags de uma mensagem (aluno + parcelas). */
function valoresTagsAviso(PDO $pdo, array $parcelas, array $config, bool $html): array
{
    $primeira = $parcelas[0];
    $valores = valoresTagsEmail($pdo, (int) $primeira['aluno_id'], $primeira['turma_id'] ? (int) $primeira['turma_id'] : null, $config);
    $valores['{nome_aluno}'] = $valores['{nome_aluno}'] ?: (string) $primeira['aluno_nome'];

    $vencimentos = array_column($parcelas, 'data_vencimento');
    sort($vencimentos);
    $total = array_sum(array_map(fn ($p) => (float) ($p['valor_recebido'] ?? $p['valor']), $parcelas));
    return $valores + [
        '{parcelas}' => $html ? listaParcelasAvisoHtml($parcelas) : listaParcelasAvisoTexto($parcelas),
        '{valor_total}' => formatMoney($total),
        '{vencimento}' => formatDate($vencimentos[0]),
        '{descricao_parcela}' => count($parcelas) > 1 ? count($parcelas) . ' parcelas' : (string) $primeira['descricao'],
        '{linha_digitavel}' => (string) ($primeira['linha_digitavel'] ?? ''),
        '{pix_copia_cola}' => (string) ($primeira['pix_copia_cola'] ?? ''),
        '{data_pagamento}' => $primeira['data_recebimento'] ? formatDate($primeira['data_recebimento']) : '',
        '{quando_vence}' => quandoVenceAviso((int) round((strtotime(date('Y-m-d')) - strtotime($vencimentos[0])) / 86400)),
    ];
}

/** {parcelas} no e-mail: uma caixinha por parcela (já é HTML). */
function listaParcelasAvisoHtml(array $parcelas): string
{
    $html = '';
    foreach ($parcelas as $p) {
        $pago = $p['data_recebimento'] && $p['valor_recebido'] !== null;
        $html .= '<div style="border:1px solid #ddd;border-radius:6px;padding:10px 12px;margin:8px 0;font-family:Arial,sans-serif;font-size:14px">'
            . '<strong>' . e($p['descricao']) . '</strong><br>'
            . 'Vencimento: ' . e(formatDate($p['data_vencimento'])) . ' · Valor: <strong>' . e(formatMoney((float) ($pago ? $p['valor_recebido'] : $p['valor']))) . '</strong>'
            . ($p['linha_digitavel'] ? '<br>Linha digitável: <span style="font-family:monospace">' . e($p['linha_digitavel']) . '</span>' : '')
            . ($p['pix_copia_cola'] ? '<br>Pix copia e cola: <span style="font-family:monospace;font-size:12px;word-break:break-all">' . e($p['pix_copia_cola']) . '</span>' : '')
            . '</div>';
    }
    return $html;
}

/** {parcelas} no WhatsApp: texto simples. */
function listaParcelasAvisoTexto(array $parcelas): string
{
    $linhas = [];
    foreach ($parcelas as $p) {
        $pago = $p['data_recebimento'] && $p['valor_recebido'] !== null;
        $linhas[] = '• ' . $p['descricao'] . ' — vence ' . formatDate($p['data_vencimento']) . ' — ' . formatMoney((float) ($pago ? $p['valor_recebido'] : $p['valor']))
            . ($p['linha_digitavel'] ? "\n  Linha digitável: " . $p['linha_digitavel'] : '')
            . ($p['pix_copia_cola'] ? "\n  Pix copia e cola: " . $p['pix_copia_cola'] : '');
    }
    return implode("\n\n", $linhas);
}

/**
 * Troca as tags. No HTML, {parcelas} já vem pronto (não é escapado);
 * as outras são escapadas como no disparo de e-mail.
 */
function aplicarTagsAviso(string $texto, array $valores, bool $html): string
{
    if (!$html) {
        return strtr($texto, $valores);
    }
    $lista = $valores['{parcelas}'];
    unset($valores['{parcelas}']);
    // Quill coloca a tag sozinha num parágrafo: <p>{parcelas}</p> vira a lista (div dentro de p não é válido).
    $texto = preg_replace('~<p>\s*\{parcelas\}\s*</p>~', '{parcelas}', $texto);
    return strtr(aplicarTagsEmail($texto, $valores), ['{parcelas}' => $lista]);
}

/** PDF do boleto da parcela (para anexar) ou null. */
function pdfBoletoParcela(PDO $pdo, int $contaReceberId): ?array
{
    $dados = dadosBoletoParcela($pdo, $contaReceberId);
    if (!$dados || !$dados['registrada']) {
        return null;
    }
    ob_start();
    require __DIR__ . '/boleto_render.php';
    $html = ob_get_clean();
    $arquivo = pdfDocumentoEmitido('boleto-' . $contaReceberId, $html);
    return $arquivo ? ['nome' => 'boleto-' . $contaReceberId . '.pdf', 'tipo' => 'application/pdf', 'conteudo' => file_get_contents($arquivo)] : null;
}

/** Grava o resultado de cada parcela da mensagem (e soma tentativas no erro). */
function registrarEnvioAviso(PDO $pdo, array $msg, string $status, ?string $destino, ?string $erro): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO cobranca_avisos_envios (aviso_id, conta_receber_id, aluno_id, canal, referencia, destino, status, erro)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE status = VALUES(status), destino = VALUES(destino), erro = VALUES(erro),
                                 tentativas = tentativas + 1, enviado_em = NOW()'
    );
    foreach ($msg['parcelas'] as $p) {
        $stmt->execute([$msg['aviso']['id'], $p['id'], $msg['aluno_id'], $msg['canal'], $p['referencia'], $destino, $status, $erro ? mb_substr($erro, 0, 250) : null]);
    }
}

/** E-mails de aviso enviados na última hora (contam no limite por hora). */
function avisosEmailUltimaHora(PDO $pdo): int
{
    try {
        return (int) $pdo->query("SELECT COUNT(DISTINCT aviso_id, aluno_id, enviado_em) FROM cobranca_avisos_envios
                                  WHERE canal = 'email' AND status = 'enviado' AND enviado_em >= NOW() - INTERVAL 1 HOUR")->fetchColumn();
    } catch (Throwable $e) {
        return 0; // tabela ainda não criada no servidor
    }
}

/**
 * Envia o que está pendente (rotina "emails" do cron). E-mail sai pelo
 * e-mail do sistema e respeita o limite por hora; WhatsApp pelo número
 * conectado. Retorna ['enviados' => n, 'erros' => n].
 */
function processarAvisosCobranca(PDO $pdo, int $limiteEmails): array
{
    $resultado = ['enviados' => 0, 'erros' => 0];
    if ((int) $pdo->query("SELECT GET_LOCK('avisos_cobranca', 0)")->fetchColumn() !== 1) {
        return $resultado;
    }
    try {
        $config = buscarConfiguracoes($pdo);
        $cfgEmail = emailConfig($pdo);
        $servidor = servidorDisparoEmail($pdo, ['remetente' => 'sistema', 'usuario_id' => null], $cfgEmail, $config);
        $cfgWhats = whatsappConfig($pdo);
        $restanteEmail = min($limiteEmails, max(0, $cfgEmail['disparo_por_hora'] - enviadosUltimaHora($pdo)));

        foreach (avisosPendentes($pdo) as $msg) {
            $aviso = $msg['aviso'];
            $primeira = $msg['parcelas'][0];
            if ($msg['canal'] === 'email') {
                if ($restanteEmail <= 0) {
                    continue; // fica para a próxima rodada
                }
                $email = strtolower(trim((string) $primeira['aluno_email']));
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    registrarEnvioAviso($pdo, $msg, 'sem_contato', null, 'Aluno sem e-mail válido no cadastro.');
                    continue;
                }
                if (!$servidor['ok']) {
                    registrarEnvioAviso($pdo, $msg, 'erro', $email, 'O e-mail do sistema não está configurado (Configurações › Sistema).');
                    $resultado['erros']++;
                    continue;
                }
                $valores = valoresTagsAviso($pdo, $msg['parcelas'], $config, true);
                $anexos = [];
                if ($aviso['anexar_boleto'] && $aviso['gatilho'] !== 'pagamento') {
                    foreach ($msg['parcelas'] as $p) {
                        if ($p['cobranca_id'] && ($pdf = pdfBoletoParcela($pdo, (int) $p['id']))) {
                            $anexos[] = $pdf;
                        }
                    }
                }
                $mensagem = EmailMime::montar([
                    'de' => $servidor['de'],
                    'para' => [['nome' => $primeira['aluno_nome'], 'email' => $email]],
                    'assunto' => aplicarTagsAviso((string) $aviso['email_assunto'], $valores, false),
                    'html' => imagensLocaisEmbutidas(aplicarTagsAviso((string) $aviso['email_html'], $valores, true)),
                    'anexos' => $anexos,
                ]);
                $r = enviarMensagemSmtp($servidor['smtp'], $servidor['de']['email'], [$email], $mensagem['bruto']);
                $restanteEmail--;
                registrarEnvioAviso($pdo, $msg, $r['sucesso'] ? 'enviado' : 'erro', $email, $r['sucesso'] ? null : $r['mensagem']);
                if ($r['sucesso']) {
                    registrarHistoricoAluno($pdo, $msg['aluno_id'], 'cobranca', 'Aviso automático "' . $aviso['nome'] . '" enviado por e-mail para ' . $email);
                }
            } else {
                $numero = numeroWhatsappAluno($primeira['aluno_telefone']);
                if (!$numero) {
                    registrarEnvioAviso($pdo, $msg, 'sem_contato', null, 'Aluno sem celular válido no cadastro.');
                    continue;
                }
                if (!whatsappConfigurado($cfgWhats)) {
                    registrarEnvioAviso($pdo, $msg, 'erro', $numero, 'O WhatsApp não está configurado (Secretaria › WhatsApp › Configurar).');
                    $resultado['erros']++;
                    continue;
                }
                $texto = trim(aplicarTagsAviso((string) $aviso['whatsapp_texto'], valoresTagsAviso($pdo, $msg['parcelas'], $config, false), false));
                $r = enviarTextoWhatsapp($cfgWhats, $numero, $texto);
                $conversa = conversaWhatsapp($pdo, $numero, $primeira['aluno_nome']);
                gravarMensagemWhatsapp($pdo, (int) $conversa['id'], 'sistema', $texto, 'texto', $r['id'] ?? null, $r['ok'] ? null : $r['erro']);
                registrarEnvioAviso($pdo, $msg, $r['ok'] ? 'enviado' : 'erro', $numero, $r['ok'] ? null : $r['erro']);
                if ($r['ok']) {
                    registrarHistoricoAluno($pdo, $msg['aluno_id'], 'cobranca', 'Aviso automático "' . $aviso['nome'] . '" enviado por WhatsApp para ' . numeroWhatsappFormatado($numero));
                }
                $r = ['sucesso' => $r['ok']];
            }
            $resultado[$r['sucesso'] ? 'enviados' : 'erros']++;
        }
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('avisos_cobranca')");
    }
    return $resultado;
}

/**
 * Envios gravados (tela), mais recentes primeiro. $filtro: status, aviso
 * (id), canal, de e ate (Y-m-d) e busca (aluno, destino ou parcela).
 */
function ultimosEnviosAvisos(PDO $pdo, int $limite = 100, array $filtro = []): array
{
    $where = ['1=1'];
    $params = [];
    if (in_array($filtro['status'] ?? '', ['enviado', 'erro', 'sem_contato'], true)) {
        $where[] = 'e.status = ?';
        $params[] = $filtro['status'];
    }
    if ((int) ($filtro['aviso'] ?? 0)) {
        $where[] = 'e.aviso_id = ?';
        $params[] = (int) $filtro['aviso'];
    }
    if (in_array($filtro['canal'] ?? '', ['email', 'whatsapp'], true)) {
        $where[] = 'e.canal = ?';
        $params[] = $filtro['canal'];
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($filtro['de'] ?? ''))) {
        $where[] = 'e.enviado_em >= ?';
        $params[] = $filtro['de'] . ' 00:00:00';
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($filtro['ate'] ?? ''))) {
        $where[] = 'e.enviado_em <= ?';
        $params[] = $filtro['ate'] . ' 23:59:59';
    }
    if (trim((string) ($filtro['busca'] ?? '')) !== '') {
        $where[] = '(a.nome LIKE ? OR e.destino LIKE ? OR cr.descricao LIKE ?)';
        array_push($params, ...array_fill(0, 3, '%' . trim($filtro['busca']) . '%'));
    }
    $stmt = $pdo->prepare(
        'SELECT e.*, av.nome AS aviso_nome, a.nome AS aluno_nome, cr.descricao AS parcela_descricao, cr.data_vencimento
           FROM cobranca_avisos_envios e
           JOIN cobranca_avisos av ON av.id = e.aviso_id
           LEFT JOIN alunos a ON a.id = e.aluno_id
           LEFT JOIN contas_receber cr ON cr.id = e.conta_receber_id
          WHERE ' . implode(' AND ', $where) . '
          ORDER BY e.enviado_em DESC, e.id DESC
          LIMIT ' . (int) $limite
    );
    $stmt->execute($params);
    return $stmt->fetchAll();
}
