<?php

// ---------------------------------------------------------------------
// Mensagens de e-mail (MIME): ler o que chega — cabeçalhos com acento,
// remetentes, texto, HTML, anexos e imagens embutidas, em qualquer
// codificação — e montar o que sai (HTML + texto + anexos + imagens).
// ---------------------------------------------------------------------

final class EmailMime
{
    // ------------------------------------------------------------------
    // Ler
    // ------------------------------------------------------------------

    /** Cabeçalhos (texto bruto) em array: nome em minúsculas => valor (1º que aparecer). */
    public static function cabecalhos(string $bruto): array
    {
        $bruto = preg_replace("/\r?\n[ \t]+/", ' ', str_replace("\r\n", "\n", $bruto));
        $cabecalhos = [];
        foreach (explode("\n", $bruto) as $linha) {
            if (!str_contains($linha, ':')) {
                continue;
            }
            [$nome, $valor] = explode(':', $linha, 2);
            $nome = strtolower(trim($nome));
            if ($nome !== '' && !isset($cabecalhos[$nome])) {
                $cabecalhos[$nome] = trim($valor);
            }
        }
        return $cabecalhos;
    }

    // "=?UTF-8?B?...?=" / "=?iso-8859-1?Q?...?=" em texto UTF-8.
    public static function decodificarCabecalho(string $valor): string
    {
        if (!str_contains($valor, '=?')) {
            return self::paraUtf8($valor, 'UTF-8');
        }
        // Palavras codificadas seguidas: o espaço entre elas não conta.
        $valor = preg_replace('/(\?=)\s+(=\?)/', '$1$2', $valor);
        return preg_replace_callback('/=\?([^?]+)\?([BbQq])\?([^?]*)\?=/', function ($m) {
            $charset = preg_replace('/\*.*$/', '', $m[1]); // RFC 2231: charset*idioma
            $dados = strtoupper($m[2]) === 'B'
                ? base64_decode($m[3])
                : quoted_printable_decode(str_replace('_', ' ', $m[3]));
            return self::paraUtf8((string) $dados, $charset);
        }, $valor);
    }

    /**
     * Endereços de um cabeçalho (From, To, Cc...): [['nome' => , 'email' => ]].
     * Respeita vírgula dentro de aspas ("Silva, Ana" <ana@x.com>).
     */
    public static function enderecos(string $valor): array
    {
        // Divide antes de decodificar: um nome codificado pode ter vírgula.
        $partes = [];
        $atual = '';
        $aspas = false;
        $angulo = 0;
        for ($i = 0, $n = strlen($valor); $i < $n; $i++) {
            $c = $valor[$i];
            if ($c === '"' && ($i === 0 || $valor[$i - 1] !== '\\')) {
                $aspas = !$aspas;
            } elseif (!$aspas && $c === '<') {
                $angulo++;
            } elseif (!$aspas && $c === '>') {
                $angulo--;
            }
            if (($c === ',' || $c === ';') && !$aspas && $angulo <= 0) {
                $partes[] = $atual;
                $atual = '';
                continue;
            }
            $atual .= $c;
        }
        $partes[] = $atual;
        $enderecos = [];
        foreach ($partes as $parte) {
            $parte = trim($parte);
            if ($parte === '') {
                continue;
            }
            if (preg_match('/^(.*)<([^>]+)>\s*$/s', $parte, $m)) {
                $nome = self::decodificarCabecalho(trim(trim($m[1]), "\" \t"));
                $email = trim($m[2]);
            } else {
                $nome = '';
                $email = trim($parte, "<> \t");
            }
            if (str_contains($email, '@')) {
                $enderecos[] = ['nome' => stripslashes($nome), 'email' => strtolower($email)];
            }
        }
        return $enderecos;
    }

