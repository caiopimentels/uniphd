<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

// Abre um documento emitido (certificado, declaração de módulo...) para o
// próprio aluno ou para a secretaria. ?c=CODIGO (o código de verificação,
// aleatório: trocar um número na URL não leva a outro documento); o link
// antigo ?matricula=ID abre o certificado de conclusão daquela matrícula.
// Seja qual for o link, o aluno só abre os documentos dele.
$pdo = getDB();
$codigo = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($_GET['c'] ?? '')));
if ($codigo !== '') {
    $documentoId = (int) (documentoEmitidoCompleto($pdo, 'codigo', $codigo)['id'] ?? 0);
} elseif (isset($_GET['matricula'])) {
    $stmt = $pdo->prepare(
        'SELECT ce.id FROM certificados ce JOIN curso_emissoes e ON e.id = ce.curso_emissao_id WHERE ce.matricula_id = ? AND e.modulo_id IS NULL AND e.avulso = 0 ORDER BY ce.id LIMIT 1'
    );
    $stmt->execute([(int) $_GET['matricula']]);
    $documentoId = (int) $stmt->fetchColumn();
} else {
    // Pelo número, só a secretaria (o aluno abre pelo código).
    $documentoId = usuarioLogado() ? (int) ($_GET['documento'] ?? 0) : 0;
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
$marcaDaguaCertificado = marcaDaguaModelo($modelo);
$modulosCertificado = modulosDoCurso($pdo, (int) $documento['curso_id']);
$valores = valoresCertificado($documento, $config);
$voltar = $ehDono ? '/portal/certificados.php' : '/matriculas/visualizar.php?id=' . (int) $documento['matricula_id'];

// A página é montada aqui e vira PDF no servidor (ver pdfDocumentoEmitido):
// quem abre recebe o arquivo, não o HTML.
ob_start();
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
<?php
$html = ob_get_clean();
// A barra (Imprimir / Voltar) muda conforme quem abre e não vai para o PDF.
$pdf = pdfDocumentoEmitido((int) $documento['id'], preg_replace('~<div class="cert-barra">.*?</div>~s', '', $html, 1));
if ($pdf) {
    // Controle da secretaria: quando o próprio aluno baixou (e quantas vezes).
    if ($ehDono && !$ehSecretaria) {
        $pdo->prepare('UPDATE certificados SET aluno_baixou_em = COALESCE(aluno_baixou_em, NOW()), aluno_ultimo_download = NOW(), aluno_downloads = aluno_downloads + 1 WHERE id = ?')
            ->execute([$documento['id']]);
    }
    $nomeArquivo = preg_replace('/[^A-Za-z0-9 ._-]+/', '', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', ($documento['documento_nome'] ?: 'Certificado') . ' - ' . $documento['aluno_nome'])) ?: 'documento';
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $nomeArquivo . '.pdf"');
    header('Content-Length: ' . filesize($pdf));
    header('Cache-Control: private, no-store');
    readfile($pdf);
    exit;
}
// Sem o PDF (navegador do servidor ausente ou com erro): a secretaria ainda
// vê a página; o aluno recebe um aviso para tentar depois.
if ($ehSecretaria) {
    echo str_replace('<div class="cert-barra">', '<div class="cert-barra"><span style="color:#b00;font-size:14px;align-self:center">PDF indisponível no servidor (instale o chromium) — mostrando a página.</span>', $html);
    exit;
}
flash('warning', 'Não conseguimos gerar o seu documento agora. Tente de novo em alguns minutos; se continuar, fale com a secretaria.');
redirect('/portal/certificados.php');
