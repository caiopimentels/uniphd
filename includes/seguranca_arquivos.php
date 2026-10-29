<?php

// ---------------------------------------------------------------------
// Segurança de arquivos (anexos de e-mail e materiais): o sistema nunca
// abre nem executa arquivo de ninguém — só guarda e entrega para baixar.
// Aqui ficam as barreiras: tipos perigosos bloqueados (programas, scripts,
// Office com macro, PDF com código, compactado com programa dentro) e o
// antivírus ClamAV, quando instalado no servidor.
// ---------------------------------------------------------------------

/** Extensões que nunca passam (programas, scripts, atalhos, macros, páginas). */
function extensoesPerigosas(): array
{
    return ['exe', 'com', 'scr', 'pif', 'bat', 'cmd', 'msi', 'msp', 'cpl', 'dll', 'sys', 'drv', 'ocx', 'lnk', 'url', 'reg', 'inf',
        'js', 'jse', 'vbs', 'vbe', 'wsf', 'wsh', 'hta', 'ps1', 'psm1', 'sh', 'bash', 'csh', 'ksh', 'py', 'pyc', 'pl', 'rb', 'php', 'php3', 'php4', 'php5',
        'php7', 'phtml', 'phar', 'cgi', 'jar', 'class', 'apk', 'app', 'dmg', 'pkg', 'iso', 'img', 'vhd', 'vhdx', 'deb', 'rpm',
        'docm', 'dotm', 'xlsm', 'xltm', 'xlam', 'pptm', 'potm', 'ppam', 'ppsm', 'sldm',
        'html', 'htm', 'xhtml', 'shtml', 'svg', 'swf', 'chm', 'scf', 'gadget', 'msc', 'application'];
}

/**
 * O arquivo é perigoso? Retorna o motivo (texto para o usuário) ou null.
 * Olha o nome (inclusive "fatura.pdf.exe"), o conteúdo real (programa
 * disfarçado), macros do Office, PDF com código e o que há dentro de .zip.
 */
function motivoArquivoPerigoso(string $nome, string $conteudo, bool $comAntivirus = true): ?string
{
    $perigosas = extensoesPerigosas();
    // Vale a última extensão ("fatura.pdf.exe" é .exe; "aula.com.br.pdf" é .pdf).
    $ultima = strtolower(trim(pathinfo(rtrim($nome, '. '), PATHINFO_EXTENSION)));
    if (in_array($ultima, $perigosas, true)) {
        return 'tipo perigoso (.' . $ultima . ') — programa, script, página ou documento com macro.';
    }
    // Programa disfarçado (Windows "MZ", Linux "ELF", Mac "Mach-O") ou script com "#!".
    if (str_starts_with($conteudo, 'MZ') || str_starts_with($conteudo, "\x7fELF") || str_starts_with($conteudo, "\xcf\xfa\xed\xfe")
        || str_starts_with($conteudo, "\xca\xfe\xba\xbe") || str_starts_with($conteudo, '#!')) {
        return 'é um programa disfarçado de outro tipo de arquivo.';
    }
    $ext = strtolower(pathinfo($nome, PATHINFO_EXTENSION));
    if (str_starts_with($conteudo, '%PDF') && preg_match('#/(JavaScript|JS|Launch|EmbeddedFile|RichMedia|XFA)\b#', substr($conteudo, 0, 8 * 1024 * 1024))) {
        return 'PDF com conteúdo ativo (JavaScript, arquivo embutido ou ação automática).';
    }
    if (preg_match('/<\?php[\s\/]/i', substr($conteudo, 0, 8 * 1024 * 1024))) {
        return 'tem código escondido.';
    }
    // Zip (inclusive .docx/.xlsx/.pptx, que são zip por dentro).
    if (str_starts_with($conteudo, "PK\x03\x04") && class_exists('ZipArchive')) {
        $tmp = tempnam(sys_get_temp_dir(), 'zip');
        file_put_contents($tmp, $conteudo);
        $zip = new ZipArchive();
        $motivo = null;
        if ($zip->open($tmp) === true) {
            for ($i = 0; $i < $zip->numFiles && !$motivo; $i++) {
                $interno = strtolower((string) $zip->getNameIndex($i));
                if (str_contains($interno, 'vbaproject') || str_contains($interno, 'activex')) {
                    $motivo = 'documento com macro.';
                } elseif (in_array(pathinfo($interno, PATHINFO_EXTENSION), $perigosas, true) && !str_ends_with($interno, '.xml')) {
                    $motivo = 'compactado com programa ou script dentro (' . basename($interno) . ').';
                }
            }
            $zip->close();
        } elseif ($ext === 'zip') {
            $motivo = 'compactado protegido ou corrompido (não dá para conferir o que tem dentro).';
        }
        @unlink($tmp);
        if ($motivo) {
            return $motivo;
        }
    }
    // Compactados que não dá para abrir e conferir.
    if (in_array($ext, ['rar', '7z', 'tar', 'gz', 'tgz', 'bz2', 'xz', 'cab', 'arj', 'ace'], true)) {
        return 'compactado .' . $ext . ' não pode ser conferido (peça para mandarem em .zip ou os arquivos soltos).';
    }
    // Office antigo (.doc/.xls/.ppt) com macro.
    if (str_starts_with($conteudo, "\xd0\xcf\x11\xe0") && preg_match('/_VBA_PROJECT|VBA[\x00]?Project/i', substr($conteudo, 0, 8 * 1024 * 1024))) {
        return 'documento com macro.';
    }
    // O antivírus é mais lento: na hora de baixar/enviar, não para só listar.
    return $comAntivirus ? verificarAntivirus($conteudo) : null;
}

