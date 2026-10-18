<?php

// Documentos que o aluno envia para um curso (diploma, RG...). O curso
// define quais (curso_documentos); o aluno envia pelo portal (ou a
// secretaria por ele); quem tem a permissão aprova, recusa ou dispensa.
// Documento que "trava o certificado" e não está aprovado/dispensado impede
// liberar o certificado da matrícula.
//
// Segurança: só PDF, JPG e PNG, conferidos pelo conteúdo (não pela
// extensão). Imagens são redesenhadas (o arquivo novo só tem os pixels — nada
// escondido sobrevive) e reduzidas; PDF com JavaScript, ação automática ou
// arquivo embutido é recusado, e é comprimido com o Ghostscript quando o
// servidor tem. Tudo fica em storage/ (fora do site) e só sai por
// /documentos/ver.php, para quem pode ver.

const DOCUMENTO_TAMANHO_MAXIMO = 15 * 1024 * 1024;
const DOCUMENTO_IMAGEM_LADO_MAXIMO = 2000;
const DOCUMENTO_IMAGEM_QUALIDADE = 75;

function statusDocumentoAluno(): array
{
    return [
        'pendente' => ['secondary', 'Não enviado'],
        'enviado' => ['warning', 'Aguardando análise'],
        'aprovado' => ['success', 'Aprovado'],
        'recusado' => ['danger', 'Recusado'],
        'dispensado' => ['info', 'Dispensado'],
    ];
}

function textoTiposDocumento(array $doc): string
{
    return implode(' ou ', array_filter([$doc['aceita_pdf'] ? 'PDF' : null, $doc['aceita_imagem'] ? 'foto (JPG ou PNG)' : null]));
}

function acceptDocumento(array $doc): string
{
    return implode(',', array_filter([$doc['aceita_pdf'] ? '.pdf,application/pdf' : null, $doc['aceita_imagem'] ? '.jpg,.jpeg,.png,image/jpeg,image/png' : null]));
}

// Documentos ativos do curso, com o envio do aluno (ou status 'pendente').
function documentosAlunoCurso(PDO $pdo, int $alunoId, int $cursoId): array
{
    $stmt = $pdo->prepare(
        'SELECT cd.*, ad.id AS envio_id, COALESCE(ad.status, \'pendente\') AS status, ad.arquivo, ad.nome_original, ad.mime, ad.tamanho,
                ad.tamanho_original, ad.motivo, ad.enviado_por, ad.enviado_em, ad.avaliado_por, ad.avaliado_em
         FROM curso_documentos cd
         LEFT JOIN aluno_documentos ad ON ad.curso_documento_id = cd.id AND ad.aluno_id = ?
         WHERE cd.curso_id = ? AND cd.ativo = 1
         ORDER BY cd.ordem, cd.id'
    );
    $stmt->execute([$alunoId, $cursoId]);
    return $stmt->fetchAll();
}

