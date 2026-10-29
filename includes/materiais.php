<?php

// ---------------------------------------------------------------------
// Materiais dos cursos e turmas (apostilas, slides, artigos, links...).
// Do curso = aparece em todas as turmas dele; da turma = só naquela.
// Ficam em "locais" (pastas). Arquivos no próprio servidor
// (storage/materiais) ou num FTP (ex.: TurboCloud), conforme
// Configurações › Arquivos. O aluno (matrícula ativa, pendente ou
// concluída) baixa pelo portal, sempre passando pelo sistema.
// Usuário ligado a um professor só mexe nas turmas dele (e nos cursos
// dessas turmas).
// ---------------------------------------------------------------------

const MATERIAIS_PASTA_LOCAL = '/storage/materiais';

function usuarioPodeGerenciarMateriais(): bool
{
    return usuarioTemPermissao('gerenciar_materiais');
}

/** Professor ligado ao usuário logado (null = equipe: vê todos). */
function professorDoUsuario(PDO $pdo): ?int
{
    static $cache = [];
    $uid = (int) usuarioAtualId();
    if (!array_key_exists($uid, $cache)) {
        $stmt = $pdo->prepare('SELECT professor_id FROM usuarios WHERE id = ?');
        $stmt->execute([$uid]);
        $cache[$uid] = ($p = $stmt->fetchColumn()) ? (int) $p : null;
    }
    // Administrador vê tudo, mesmo ligado a um professor.
    return usuarioEhAdmin() ? null : $cache[$uid];
}

