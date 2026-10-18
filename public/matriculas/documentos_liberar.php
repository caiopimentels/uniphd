<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();

// Libera ou bloqueia um documento (certificado, declaração de módulo) para
// um ou vários alunos — a partir da turma (caixas marcadas) ou da matrícula.
// Liberar respeita as pendências do curso; "forcar" (só administrador) passa
// por cima delas.
$pdo = getDB();
$voltar = (string) ($_POST['voltar'] ?? '');
if (!preg_match('~^/(turmas|matriculas)/[a-z_]+\.php(\?[a-z_]+=\d+(#[a-z]+)?)?$~', $voltar)) {
    $voltar = '/turmas/listar.php';
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !usuarioPodeLiberarCertificado()) {
    flash('danger', 'Seu perfil não pode liberar ou bloquear documentos dos alunos.');
    redirect($voltar);
}

$ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])))));
// Documento escolhido na pesquisa: "e:ID" = do curso; "m:ID" = qualquer
// modelo, liberado como documento avulso (só para os alunos marcados).
$escolhido = (string) ($_POST['documento'] ?? '');
$emissaoId = (int) ($_POST['emissao_id'] ?? 0);
if (preg_match('/^e:(\d+)$/', $escolhido, $m)) {
    $emissaoId = (int) $m[1];
} elseif (preg_match('/^m:(\d+)$/', $escolhido, $m) && $ids) {
    $stmt = $pdo->prepare('SELECT t.curso_id FROM matriculas mt JOIN turmas t ON t.id = mt.turma_id WHERE mt.id = ?');
    $stmt->execute([$ids[0] ?? 0]);
    $emissaoId = (int) documentoAvulsoCurso($pdo, (int) $stmt->fetchColumn(), (int) $m[1]);
}
$liberar = !empty($_POST['liberado']);
$forcar = $liberar && !empty($_POST['forcar']) && usuarioEhAdmin();
if (!$ids || !$emissaoId) {
    flash('warning', 'Marque pelo menos um aluno e escolha o documento.');
    redirect($voltar);
}

$feitos = [];
$recusados = [];
foreach ($ids as $matriculaId) {
    $r = definirLiberacaoDocumento($pdo, $matriculaId, $emissaoId, $liberar, $forcar);
    if ($r['ok']) {
        $feitos[] = $r['mensagem'];
    } else {
        $recusados[] = $r['mensagem'];
    }
}

if ($feitos) {
    $stmt = $pdo->prepare('SELECT nome FROM curso_emissoes WHERE id = ?');
    $stmt->execute([$emissaoId]);
    $nomeDocumento = (string) $stmt->fetchColumn();
    registrarAtividade('matriculas', 'edicao', '"' . $nomeDocumento . '" ' . ($liberar ? 'liberado' : 'bloqueado') . ($forcar ? ' (mesmo com pendência)' : '') . ' para ' . count($feitos) . ' aluno(s)');
    flash('success', '"' . $nomeDocumento . '" ' . ($liberar ? 'liberado' : 'bloqueado') . ' para ' . count($feitos) . ' aluno(s).');
}
if ($recusados) {
    flash('warning', 'Não liberado: ' . implode(' ', $recusados)
        . (usuarioEhAdmin() ? ' Para liberar mesmo assim, marque "Liberar mesmo com pendência".' : ' Resolva a pendência (ficha do aluno) e tente de novo.'));
}
redirect($voltar);