    /**
     * Mensagem inteira: cabeçalhos principais, texto, html e anexos
     * (['id' => '2', 'nome', 'tipo', 'tamanho', 'cid', 'inline']).
     */
    public static function analisar(string $bruto): array
    {
        [$cabecalhoBruto, $corpo] = self::separar($bruto);
        $h = self::cabecalhos($cabecalhoBruto);
        $mensagem = [
            'assunto' => self::decodificarCabecalho($h['subject'] ?? ''),
            'de' => self::enderecos($h['from'] ?? '')[0] ?? ['nome' => '', 'email' => ''],
            'para' => self::enderecos($h['to'] ?? ''),
            'cc' => self::enderecos($h['cc'] ?? ''),
            'cco' => self::enderecos($h['bcc'] ?? ''),
            'origem' => trim((string) ($h['x-sistema-origem'] ?? '')),
            'responder_para' => self::enderecos($h['reply-to'] ?? ''),
            'data' => ($t = strtotime((string) ($h['date'] ?? ''))) ? date('Y-m-d H:i:s', $t) : null,
            'message_id' => trim((string) ($h['message-id'] ?? '')),
            'referencias' => trim((string) ($h['references'] ?? '')),
            'texto' => null,
            'html' => null,
            'anexos' => [],
        ];
        self::percorrer($cabecalhoBruto, $corpo, '1', $mensagem, true);
        return $mensagem;
    }

    /** Conteúdo decodificado de uma parte (anexo / imagem): ['nome', 'tipo', 'conteudo'] ou null. */
    public static function parte(string $bruto, string $id): ?array
    {
        [$cabecalhoBruto, $corpo] = self::separar($bruto);
        $achado = null;
        $buscar = function (string $cab, string $corpoParte, string $atual) use (&$buscar, $id, &$achado) {
            $h = self::cabecalhos($cab);
            [$tipo, $params] = self::valorComParametros($h['content-type'] ?? 'text/plain');
            if (str_starts_with($tipo, 'multipart/') && !empty($params['boundary'])) {
                foreach (self::dividir($corpoParte, $params['boundary']) as $i => $sub) {
                    [$subCab, $subCorpo] = self::separar($sub);
                    $buscar($subCab, $subCorpo, $atual === '' ? (string) ($i + 1) : $atual . '.' . ($i + 1));
                    if ($achado) {
                        return;
                    }
                }
                return;
            }
            if ($atual === $id || ($atual === '' && $id === '1')) {
                $achado = ['nome' => self::nomeArquivo($h, $params) ?: 'anexo', 'tipo' => $tipo, 'conteudo' => self::decodificar($corpoParte, $h['content-transfer-encoding'] ?? '')];
            }
        };
        $buscar($cabecalhoBruto, $corpo, '');
        return $achado;
    }

    private static function percorrer(string $cab, string $corpo, string $id, array &$mensagem, bool $raiz = false, string $pai = ''): void
    {
        $h = self::cabecalhos($cab);
        [$tipo, $params] = self::valorComParametros($h['content-type'] ?? 'text/plain');
        if (str_starts_with($tipo, 'multipart/') && !empty($params['boundary'])) {
            foreach (self::dividir($corpo, $params['boundary']) as $i => $sub) {
                [$subCab, $subCorpo] = self::separar($sub);
                self::percorrer($subCab, $subCorpo, $raiz ? (string) ($i + 1) : $id . '.' . ($i + 1), $mensagem, false, $tipo);
            }
            return;
        }
        if ($tipo === 'message/rfc822') {
            // E-mail encaminhado como anexo: vira anexo .eml.
            $mensagem['anexos'][] = ['id' => $raiz ? '1' : $id, 'nome' => 'mensagem-encaminhada.eml', 'tipo' => $tipo, 'tamanho' => strlen($corpo), 'cid' => null, 'inline' => false];
            return;
        }
        [$disposicao] = self::valorComParametros($h['content-disposition'] ?? '');
        $nome = self::nomeArquivo($h, $params);
        $cid = trim((string) ($h['content-id'] ?? ''), "<> \t") ?: null;
        $ehTexto = in_array($tipo, ['text/plain', 'text/html'], true) && $disposicao !== 'attachment' && !$nome;
        if ($ehTexto) {
            $conteudo = self::paraUtf8(self::decodificar($corpo, $h['content-transfer-encoding'] ?? ''), $params['charset'] ?? 'UTF-8');
            $chave = $tipo === 'text/html' ? 'html' : 'texto';
            // Mais de um bloco de texto (ex.: assinatura em outra parte): junta.
            $mensagem[$chave] = $mensagem[$chave] === null ? $conteudo : $mensagem[$chave] . ($chave === 'html' ? '<br>' : "\n") . $conteudo;
            return;
        }
        $id = $raiz ? '1' : $id;
        $mensagem['anexos'][] = [
            'id' => $id,
            'nome' => $nome ?: ($cid ? 'imagem-' . preg_replace('/\W+/', '', $cid) : 'anexo-' . str_replace('.', '-', $id)) . self::extensaoPadrao($tipo),
            'tipo' => $tipo,
            'tamanho' => (int) (strlen($corpo) * (strtolower($h['content-transfer-encoding'] ?? '') === 'base64' ? 0.74 : 1)),
            'cid' => $cid,
            'inline' => $cid !== null && str_starts_with($tipo, 'image/') && $disposicao !== 'attachment',
        ];
    }

