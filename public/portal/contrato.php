<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/documento.php';
exigirAluno();

// Contrato da matrícula para o próprio aluno, em PDF. Só das matrículas dele,
// em turma que libera o contrato no portal.
$pdo = getDB();
$dados = valoresContratoMatricula($pdo, (int) ($_GET['matricula'] ?? 0));
$m = $dados['matricula'] ?? null;
if (!$m || (int) $m['aluno_id'] !== (int) alunoAtualId() || !$m['contrato_portal'] || $m['status'] === 'cancelada') {
    flash('warning', 'Contrato não disponível.');
    redirect('/portal/documentos.php');
}
$modelo = modeloContratoDoCurso($pdo, $m['contrato_modelo_id'] ? (int) $m['contrato_modelo_id'] : null);
if (!$modelo) {
    flash('warning', 'Contrato não disponível.');
    redirect('/portal/documentos.php');
}

// No portal o contrato sai sempre em PDF.
if (!entregarPdfContrato($pdo, $modelo, $dados)) {
    flash('danger', 'Não foi possível gerar o PDF do contrato agora. Tente de novo em instantes ou fale com a secretaria.');
    redirect('/portal/documentos.php');
}
