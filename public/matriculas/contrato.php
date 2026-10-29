<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/documento.php';
exigirLogin();

// Contrato da matrícula em PDF, para imprimir e assinar: o modelo do curso (ou
// o padrão) com as variáveis trocadas pelos dados do aluno e da matrícula.
if (!usuarioPodeVerCursos() && !usuarioPodeVerAlunos()) {
    flash('danger', 'Seu perfil não tem acesso a matrículas.');
    redirect('/sem_acesso.php');
}
$pdo = getDB();
garantirContratoPadrao($pdo);
$id = (int) ($_GET['id'] ?? 0);
$dados = valoresContratoMatricula($pdo, $id);
if (!$dados) {
    flash('danger', 'Matrícula não encontrada.');
    redirect('/turmas/listar.php');
}
$m = $dados['matricula'];
$modelo = modeloContratoDoCurso($pdo, $m['contrato_modelo_id'] ? (int) $m['contrato_modelo_id'] : null);
if (!$modelo) {
    flash('warning', 'Nenhum modelo de contrato cadastrado (Portal › Documentos (modelos)).');
    redirect('/matriculas/visualizar.php?id=' . $id);
}

// Em PDF, como no portal. Se o PDF não sair (sem Chrome no servidor), abre
// a página para imprimir, avisando — a secretaria não fica sem o contrato.
if (!entregarPdfContrato($pdo, $modelo, $dados)) {
    $assinaturas = '<div class="assinaturas"><div>' . e($m['aluno_nome']) . '<br>Contratante</div><div>' . e(documentoDados($pdo)['nome']) . '<br>Contratada</div></div>';
    renderizarPaginaContrato($pdo, $modelo, $dados['valores'], 'Contrato · ' . $m['aluno_nome'], '/matriculas/visualizar.php?id=' . $id, $assinaturas,
        'Não foi possível gerar o PDF agora; use Imprimir › Salvar como PDF.');
}