    private static function separar(string $bruto): array
    {
        $bruto = str_replace("\r\n", "\n", $bruto);
        $pos = strpos($bruto, "\n\n");
        if ($pos === false) {
            return [$bruto, ''];
        }
        return [substr($bruto, 0, $pos), substr($bruto, $pos + 2)];
    }

    private static function dividir(string $corpo, string $fronteira): array
    {
        $partes = preg_split('/^--' . preg_quote($fronteira, '/') . '(--)?[ \t]*$/m', $corpo);
        // Antes da 1ª fronteira (preâmbulo) e depois da última (epílogo) não contam.
        array_shift($partes);
        if (preg_match('/^--' . preg_quote($fronteira, '/') . '--/m', $corpo)) {
            array_pop($partes);
        }
        return array_map(fn ($p) => preg_replace('/^\n/', '', $p), $partes);
    }

    // "text/plain; charset=utf-8; name=..." => ['text/plain', ['charset' => 'utf-8', ...]].
    private static function valorComParametros(string $valor): array
    {
        $partes = preg_split('/;(?=(?:[^"]*"[^"]*")*[^"]*$)/', $valor);
        $principal = strtolower(trim(array_shift($partes)));
        $params = [];
        $continuacoes = [];
        foreach ($partes as $parte) {
            if (!str_contains($parte, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $parte, 2);
            $k = strtolower(trim($k));
            $v = trim(trim($v), '"');
            // RFC 2231: filename*0*=, filename*1*= ... e filename*=UTF-8''nome%20com%20acento
            if (preg_match('/^([^*]+)\*(\d+)?(\*)?$/', $k, $m)) {
                $continuacoes[$m[1]][(int) ($m[2] ?? 0)] = [$v, isset($m[3]) || !isset($m[2])];
                continue;
            }
            $params[$k] = $v;
        }
        foreach ($continuacoes as $k => $pedacos) {
            ksort($pedacos);
            $charset = 'UTF-8';
            $valorFinal = '';
            foreach ($pedacos as $n => [$v, $codificado]) {
                if ($codificado && $n === array_key_first($pedacos) && preg_match("/^([^']*)'[^']*'(.*)$/", $v, $m)) {
                    $charset = $m[1] ?: 'UTF-8';
                    $v = $m[2];
                }
                $valorFinal .= $codificado ? rawurldecode($v) : $v;
            }
            $params[$k] = self::paraUtf8($valorFinal, $charset);
        }
        return [$principal, $params];
    }

    private static function nomeArquivo(array $h, array $paramsTipo): ?string
    {
        [, $paramsDisp] = self::valorComParametros($h['content-disposition'] ?? '');
        $nome = $paramsDisp['filename'] ?? $paramsTipo['name'] ?? null;
        if ($nome === null || $nome === '') {
            return null;
        }
        $nome = self::decodificarCabecalho($nome);
        // Nome seguro para baixar: sem caminho nem caracteres de controle.
        return trim(preg_replace('/[\x00-\x1f\/\\\\]+/', '_', $nome)) ?: null;
    }

    private static function decodificar(string $corpo, string $codificacao): string
    {
        return match (strtolower(trim($codificacao))) {
            'base64' => (string) base64_decode(preg_replace('/\s+/', '', $corpo)),
            'quoted-printable' => quoted_printable_decode($corpo),
            default => $corpo,
        };
    }

    public static function paraUtf8(string $texto, string $charset): string
    {
        $charset = strtoupper(trim($charset, "\" \t")) ?: 'UTF-8';
        $charset = ['ASCII' => 'UTF-8', 'US-ASCII' => 'UTF-8', 'LATIN1' => 'ISO-8859-1', 'UTF8' => 'UTF-8', 'KS_C_5601-1987' => 'CP949'][$charset] ?? $charset;
        if ($charset === 'UTF-8') {
            return mb_check_encoding($texto, 'UTF-8') ? $texto : mb_convert_encoding($texto, 'UTF-8', 'Windows-1252');
        }
        $convertido = @iconv($charset, 'UTF-8//IGNORE', $texto);
        if ($convertido === false) {
            $convertido = @mb_convert_encoding($texto, 'UTF-8', $charset) ?: mb_convert_encoding($texto, 'UTF-8', 'Windows-1252');
        }
        return $convertido;
    }

    private static function extensaoPadrao(string $tipo): string
    {
        return ['image/png' => '.png', 'image/jpeg' => '.jpg', 'image/gif' => '.gif', 'image/webp' => '.webp', 'application/pdf' => '.pdf', 'text/calendar' => '.ics'][$tipo] ?? '';
    }

    // ------------------------------------------------------------------
    // Montar (enviar)
    // ------------------------------------------------------------------

    /**
     * Mensagem pronta para o SMTP e para gravar em Enviados.
     * $dados: de ['nome','email'], para/cc/cco (listas de ['nome','email']),
     * assunto, html, anexos [['nome','tipo','conteudo']], responde_a
     * (Message-ID), referencias. Imagens coladas no texto (data:) vão
     * embutidas (cid). Retorna ['bruto' => mensagem sem Bcc, 'message_id'].
     * Com 'rascunho' => true o Bcc fica no cabeçalho (é só para guardar) e
     * 'extras' acrescenta cabeçalhos X-... (ex.: de qual e-mail é a resposta).
     */
    public static function montar(array $dados): array
    {
        $dominio = substr(strrchr($dados['de']['email'], '@') ?: '@localhost', 1);
        $messageId = '<' . bin2hex(random_bytes(16)) . '@' . $dominio . '>';
        $html = (string) $dados['html'];

        // data:image/...;base64 => parte embutida com Content-ID.
        $embutidas = [];
        $html = preg_replace_callback('#src="data:(image/(?:png|jpe?g|gif|webp));base64,([A-Za-z0-9+/=\s]+)"#i', function ($m) use (&$embutidas, $dominio) {
            $cid = 'img' . count($embutidas) . '.' . bin2hex(random_bytes(6)) . '@' . $dominio;
            $embutidas[] = ['cid' => $cid, 'tipo' => strtolower($m[1]), 'conteudo' => base64_decode(preg_replace('/\s+/', '', $m[2]))];
            return 'src="cid:' . $cid . '"';
        }, $html);

        $documento = '<!doctype html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>';
        $alternativa = self::partesAlternativas(self::htmlParaTexto($html), $documento);
        $corpo = $alternativa;
        if ($embutidas) {
            $f = self::fronteira();
            $corpo = ['Content-Type: multipart/related; boundary="' . $f . '"', self::juntar($f, [$alternativa, ...array_map(fn ($img) => [
                'Content-Type: ' . $img['tipo'] . "\r\nContent-Transfer-Encoding: base64\r\nContent-ID: <" . $img['cid'] . ">\r\nContent-Disposition: inline",
                chunk_split(base64_encode($img['conteudo']), 76, "\r\n"),
            ], $embutidas)])];
        }
        if (!empty($dados['anexos'])) {
            $f = self::fronteira();
            $corpo = ['Content-Type: multipart/mixed; boundary="' . $f . '"', self::juntar($f, [$corpo, ...array_map(fn ($a) => [
                'Content-Type: ' . ($a['tipo'] ?: 'application/octet-stream') . '; name="' . self::cabecalhoCodificado($a['nome'], true) . "\"\r\n"
                    . "Content-Transfer-Encoding: base64\r\n"
                    . 'Content-Disposition: attachment; filename="' . self::cabecalhoCodificado($a['nome'], true) . '"',
                chunk_split(base64_encode($a['conteudo']), 76, "\r\n"),
            ], $dados['anexos'])])];
        }

        $cabecalhos = [
            'Date: ' . date('r'),
            'Message-ID: ' . $messageId,
            'From: ' . self::enderecoFormatado($dados['de']),
        ];
        if (!empty($dados['para'])) {
            $cabecalhos[] = 'To: ' . implode(",\r\n ", array_map([self::class, 'enderecoFormatado'], $dados['para']));
        }
        if (!empty($dados['cc'])) {
            $cabecalhos[] = 'Cc: ' . implode(",\r\n ", array_map([self::class, 'enderecoFormatado'], $dados['cc']));
        }
        if (!empty($dados['rascunho']) && !empty($dados['cco'])) {
            $cabecalhos[] = 'Bcc: ' . implode(",\r\n ", array_map([self::class, 'enderecoFormatado'], $dados['cco']));
        }
        foreach ($dados['extras'] ?? [] as $nome => $valor) {
            if (preg_match('/^X-[A-Za-z0-9-]+$/', $nome)) {
                $cabecalhos[] = $nome . ': ' . preg_replace('/[\r\n]+/', ' ', (string) $valor);
            }
        }
        if (!empty($dados['responder_para'])) {
            $cabecalhos[] = 'Reply-To: ' . self::enderecoFormatado($dados['responder_para']);
        }
        $cabecalhos[] = 'Subject: ' . self::cabecalhoCodificado((string) $dados['assunto']);
        if (!empty($dados['responde_a'])) {
            $cabecalhos[] = 'In-Reply-To: ' . $dados['responde_a'];
            $cabecalhos[] = 'References: ' . trim(($dados['referencias'] ?? '') . ' ' . $dados['responde_a']);
        }
        $cabecalhos[] = 'MIME-Version: 1.0';
        $cabecalhos[] = $corpo[0];
        return ['bruto' => implode("\r\n", $cabecalhos) . "\r\n\r\n" . $corpo[1], 'message_id' => $messageId];
    }

    private static function partesAlternativas(string $texto, string $html): array
    {
        $f = self::fronteira();
        return ['Content-Type: multipart/alternative; boundary="' . $f . '"', self::juntar($f, [
            ["Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64", chunk_split(base64_encode($texto), 76, "\r\n")],
            ["Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64", chunk_split(base64_encode($html), 76, "\r\n")],
        ])];
    }

    // [cabeçalho, corpo] de cada parte => corpo multipart com a fronteira.
    private static function juntar(string $fronteira, array $partes): string
    {
        $saida = '';
        foreach ($partes as [$cabecalho, $corpo]) {
            $saida .= '--' . $fronteira . "\r\n" . $cabecalho . "\r\n\r\n" . $corpo . (str_ends_with($corpo, "\r\n") ? '' : "\r\n");
        }
        return $saida . '--' . $fronteira . "--\r\n";
    }

    private static function fronteira(): string
    {
        return '=_' . bin2hex(random_bytes(12));
    }

    public static function cabecalhoCodificado(string $texto, bool $parametro = false): string
    {
        if (preg_match('/^[\x20-\x7e]*$/', $texto) && !($parametro && str_contains($texto, '"'))) {
            return $texto;
        }
        return '=?UTF-8?B?' . base64_encode($texto) . '?=';
    }

    public static function enderecoFormatado(array $endereco): string
    {
        $nome = trim((string) ($endereco['nome'] ?? ''));
        if ($nome === '') {
            return '<' . $endereco['email'] . '>';
        }
        $nome = preg_match('/^[\x20-\x7e]*$/', $nome) ? '"' . addcslashes($nome, '"\\') . '"' : self::cabecalhoCodificado($nome);
        return $nome . ' <' . $endereco['email'] . '>';
    }

    // Versão em texto do HTML (vai junto: filtros anti-spam pedem).
    public static function htmlParaTexto(string $html): string
    {
        $html = preg_replace('#<(script|style|head)[^>]*>.*?</\1>#is', '', $html);
        $html = preg_replace('#<a\s[^>]*href="(https?://[^"]+)"[^>]*>(.*?)</a>#is', '$2 ($1)', $html);
        $texto = strip_tags(preg_replace(['~<br\s*/?>~i', '~</(p|div|h[1-6]|li|tr|blockquote)>~i', '~<li[^>]*>~i'], ["\n", "\n\n", '• '], $html));
        $texto = html_entity_decode($texto, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace(["/[ \t]+\n/", "/\n{3,}/"], ["\n", "\n\n"], $texto));
    }
}
