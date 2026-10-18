<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

// Abre um documento emitido (certificado, declaração de módulo...) para o
// próprio aluno ou para a secretaria. ?documento=ID; o link antigo
// ?matricula=ID abre o certificado de conclusão daquela matrícula.
$pdo = getDB();
if (isset($_GET['matricula'])) {
    $stmt = $pdo->prepare(
        'SELECT ce.id FROM certificados ce JOIN curso_emissoes e ON e.id = ce.curso_emissao_id WHERE ce.matricula_id = ? AND e.modulo_id IS NULL AND e.avulso = 0 ORDER BY ce.id LIMIT 1'
    );
    $stmt->execute([(int) $_GET['matricula']]);
    $documentoId = (int) $stmt->fetchColumn();
} else {
    $documentoId = (int) ($_GET['documento'] ?? 0);
}
$documento = $documentoId ? documentoEmitidoCompleto($pdo, 'id', $documentoId) : null;

// Quem atende o aluno vê sempre (até bloqueado); o aluno só o que está
// liberado e sem pendência.
$ehSecretaria = usuarioLogado() && (usuarioPodeVerAlunos() || usuarioPodeGerenciarMatriculas() || usuarioPodeLiberarCertificado());
$ehDono = $documento && alunoLogado() && (int) $documento['aluno_id'] === alunoAtualId();
if (!$documento || (!$ehDono && !$ehSecretaria)) {
    if (!alunoLogado() && !usuarioLogado()) {
        exigirAluno();
    }
    if (alunoLogado() && isset($_GET['matricula'])) {
        flash('info', 'Este documento ainda não está disponível.');
        redirect('/portal/certificados.php');
    }
    http_response_code(404);
    exit('Documento não encontrado.');
}
if (!$ehSecretaria) {
    $matricula = matriculaParaDocumentos($pdo, (int) $documento['matricula_id']);
    if ($bloqueios = bloqueiosDocumentosMatricula($pdo, $matricula)) {
        flash('warning', 'Seu documento está bloqueado: ' . implode(' · ', $bloqueios) . '. Em caso de dúvida, fale com a secretaria.');
        redirect(isset($bloqueios['documentacao']) ? '/portal/documentos.php' : '/portal/certificados.php');
    }
    if (!$documento['liberado']) {
        flash('info', 'Este documento está em análise pela secretaria. Em caso de dúvida, fale com a gente.');
        redirect('/portal/certificados.php');
    }
}

$config = buscarConfiguracoes($pdo);
$modelo = modeloDoDocumento($pdo, $documento);
$paginasCertificado = paginasModeloCertificado($modelo);
$orientacaoCertificado = $modelo['orientacao'] ?? 'horizontal';
$modulosCertificado = modulosDoCurso($pdo, (int) $documento['curso_id']);
$valores = valoresCertificado($documento, $config);
$voltar = $ehDono ? '/portal/certificados.php' : '/matriculas/visualizar.php?id=' . (int) $documento['matricula_id'];
?>
<!doctype html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($documento['documento_nome'] ?: 'Certificado') ?> - <?= e($documento['aluno_nome']) ?> - <?= e($documento['curso_nome']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?= e(assetUrl('/assets/css/certificado.css')) ?>" rel="stylesheet">
    <?php if ($orientacaoCertificado === 'vertical'): ?>
        <style>@media print { @page { size: A4 portrait; margin: 0; } .cert-moldura { width: 210mm; } .cert-folha { width: 210mm; height: 297mm; } }</style>
    <?php endif; ?>
</head>
<body class="cert-pagina">
    <div class="cert-barra">
        <button type="button" class="principal" onclick="window.print()"><i class="bi bi-printer"></i> Imprimir / salvar em PDF</button>
        <a href="<?= e($voltar) ?>">Voltar</a>
    </div>
    <div class="cert-moldura<?= $orientacaoCertificado === 'vertical' ? ' cert-moldura-vertical' : '' ?>"><?php require __DIR__ . '/../../includes/certificado_render.php'; ?></div>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <script src="<?= e(assetUrl('/assets/js/certificado.js')) ?>"></script>
    <script>
        // Espera as fontes para medir o texto "em uma linha" do jeito que vai sair.
        (document.fonts ? document.fonts.ready : Promise.resolve()).then(() => ajustarCertificado());
        window.addEventListener('beforeprint', () => ajustarCertificado());
    </script>
</body>
</html>
