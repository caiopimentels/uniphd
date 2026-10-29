<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirPermissaoAcao('disparar_email', 'Seu perfil não pode disparar e-mails em massa.');

// Disparo em massa (JSON): contar destinatários, prévia com as tags de um
// aluno, processar a fila (tela aberta), pausar, retomar, cancelar e
// reenviar as falhas.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responderJson(['sucesso' => false]);
}
$pdo = getDB();
$acao = (string) ($_POST['acao'] ?? '');

if ($acao === 'contar') {
    $publico = array_key_exists((string) ($_POST['publico'] ?? ''), publicosDisparo()) ? $_POST['publico'] : 'turma';
    $ids = (array) ($_POST['ids'] ?? []);
    $destinos = destinatariosDisparo($pdo, $publico, $ids);
    // Alunos do grupo sem e-mail (ficam de fora).
    $semEmail = 0;
    if (in_array($publico, ['turma', 'curso', 'andamento'], true)) {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        $marcas = $ids ? implode(',', array_fill(0, count($ids), '?')) : '0';
        $filtro = ['turma' => "AND m.turma_id IN ($marcas)", 'curso' => "AND t.curso_id IN ($marcas)", 'andamento' => ''][$publico];
        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT a.id) FROM matriculas m JOIN turmas t ON t.id = m.turma_id JOIN alunos a ON a.id = m.aluno_id
                               WHERE m.status IN ('ativa', 'pendente') AND a.ativo = 1 AND (a.email IS NULL OR a.email = '') $filtro");
        $stmt->execute($publico === 'andamento' ? [] : $ids);
        $semEmail = (int) $stmt->fetchColumn();
    }
    $porHora = emailConfig($pdo)['disparo_por_hora'];
    $horas = count($destinos) > $porHora ? (count($destinos) / $porHora < 2 ? 'mais de 1 hora' : 'umas ' . (int) ceil(count($destinos) / $porHora) . ' horas') : null;
    responderJson(['sucesso' => true, 'total' => count($destinos), 'sem_email' => $semEmail, 'horas' => $horas, 'primeiro' => $destinos[0] ?? null]);
}

if ($acao === 'previa') {
    $valores = valoresTagsEmail($pdo, (int) ($_POST['aluno_id'] ?? 0) ?: null, (int) ($_POST['turma_id'] ?? 0) ?: null, buscarConfiguracoes($pdo));
    responderJson([
        'sucesso' => true,
        'assunto' => aplicarTagsEmail((string) ($_POST['assunto'] ?? ''), $valores, false),
        'html' => aplicarTagsEmail(limparHtmlContrato((string) ($_POST['html'] ?? ''), true), $valores),
    ]);
}

$id = (int) ($_POST['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM email_disparos WHERE id = ?');
$stmt->execute([$id]);
$disparo = $stmt->fetch();
if (!$disparo) {
    responderJson(['sucesso' => false, 'mensagem' => 'Disparo não encontrado.']);
}

switch ($acao) {
    case 'processar':
        // Tela do disparo aberta: manda a próxima leva (dentro do limite por hora).
        session_write_close();
        if ($disparo['status'] === 'enviando') {
            processarDisparosEmail($pdo, 5, $id);
        }
        break;
    case 'pausar':
        $pdo->prepare("UPDATE email_disparos SET status = 'pausado' WHERE id = ? AND status = 'enviando'")->execute([$id]);
        break;
    case 'retomar':
        $pdo->prepare("UPDATE email_disparos SET status = 'enviando' WHERE id = ? AND status = 'pausado'")->execute([$id]);
        break;
    case 'cancelar':
        $pdo->prepare("UPDATE email_disparos SET status = 'cancelado', concluido_em = NOW() WHERE id = ? AND status IN ('enviando', 'pausado')")->execute([$id]);
        registrarAtividade('email', 'edicao', 'Disparo de e-mail "' . $disparo['assunto'] . '" cancelado');
        break;
    case 'reenviar_falhas':
        $n = $pdo->prepare("UPDATE email_disparo_destinos SET status = 'pendente', erro = NULL, enviado_em = NULL WHERE disparo_id = ? AND status = 'erro'");
        $n->execute([$id]);
        if ($n->rowCount()) {
            $pdo->prepare("UPDATE email_disparos SET status = 'enviando', falhas = 0, concluido_em = NULL WHERE id = ?")->execute([$id]);
        }
        break;
    default:
        responderJson(['sucesso' => false, 'mensagem' => 'Ação desconhecida.']);
}
$stmt->execute([$id]);
$disparo = $stmt->fetch();
responderJson(['sucesso' => true, 'status' => $disparo['status'], 'enviados' => (int) $disparo['enviados'], 'falhas' => (int) $disparo['falhas'], 'total' => (int) $disparo['total']]);
