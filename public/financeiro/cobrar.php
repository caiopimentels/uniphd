<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirInadimplencia();

// Cobrança de inadimplentes (JSON):
// GET ?aluno=ID            → mensagens prontas (WhatsApp e e-mail) com os dados dele
// POST acao=whatsapp|email → envia para um aluno (whatsapp com ?modelo= usa as tags)
// POST acao=lote_email     → fila de e-mails para os alunos marcados (disparo)
$pdo = getDB();
$config = buscarConfiguracoes($pdo);
$buscarAluno = function (int $id) use ($pdo): ?array {
    $stmt = $pdo->prepare('SELECT id, nome, email, telefone FROM alunos WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
};

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $aluno = $buscarAluno((int) ($_GET['aluno'] ?? 0));
    if (!$aluno) {
        responderJson(['sucesso' => false, 'mensagem' => 'Aluno não encontrado.']);
    }
    $valores = valoresTagsEmail($pdo, (int) $aluno['id'], null, $config);
    $modelos = modelosCobranca();
    $cfgWhats = whatsappConfig($pdo);
    $caixa = caixaUsuario($pdo, (int) usuarioAtualId());
    responderJson([
        'sucesso' => true,
        'nome' => $aluno['nome'],
        'email' => $aluno['email'],
        'telefone' => telefoneFormatado($aluno['telefone']),
        'whatsapp_ok' => whatsappConfigurado($cfgWhats) && numeroWhatsappAluno($aluno['telefone']) !== null,
        'whatsapp_motivo' => !whatsappConfigurado($cfgWhats) ? 'WhatsApp do sistema não configurado.' : (numeroWhatsappAluno($aluno['telefone']) ? '' : 'Sem celular no cadastro.'),
        'link_wa' => linkWhatsapp(numeroWhatsappAluno($aluno['telefone']), aplicarTagsEmail($modelos['whatsapp'], $valores, false)),
        'texto' => aplicarTagsEmail($modelos['whatsapp'], $valores, false),
        'assunto' => aplicarTagsEmail($modelos['assunto'], $valores, false),
        'html' => aplicarTagsEmail($modelos['email'], $valores),
        'minha_caixa' => $caixa['configurada'] ? $caixa['email'] : null,
    ]);
}

$acao = (string) ($_POST['acao'] ?? '');
if ($acao === 'lote_email') {
    $ids = array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? []))));
    $destinos = destinatariosDisparo($pdo, 'alunos', $ids);
    if (!$destinos) {
        responderJson(['sucesso' => false, 'mensagem' => 'Nenhum dos alunos marcados tem e-mail.']);
    }
    $remetente = ($_POST['remetente'] ?? '') === 'sistema' ? 'sistema' : 'usuario';
    if ($remetente === 'usuario' && !caixaUsuario($pdo, (int) usuarioAtualId())['configurada']) {
        $remetente = 'sistema';
    }
    $modelos = modelosCobranca();
    $assunto = trim((string) ($_POST['assunto'] ?? '')) ?: $modelos['assunto'];
    $html = limparHtmlContrato((string) ($_POST['html'] ?? '') ?: $modelos['email'], true);
    $pdo->beginTransaction();
    $pdo->prepare('INSERT INTO email_disparos (assunto, corpo_html, publico, filtro, remetente, usuario_id, usuario_nome, total) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([mb_substr($assunto, 0, 250), $html, 'alunos', json_encode($ids), $remetente, usuarioAtualId(), usuarioAtualNome(), count($destinos)]);
    $disparoId = (int) $pdo->lastInsertId();
    $stmt = $pdo->prepare('INSERT INTO email_disparo_destinos (disparo_id, aluno_id, turma_id, nome, email) VALUES (?,?,?,?,?)');
    foreach ($destinos as $d) {
        $stmt->execute([$disparoId, $d['aluno_id'], $d['turma_id'], $d['nome'], $d['email']]);
        registrarHistoricoAluno($pdo, (int) $d['aluno_id'], 'cobranca', 'Cobrança por e-mail colocada na fila (disparo #' . $disparoId . ')');
        registrarCobranca($pdo, (int) $d['aluno_id'], 'email', 'E-mail em massa "' . $assunto . '" (disparo #' . $disparoId . ')', ['automatico' => true]);
    }
    $pdo->commit();
    registrarAtividade('email', 'criacao', 'Cobrança por e-mail para ' . count($destinos) . ' inadimplente(s)');
    responderJson(['sucesso' => true, 'mensagem' => count($destinos) . ' e-mail(s) de cobrança na fila' . (count($destinos) < count($ids) ? ' (' . (count($ids) - count($destinos)) . ' sem e-mail ficaram de fora)' : '') . '.', 'disparo' => $disparoId]);
}

$aluno = $buscarAluno((int) ($_POST['aluno'] ?? 0));
if (!$aluno) {
    responderJson(['sucesso' => false, 'mensagem' => 'Aluno não encontrado.']);
}
if (limiteDeTentativasExcedido($pdo, 'cobranca_' . $acao, (string) usuarioAtualId(), 60, 60, 60)) {
    responderJson(['sucesso' => false, 'mensagem' => 'Muitas cobranças seguidas. Espere alguns minutos.']);
}
registrarTentativaAcesso($pdo, 'cobranca_' . $acao, (string) usuarioAtualId());
$r = match ($acao) {
    // Em lote, chega o modelo com tags ({primeiro_nome}...), preenchido para este aluno.
    'whatsapp' => cobrarPorWhatsapp($pdo, $aluno, isset($_POST['modelo'])
        ? aplicarTagsEmail((string) $_POST['modelo'], valoresTagsEmail($pdo, (int) $aluno['id'], null, $config), false)
        : (string) ($_POST['texto'] ?? '')),
    'email' => cobrarPorEmail($pdo, $aluno, (string) ($_POST['assunto'] ?? ''), (string) ($_POST['html'] ?? ''), ($_POST['remetente'] ?? '') === 'sistema' ? 'sistema' : 'usuario'),
    default => ['ok' => false, 'mensagem' => 'Ação desconhecida.'],
};
responderJson(['sucesso' => $r['ok'], 'mensagem' => $r['mensagem'], 'ultima' => $r['ok'] ? 'agora' : null]);