/** Turmas do professor: a principal (turmas.professor_id) e as liberadas (turma_professores). */
function turmasDoProfessor(PDO $pdo, int $professorId): array
{
    $stmt = $pdo->prepare('SELECT id FROM turmas WHERE professor_id = ? UNION SELECT turma_id FROM turma_professores WHERE professor_id = ?');
    $stmt->execute([$professorId, $professorId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function podeMaterialTurma(PDO $pdo, int $turmaId): bool
{
    if (!usuarioPodeGerenciarMateriais()) {
        return false;
    }
    $prof = professorDoUsuario($pdo);
    return $prof === null || in_array($turmaId, turmasDoProfessor($pdo, $prof), true);
}

// Curso: a equipe sempre; o professor, se dá aula em alguma turma do curso.
function podeMaterialCurso(PDO $pdo, int $cursoId): bool
{
    if (!usuarioPodeGerenciarMateriais()) {
        return false;
    }
    $prof = professorDoUsuario($pdo);
    if ($prof === null) {
        return true;
    }
    $turmas = turmasDoProfessor($pdo, $prof);
    if (!$turmas) {
        return false;
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM turmas WHERE curso_id = ? AND id IN (' . implode(',', $turmas) . ')');
    $stmt->execute([$cursoId]);
    return (int) $stmt->fetchColumn() > 0;
}

/**
 * Editar/excluir um material: a equipe, qualquer um; o professor, os da
 * turma dele e, do curso, só os que ele mesmo enviou.
 */
function podeEditarMaterial(PDO $pdo, array $material): bool
{
    if (!usuarioPodeGerenciarMateriais()) {
        return false;
    }
    if (professorDoUsuario($pdo) === null) {
        return true;
    }
    if ($material['turma_id']) {
        return podeMaterialTurma($pdo, (int) $material['turma_id']);
    }
    return (int) $material['usuario_id'] === (int) usuarioAtualId() && podeMaterialCurso($pdo, (int) $material['curso_id']);
}

function locaisMaterial(PDO $pdo, bool $soAtivos = true): array
{
    return $pdo->query('SELECT * FROM material_locais' . ($soAtivos ? ' WHERE ativo = 1' : '') . ' ORDER BY ordem, nome')->fetchAll();
}

/**
 * Materiais de uma turma: os do curso dela e os extras da turma.
 * $soLiberados: só os visíveis e já liberados (o que o aluno vê).
 */
function materiaisDaTurma(PDO $pdo, int $turmaId, bool $soLiberados = false): array
{
    $sql = "SELECT mt.*, ml.nome AS local_nome, ml.icone AS local_icone, ml.ordem AS local_ordem, cm.nome AS modulo_nome, cm.ordem AS modulo_ordem,
                   (SELECT COUNT(*) FROM material_acessos a WHERE a.material_id = mt.id) AS alunos_acessaram
            FROM materiais mt
            JOIN material_locais ml ON ml.id = mt.local_id
            LEFT JOIN curso_modulos cm ON cm.id = mt.curso_modulo_id
            WHERE (mt.turma_id = ? OR mt.curso_id = (SELECT curso_id FROM turmas WHERE id = ?))";
    if ($soLiberados) {
        $sql .= ' AND mt.visivel = 1 AND (mt.liberar_em IS NULL OR mt.liberar_em <= NOW())';
    }
    $stmt = $pdo->prepare($sql . ' ORDER BY ml.ordem, ml.nome, cm.ordem IS NULL, cm.ordem, mt.criado_em');
    $stmt->execute([$turmaId, $turmaId]);
    return $stmt->fetchAll();
}

function materiaisDoCurso(PDO $pdo, int $cursoId): array
{
    $stmt = $pdo->prepare("SELECT mt.*, ml.nome AS local_nome, ml.icone AS local_icone, cm.nome AS modulo_nome,
                                  (SELECT COUNT(*) FROM material_acessos a WHERE a.material_id = mt.id) AS alunos_acessaram
                           FROM materiais mt JOIN material_locais ml ON ml.id = mt.local_id LEFT JOIN curso_modulos cm ON cm.id = mt.curso_modulo_id
                           WHERE mt.curso_id = ? ORDER BY ml.ordem, ml.nome, cm.ordem IS NULL, cm.ordem, mt.criado_em");
    $stmt->execute([$cursoId]);
    return $stmt->fetchAll();
}

/** Situação para a equipe: [texto, cor]. */
function situacaoMaterial(array $m): array
{
    if (!$m['visivel']) {
        return ['Escondido', 'secondary'];
    }
    if ($m['liberar_em'] && $m['liberar_em'] > date('Y-m-d H:i:s')) {
        return ['Libera em ' . formatDateTime($m['liberar_em']), 'info'];
    }
    return ['Liberado', 'success'];
}

/** Matrícula do aluno que dá acesso ao material (ativa, pendente ou concluída) ou null. */
function matriculaComAcessoMaterial(PDO $pdo, int $alunoId, array $material): ?int
{
    $stmt = $pdo->prepare("SELECT m.id FROM matriculas m JOIN turmas t ON t.id = m.turma_id
                           WHERE m.aluno_id = ? AND m.status IN (" . statusAcessoConteudoPortal($pdo) . ") AND (m.turma_id = ? OR t.curso_id = ?) LIMIT 1");
    $stmt->execute([$alunoId, (int) $material['turma_id'], (int) $material['curso_id']]);
    return ($id = $stmt->fetchColumn()) ? (int) $id : null;
}

function registrarAcessoMaterial(PDO $pdo, int $materialId, int $alunoId): void
{
    $pdo->prepare('INSERT INTO material_acessos (material_id, aluno_id) VALUES (?, ?)
                   ON DUPLICATE KEY UPDATE vezes = vezes + 1, ultimo_em = NOW()')->execute([$materialId, $alunoId]);
}

function tamanhoLegivel(int $bytes): string
{
    return match (true) {
        $bytes >= 1073741824 => number_format($bytes / 1073741824, 1, ',', '.') . ' GB',
        $bytes >= 1048576 => number_format($bytes / 1048576, 1, ',', '.') . ' MB',
        default => max(1, (int) round($bytes / 1024)) . ' KB',
    };
}

function iconeArquivo(?string $tipo, ?string $nome = null): string
{
    $ext = strtolower(pathinfo((string) $nome, PATHINFO_EXTENSION));
    return match (true) {
        $tipo === 'application/pdf' || $ext === 'pdf' => 'bi-file-earmark-pdf text-danger',
        str_starts_with((string) $tipo, 'image/') => 'bi-file-earmark-image text-primary',
        str_starts_with((string) $tipo, 'video/') => 'bi-file-earmark-play text-primary',
        str_starts_with((string) $tipo, 'audio/') => 'bi-file-earmark-music text-primary',
        in_array($ext, ['doc', 'docx', 'odt', 'rtf'], true) => 'bi-file-earmark-word text-primary',
        in_array($ext, ['xls', 'xlsx', 'ods', 'csv'], true) => 'bi-file-earmark-excel text-success',
        in_array($ext, ['ppt', 'pptx', 'odp'], true) => 'bi-file-earmark-slides text-warning',
        in_array($ext, ['zip', 'rar', '7z'], true) => 'bi-file-earmark-zip',
        default => 'bi-file-earmark',
    };
}

// ---------------------------------------------------------------------
// Armazenamento: no servidor (storage/materiais) ou no FTP
// ---------------------------------------------------------------------

/** Configuração do FTP (senha descriptografada). */
function ftpMateriais(PDO $pdo): array
{
    $c = buscarConfiguracoes($pdo);
    return [
        'destino' => $c['arquivos_destino'] ?? 'local',
        'host' => trim((string) ($c['ftp_host'] ?? '')),
        'porta' => (int) ($c['ftp_porta'] ?? 21),
        'seguranca' => $c['ftp_seguranca'] ?? 'ftps',
        'verificar' => (bool) ($c['ftp_verificar_certificado'] ?? 0),
        'usuario' => (string) ($c['ftp_usuario'] ?? ''),
        'senha' => (string) (descriptografar($c['ftp_senha_cripto'] ?? null) ?? ''),
        'pasta' => '/' . trim((string) ($c['ftp_pasta'] ?? '/materiais'), '/'),
        'max_mb' => max(1, (int) ($c['material_max_mb'] ?? 10)),
        'limite_gb' => max(1, (int) ($c['material_limite_gb'] ?? 15)),
    ];
}

/** Sessão curl para um caminho no FTP. */
function curlFtp(array $ftp, string $caminho)
{
    $caminho = '/' . ltrim($caminho, '/');
    $url = 'ftp://' . $ftp['host'] . ':' . $ftp['porta'] . implode('/', array_map('rawurlencode', explode('/', $caminho)));
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_USERPWD => $ftp['usuario'] . ':' . $ftp['senha'],
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 600,
        CURLOPT_FTP_USE_EPSV => true,
        CURLOPT_FTP_CREATE_MISSING_DIRS => CURLFTP_CREATE_DIR_RETRY,
    ]);
    if ($ftp['seguranca'] === 'ftps') {
        curl_setopt_array($ch, [
            CURLOPT_USE_SSL => CURLUSESSL_ALL,
            CURLOPT_FTPSSLAUTH => CURLFTPAUTH_TLS,
            CURLOPT_SSL_VERIFYPEER => $ftp['verificar'],
            CURLOPT_SSL_VERIFYHOST => $ftp['verificar'] ? 2 : 0,
        ]);
    }
    return $ch;
}

function erroFtpLegivel(int $codigo, string $erro): string
{
    // Códigos do curl: 67 login recusado; 6/7/28 endereço, conexão, tempo; 64 sem FTPS.
    return match ($codigo) {
        67 => 'O FTP recusou o acesso: confira usuário e senha' . ($erro && str_contains($erro, '530') ? ' — e, se estiver em "FTP simples", tente "FTPS" (muitos servidores exigem).' : '.'),
        6, 7, 28 => 'Não foi possível conectar ao FTP (endereço/porta).',
        64 => 'O FTP não aceitou conexão criptografada (FTPS). Escolha "FTP simples" em Configurações › Arquivos.',
        9 => 'O FTP recusou a pasta (sem permissão para criar/gravar): confira a "Pasta no FTP".',
        default => 'Erro no FTP: ' . $erro,
    };
}

/**
 * Guarda um arquivo enviado. Retorna ['ok', 'armazenamento', 'caminho', 'mensagem'].
 * Caminho: AAAA/MM/codigo.ext (o nome original fica no banco).
 */
function guardarArquivoMaterial(PDO $pdo, string $arquivoTemporario, string $nomeOriginal, string $codigo): array
{
    $ftp = ftpMateriais($pdo);
    $ext = strtolower(preg_replace('/[^a-z0-9]/i', '', pathinfo($nomeOriginal, PATHINFO_EXTENSION)));
    $relativo = date('Y/m') . '/' . $codigo . ($ext !== '' ? '.' . substr($ext, 0, 10) : '');
    if ($ftp['destino'] === 'ftp') {
        if (!$ftp['host'] || !$ftp['usuario']) {
            return ['ok' => false, 'mensagem' => 'O FTP não está configurado (Configurações › Arquivos).'];
        }
        $fp = fopen($arquivoTemporario, 'rb');
        $ch = curlFtp($ftp, $ftp['pasta'] . '/' . $relativo);
        curl_setopt_array($ch, [CURLOPT_UPLOAD => true, CURLOPT_INFILE => $fp, CURLOPT_INFILESIZE => filesize($arquivoTemporario)]);
        curl_exec($ch);
        $codigoErro = curl_errno($ch);
        $erro = curl_error($ch);
        curl_close($ch);
        fclose($fp);
        if ($codigoErro) {
            return ['ok' => false, 'mensagem' => erroFtpLegivel($codigoErro, $erro)];
        }
        return ['ok' => true, 'armazenamento' => 'ftp', 'caminho' => $ftp['pasta'] . '/' . $relativo, 'mensagem' => ''];
    }
    $destino = BASE_PATH . MATERIAIS_PASTA_LOCAL . '/' . $relativo;
    if (!is_dir(dirname($destino)) && !@mkdir(dirname($destino), 0750, true)) {
        return ['ok' => false, 'mensagem' => 'Não foi possível criar a pasta dos materiais no servidor.'];
    }
    $movido = is_uploaded_file($arquivoTemporario) ? move_uploaded_file($arquivoTemporario, $destino) : copy($arquivoTemporario, $destino);
    return $movido
        ? ['ok' => true, 'armazenamento' => 'local', 'caminho' => $relativo, 'mensagem' => '']
        : ['ok' => false, 'mensagem' => 'Não foi possível gravar o arquivo no servidor.'];
}

/** Manda o arquivo para o navegador (do servidor ou puxando do FTP). */
function enviarArquivoMaterial(PDO $pdo, array $material, bool $baixar = true): void
{
    $nome = (string) ($material['arquivo_nome'] ?: 'material');
    $tipo = (string) ($material['arquivo_tipo'] ?: 'application/octet-stream');
    // PDF e imagem abrem no navegador; o resto sempre baixa.
    $abrir = !$baixar && in_array($tipo, ['application/pdf', 'image/png', 'image/jpeg', 'image/gif', 'image/webp'], true);
    $cabecalhos = function (?int $tamanho) use ($nome, $tipo, $abrir) {
        header('Content-Type: ' . ($abrir ? $tipo : 'application/octet-stream'));
        header('Content-Disposition: ' . ($abrir ? 'inline' : 'attachment') . '; filename="' . preg_replace('/[^\x20-\x7e]|"/', '_', $nome) . "\"; filename*=UTF-8''" . rawurlencode($nome));
        if ($tamanho) {
            header('Content-Length: ' . $tamanho);
        }
        header('Cache-Control: private, max-age=0');
        if ($tipo !== 'application/pdf') {
            header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
        }
    };
    if ($material['armazenamento'] === 'ftp') {
        $ftp = ftpMateriais($pdo);
        $ch = curlFtp($ftp, (string) $material['caminho']);
        $comecou = false;
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $dados) use (&$comecou, $cabecalhos, $material) {
            if (!$comecou) {
                $cabecalhos((int) $material['tamanho'] ?: null);
                $comecou = true;
            }
            echo $dados;
            return strlen($dados);
        });
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        curl_exec($ch);
        $erro = curl_errno($ch);
        curl_close($ch);
        if ($erro && !$comecou) {
            http_response_code(502);
            exit('Não foi possível buscar o arquivo agora. Tente de novo em instantes.');
        }
        exit;
    }
    $arquivo = BASE_PATH . MATERIAIS_PASTA_LOCAL . '/' . str_replace('..', '', (string) $material['caminho']);
    if (!is_file($arquivo)) {
        http_response_code(404);
        exit('Arquivo não encontrado.');
    }
    $cabecalhos(filesize($arquivo));
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    readfile($arquivo);
    exit;
}

function apagarArquivoMaterial(PDO $pdo, array $material): void
{
    if ($material['tipo'] !== 'arquivo' || !$material['caminho']) {
        return;
    }
    if ($material['armazenamento'] === 'ftp') {
        $ftp = ftpMateriais($pdo);
        $ch = curlFtp($ftp, $ftp['pasta']);
        curl_setopt_array($ch, [CURLOPT_QUOTE => ['DELE ' . $material['caminho']], CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true]);
        curl_exec($ch);
        curl_close($ch);
        return;
    }
    @unlink(BASE_PATH . MATERIAIS_PASTA_LOCAL . '/' . str_replace('..', '', (string) $material['caminho']));
}

/** Testa o FTP: conecta, entra na pasta, grava e apaga um arquivinho. */
function testarFtpMateriais(array $ftp): array
{
    if (!$ftp['host'] || !$ftp['usuario']) {
        return ['ok' => false, 'mensagem' => 'Preencha o endereço, o usuário e a senha do FTP.'];
    }
    $tmp = tempnam(sys_get_temp_dir(), 'ftp');
    file_put_contents($tmp, 'teste ' . date('c'));
    $nome = $ftp['pasta'] . '/.teste-' . bin2hex(random_bytes(4)) . '.txt';
    $fp = fopen($tmp, 'rb');
    $ch = curlFtp($ftp, $nome);
    curl_setopt_array($ch, [CURLOPT_UPLOAD => true, CURLOPT_INFILE => $fp, CURLOPT_INFILESIZE => filesize($tmp), CURLOPT_TIMEOUT => 30]);
    curl_exec($ch);
    $codigo = curl_errno($ch);
    $erro = curl_error($ch);
    curl_close($ch);
    fclose($fp);
    @unlink($tmp);
    if ($codigo) {
        return ['ok' => false, 'mensagem' => erroFtpLegivel($codigo, $erro)];
    }
    $ch = curlFtp($ftp, $ftp['pasta'] . '/');
    curl_setopt_array($ch, [CURLOPT_QUOTE => ['DELE ' . $nome], CURLOPT_RETURNTRANSFER => true, CURLOPT_FTPLISTONLY => true]);
    curl_exec($ch);
    curl_close($ch);
    return ['ok' => true, 'mensagem' => 'FTP funcionando: gravou e apagou um arquivo de teste em ' . $ftp['pasta'] . '.'];
}

/** Espaço usado pelos arquivos dos materiais: ['local' => bytes, 'ftp' => bytes, 'quantos' => n]. */
function espacoMateriais(PDO $pdo): array
{
    $linhas = $pdo->query("SELECT armazenamento, SUM(tamanho) AS total, COUNT(*) AS n FROM materiais WHERE tipo = 'arquivo' GROUP BY armazenamento")->fetchAll();
    $espaco = ['local' => 0, 'ftp' => 0, 'quantos' => 0];
    foreach ($linhas as $l) {
        $espaco[$l['armazenamento'] ?: 'local'] = (int) $l['total'];
        $espaco['quantos'] += (int) $l['n'];
    }
    return $espaco;
}

/** Espaço total usado x limite: ['usado' => bytes, 'limite' => bytes, 'livre' => bytes, 'pct' => 0-100]. */
function cotaMateriais(PDO $pdo): array
{
    $usado = (int) $pdo->query("SELECT COALESCE(SUM(tamanho), 0) FROM materiais WHERE tipo = 'arquivo'")->fetchColumn();
    $limite = ftpMateriais($pdo)['limite_gb'] * 1073741824;
    return ['usado' => $usado, 'limite' => $limite, 'livre' => max(0, $limite - $usado), 'pct' => min(100, (int) round($usado / max(1, $limite) * 100))];
}

/** Link aceito para material: http(s) (YouTube, Drive...). */
function linkMaterialValido(string $link): ?string
{
    $link = trim($link);
    if (preg_match('#^www\.#i', $link)) {
        $link = 'https://' . $link;
    }
    return preg_match('#^https?://[^\s"<>]+$#i', $link) ? mb_substr($link, 0, 500) : null;
}

// ---------------------------------------------------------------------
// Segurança dos arquivos enviados
// ---------------------------------------------------------------------

/**
 * Tipos aceitos: extensão => tipos (MIME) que o conteúdo real pode ter.
 * O conteúdo é conferido (finfo), não só o nome: um .exe renomeado para
 * .pdf é recusado. Fora daqui (programas, scripts, HTML, SVG, compactados,
 * Office com macro...) não entra.
 */
function tiposArquivoMaterial(): array
{
    $office = ['application/zip', 'application/octet-stream'];
    return [
        'pdf' => ['application/pdf'],
        'doc' => ['application/msword', 'application/CDFV2', 'application/x-ole-storage', 'application/vnd.ms-office'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', ...$office],
        'xls' => ['application/vnd.ms-excel', 'application/CDFV2', 'application/x-ole-storage', 'application/vnd.ms-office'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', ...$office],
        'ppt' => ['application/vnd.ms-powerpoint', 'application/CDFV2', 'application/x-ole-storage', 'application/vnd.ms-office'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', ...$office],
        'odt' => ['application/vnd.oasis.opendocument.text', ...$office],
        'ods' => ['application/vnd.oasis.opendocument.spreadsheet', ...$office],
        'odp' => ['application/vnd.oasis.opendocument.presentation', ...$office],
        'txt' => ['text/plain'],
        'csv' => ['text/plain', 'text/csv', 'application/csv'],
        'rtf' => ['text/rtf', 'application/rtf'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
        'mp3' => ['audio/mpeg', 'audio/mp3'],
        'm4a' => ['audio/mp4', 'audio/x-m4a', 'video/mp4'],
        'mp4' => ['video/mp4'],
    ];
}

/**
 * Confere um arquivo enviado. Retorna ['ok' => bool, 'tipo' => MIME
 * real, 'mensagem' => motivo da recusa].
 */
function validarArquivoMaterial(string $caminho, string $nome): array
{
    $recusa = fn (string $motivo) => ['ok' => false, 'tipo' => null, 'mensagem' => '"' . $nome . '": ' . $motivo];
    $ext = strtolower(pathinfo($nome, PATHINFO_EXTENSION));
    $tipos = tiposArquivoMaterial();
    // Nome com duas extensões perigosas ("apostila.php.pdf") também não passa.
    if (in_array($ext, extensoesPerigosas(), true)) {
        return $recusa('esse tipo de arquivo não é aceito (programa/script).');
    }
    if (!isset($tipos[$ext])) {
        return $recusa('tipo não aceito. Aceitos: ' . implode(', ', array_keys($tipos)) . '.');
    }
    $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($caminho);
    if (!in_array($mime, $tipos[$ext], true)) {
        return $recusa('o conteúdo não é de um .' . $ext . ' de verdade (parece ' . ($mime ?: 'outro tipo') . ').');
    }
    $inicio = (string) file_get_contents($caminho, false, null, 0, 8 * 1024 * 1024);
    // Código PHP / script escondido dentro (ex.: imagem "poliglota"). Só
    // assinaturas longas: sequências curtas aparecem por acaso em fotos.
    if (preg_match('/<\?php[\s\/]|<script[\s>]/i', $inicio)) {
        return $recusa('o arquivo tem código escondido e foi bloqueado.');
    }
    // Imagem: precisa abrir como imagem.
    if (str_starts_with($mime, 'image/') && !@getimagesize($caminho)) {
        return $recusa('a imagem está corrompida ou não é uma imagem.');
    }
    // PDF com ação automática (JavaScript, abrir programa, arquivo embutido).
    if ($ext === 'pdf' && preg_match('#/(JavaScript|JS|Launch|EmbeddedFile|OpenAction\s*<<[^>]*/JS|RichMedia|XFA)\b#', $inicio)) {
        return $recusa('o PDF tem conteúdo ativo (JavaScript, arquivo embutido ou ação automática) e foi bloqueado. Salve de novo como PDF comum ("Imprimir como PDF").');
    }
    // Office com macro (VBA): no formato novo (zip) e no antigo.
    if (in_array($ext, ['docx', 'xlsx', 'pptx', 'odt', 'ods', 'odp'], true)) {
        $zip = new ZipArchive();
        if ($zip->open($caminho) !== true) {
            return $recusa('o documento está corrompido.');
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $interno = strtolower((string) $zip->getNameIndex($i));
            if (str_contains($interno, 'vbaproject') || str_contains($interno, 'activex') || str_starts_with($interno, 'basic/') || str_ends_with($interno, '.bin') && str_contains($interno, 'macro')) {
                $zip->close();
                return $recusa('o documento tem macro e foi bloqueado. Salve sem macros (.docx/.xlsx/.pptx comum).');
            }
            if (preg_match('/\.(exe|dll|js|vbs|bat|cmd|ps1|jar|scr|hta)$/', $interno)) {
                $zip->close();
                return $recusa('o documento tem um programa embutido e foi bloqueado.');
            }
        }
        $zip->close();
    }
    if (in_array($ext, ['doc', 'xls', 'ppt'], true) && preg_match('/_VBA_PROJECT|VBA[\x00]?Project|Macros/i', $inicio)) {
        return $recusa('o documento tem macro e foi bloqueado. Salve sem macros.');
    }
    // Antivírus do servidor (ClamAV), se estiver instalado.
    $antivirus = antivirusMaterial($caminho);
    if ($antivirus !== null) {
        return $recusa($antivirus);
    }
    return ['ok' => true, 'tipo' => $mime, 'mensagem' => ''];
}

/** Antivírus (seguranca_arquivos.php) num arquivo enviado. null = limpo ou sem antivírus. */
function antivirusMaterial(string $caminho): ?string
{
    return verificarAntivirusArquivo($caminho);
}
