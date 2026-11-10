<?php

// ---------------------------------------------------------------------
// Álbum de fotos da turma. A equipe (editar turma) e os professores da
// turma (materiais) enviam; os alunos com acesso ao conteúdo da turma
// (ativa, pendente, concluída) veem e baixam pelo portal. Uma foto pode
// virar a capa da turma: uma cópia reduzida vai para uploads/portal e
// entra em turmas.portal_imagem (site, Meus cursos e a tela da turma).
// O original fica onde ficam os materiais (servidor ou FTP) e conta no
// mesmo limite de espaço; a miniatura fica sempre no servidor.
// ---------------------------------------------------------------------

const ALBUM_PASTA_MINIATURAS = '/storage/album/miniaturas';

function podeAlbumTurma(PDO $pdo, int $turmaId): bool
{
    return usuarioPodeEditarTurma() || podeMaterialTurma($pdo, $turmaId);
}

function fotosDaTurma(PDO $pdo, int $turmaId, bool $soVisiveis = false): array
{
    $stmt = $pdo->prepare('SELECT * FROM turma_fotos WHERE turma_id = ?' . ($soVisiveis ? ' AND visivel = 1' : '') . ' ORDER BY criado_em, id');
    $stmt->execute([$turmaId]);
    return $stmt->fetchAll();
}

/** Foto que é a capa atual da turma (o arquivo da capa leva o código da foto). */
function fotoEhCapa(array $foto, ?string $capa): bool
{
    return $capa && str_contains($capa, '/turma-capa-' . $foto['codigo'] . '-');
}

/** Confere uma foto enviada: só JPG, PNG e WEBP, com as mesmas checagens dos materiais. */
function validarFotoAlbum(string $caminho, string $nome): array
{
    $ext = strtolower(pathinfo($nome, PATHINFO_EXTENSION));
    if (in_array($ext, ['heic', 'heif'], true)) {
        return ['ok' => false, 'tipo' => null, 'mensagem' => '"' . $nome . '": foto do iPhone em HEIC. No iPhone, em Ajustes › Câmera › Formatos, escolha "Mais compatível", ou envie pelo navegador (ele já converte para JPG).'];
    }
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
        return ['ok' => false, 'tipo' => null, 'mensagem' => '"' . $nome . '": o álbum aceita só fotos JPG, PNG ou WEBP.'];
    }
    return validarArquivoMaterial($caminho, $nome);
}

/** Abre a imagem já virada do jeito certo (fotos de celular guardam o giro no EXIF). */
function abrirImagemAlbum(string $caminho, string $mime): ?GdImage
{
    if ((int) ini_get('memory_limit') > 0 && (int) ini_get('memory_limit') < 512) {
        ini_set('memory_limit', '512M');
    }
    $img = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($caminho),
        'image/png' => @imagecreatefrompng($caminho),
        'image/webp' => @imagecreatefromwebp($caminho),
        default => false,
    };
    if (!$img) {
        return null;
    }
    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $giro = match ((int) (@exif_read_data($caminho)['Orientation'] ?? 1)) {
            3 => 180, 6 => 270, 8 => 90, default => 0,
        };
        if ($giro && ($virada = imagerotate($img, $giro, 0))) {
            $img = $virada;
        }
    }
    return $img;
}

/** Grava uma versão JPEG com o maior lado até $lado. Retorna [largura, altura] da original ou null. */
function reduzirImagemAlbum(string $origem, string $mime, string $destino, int $lado, int $qualidade = 82): ?array
{
    $img = abrirImagemAlbum($origem, $mime);
    if (!$img) {
        return null;
    }
    [$w, $h] = [imagesx($img), imagesy($img)];
    $escala = min(1, $lado / max($w, $h));
    $nova = imagecreatetruecolor(max(1, (int) round($w * $escala)), max(1, (int) round($h * $escala)));
    imagefill($nova, 0, 0, imagecolorallocate($nova, 255, 255, 255)); // PNG transparente fica com fundo branco
    imagecopyresampled($nova, $img, 0, 0, 0, 0, imagesx($nova), imagesy($nova), $w, $h);
    if (!is_dir(dirname($destino))) {
        @mkdir(dirname($destino), 0750, true);
    }
    $ok = imagejpeg($nova, $destino, $qualidade);
    return $ok ? [$w, $h] : null;
}

/** Arquivo original num caminho local (do FTP, baixa para um temporário). ['caminho', 'temporario']. */
function arquivoLocalFotoAlbum(PDO $pdo, array $foto): ?array
{
    if ($foto['armazenamento'] !== 'ftp') {
        $arquivo = BASE_PATH . MATERIAIS_PASTA_LOCAL . '/' . str_replace('..', '', (string) $foto['caminho']);
        return is_file($arquivo) ? ['caminho' => $arquivo, 'temporario' => false] : null;
    }
    $tmp = tempnam(sys_get_temp_dir(), 'foto');
    $fp = fopen($tmp, 'wb');
    $ch = curlFtp(ftpMateriais($pdo), (string) $foto['caminho']);
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_exec($ch);
    $erro = curl_errno($ch);
    curl_close($ch);
    fclose($fp);
    if ($erro || !filesize($tmp)) {
        @unlink($tmp);
        return null;
    }
    return ['caminho' => $tmp, 'temporario' => true];
}

