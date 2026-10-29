<?php

/**
 * Manda um e-mail de aviso pra quem administra o sistema quando algo falha
 * de um jeito que não devia (erro fatal, exceção não tratada) — não serve
 * pra validação de negócio já mostrada na tela pro usuário (estoque
 * insuficiente, campo obrigatório etc.), só pra falha de verdade.
 *
 * Nunca lança exceção: se o SMTP não estiver configurado ou o envio falhar,
 * só grava no log do servidor e segue — notificar erro não pode virar um
 * novo erro.
 */
function notificarErroSistema(string $mensagem, string $contexto = ''): void
{
    try {
        $config = buscarConfiguracoes(getDB());

        $destino = trim((string) ($config['email_notificacao_erros'] ?? ''));
        if ($destino === '') {
            $destino = trim((string) ($config['smtp_remetente_email'] ?? ''));
        }
        if ($destino === '') {
            return;
        }

        $nomeSistema = $config['nome_sistema'] ?? 'Sistema';
        $corpo = '<p><strong>Erro no sistema ' . e($nomeSistema) . '</strong></p>'
            . '<p><strong>Quando:</strong> ' . e(date('d/m/Y H:i:s')) . '</p>'
            . ($contexto !== '' ? '<p><strong>Onde:</strong> ' . e($contexto) . '</p>' : '')
            . '<pre style="white-space:pre-wrap;font-family:monospace;font-size:13px;">' . e($mensagem) . '</pre>';

        enviarEmail($config, $destino, '', 'Erro no sistema — ' . $nomeSistema, $corpo);
    } catch (Throwable $e) {
        error_log('Falha ao notificar erro por e-mail: ' . $e->getMessage());
    }
}

