<?php

// ---------------------------------------------------------------------
// Cobrança de inadimplentes (Financeiro › Inadimplência) direto pelo
// sistema: WhatsApp pelo número conectado (Evolution) e e-mail pela caixa
// do usuário ou pelo e-mail do sistema. Cada cobrança vai para o histórico
// do aluno (tipo "cobranca") e para a agenda de cobrança (cobranca_registros),
// onde a equipe também anota ligações, respostas e a próxima cobrança.
// ---------------------------------------------------------------------

/** Textos padrão (com tags, ver tagsEmail). */
function modelosCobranca(): array
{
    return [
        'whatsapp' => "Olá, {primeiro_nome}! Aqui é da {empresa}. 😊\n\nIdentificamos {parcelas_em_atraso} parcela(s) em aberto, no total de {valor_em_atraso}, com vencimento desde {vencimento_mais_antigo}.\n\nVocê pode pagar pelo portal, com boleto ou Pix: {link_pagamento}\n\nSe já pagou, pode desconsiderar. Qualquer dúvida, estamos à disposição!",
        'assunto' => '{primeiro_nome}, parcela(s) em aberto — {empresa}',
        'email' => '<p>Olá, <strong>{primeiro_nome}</strong>!</p><p>Identificamos <strong>{parcelas_em_atraso} parcela(s) em aberto</strong>, no total de <strong>{valor_em_atraso}</strong>, com vencimento desde {vencimento_mais_antigo}.</p>'
            . '<p>Você pode pagar pelo portal do aluno, com boleto ou Pix: <a href="{link_pagamento}">{link_pagamento}</a></p><p>Se já pagou, pode desconsiderar esta mensagem. Qualquer dúvida, é só responder.</p><p>Atenciosamente,<br>{empresa}</p>',
    ];
}

/** Número de WhatsApp do aluno (com 55) ou null. */
function numeroWhatsappAluno(?string $telefone): ?string
{
    $digitos = apenasDigitos((string) $telefone);
    if (strlen($digitos) < 10) {
        return null;
    }
    return strlen($digitos) <= 11 ? '55' . $digitos : $digitos;
}

function canaisCobranca(): array
{
    return [
        'whatsapp' => ['WhatsApp', 'bi-whatsapp', 'success'],
        'telefone' => ['Ligação', 'bi-telephone', 'primary'],
        'email' => ['E-mail', 'bi-envelope', 'secondary'],
        'presencial' => ['Pessoalmente', 'bi-person', 'info'],
        'outro' => ['Outro', 'bi-chat-dots', 'secondary'],
    ];
}

function situacoesCobranca(): array
{
    return [
        'sem_resposta' => ['Sem resposta', 'secondary'],
        'respondeu' => ['Respondeu', 'info'],
        'prometeu' => ['Prometeu pagar', 'success'],
        'recusou' => ['Não vai pagar', 'danger'],
    ];
}

/** Grava um contato de cobrança na agenda. Retorna o id. */
function registrarCobranca(PDO $pdo, int $alunoId, string $canal, string $descricao, array $extra = []): int
{
    $canal = isset(canaisCobranca()[$canal]) ? $canal : 'outro';
    $situacao = isset(situacoesCobranca()[$extra['situacao'] ?? '']) ? $extra['situacao'] : 'sem_resposta';
    $pdo->prepare('INSERT INTO cobranca_registros (aluno_id, canal, automatico, contato_em, descricao, resposta, situacao, promessa_data, usuario_id, usuario_nome)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([
            $alunoId, $canal, !empty($extra['automatico']) ? 1 : 0, $extra['contato_em'] ?? date('Y-m-d H:i:s'),
            mb_substr(trim($descricao), 0, 5000), trim((string) ($extra['resposta'] ?? '')) ?: null, $situacao,
            $situacao === 'prometeu' ? (($extra['promessa_data'] ?? null) ?: null) : null,
            usuarioAtualId(), usuarioAtualNome() ?: null,
        ]);
    return (int) $pdo->lastInsertId();
}

