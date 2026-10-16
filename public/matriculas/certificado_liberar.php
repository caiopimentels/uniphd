<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();

// Libera ou bloqueia o certificado de uma ou várias matrículas (caixas da
// lista de alunos da turma). Responde JSON.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !usuarioPodeLiberarCertificado()) {
    if (!ehRequisicaoAjax()) {
        flash('danger', 'Seu perfil não pode liberar ou bloquear certificados.');
        redirect('/sem_acesso.php');
    }
    http_response_code(403);
    responderJson(['sucesso' => false, 'mensagem' => 'Seu perfil não pode liberar ou bloquear certificados.']);
}

$pdo = getDB();
$ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])))));
$liberado = !empty($_POST['liberado']) ? 1 : 0;
if (!$ids) {
    responderJson(['sucesso' => false, 'mensagem' => 'Nenhum aluno escolhido.']);
}

$marcas = implode(',', array_fill(0, count($ids), '?'));
$stmt = $pdo->prepare("UPDATE matriculas SET certificado_liberado = ? WHERE id IN ({$marcas})");
$stmt->execute([$liberado, ...$ids]);

$stmt = $pdo->prepare("SELECT a.nome FROM matriculas m JOIN alunos a ON a.id = m.aluno_id WHERE m.id IN ({$marcas}) ORDER BY a.nome");
$stmt->execute($ids);
$nomes = $stmt->fetchAll(PDO::FETCH_COLUMN);
registrarAtividade('matriculas', 'edicao', 'Certificado ' . ($liberado ? 'liberado' : 'bloqueado') . ' para: ' . implode(', ', $nomes));

if (!ehRequisicaoAjax()) {
    flash('success', 'Certificado ' . ($liberado ? 'liberado' : 'bloqueado') . '.');
    redirect('/matriculas/visualizar.php?id=' . $ids[0]);
}
responderJson(['sucesso' => true, 'quantidade' => count($ids)]);