// Cliente SMTP mínimo, sem dependências externas (o projeto não usa Composer).
// Suporta autenticação (AUTH LOGIN) e conexão via STARTTLS (porta 587,
// comum no Gmail/Outlook/SendGrid) ou SSL implícito (porta 465).
// Retorna ['sucesso' => bool, 'mensagem' => string] — nunca lança exceção,
// para o chamador poder decidir o que mostrar ao usuário.
function enviarEmail(array $configSmtp, string $paraEmail, string $paraNome, string $assunto, string $corpoHtml): array
{
    $host = trim((string) ($configSmtp['smtp_host'] ?? ''));
    $porta = (int) ($configSmtp['smtp_porta'] ?? 587);
    $seguranca = $configSmtp['smtp_seguranca'] ?? 'tls';
    $usuario = (string) ($configSmtp['smtp_usuario'] ?? '');
    $senha = (string) ($configSmtp['smtp_senha'] ?? '');
    // Remetente em branco (campo vazio na tela) = o próprio usuário do SMTP.
    $remetenteEmail = trim((string) ($configSmtp['smtp_remetente_email'] ?? '')) ?: trim($usuario);
    $remetenteNome = trim((string) ($configSmtp['smtp_remetente_nome'] ?? ''));

    if ($host === '' || $usuario === '' || $senha === '' || $remetenteEmail === '') {
        return ['sucesso' => false, 'mensagem' => 'O envio de e-mail não está configurado (Configurações → E-mail).'];
    }

    $transporte = $seguranca === 'ssl' ? 'ssl://' . $host : $host;

    $socket = @stream_socket_client(
        $transporte . ':' . $porta,
        $codigoErro,
        $mensagemErro,
        10,
        STREAM_CLIENT_CONNECT
    );

    if (!$socket) {
        return ['sucesso' => false, 'mensagem' => "Não foi possível conectar ao servidor SMTP: {$mensagemErro}"];
    }

    stream_set_timeout($socket, 10);

    $ler = function () use ($socket): string {
        $resposta = '';
        while (($linha = fgets($socket, 515)) !== false) {
            $resposta .= $linha;
            if (isset($linha[3]) && $linha[3] === ' ') {
                break;
            }
        }
        return $resposta;
    };

    $enviar = function (string $comando) use ($socket): void {
        fwrite($socket, $comando . "\r\n");
    };

    $codigoResposta = fn (string $resposta): int => (int) substr(trim($resposta), 0, 3);

    try {
        $resposta = $ler();
        if ($codigoResposta($resposta) !== 220) {
            throw new RuntimeException("Servidor SMTP não respondeu corretamente: {$resposta}");
        }

        $enviar('EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'));
        $ler();

        if ($seguranca === 'tls') {
            $enviar('STARTTLS');
            $resposta = $ler();
            if ($codigoResposta($resposta) !== 220) {
                throw new RuntimeException("Falha ao iniciar STARTTLS: {$resposta}");
            }
            if (!@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('Falha ao habilitar TLS na conexão SMTP.');
            }
            $enviar('EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'));
            $ler();
        }

        $enviar('AUTH LOGIN');
        $ler();
        $enviar(base64_encode($usuario));
        $ler();
        $enviar(base64_encode($senha));
        $resposta = $ler();
        if ($codigoResposta($resposta) !== 235) {
            throw new RuntimeException('Usuário/senha do SMTP recusados pelo servidor.');
        }

        $enviar('MAIL FROM:<' . $remetenteEmail . '>');
        $resposta = $ler();
        if ($codigoResposta($resposta) !== 250) {
            throw new RuntimeException("Remetente recusado: {$resposta}");
        }

        $enviar('RCPT TO:<' . $paraEmail . '>');
        $resposta = $ler();
        if ($codigoResposta($resposta) !== 250 && $codigoResposta($resposta) !== 251) {
            throw new RuntimeException("Destinatário recusado: {$resposta}");
        }

        $enviar('DATA');
        $resposta = $ler();
        if ($codigoResposta($resposta) !== 354) {
            throw new RuntimeException("Servidor não aceitou o corpo do e-mail: {$resposta}");
        }

        $nomeRemetenteCodificado = $remetenteNome !== ''
            ? '=?UTF-8?B?' . base64_encode($remetenteNome) . '?= '
            : '';
        $nomeParaCodificado = $paraNome !== ''
            ? '=?UTF-8?B?' . base64_encode($paraNome) . '?= '
            : '';
        $assuntoCodificado = '=?UTF-8?B?' . base64_encode($assunto) . '?=';

        // Data, Message-ID e versão em texto junto com a HTML: sem eles os
        // filtros anti-spam (ex.: rspamd do provedor) pontuam a mensagem como
        // suspeita e ela volta como "classified as rSPAM".
        $dominio = substr(strrchr($remetenteEmail, '@') ?: '@localhost', 1);
        $fronteira = 'b' . bin2hex(random_bytes(12));
        $cabecalhos = [
            'Date: ' . date('r'),
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $dominio . '>',
            'From: ' . $nomeRemetenteCodificado . '<' . $remetenteEmail . '>',
            'To: ' . $nomeParaCodificado . '<' . $paraEmail . '>',
            'Subject: ' . $assuntoCodificado,
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $fronteira . '"',
        ];
        $texto = html_entity_decode(strip_tags(preg_replace(['~<br\s*/?>~i', '~</(p|div|h[1-6]|li|tr|pre)>~i'], ["\n", "\n\n"], $corpoHtml)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $texto = trim(preg_replace("/\n{3,}/", "\n\n", $texto));
        $html = '<!doctype html><html><head><meta charset="utf-8"></head><body>' . $corpoHtml . '</body></html>';
        // Base64 em linhas de 76: sem linha longa e sem precisar de dot-stuffing.
        $corpo = '--' . $fronteira . "\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($texto), 76, "\r\n")
            . '--' . $fronteira . "\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($html), 76, "\r\n")
            . '--' . $fronteira . '--';

        $enviar(implode("\r\n", $cabecalhos) . "\r\n\r\n" . $corpo . "\r\n.");
        $resposta = $ler();
        if ($codigoResposta($resposta) !== 250) {
            throw new RuntimeException("Servidor recusou o envio: {$resposta}");
        }

        $enviar('QUIT');
        fclose($socket);

        return ['sucesso' => true, 'mensagem' => 'E-mail enviado.'];
    } catch (RuntimeException $e) {
        if (is_resource($socket)) {
            fclose($socket);
        }
        return ['sucesso' => false, 'mensagem' => $e->getMessage()];
    }
}

/**
 * Envia uma mensagem já montada (EmailMime::montar) pelo SMTP: vários
 * destinatários (Para, Cc e Cco), anexos, qualquer tamanho.
 * $servidor: host, porta, seguranca (tls|ssl|none), usuario, senha,
 * verificar (certificado). Retorna ['sucesso' => bool, 'mensagem' => string].
 */