/** Marca (ou, sem data, desmarca) a próxima cobrança do aluno. */
function salvarAgendaCobranca(PDO $pdo, int $alunoId, ?string $data, string $nota = ''): void
{
    if (!$data) {
        $pdo->prepare('DELETE FROM cobranca_agenda WHERE aluno_id = ?')->execute([$alunoId]);
        return;
    }
    $pdo->prepare('INSERT INTO cobranca_agenda (aluno_id, data, nota, usuario_id, usuario_nome) VALUES (?, ?, ?, ?, ?)
                   ON DUPLICATE KEY UPDATE data = VALUES(data), nota = VALUES(nota), usuario_id = VALUES(usuario_id), usuario_nome = VALUES(usuario_nome)')
        ->execute([$alunoId, $data, mb_substr(trim($nota), 0, 255) ?: null, usuarioAtualId(), usuarioAtualNome() ?: null]);
}

/** Próxima cobrança marcada de cada aluno: [aluno_id => linha de cobranca_agenda]. */
function agendaCobrancas(PDO $pdo, array $alunoIds): array
{
    $alunoIds = array_values(array_filter(array_map('intval', $alunoIds)));
    if (!$alunoIds) {
        return [];
    }
    $agenda = [];
    foreach ($pdo->query('SELECT * FROM cobranca_agenda WHERE aluno_id IN (' . implode(',', $alunoIds) . ')') as $l) {
        $agenda[(int) $l['aluno_id']] = $l;
    }
    return $agenda;
}

/** Último contato de cobrança de cada aluno: [aluno_id => ['quando' =>, 'descricao' =>, 'canal' =>, 'situacao' =>]]. */
function ultimasCobrancas(PDO $pdo, array $alunoIds): array
{
    $alunoIds = array_values(array_filter(array_map('intval', $alunoIds)));
    if (!$alunoIds) {
        return [];
    }
    $lista = implode(',', $alunoIds);
    $ultimas = [];
    // Cobranças de antes da agenda existir ficaram só no histórico do aluno.
    $stmt = $pdo->query("SELECT h.aluno_id, h.criado_em, h.descricao FROM aluno_historico h
                         JOIN (SELECT aluno_id, MAX(id) AS id FROM aluno_historico WHERE tipo = 'cobranca' AND aluno_id IN ({$lista}) GROUP BY aluno_id) u ON u.id = h.id");
    foreach ($stmt as $l) {
        $ultimas[(int) $l['aluno_id']] = ['quando' => $l['criado_em'], 'descricao' => $l['descricao'], 'canal' => str_contains($l['descricao'], 'WhatsApp') ? 'whatsapp' : 'email', 'situacao' => null];
    }
    $stmt = $pdo->query("SELECT r.aluno_id, r.contato_em, r.descricao, r.canal, r.situacao FROM cobranca_registros r
                         JOIN (SELECT aluno_id, MAX(contato_em) AS quando FROM cobranca_registros WHERE aluno_id IN ({$lista}) GROUP BY aluno_id) u
                           ON u.aluno_id = r.aluno_id AND u.quando = r.contato_em ORDER BY r.id");
    foreach ($stmt as $l) {
        // O registro da agenda vale sobre o do histórico (que é gravado junto).
        $ultimas[(int) $l['aluno_id']] = ['quando' => $l['contato_em'], 'descricao' => mb_strimwidth((string) $l['descricao'], 0, 160, '…'), 'canal' => $l['canal'], 'situacao' => $l['situacao']];
    }
    return $ultimas;
}

/**
 * Ficha de cobrança do aluno: os contatos registrados e as mensagens que
 * ele mandou no WhatsApp do sistema (as respostas), do mais novo ao mais
 * antigo, mais a próxima cobrança marcada.
 */
function fichaCobranca(PDO $pdo, int $alunoId): array
{
    $stmt = $pdo->prepare('SELECT * FROM cobranca_registros WHERE aluno_id = ? ORDER BY contato_em DESC, id DESC LIMIT 200');
    $stmt->execute([$alunoId]);
    $itens = [];
    foreach ($stmt->fetchAll() as $r) {
        $itens[] = ['tipo' => 'registro'] + $r;
    }
    // Respostas pelo WhatsApp: mensagens do aluno desde 7 dias antes do
    // primeiro contato registrado (sem contato, dos últimos 90 dias).
    $desde = $itens ? date('Y-m-d H:i:s', strtotime(end($itens)['contato_em'] . ' -7 days')) : date('Y-m-d H:i:s', strtotime('-90 days'));
    $stmt = $pdo->prepare("SELECT m.texto, m.tipo, m.criado_em FROM whatsapp_mensagens m JOIN whatsapp_conversas c ON c.id = m.conversa_id
                           WHERE c.aluno_id = ? AND m.autor = 'cliente' AND m.criado_em >= ? ORDER BY m.id DESC LIMIT 100");
    $stmt->execute([$alunoId, $desde]);
    foreach ($stmt->fetchAll() as $m) {
        $itens[] = ['tipo' => 'whatsapp', 'contato_em' => $m['criado_em'], 'descricao' => $m['texto'] ?: '[' . $m['tipo'] . ']'];
    }
    usort($itens, fn ($a, $b) => strcmp($b['contato_em'], $a['contato_em']));
    $stmt = $pdo->prepare('SELECT * FROM cobranca_agenda WHERE aluno_id = ?');
    $stmt->execute([$alunoId]);
    return ['itens' => $itens, 'agenda' => $stmt->fetch() ?: null];
}

/** Manda a cobrança por WhatsApp (registra na conversa e no histórico). */
function cobrarPorWhatsapp(PDO $pdo, array $aluno, string $texto): array
{
    $cfg = whatsappConfig($pdo);
    if (!whatsappConfigurado($cfg)) {
        return ['ok' => false, 'mensagem' => 'O WhatsApp não está configurado (Secretaria › WhatsApp › Configurar).'];
    }
    $numero = numeroWhatsappAluno($aluno['telefone'] ?? null);
    if (!$numero) {
        return ['ok' => false, 'mensagem' => 'O aluno não tem celular válido no cadastro.'];
    }
    $texto = trim($texto);
    if ($texto === '') {
        return ['ok' => false, 'mensagem' => 'Escreva a mensagem.'];
    }
    $r = enviarTextoWhatsapp($cfg, $numero, $texto);
    $conversa = conversaWhatsapp($pdo, $numero, $aluno['nome'] ?? null);
    gravarMensagemWhatsapp($pdo, (int) $conversa['id'], 'atendente', $texto, 'texto', $r['id'] ?? null, $r['ok'] ? null : $r['erro']);
    if (!$r['ok']) {
        return ['ok' => false, 'mensagem' => 'O WhatsApp não enviou: ' . ($r['erro'] ?: 'erro desconhecido')];
    }
    // Atendente falou com ele: a IA não responde por cima nesta conversa.
    $pdo->prepare("UPDATE whatsapp_conversas SET modo = 'humano', aguardando = 0 WHERE id = ?")->execute([$conversa['id']]);
    registrarHistoricoAluno($pdo, (int) $aluno['id'], 'cobranca', 'Cobrança enviada por WhatsApp para ' . numeroWhatsappFormatado($numero));
    registrarCobranca($pdo, (int) $aluno['id'], 'whatsapp', $texto, ['automatico' => true]);
    return ['ok' => true, 'mensagem' => 'Mensagem enviada pelo WhatsApp.'];
}

/** Manda a cobrança por e-mail (caixa do usuário ou e-mail do sistema). */
function cobrarPorEmail(PDO $pdo, array $aluno, string $assunto, string $html, string $remetente): array
{
    $email = strtolower(trim((string) ($aluno['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'mensagem' => 'O aluno não tem e-mail válido no cadastro.'];
    }
    $html = limparHtmlContrato($html, true);
    if (trim($assunto) === '' || trim(strip_tags($html)) === '') {
        return ['ok' => false, 'mensagem' => 'Preencha o assunto e a mensagem.'];
    }
    if ($remetente === 'usuario') {
        $r = enviarEmailUsuario($pdo, (int) usuarioAtualId(), ['para' => $aluno['nome'] . ' <' . $email . '>', 'assunto' => $assunto, 'html' => $html]);
    } else {
        $cfg = emailConfig($pdo);
        $servidor = servidorDisparoEmail($pdo, ['remetente' => 'sistema', 'usuario_id' => null], $cfg, buscarConfiguracoes($pdo));
        if (!$servidor['ok']) {
            return ['ok' => false, 'mensagem' => 'O e-mail do sistema não está configurado (Configurações › Sistema).'];
        }
        $mensagem = EmailMime::montar(['de' => $servidor['de'], 'para' => [['nome' => $aluno['nome'], 'email' => $email]], 'assunto' => $assunto, 'html' => imagensLocaisEmbutidas($html)]);
        $r = enviarMensagemSmtp($servidor['smtp'], $servidor['de']['email'], [$email], $mensagem['bruto']);
    }
    if (!$r['sucesso']) {
        return ['ok' => false, 'mensagem' => $r['mensagem']];
    }
    registrarHistoricoAluno($pdo, (int) $aluno['id'], 'cobranca', 'Cobrança enviada por e-mail para ' . $email);
    $textoEmail = trim(html_entity_decode(strip_tags(str_replace(['</p>', '<br>'], "\n", $html)), ENT_QUOTES, 'UTF-8'));
    registrarCobranca($pdo, (int) $aluno['id'], 'email', 'E-mail "' . trim($assunto) . '"' . "\n" . $textoEmail, ['automatico' => true]);
    return ['ok' => true, 'mensagem' => 'E-mail enviado para ' . $email . '.'];
}
