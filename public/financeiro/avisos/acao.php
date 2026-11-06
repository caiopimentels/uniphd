<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
exigirPermissao('configurar_avisos_cobranca', 'Seu perfil não pode configurar os avisos automáticos.');

// Ações da lista de avisos automáticos: ligar, desligar, duplicar, excluir
// e "criar o exemplo" (boleto emitido + lembretes 5 e 2 dias antes e no
// dia — desligados, para revisar os textos antes de ligar).
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/financeiro/avisos/listar.php');
}
$pdo = getDB();
$acao = (string) ($_POST['acao'] ?? '');
$id = (int) ($_POST['id'] ?? 0);

if ($acao === 'sugestao') {
    $inserir = $pdo->prepare(
        'INSERT INTO cobranca_avisos (nome, ativo, gatilho, dias, horario, so_com_boleto, enviar_email, enviar_whatsapp, email_assunto, email_html, anexar_boleto, whatsapp_texto, usuario_nome)
         VALUES (?, 0, ?, ?, ?, 1, 1, 0, ?, ?, 1, ?, ?)'
    );
    foreach ([['Boleto emitido', 'emissao', ''], ['Lembretes de vencimento', 'vencimento', '-5,-2,0']] as [$nome, $gatilho, $dias]) {
        $m = modeloAvisoCobranca($gatilho);
        $inserir->execute([$nome, $gatilho, $dias, '08:00:00', $m['email_assunto'], $m['email_html'], $m['whatsapp_texto'], usuarioAtualNome()]);
    }
    registrarAtividade('financeiro', 'cadastro', 'Avisos automáticos de exemplo criados (desligados)');
    flash('success', 'Criados 2 avisos de exemplo, desligados: "Boleto emitido" e "Lembretes de vencimento" (5 e 2 dias antes e no dia). Abra cada um, revise o texto e ligue.');
    redirect('/financeiro/avisos/listar.php');
}

$stmt = $pdo->prepare('SELECT * FROM cobranca_avisos WHERE id = ?');
$stmt->execute([$id]);
$aviso = $stmt->fetch();
if (!$aviso) {
    flash('danger', 'Aviso não encontrado.');
    redirect('/financeiro/avisos/listar.php');
}

switch ($acao) {
    case 'ligar':
    case 'desligar':
        $ligar = $acao === 'ligar';
        // Ligou agora: emissão e pagamento só contam o que vier daqui pra frente.
        $pdo->prepare('UPDATE cobranca_avisos SET ativo = ?' . ($ligar ? ', ativado_em = NOW()' : '') . ' WHERE id = ?')->execute([(int) $ligar, $id]);
        registrarAtividade('financeiro', 'edicao', 'Aviso automático ' . ($ligar ? 'ligado' : 'desligado') . ': ' . $aviso['nome']);
        flash('success', 'Aviso "' . $aviso['nome'] . '" ' . ($ligar ? 'ligado.' : 'desligado.'));
        break;

    case 'duplicar':
        $pdo->prepare(
            'INSERT INTO cobranca_avisos (nome, ativo, gatilho, dias, horario, so_com_boleto, enviar_email, enviar_whatsapp, email_assunto, email_html, anexar_boleto, whatsapp_texto, usuario_nome)
             SELECT CONCAT(nome, \' (cópia)\'), 0, gatilho, dias, horario, so_com_boleto, enviar_email, enviar_whatsapp, email_assunto, email_html, anexar_boleto, whatsapp_texto, ?
               FROM cobranca_avisos WHERE id = ?'
        )->execute([usuarioAtualNome(), $id]);
        $novo = (int) $pdo->lastInsertId();
        flash('success', 'Cópia criada (desligada). Ajuste e ligue.');
        redirect('/financeiro/avisos/form.php?id=' . $novo);

    case 'excluir':
        $pdo->prepare('DELETE FROM cobranca_avisos WHERE id = ?')->execute([$id]);
        registrarAtividade('financeiro', 'exclusao', 'Aviso automático excluído: ' . $aviso['nome']);
        flash('success', 'Aviso "' . $aviso['nome'] . '" excluído.');
        break;
}
redirect('/financeiro/avisos/listar.php');