/** Guarda uma foto enviada (original + miniatura). Retorna ['ok', 'mensagem', 'id']. */
function guardarFotoAlbum(PDO $pdo, int $turmaId, string $tmp, string $nome, int $tamanho, string $mime, bool $visivel): array
{
    $codigo = bin2hex(random_bytes(16));
    $miniatura = date('Y/m') . '/' . $codigo . '.jpg';
    $medidas = reduzirImagemAlbum($tmp, $mime, BASE_PATH . ALBUM_PASTA_MINIATURAS . '/' . $miniatura, 600, 78);
    if (!$medidas) {
        return ['ok' => false, 'mensagem' => '"' . $nome . '": não foi possível abrir a foto.'];
    }
    $g = guardarArquivoMaterial($pdo, $tmp, $nome, 'album-' . $codigo);
    if (!$g['ok']) {
        @unlink(BASE_PATH . ALBUM_PASTA_MINIATURAS . '/' . $miniatura);
        return ['ok' => false, 'mensagem' => $g['mensagem']];
    }
    $pdo->prepare('INSERT INTO turma_fotos (turma_id, arquivo_nome, arquivo_tipo, tamanho, largura, altura, armazenamento, caminho, miniatura, codigo, visivel, usuario_id, usuario_nome)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$turmaId, $nome, $mime, $tamanho, $medidas[0], $medidas[1], $g['armazenamento'], $g['caminho'], $miniatura, $codigo, (int) $visivel, usuarioAtualId(), usuarioAtualNome()]);
    return ['ok' => true, 'mensagem' => '', 'id' => (int) $pdo->lastInsertId()];
}

/** Manda a foto para o navegador: 'mini' (miniatura) ou 'foto' (original). */
function enviarFotoAlbum(PDO $pdo, array $foto, string $tamanho = 'foto', bool $baixar = false): void
{
    session_write_close();
    if ($tamanho === 'mini' && $foto['miniatura']) {
        $arquivo = BASE_PATH . ALBUM_PASTA_MINIATURAS . '/' . str_replace('..', '', (string) $foto['miniatura']);
        if (is_file($arquivo)) {
            header('Content-Type: image/jpeg');
            header('Content-Length: ' . filesize($arquivo));
            header('Cache-Control: private, max-age=86400');
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            readfile($arquivo);
            exit;
        }
    }
    enviarArquivoMaterial($pdo, ['arquivo_nome' => $foto['arquivo_nome'], 'arquivo_tipo' => $foto['arquivo_tipo'], 'armazenamento' => $foto['armazenamento'],
        'caminho' => $foto['caminho'], 'tamanho' => $foto['tamanho']], $baixar);
}

function apagarFotoAlbum(PDO $pdo, array $foto): void
{
    apagarArquivoMaterial($pdo, ['tipo' => 'arquivo', 'armazenamento' => $foto['armazenamento'], 'caminho' => $foto['caminho']]);
    if ($foto['miniatura']) {
        @unlink(BASE_PATH . ALBUM_PASTA_MINIATURAS . '/' . str_replace('..', '', (string) $foto['miniatura']));
    }
}

/**
 * Põe a foto como capa da turma: grava uma cópia de até 1600 px em
 * uploads/portal e troca turmas.portal_imagem (a capa antiga é apagada se
 * nenhuma outra turma usa o mesmo arquivo).
 */
function usarFotoComoCapa(PDO $pdo, array $foto): array
{
    $local = arquivoLocalFotoAlbum($pdo, $foto);
    if (!$local) {
        return ['ok' => false, 'mensagem' => 'Não foi possível abrir a foto original agora. Tente de novo.'];
    }
    if ($problema = problemaPastaUpload(pastaUploadsPortal())) {
        return ['ok' => false, 'mensagem' => 'Não foi possível gravar a capa. ' . $problema];
    }
    $nome = 'turma-capa-' . $foto['codigo'] . '-' . bin2hex(random_bytes(3)) . '.jpg';
    $ok = reduzirImagemAlbum($local['caminho'], $foto['arquivo_tipo'], pastaUploadsPortal() . '/' . $nome, 1600, 85);
    if ($local['temporario']) {
        @unlink($local['caminho']);
    }
    if (!$ok) {
        return ['ok' => false, 'mensagem' => 'Não foi possível preparar a capa.'];
    }
    $stmt = $pdo->prepare('SELECT portal_imagem FROM turmas WHERE id = ?');
    $stmt->execute([$foto['turma_id']]);
    $antiga = $stmt->fetchColumn() ?: null;
    $pdo->prepare('UPDATE turmas SET portal_imagem = ? WHERE id = ?')->execute(['/uploads/portal/' . $nome, $foto['turma_id']]);
    if ($antiga) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM turmas WHERE portal_imagem = ?');
        $stmt->execute([$antiga]);
        if (!(int) $stmt->fetchColumn()) {
            apagarImagemPortal($antiga);
        }
    }
    return ['ok' => true, 'mensagem' => ''];
}

