<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

$pdo = getDB();
$matriculaId = (int) ($_GET['matricula'] ?? 0);

// Abre para o próprio aluno, ou para a secretaria (botão na matrícula).
// O aluno só abre com o certificado liberado (caixa na lista da turma).
$stmt = $pdo->prepare('SELECT aluno_id, certificado_liberado FROM matriculas WHERE id = ?');
$stmt->execute([$matriculaId]);
$dono = $stmt->fetch() ?: ['aluno_id' => 0, 'certificado_liberado' => 0];
$donoId = (int) $dono['aluno_id'];
// Quem atende o aluno (vê a ficha dele) abre o certificado mesmo bloqueado.
$ehSecretaria = usuarioLogado() && (usuarioPodeVerAlunos() || usuarioPodeGerenciarMatriculas() || usuarioPodeLiberarCertificado());
$podeVer = (alunoLogado() && $donoId === alunoAtualId()) || $ehSecretaria;
if (!$podeVer) {
    if (!alunoLogado() && !usuarioLogado()) {
        exigirAluno();
    }
    http_response_code(404);
    exit('Certificado não encontrado.');
}

if (!$ehSecretaria && !$dono['certificado_liberado']) {
    flash('info', 'Seu certificado está em análise pela secretaria. Em caso de dúvida, fale com a gente.');
    redirect('/portal/certificados.php');
}

$emitido = emitirCertificado($pdo, $matriculaId);
if (!$emitido) {
    flash('warning', 'O certificado fica disponível quando o curso for concluído.');
    redirect(alunoLogado() ? '/portal/certificados.php' : '/matriculas/visualizar.php?id=' . $matriculaId);
}

$config = buscarConfiguracoes($pdo);
$certificado = buscarCertificadoCompleto($pdo, 'matricula', $matriculaId);
$paginasCertificado = paginasModeloCertificado(modeloCertificadoDaMatricula($pdo, $matriculaId));
$modulosCertificado = modulosDoCurso($pdo, (int) $certificado['curso_id']);
$valores = valoresCertificado($certificado, $config);
?>
<!doctype html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Certificado - <?= e($certificado['aluno_nome']) ?> - <?= e($certificado['curso_nome']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?= e(assetUrl('/assets/css/certificado.css')) ?>" rel="stylesheet">
</head>
<body class="cert-pagina">
    <div class="cert-barra">
        <button type="button" class="principal" onclick="window.print()"><i class="bi bi-printer"></i> Imprimir / salvar em PDF</button>
        <a href="<?= alunoLogado() && $donoId === alunoAtualId() ? '/portal/certificados.php' : '/matriculas/visualizar.php?id=' . $matriculaId ?>">Voltar</a>
    </div>
    <div class="cert-moldura"><?php require __DIR__ . '/../../includes/certificado_render.php'; ?></div>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <script src="<?= e(assetUrl('/assets/js/certificado.js')) ?>"></script>
    <script>
        // Espera as fontes para medir o texto "em uma linha" do jeito que vai sair.
        (document.fonts ? document.fonts.ready : Promise.resolve()).then(() => ajustarCertificado());
        window.addEventListener('beforeprint', () => ajustarCertificado());
    </script>
</body>
</html>
