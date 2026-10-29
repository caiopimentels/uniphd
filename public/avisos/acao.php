<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();
exigirPermissao('gerenciar_avisos', 'Seu perfil não pode enviar avisos aos alunos.');

// Ligar, desligar ou excluir um aviso.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/avisos/listar.php');
}
$pdo = getDB();
$stmt = $pdo->prepare('SELECT * FROM avisos WHERE id = ?');
$stmt->execute([(int) ($_POST['id'] ?? 0)]);
$aviso = $stmt->fetch();
if (!$aviso) {
    flash('danger', 'Aviso não encontrado.');
    redirect('/avisos/listar.php');
}
$acao = (string) ($_POST['acao'] ?? '');
if ($acao === 'excluir') {
    $pdo->prepare('DELETE FROM avisos WHERE id = ?')->execute([$aviso['id']]);
    foreach (imagensDoAviso($aviso) as $url) {
        apagarImagemPortal($url);
    }
    registrarAtividade('avisos', 'exclusao', 'Aviso "' . $aviso['titulo'] . '" excluído');
    flash('success', 'Aviso excluído.');
    redirect('/avisos/listar.php');
}
if (in_array($acao, ['ligar', 'desligar'], true)) {
    $pdo->prepare('UPDATE avisos SET ativo = ? WHERE id = ?')->execute([$acao === 'ligar' ? 1 : 0, $aviso['id']]);
    registrarAtividade('avisos', 'edicao', 'Aviso "' . $aviso['titulo'] . '" ' . ($acao === 'ligar' ? 'ligado' : 'desligado'));
    flash('success', $acao === 'ligar' ? 'Aviso ligado: volta a aparecer no portal (dentro do período).' : 'Aviso desligado: não aparece mais no portal.');
}
redirect('/avisos/ver.php?id=' . (int) $aviso['id']);
