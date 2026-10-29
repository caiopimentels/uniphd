<?php

// ---------------------------------------------------------------------
// Cliente IMAP (receber e-mails) escrito em PHP puro: o servidor não
// precisa da extensão imap do PHP (que saiu do PHP 8.4). Fala o básico do
// IMAP4rev1: login, pastas, busca, cabeçalhos, mensagem inteira, marcar
// como lida, mover, excluir e gravar uma cópia em Enviados (APPEND).
// Erro de rede ou do servidor vira RuntimeException com texto para o usuário.
// ---------------------------------------------------------------------

final class ImapCliente
{
    /** @var resource */
    private $conexao;
    private int $tag = 0;
    private array $capacidades = [];
    private ?string $pastaAberta = null;

    private function __construct($conexao)
    {
        $this->conexao = $conexao;
    }

    /**
     * Conecta (ssl = porta 993, tls = STARTTLS na 143, none = sem
     * criptografia) e faz o login.
     */
    public static function abrir(string $host, int $porta, string $seguranca, string $usuario, string $senha, bool $verificarCertificado = true, int $tempo = 20): self
    {
        $contexto = stream_context_create(['ssl' => [
            'verify_peer' => $verificarCertificado,
            'verify_peer_name' => $verificarCertificado,
            'allow_self_signed' => !$verificarCertificado,
            'SNI_enabled' => true,
            'peer_name' => $host,
        ]]);
        $endereco = ($seguranca === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $porta;
        $conexao = @stream_socket_client($endereco, $codigo, $mensagem, $tempo, STREAM_CLIENT_CONNECT, $contexto);
        if (!$conexao) {
            throw new RuntimeException('Não foi possível conectar ao servidor de e-mail (' . $host . ':' . $porta . '): ' . ($mensagem ?: 'sem resposta') . '.');
        }
        stream_set_timeout($conexao, $tempo);
        $cliente = new self($conexao);
        $saudacao = $cliente->lerLinha();
        if (!str_starts_with($saudacao, '* OK') && !str_starts_with($saudacao, '* PREAUTH')) {
            throw new RuntimeException('O servidor de e-mail não respondeu como IMAP.');
        }
        if ($seguranca === 'tls') {
            $cliente->comando('STARTTLS');
            if (!@stream_socket_enable_crypto($conexao, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('Não foi possível ativar a criptografia (STARTTLS) com o servidor de e-mail.');
            }
        }
        try {
            $cliente->comando('LOGIN ' . self::texto($usuario) . ' ' . self::texto($senha));
        } catch (RuntimeException $e) {
            throw new RuntimeException('E-mail ou senha recusados pelo servidor de e-mail.');
        }
        $cliente->carregarCapacidades();
        return $cliente;
    }

    public function fechar(): void
    {
        if (is_resource($this->conexao)) {
            try {
                $this->comando('LOGOUT');
            } catch (Throwable $e) {
            }
            fclose($this->conexao);
        }
    }

    public function __destruct()
    {
        if (is_resource($this->conexao)) {
            @fclose($this->conexao);
        }
    }

    public function tem(string $capacidade): bool
    {
        return in_array(strtoupper($capacidade), $this->capacidades, true);
    }

    // ------------------------------------------------------------------
    // Pastas
    // ------------------------------------------------------------------

    /**
     * Pastas da caixa: [['nome' => nome no servidor, 'rotulo' => legível,
     * 'especial' => inbox|enviados|lixeira|rascunhos|spam|arquivo|null,
     * 'selecionavel' => bool]].
     */
    public function pastas(): array
    {
        $pastas = [];
        foreach ($this->comando('LIST "" "*"') as $item) {
            if (($item[0] ?? '') !== 'LIST') {
                continue;
            }
            $atributos = array_map('strtolower', (array) ($item[1] ?? []));
            $delimitador = is_string($item[2] ?? null) ? $item[2] : '/';
            $nome = (string) ($item[3] ?? '');
            if ($nome === '') {
                continue;
            }
            $especial = self::tipoPasta($nome, $atributos);
            $pastas[] = [
                'nome' => $nome,
                'delimitador' => $delimitador,
                // As especiais com o nome de sempre, em português (o servidor pode chamar de "Sent", "INBOX.Trash"...).
                'rotulo' => ['enviados' => 'Enviados', 'lixeira' => 'Lixeira', 'rascunhos' => 'Rascunhos', 'spam' => 'Spam', 'arquivo' => 'Arquivo'][$especial] ?? self::nomeLegivel($nome, $delimitador),
                'especial' => $especial,
                // Marcada pelo próprio servidor (\Junk, \Sent...), não só pelo nome.
                'pelo_servidor' => (bool) array_intersect(['\\sent', '\\trash', '\\drafts', '\\junk', '\\archive'], $atributos),
                'selecionavel' => !in_array('\\noselect', $atributos, true) && !in_array('\\nonexistent', $atributos, true),
            ];
        }
        return $pastas;
    }

    // Nome da pasta para mostrar: sem o "INBOX." da frente, UTF-7 decodificado.
    public static function nomeLegivel(string $nome, string $delimitador = '.'): string
    {
        if (strtoupper($nome) === 'INBOX') {
            return 'Caixa de entrada';
        }
        $legivel = @mb_convert_encoding($nome, 'UTF-8', 'UTF7-IMAP') ?: $nome;
        if ($delimitador !== '' && stripos($legivel, 'INBOX' . $delimitador) === 0) {
            $legivel = substr($legivel, 6);
        }
        return str_replace($delimitador ?: '/', ' / ', $legivel);
    }

    private static function tipoPasta(string $nome, array $atributos): ?string
    {
        if (strtoupper($nome) === 'INBOX') {
            return 'inbox';
        }
        foreach (['\\sent' => 'enviados', '\\trash' => 'lixeira', '\\drafts' => 'rascunhos', '\\junk' => 'spam', '\\archive' => 'arquivo'] as $flag => $tipo) {
            if (in_array($flag, $atributos, true)) {
                return $tipo;
            }
        }
        // Servidores sem SPECIAL-USE: pelo nome (inglês e português).
        $base = strtolower(preg_replace('/^inbox[.\/]/i', '', @mb_convert_encoding($nome, 'UTF-8', 'UTF7-IMAP') ?: $nome));
        return match (true) {
            in_array($base, ['sent', 'sent items', 'sent messages', 'enviados', 'itens enviados', 'mensagens enviadas'], true) => 'enviados',
            in_array($base, ['trash', 'deleted', 'deleted items', 'deleted messages', 'lixeira', 'lixo', 'itens excluídos'], true) => 'lixeira',
            in_array($base, ['drafts', 'rascunhos'], true) => 'rascunhos',
            in_array($base, ['junk', 'spam', 'junk e-mail', 'lixo eletrônico'], true) => 'spam',
            in_array($base, ['archive', 'arquivo', 'arquivados'], true) => 'arquivo',
            default => null,
        };
    }

    /** ['mensagens' => n, 'nao_lidas' => n] sem abrir a pasta. */
    public function status(string $pasta): array
    {
        foreach ($this->comando('STATUS ' . self::texto($pasta) . ' (MESSAGES UNSEEN)') as $item) {
            if (($item[0] ?? '') === 'STATUS' && is_array($item[2] ?? null)) {
                $valores = [];
                for ($i = 0; $i + 1 < count($item[2]); $i += 2) {
                    $valores[strtoupper((string) $item[2][$i])] = (int) $item[2][$i + 1];
                }
                return ['mensagens' => $valores['MESSAGES'] ?? 0, 'nao_lidas' => $valores['UNSEEN'] ?? 0];
            }
        }
        return ['mensagens' => 0, 'nao_lidas' => 0];
    }

    public function abrirPasta(string $pasta, bool $somenteLeitura = false): void
    {
        if ($this->pastaAberta === $pasta) {
            return;
        }
        $this->comando(($somenteLeitura ? 'EXAMINE ' : 'SELECT ') . self::texto($pasta));
        $this->pastaAberta = $pasta;
    }

    // ------------------------------------------------------------------
    // Mensagens (sempre por UID)
    // ------------------------------------------------------------------

    /**
     * UIDs da pasta aberta, do mais novo para o mais antigo. $busca procura
     * no texto todo (assunto, remetente e corpo).
     */
    public function uids(?string $busca = null, bool $soNaoLidas = false): array
    {
        $criterio = $soNaoLidas ? 'UNSEEN' : 'ALL';
        if ($busca !== null && trim($busca) !== '') {
            $busca = trim($busca);
            $literal = !preg_match('/^[\x20-\x7e]*$/', $busca);
            $resposta = $literal
                ? $this->comando('UID SEARCH CHARSET UTF-8 ' . ($soNaoLidas ? 'UNSEEN ' : '') . 'TEXT', [$busca])
                : $this->comando('UID SEARCH ' . ($soNaoLidas ? 'UNSEEN ' : '') . 'TEXT ' . self::texto($busca));
        } else {
            $resposta = $this->comando('UID SEARCH ' . $criterio);
        }
        $uids = [];
        foreach ($resposta as $item) {
            if (($item[0] ?? '') === 'SEARCH') {
                foreach (array_slice($item, 1) as $uid) {
                    if (ctype_digit((string) $uid)) {
                        $uids[] = (int) $uid;
                    }
                }
            }
        }
        rsort($uids);
        return $uids;
    }

    /**
     * Resumo das mensagens (lista): uid, de, para, assunto, data, lida,
     * respondida, importante, tamanho, tem_anexo.
     */
    public function resumos(array $uids): array
    {
        if (!$uids) {
            return [];
        }
        $resposta = $this->comando('UID FETCH ' . implode(',', $uids)
            . ' (UID FLAGS INTERNALDATE RFC822.SIZE BODY.PEEK[HEADER.FIELDS (FROM TO CC SUBJECT DATE CONTENT-TYPE)])');
        $porUid = [];
        foreach ($resposta as $item) {
            if (($item[1] ?? '') !== 'FETCH' || !is_array($item[2] ?? null)) {
                continue;
            }
            $dados = self::paresFetch($item[2]);
            $uid = (int) ($dados['UID'] ?? 0);
            $cabecalho = '';
            foreach ($dados as $chave => $valor) {
                if (str_starts_with($chave, 'BODY[HEADER')) {
                    $cabecalho = (string) $valor;
                }
            }
            $h = EmailMime::cabecalhos($cabecalho);
            $flags = array_map('strtolower', (array) ($dados['FLAGS'] ?? []));
            $tipo = strtolower($h['content-type'] ?? '');
            $data = strtotime((string) ($h['date'] ?? '')) ?: strtotime((string) ($dados['INTERNALDATE'] ?? '')) ?: time();
            $porUid[$uid] = [
                'uid' => $uid,
                'de' => EmailMime::enderecos($h['from'] ?? '')[0] ?? ['nome' => '', 'email' => ''],
                'para' => EmailMime::enderecos($h['to'] ?? ''),
                'assunto' => EmailMime::decodificarCabecalho($h['subject'] ?? ''),
                'data' => date('Y-m-d H:i:s', $data),
                'lida' => in_array('\\seen', $flags, true),
                'respondida' => in_array('\\answered', $flags, true),
                'importante' => in_array('\\flagged', $flags, true),
                'tamanho' => (int) ($dados['RFC822.SIZE'] ?? 0),
                'tem_anexo' => str_starts_with($tipo, 'multipart/mixed'),
            ];
        }
        // Na ordem pedida (mais novo primeiro).
        return array_values(array_filter(array_map(fn ($u) => $porUid[$u] ?? null, $uids)));
    }

    /** Mensagem inteira (texto bruto, RFC 822) sem marcar como lida. */
    public function mensagemBruta(int $uid): ?string
    {
        foreach ($this->comando('UID FETCH ' . $uid . ' (UID BODY.PEEK[])') as $item) {
            if (($item[1] ?? '') === 'FETCH' && is_array($item[2] ?? null)) {
                $dados = self::paresFetch($item[2]);
                if ((int) ($dados['UID'] ?? 0) === $uid && isset($dados['BODY[]'])) {
                    return (string) $dados['BODY[]'];
                }
            }
        }
        return null;
    }

    public function marcar(array $uids, string $flag, bool $ligar = true): void
    {
        if ($uids) {
            $this->comando('UID STORE ' . implode(',', array_map('intval', $uids)) . ($ligar ? ' +' : ' -') . 'FLAGS.SILENT (' . $flag . ')');
        }
    }

    public function mover(array $uids, string $destino): void
    {
        if (!$uids) {
            return;
        }
        $lista = implode(',', array_map('intval', $uids));
        if ($this->tem('MOVE')) {
            $this->comando('UID MOVE ' . $lista . ' ' . self::texto($destino));
            return;
        }
        $this->comando('UID COPY ' . $lista . ' ' . self::texto($destino));
        $this->apagarDeVez($uids);
    }

    // Some de vez (na lixeira, ou depois de copiar para outra pasta).
    public function apagarDeVez(array $uids): void
    {
        if (!$uids) {
            return;
        }
        $lista = implode(',', array_map('intval', $uids));
        $this->comando('UID STORE ' . $lista . ' +FLAGS.SILENT (\\Deleted)');
        $this->comando($this->tem('UIDPLUS') ? 'UID EXPUNGE ' . $lista : 'EXPUNGE');
    }

    // Grava a mensagem numa pasta (cópia do enviado em "Enviados").
    public function gravar(string $pasta, string $mensagem, string $flags = '\\Seen'): void
    {
        $this->comando('APPEND ' . self::texto($pasta) . ' (' . $flags . ')', [$mensagem]);
    }

    /** UID (na pasta aberta) da mensagem com este Message-ID, ou null. */
    public function uidPorMessageId(string $messageId): ?int
    {
        foreach ($this->comando('UID SEARCH HEADER Message-ID ' . self::texto($messageId)) as $item) {
            if (($item[0] ?? '') === 'SEARCH') {
                $uids = array_map('intval', array_filter(array_slice($item, 1), fn ($u) => ctype_digit((string) $u)));
                return $uids ? max($uids) : null;
            }
        }
        return null;
    }

    public function criarPasta(string $pasta): void
    {
        $this->comando('CREATE ' . self::texto($pasta));
    }

    // ------------------------------------------------------------------
    // Protocolo
    // ------------------------------------------------------------------

    /**
     * Manda um comando e devolve as respostas "*" já interpretadas (listas
     * de tokens). $literais vão como {n} no fim do comando, um depois do
     * outro (o servidor pede cada um com "+").
     */
    private function comando(string $comando, array $literais = []): array
    {
        $tag = 'A' . (++$this->tag);
        $partes = $literais ? [$comando . ' {' . strlen($literais[0]) . '}'] : [$comando];
        $this->escrever($tag . ' ' . $partes[0] . "\r\n");
        foreach ($literais as $i => $literal) {
            // Espera o "+ " do servidor antes de cada literal.
            while (true) {
                $linha = $this->lerLinha();
                if (str_starts_with($linha, '+')) {
                    break;
                }
                if (str_starts_with($linha, $tag . ' ')) {
                    throw new RuntimeException('O servidor de e-mail recusou: ' . trim(substr($linha, strlen($tag) + 1)));
                }
            }
            $proximo = $literais[$i + 1] ?? null;
            $this->escrever($literal . ($proximo !== null ? ' {' . strlen($proximo) . '}' : '') . "\r\n");
        }

        $respostas = [];
        while (true) {
            [$texto, $blocos] = $this->lerResposta();
            if (str_starts_with($texto, $tag . ' ')) {
                $resto = substr($texto, strlen($tag) + 1);
                if (stripos($resto, 'OK') !== 0) {
                    throw new RuntimeException('O servidor de e-mail recusou: ' . trim(preg_replace('/^(NO|BAD)\s*/i', '', $resto)));
                }
                return $respostas;
            }
            if (str_starts_with($texto, '* ')) {
                $pos = 2;
                $respostas[] = self::interpretar($texto, $pos, $blocos);
            }
        }
    }

    private function escrever(string $dados): void
    {
        $total = strlen($dados);
        $enviado = 0;
        while ($enviado < $total) {
            $n = @fwrite($this->conexao, substr($dados, $enviado, 65536));
            if ($n === false || $n === 0) {
                throw new RuntimeException('A conexão com o servidor de e-mail caiu.');
            }
            $enviado += $n;
        }
    }

    private function lerLinha(): string
    {
        $linha = fgets($this->conexao);
        if ($linha === false) {
            $meta = stream_get_meta_data($this->conexao);
            throw new RuntimeException($meta['timed_out'] ? 'O servidor de e-mail demorou demais para responder.' : 'A conexão com o servidor de e-mail caiu.');
        }
        return rtrim($linha, "\r\n");
    }

    private function lerBytes(int $quantos): string
    {
        $dados = '';
        while (strlen($dados) < $quantos) {
            $pedaco = fread($this->conexao, min(65536, $quantos - strlen($dados)));
            if ($pedaco === false || ($pedaco === '' && feof($this->conexao))) {
                throw new RuntimeException('A conexão com o servidor de e-mail caiu no meio da mensagem.');
            }
            $dados .= $pedaco;
        }
        return $dados;
    }

    /**
     * Uma resposta inteira: o texto (com cada literal {n} trocado por uma
     * marca \x00n\x00) e os literais lidos.
     */
    private function lerResposta(): array
    {
        $texto = '';
        $blocos = [];
        while (true) {
            $linha = $this->lerLinha();
            if (preg_match('/\{(\d+)\+?\}$/', $linha, $m)) {
                $texto .= substr($linha, 0, -strlen($m[0])) . "\x00" . count($blocos) . "\x00";
                $blocos[] = $this->lerBytes((int) $m[1]);
                continue;
            }
            return [$texto . $linha, $blocos];
        }
    }

    // Texto da resposta em tokens: listas () viram arrays; NIL vira null.
    private static function interpretar(string $texto, int &$pos, array $blocos, bool $dentro = false): array
    {
        $tokens = [];
        $tamanho = strlen($texto);
        while ($pos < $tamanho) {
            $c = $texto[$pos];
            if ($c === ' ') {
                $pos++;
            } elseif ($c === '(') {
                $pos++;
                $tokens[] = self::interpretar($texto, $pos, $blocos, true);
            } elseif ($c === ')') {
                $pos++;
                if ($dentro) {
                    return $tokens;
                }
            } elseif ($c === '"') {
                $pos++;
                $valor = '';
                while ($pos < $tamanho && $texto[$pos] !== '"') {
                    if ($texto[$pos] === '\\' && $pos + 1 < $tamanho) {
                        $pos++;
                    }
                    $valor .= $texto[$pos++];
                }
                $pos++;
                $tokens[] = $valor;
            } elseif ($c === "\x00") {
                $fim = strpos($texto, "\x00", $pos + 1);
                $tokens[] = $blocos[(int) substr($texto, $pos + 1, $fim - $pos - 1)] ?? '';
                $pos = $fim + 1;
            } else {
                // Átomo; "BODY[HEADER.FIELDS (A B)]" inteiro, com o que está entre colchetes.
                $inicio = $pos;
                $colchetes = 0;
                while ($pos < $tamanho) {
                    $ch = $texto[$pos];
                    if ($ch === '[') {
                        $colchetes++;
                    } elseif ($ch === ']') {
                        $colchetes--;
                    } elseif ($colchetes <= 0 && ($ch === ' ' || $ch === '(' || $ch === ')' || $ch === "\x00")) {
                        break;
                    }
                    $pos++;
                }
                $atomo = substr($texto, $inicio, $pos - $inicio);
                $tokens[] = strtoupper($atomo) === 'NIL' ? null : $atomo;
            }
        }
        return $tokens;
    }

    // (CHAVE valor CHAVE valor ...) do FETCH em array associativo.
    private static function paresFetch(array $lista): array
    {
        $pares = [];
        for ($i = 0; $i + 1 < count($lista); $i += 2) {
            $chave = strtoupper((string) $lista[$i]);
            // "BODY[]<0>" e afins: tira o <...>.
            $chave = preg_replace('/<\d+>$/', '', $chave);
            $pares[$chave] = $lista[$i + 1];
        }
        return $pares;
    }

    private function carregarCapacidades(): void
    {
        foreach ($this->comando('CAPABILITY') as $item) {
            if (($item[0] ?? '') === 'CAPABILITY') {
                $this->capacidades = array_map(fn ($c) => strtoupper((string) $c), array_slice($item, 1));
            }
        }
    }

    // Texto entre aspas (com \ e " escapados); quebra de linha não passa.
    private static function texto(string $valor): string
    {
        return '"' . str_replace(['\\', '"', "\r", "\n"], ['\\\\', '\\"', '', ''], $valor) . '"';
    }
}