/** Banners (carrosséis) do site do portal, para escolher onde pôr uma foto. */
function bannersPortal(PDO $pdo): array
{
    return $pdo->query("SELECT b.id, b.titulo, b.ativo, p.titulo AS pagina, (SELECT COUNT(*) FROM portal_banner_imagens i WHERE i.bloco_id = b.id) AS imagens
                        FROM portal_blocos b JOIN portal_paginas p ON p.id = b.pagina_id
                        WHERE b.tipo = 'banner' ORDER BY FIELD(p.tipo, 'inicio', 'personalizada', 'sistema'), p.titulo, b.ordem")->fetchAll();
}

/** Banner novo no fim da página inicial (quando ainda não há nenhum). */
function criarBannerPortal(PDO $pdo): int
{
    $inicio = buscarPaginaPortal($pdo, 'chave', 'inicio');
    $ordem = ordemParaInserirBloco($pdo, (int) $inicio['id'], 'topo', 0);
    $pdo->prepare("INSERT INTO portal_blocos (pagina_id, regiao, tipo, titulo, largura, altura, publico, ativo, ordem) VALUES (?, 'topo', 'banner', NULL, 'inteira', 'media', 'todos', 1, ?)")
        ->execute([$inicio['id'], $ordem]);
    return (int) $pdo->lastInsertId();
}

/**
 * Copia uma foto do álbum para um banner do portal (entra por último no
 * carrossel). A cópia é própria do banner: apagar a foto do álbum não
 * tira a imagem do site, e vice-versa. Link opcional ao clicar.
 */
function fotoParaBanner(PDO $pdo, array $foto, int $blocoId, ?string $link): array
{
    $local = arquivoLocalFotoAlbum($pdo, $foto);
    if (!$local) {
        return ['ok' => false, 'mensagem' => 'Não foi possível abrir a foto original agora. Tente de novo.'];
    }
    if ($problema = problemaPastaUpload(pastaUploadsPortal())) {
        return ['ok' => false, 'mensagem' => 'Não foi possível gravar a imagem. ' . $problema];
    }
    $nome = 'banner-album-' . $foto['codigo'] . '-' . bin2hex(random_bytes(3)) . '.jpg';
    $ok = reduzirImagemAlbum($local['caminho'], $foto['arquivo_tipo'], pastaUploadsPortal() . '/' . $nome, 1800, 85);
    if ($local['temporario']) {
        @unlink($local['caminho']);
    }
    if (!$ok) {
        return ['ok' => false, 'mensagem' => 'Não foi possível preparar a imagem do banner.'];
    }
    $stmt = $pdo->prepare('SELECT COALESCE(MAX(ordem), 0) + 10 FROM portal_banner_imagens WHERE bloco_id = ?');
    $stmt->execute([$blocoId]);
    $pdo->prepare('INSERT INTO portal_banner_imagens (bloco_id, imagem, link, nova_aba, texto_alternativo, ordem) VALUES (?, ?, ?, 0, ?, ?)')
        ->execute([$blocoId, '/uploads/portal/' . $nome, $link ?: null, mb_substr((string) ($foto['legenda'] ?: ''), 0, 150) ?: null, (int) $stmt->fetchColumn()]);
    return ['ok' => true, 'mensagem' => ''];
}

/** Baixa várias fotos num .zip (nomes numerados na ordem do álbum). */
function enviarZipAlbum(PDO $pdo, array $fotos, string $nomeZip): void
{
    session_write_close();
    set_time_limit(0);
    $zipCaminho = tempnam(sys_get_temp_dir(), 'album');
    $zip = new ZipArchive();
    if (!$fotos || $zip->open($zipCaminho, ZipArchive::OVERWRITE) !== true) {
        http_response_code(404);
        exit('Nenhuma foto para baixar.');
    }
    $temporarios = [];
    foreach (array_values($fotos) as $i => $foto) {
        $local = arquivoLocalFotoAlbum($pdo, $foto);
        if (!$local) {
            continue;
        }
        if ($local['temporario']) {
            $temporarios[] = $local['caminho'];
        }
        $nome = sprintf('%03d - %s', $i + 1, preg_replace('/[\x00-\x1f\/\\\\:*?"<>|]+/', '_', (string) $foto['arquivo_nome']));
        $zip->addFile($local['caminho'], $nome);
        // Foto já é comprimida: guardar sem recomprimir é bem mais rápido.
        $zip->setCompressionName($nome, ZipArchive::CM_STORE);
    }
    $zip->close();
    foreach ($temporarios as $t) {
        @unlink($t);
    }
    $nomeZip = trim(preg_replace('/[\x00-\x1f\/\\\\:*?"<>|]+/', '_', $nomeZip)) . '.zip';
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^\x20-\x7e]|"/', '_', $nomeZip) . "\"; filename*=UTF-8''" . rawurlencode($nomeZip));
    header('Content-Length: ' . filesize($zipCaminho));
    header('Cache-Control: private, max-age=0');
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    readfile($zipCaminho);
    @unlink($zipCaminho);
    exit;
}