// Nomes dos documentos que ainda travam o certificado desta matrícula.
function pendenciasDocumentosMatricula(PDO $pdo, int $matriculaId): array
{
    $stmt = $pdo->prepare(
        "SELECT cd.nome FROM matriculas m JOIN turmas t ON t.id = m.turma_id
         JOIN curso_documentos cd ON cd.curso_id = t.curso_id AND cd.ativo = 1 AND cd.trava_certificado = 1
         LEFT JOIN aluno_documentos ad ON ad.curso_documento_id = cd.id AND ad.aluno_id = m.aluno_id
         WHERE m.id = ? AND COALESCE(ad.status, 'pendente') NOT IN ('aprovado', 'dispensado')
         ORDER BY cd.ordem, cd.id"
    );
    $stmt->execute([$matriculaId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function caminhoDocumentoAluno(array $envio): ?string
{
    return $envio['arquivo'] ? caminhoStorage('documentos/' . $envio['arquivo']) : null;
}

// Ghostscript (comprime PDF). Sem ele, o PDF é guardado como veio.
function caminhoGhostscript(): ?string
{
    static $gs = false;
    if ($gs === false) {
        $gs = null;
        foreach (['/usr/bin/gs', '/usr/local/bin/gs', '/opt/homebrew/bin/gs'] as $candidato) {
            if (is_executable($candidato)) {
                $gs = $candidato;
                break;
            }
        }
    }
    return $gs;
}

/**
 * Confere, limpa e comprime um arquivo enviado (PDF, JPG ou PNG) e grava em
 * storage/documentos/aluno_N/. Usado pelos documentos do curso e pelos
 * anexos da ficha. $rotulo e $tipos: para as mensagens e o que é aceito.
 * Retorna ['ok', 'mensagem', 'arquivo' (relativo), 'mime', 'tamanho_original', 'tamanho', 'nome_original'].
 */
function processarArquivoAluno(int $alunoId, array $arquivo, bool $aceitaPdf, bool $aceitaImagem, string $rotulo): array
{
    $doc = ['aceita_pdf' => $aceitaPdf, 'aceita_imagem' => $aceitaImagem, 'nome' => $rotulo];
    $falha = fn (string $m) => ['ok' => false, 'mensagem' => $m];
    $erro = (int) ($arquivo['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($erro === UPLOAD_ERR_INI_SIZE || $erro === UPLOAD_ERR_FORM_SIZE) {
        return $falha('O arquivo é maior do que o servidor aceita (' . ini_get('upload_max_filesize') . '). Envie um arquivo menor ou uma foto.');
    }
    if ($erro === UPLOAD_ERR_NO_FILE) {
        return $falha('Escolha o arquivo.');
    }
    if ($erro !== UPLOAD_ERR_OK || !is_uploaded_file($arquivo['tmp_name'])) {
        return $falha('O envio do arquivo falhou. Tente de novo.');
    }
    $tamanhoOriginal = (int) filesize($arquivo['tmp_name']);
    if ($tamanhoOriginal > DOCUMENTO_TAMANHO_MAXIMO) {
        return $falha('O arquivo passa de ' . (DOCUMENTO_TAMANHO_MAXIMO / 1024 / 1024) . ' MB.');
    }

    // Tipo pelo conteúdo, não pelo nome.
    $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($arquivo['tmp_name']);
    $ehPdf = $mime === 'application/pdf';
    $ehImagem = in_array($mime, ['image/jpeg', 'image/png'], true);
    if (!$ehPdf && !$ehImagem) {
        return $falha('Tipo de arquivo não aceito. Envie ' . textoTiposDocumento($doc) . '.');
    }
    if (($ehPdf && !$doc['aceita_pdf']) || ($ehImagem && !$doc['aceita_imagem'])) {
        return $falha('Para "' . $doc['nome'] . '" envie ' . textoTiposDocumento($doc) . '.');
    }

    $pasta = caminhoStorage('documentos/aluno_' . $alunoId);
    if (!is_dir($pasta)) {
        @mkdir($pasta, 0700, true);
    }
    if ($problema = problemaPastaUpload($pasta)) {
        return $falha('Não foi possível guardar o arquivo agora. A secretaria foi avisada.' . (usuarioLogado() ? ' ' . $problema : ''));
    }
    $nomeArquivo = bin2hex(random_bytes(16)) . ($ehPdf ? '.pdf' : '.jpg');
    $destino = $pasta . '/' . $nomeArquivo;

    if ($ehImagem) {
        $info = @getimagesize($arquivo['tmp_name']);
        if (!$info || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > 40_000_000) {
            return $falha('Não foi possível ler a imagem.');
        }
        $imagem = @imagecreatefromstring((string) file_get_contents($arquivo['tmp_name']));
        if (!$imagem) {
            return $falha('Não foi possível ler a imagem.');
        }
        // Foto de celular deitada: respeita a orientação gravada pela câmera.
        if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
            $exif = @exif_read_data($arquivo['tmp_name']);
            $giro = [3 => 180, 6 => -90, 8 => 90][(int) ($exif['Orientation'] ?? 1)] ?? 0;
            if ($giro) {
                $imagem = imagerotate($imagem, $giro, 0) ?: $imagem;
            }
        }
        [$largura, $altura] = [imagesx($imagem), imagesy($imagem)];
        $escala = min(1, DOCUMENTO_IMAGEM_LADO_MAXIMO / max($largura, $altura));
        $novaLargura = max(1, (int) round($largura * $escala));
        $novaAltura = max(1, (int) round($altura * $escala));
        // Redesenha numa tela nova, fundo branco (PNG transparente).
        $tela = imagecreatetruecolor($novaLargura, $novaAltura);
        imagefill($tela, 0, 0, imagecolorallocate($tela, 255, 255, 255));
        imagecopyresampled($tela, $imagem, 0, 0, 0, 0, $novaLargura, $novaAltura, $largura, $altura);
        if (!imagejpeg($tela, $destino, DOCUMENTO_IMAGEM_QUALIDADE)) {
            return $falha('Não foi possível guardar a imagem.');
        }
        $mimeFinal = 'image/jpeg';
    } else {
        $conteudo = (string) file_get_contents($arquivo['tmp_name']);
        if (!str_starts_with(ltrim(substr($conteudo, 0, 1024)), '%PDF-')) {
            return $falha('O arquivo não é um PDF válido.');
        }
        // Nomes do PDF podem vir "escondidos" (/J#61vaScript): desfaz antes de procurar.
        $normalizado = preg_replace_callback('/#([0-9A-Fa-f]{2})/', fn ($m) => chr(hexdec($m[1])), $conteudo);
        if (preg_match('~/(JavaScript|JS|Launch|EmbeddedFile|RichMedia|XFA|ImportData|SubmitForm)\b~', $normalizado)) {
            return $falha('Este PDF tem conteúdo ativo (script, formulário ou arquivo embutido) e não é aceito por segurança. Envie uma versão "impressa em PDF" ou uma foto do documento.');
        }
        $gravado = false;
        if ($gs = caminhoGhostscript()) {
            $temporario = $pasta . '/.gs_' . bin2hex(random_bytes(6)) . '.pdf';
            $comando = escapeshellarg($gs) . ' -q -dSAFER -dBATCH -dNOPAUSE -sDEVICE=pdfwrite -dCompatibilityLevel=1.5 -dPDFSETTINGS=/ebook'
                . ' -sOutputFile=' . escapeshellarg($temporario) . ' ' . escapeshellarg($arquivo['tmp_name']) . ' 2>&1';
            @exec($comando, $saida, $codigo);
            if ($codigo === 0 && is_file($temporario) && filesize($temporario) > 0 && filesize($temporario) < $tamanhoOriginal
                && str_starts_with((string) file_get_contents($temporario, false, null, 0, 5), '%PDF-')) {
                $gravado = @rename($temporario, $destino);
            }
            @unlink($temporario);
        }
        if (!$gravado && !@move_uploaded_file($arquivo['tmp_name'], $destino)) {
            return $falha('Não foi possível guardar o arquivo.');
        }
        $mimeFinal = 'application/pdf';
    }
    @chmod($destino, 0600);
    return [
        'ok' => true, 'mensagem' => '', 'arquivo' => 'aluno_' . $alunoId . '/' . $nomeArquivo, 'mime' => $mimeFinal,
        'tamanho_original' => $tamanhoOriginal, 'tamanho' => (int) filesize($destino),
        'nome_original' => mb_substr(basename((string) ($arquivo['name'] ?? 'documento')), 0, 200),
    ];
}

/**
 * Confere e grava o arquivo enviado para um documento do curso.
 * Retorna ['ok' => bool, 'mensagem' => string].
 */
function salvarDocumentoAluno(PDO $pdo, int $alunoId, array $doc, array $arquivo, string $quem): array
{
    $gravado = processarArquivoAluno($alunoId, $arquivo, (bool) $doc['aceita_pdf'], (bool) $doc['aceita_imagem'], $doc['nome']);
    if (!$gravado['ok']) {
        return $gravado;
    }
    $relativo = $gravado['arquivo'];
    $mimeFinal = $gravado['mime'];
    $tamanhoOriginal = $gravado['tamanho_original'];
    $nomeOriginal = $gravado['nome_original'];
    $destino = caminhoStorage('documentos/' . $relativo);

    // Substitui o envio anterior deste documento (o arquivo antigo é apagado).
    $stmt = $pdo->prepare('SELECT * FROM aluno_documentos WHERE aluno_id = ? AND curso_documento_id = ?');
    $stmt->execute([$alunoId, $doc['id']]);
    $anterior = $stmt->fetch();
    $pdo->prepare(
        "INSERT INTO aluno_documentos (aluno_id, curso_documento_id, status, arquivo, nome_original, mime, tamanho_original, tamanho, motivo, enviado_por, enviado_em, avaliado_por, avaliado_em)
         VALUES (?, ?, 'enviado', ?, ?, ?, ?, ?, NULL, ?, NOW(), NULL, NULL)
         ON DUPLICATE KEY UPDATE status = 'enviado', arquivo = VALUES(arquivo), nome_original = VALUES(nome_original), mime = VALUES(mime),
             tamanho_original = VALUES(tamanho_original), tamanho = VALUES(tamanho), motivo = NULL, enviado_por = VALUES(enviado_por),
             enviado_em = NOW(), avaliado_por = NULL, avaliado_em = NULL"
    )->execute([$alunoId, $doc['id'], $relativo, $nomeOriginal, $mimeFinal, $tamanhoOriginal, (int) filesize($destino), $quem]);
    if ($anterior && $anterior['arquivo'] && $anterior['arquivo'] !== $relativo) {
        @unlink(caminhoStorage('documentos/' . $anterior['arquivo']));
    }

    registrarHistoricoAluno($pdo, $alunoId, 'documento', 'Enviou "' . $doc['nome'] . '" (' . ($mimeFinal === 'application/pdf' ? 'PDF' : 'imagem') . ', '
        . formatarTamanhoArquivo((int) filesize($destino)) . ')');
    return ['ok' => true, 'mensagem' => '"' . $doc['nome'] . '" enviado. Agora a secretaria confere.'];
}

/**
 * Aprovar, recusar (com motivo) ou dispensar um documento do aluno.
 * Dispensar não precisa de arquivo: o aluno fica livre dele.
 */
function avaliarDocumentoAluno(PDO $pdo, int $alunoId, array $doc, string $acao, string $motivo = ''): array
{
    $status = ['aprovar' => 'aprovado', 'recusar' => 'recusado', 'dispensar' => 'dispensado', 'reabrir' => 'enviado'][$acao] ?? null;
    if (!$status) {
        return ['ok' => false, 'mensagem' => 'Ação inválida.'];
    }
    $stmt = $pdo->prepare('SELECT * FROM aluno_documentos WHERE aluno_id = ? AND curso_documento_id = ?');
    $stmt->execute([$alunoId, $doc['id']]);
    $envio = $stmt->fetch();
    if (in_array($acao, ['aprovar', 'recusar'], true) && (!$envio || !$envio['arquivo'])) {
        return ['ok' => false, 'mensagem' => 'O aluno ainda não enviou este documento.'];
    }
    if ($acao === 'recusar' && trim($motivo) === '') {
        return ['ok' => false, 'mensagem' => 'Diga o motivo da recusa (o aluno vê no portal).'];
    }
    if ($acao === 'reabrir' && $envio && !$envio['arquivo']) {
        // Desfazer uma dispensa sem arquivo: volta a "não enviado".
        $pdo->prepare('DELETE FROM aluno_documentos WHERE id = ?')->execute([$envio['id']]);
    } else {
        $pdo->prepare(
            'INSERT INTO aluno_documentos (aluno_id, curso_documento_id, status, motivo, avaliado_por, avaliado_em) VALUES (?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE status = VALUES(status), motivo = VALUES(motivo), avaliado_por = VALUES(avaliado_por), avaliado_em = NOW()'
        )->execute([$alunoId, $doc['id'], $status, $acao === 'recusar' || $acao === 'dispensar' ? (mb_substr(trim($motivo), 0, 255) ?: null) : null, usuarioAtualNome()]);
    }
    $rotulo = ['aprovar' => 'Aprovado', 'recusar' => 'Recusado', 'dispensar' => 'Dispensado', 'reabrir' => 'Reaberto para nova análise'][$acao];
    registrarHistoricoAluno($pdo, $alunoId, 'documento', $rotulo . ': "' . $doc['nome'] . '"' . (trim($motivo) !== '' ? ' — ' . trim($motivo) : ''));
    if (in_array($acao, ['aprovar', 'dispensar'], true)) {
        sincronizarDocumentosAluno($pdo, $alunoId); // sem pendência, libera os documentos que esperavam
    }
    return ['ok' => true, 'mensagem' => $rotulo . ': ' . $doc['nome'] . '.'];
}

function formatarTamanhoArquivo(int $bytes): string
{
    return $bytes >= 1048576 ? number_format($bytes / 1048576, 1, ',', '.') . ' MB' : max(1, (int) round($bytes / 1024)) . ' KB';
}

// Cursos do aluno que pedem documentos, com os documentos de cada um.
function documentosDoAluno(PDO $pdo, int $alunoId): array
{
    $stmt = $pdo->prepare(
        "SELECT DISTINCT c.id, c.nome FROM matriculas m JOIN turmas t ON t.id = m.turma_id JOIN cursos c ON c.id = t.curso_id
         WHERE m.aluno_id = ? AND m.status IN ('ativa', 'pendente', 'concluida')
           AND EXISTS (SELECT 1 FROM curso_documentos cd WHERE cd.curso_id = c.id AND cd.ativo = 1)
         ORDER BY c.nome"
    );
    $stmt->execute([$alunoId]);
    $grupos = [];
    foreach ($stmt->fetchAll() as $curso) {
        $grupos[] = ['curso' => $curso, 'documentos' => documentosAlunoCurso($pdo, $alunoId, (int) $curso['id'])];
    }
    return $grupos;
}

// Quantos documentos o aluno ainda precisa enviar (não enviados ou recusados).
function documentosParaEnviar(array $grupos): int
{
    $total = 0;
    foreach ($grupos as $grupo) {
        $total += count(array_filter($grupo['documentos'], fn ($d) => in_array($d['status'], ['pendente', 'recusado'], true)));
    }
    return $total;
}

// ---------------------------------------------------------------------
// Anexos da ficha do aluno (guardados pela secretaria)
// ---------------------------------------------------------------------

function anexosAluno(PDO $pdo, int $alunoId, bool $soVisiveis = false): array
{
    $stmt = $pdo->prepare('SELECT * FROM aluno_anexos WHERE aluno_id = ?' . ($soVisiveis ? ' AND visivel_aluno = 1' : '') . ' ORDER BY criado_em DESC, id DESC');
    $stmt->execute([$alunoId]);
    return $stmt->fetchAll();
}

function salvarAnexoAluno(PDO $pdo, int $alunoId, string $nome, array $arquivo, bool $visivel): array
{
    $nome = mb_substr(trim($nome), 0, 150);
    if ($nome === '') {
        return ['ok' => false, 'mensagem' => 'Dê um nome ao anexo (ex.: Contrato assinado).'];
    }
    $gravado = processarArquivoAluno($alunoId, $arquivo, true, true, $nome);
    if (!$gravado['ok']) {
        return $gravado;
    }
    $pdo->prepare(
        'INSERT INTO aluno_anexos (aluno_id, nome, arquivo, nome_original, mime, tamanho_original, tamanho, visivel_aluno, enviado_por) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([$alunoId, $nome, $gravado['arquivo'], $gravado['nome_original'], $gravado['mime'], $gravado['tamanho_original'], $gravado['tamanho'], $visivel ? 1 : 0, usuarioAtualNome()]);
    registrarHistoricoAluno($pdo, $alunoId, 'documento', 'Anexo "' . $nome . '" guardado na ficha' . ($visivel ? ' (liberado para o aluno)' : ''));
    return ['ok' => true, 'mensagem' => 'Anexo "' . $nome . '" guardado' . ($visivel ? ' e liberado para o aluno.' : '.')];
}