// ---------------------------------------------------------------------
// Antivírus (ClamAV)
// ---------------------------------------------------------------------

/** Programa do ClamAV no servidor: clamdscan (rápido, usa o serviço) ou clamscan. */
function programaAntivirus(): ?string
{
    foreach (['/usr/bin/clamdscan', '/usr/local/bin/clamdscan', '/opt/homebrew/bin/clamdscan', '/usr/bin/clamscan', '/usr/local/bin/clamscan', '/opt/homebrew/bin/clamscan'] as $programa) {
        if (@is_executable($programa)) {
            return $programa;
        }
    }
    return null;
}

/** Situação do antivírus para mostrar nas configurações. */
function situacaoAntivirus(): array
{
    $programa = programaAntivirus();
    if (!$programa) {
        return ['ativo' => false, 'texto' => 'Não instalado no servidor — os arquivos passam só pelas regras de tipo (veja docs/antivirus.md).'];
    }
    exec(escapeshellarg($programa) . ' --version 2>&1', $saida, $codigo);
    return ['ativo' => $codigo === 0, 'texto' => $codigo === 0 ? 'Ativo: ' . trim($saida[0] ?? 'ClamAV') : 'Instalado, mas não respondeu: ' . trim(implode(' ', $saida))];
}

/**
 * Passa o conteúdo no antivírus. Retorna o motivo do bloqueio ou null (limpo,
 * ou antivírus ausente / com problema — aí valem só as outras barreiras).
 */
function verificarAntivirus(string $conteudo): ?string
{
    $programa = programaAntivirus();
    if (!$programa) {
        return null;
    }
    $tmp = tempnam(sys_get_temp_dir(), 'av');
    file_put_contents($tmp, $conteudo);
    @chmod($tmp, 0644); // o serviço do ClamAV (outro usuário) precisa ler
    $codigo = verificarAntivirusArquivo($tmp, $programa);
    @unlink($tmp);
    return $codigo;
}

function verificarAntivirusArquivo(string $caminho, ?string $programa = null): ?string
{
    $programa ??= programaAntivirus();
    if (!$programa) {
        return null;
    }
    $extra = str_contains($programa, 'clamdscan') ? ' --fdpass' : '';
    exec(escapeshellarg($programa) . $extra . ' --no-summary ' . escapeshellarg($caminho) . ' 2>&1', $saida, $codigo);
    // 0 = limpo, 1 = vírus, 2 = erro do antivírus.
    if ($codigo === 1) {
        $nomeVirus = preg_match('/:\s*(.+?)\s+FOUND/', implode("\n", $saida), $m) ? ' (' . $m[1] . ')' : '';
        return 'o antivírus encontrou uma ameaça' . $nomeVirus . '.';
    }
    if ($codigo === 2) {
        error_log('[Antivírus] erro ao verificar: ' . implode(' ', $saida));
    }
    return null;
}