function enviarMensagemSmtp(array $servidor, string $remetente, array $destinatarios, string $mensagem): array
{
    $host = trim((string) ($servidor['host'] ?? ''));
    $porta = (int) ($servidor['porta'] ?? 587);
    $seguranca = $servidor['seguranca'] ?? 'tls';
    $verificar = (bool) ($servidor['verificar'] ?? true);
    if ($host === '' || !$destinatarios) {
        return ['sucesso' => false, 'mensagem' => $host === '' ? 'O servidor de envio (SMTP) não está configurado (Configurações › E-mail).' : 'Informe pelo menos um destinatário.'];
    }
    $contexto = stream_context_create(['ssl' => ['verify_peer' => $verificar, 'verify_peer_name' => $verificar, 'allow_self_signed' => !$verificar, 'peer_name' => $host]]);
    $socket = @stream_socket_client(($seguranca === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $porta, $codigoErro, $mensagemErro, 15, STREAM_CLIENT_CONNECT, $contexto);
    if (!$socket) {
        return ['sucesso' => false, 'mensagem' => 'Não foi possível conectar ao servidor de envio (' . $host . ':' . $porta . '): ' . ($mensagemErro ?: 'sem resposta') . '.'];
    }
    stream_set_timeout($socket, 30);
    $ler = function () use ($socket): string {
        $resposta = '';
        while (($linha = fgets($socket, 1024)) !== false) {
            $resposta .= $linha;
            if (!isset($linha[3]) || $linha[3] === ' ') {
                break;
            }
        }
        return $resposta;
    };
    $enviar = fn (string $dados) => fwrite($socket, $dados . "\r\n");
    $codigo = fn (string $resposta): int => (int) substr(trim($resposta), 0, 3);
    $ehlo = 'EHLO ' . (preg_replace('/[^A-Za-z0-9.-]/', '', (string) ($_SERVER['SERVER_NAME'] ?? '')) ?: 'localhost');

    try {
        if ($codigo($ler()) !== 220) {
            throw new RuntimeException('O servidor de envio não respondeu corretamente.');
        }
        $enviar($ehlo);
        $recursos = $ler();
        if ($seguranca === 'tls') {
            $enviar('STARTTLS');
            if ($codigo($ler()) !== 220 || !@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('Não foi possível ativar a criptografia (STARTTLS) com o servidor de envio.');
            }
            $enviar($ehlo);
            $recursos = $ler();
        }
        if (($servidor['usuario'] ?? '') !== '' && stripos($recursos, 'AUTH') !== false) {
            if (preg_match('/AUTH[ =][^\r\n]*\bPLAIN\b/i', $recursos)) {
                $enviar('AUTH PLAIN ' . base64_encode("\0" . $servidor['usuario'] . "\0" . $servidor['senha']));
            } else {
                $enviar('AUTH LOGIN');
                $ler();
                $enviar(base64_encode($servidor['usuario']));
                $ler();
                $enviar(base64_encode((string) $servidor['senha']));
            }
            if ($codigo($ler()) !== 235) {
                throw new RuntimeException('E-mail ou senha recusados pelo servidor de envio.');
            }
        }
        $enviar('MAIL FROM:<' . $remetente . '>');
        $resposta = $ler();
        if ($codigo($resposta) !== 250) {
            throw new RuntimeException('Remetente recusado pelo servidor: ' . trim($resposta));
        }
        $aceitos = 0;
        $recusados = [];
        foreach (array_unique(array_map('strtolower', $destinatarios)) as $email) {
            $enviar('RCPT TO:<' . $email . '>');
            $resposta = $ler();
            if (in_array($codigo($resposta), [250, 251], true)) {
                $aceitos++;
            } else {
                $recusados[] = $email;
            }
        }
        if (!$aceitos) {
            throw new RuntimeException('O servidor recusou o(s) destinatário(s): ' . implode(', ', $recusados) . '.');
        }
        $enviar('DATA');
        if ($codigo($ler()) !== 354) {
            throw new RuntimeException('O servidor não aceitou a mensagem.');
        }
        // Linha começando com "." ganha outro "." (o servidor tira).
        $corpo = preg_replace('/^\./m', '..', str_replace(["\r\n", "\r"], ["\n", "\n"], $mensagem));
        fwrite($socket, str_replace("\n", "\r\n", $corpo) . "\r\n.\r\n");
        $resposta = $ler();
        if ($codigo($resposta) !== 250) {
            throw new RuntimeException('O servidor recusou a mensagem: ' . trim($resposta));
        }
        $enviar('QUIT');
        fclose($socket);
        return ['sucesso' => true, 'mensagem' => $recusados ? 'Enviado, mas o servidor recusou: ' . implode(', ', $recusados) . '.' : 'E-mail enviado.'];
    } catch (RuntimeException $e) {
        if (is_resource($socket)) {
            fclose($socket);
        }
        return ['sucesso' => false, 'mensagem' => $e->getMessage()];
    }
}
