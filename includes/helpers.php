<?php

function ehRequisicaoAjax(): bool
{
    return strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
}

/**
 * Token de CSRF por sessão — gerado uma vez e reaproveitado. Todo POST do
 * sistema (formulário normal ou fetch) precisa mandar esse valor de volta,
 * senão é rejeitado (ver o bootstrap.php). Não depende de estar logado:
 * login e recuperação de senha também são formulários POST.
 */
function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function responderJson(array $dados): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($dados);
    exit;
}

function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

/**
 * Monta a URL de um arquivo dentro de public/ (assets, uploads) a partir da
 * pasta real de onde a página atual está sendo executada, comparando o
 * caminho de disco do script atual com o caminho de disco de public/. Evita
 * que imagens/CSS quebrem quando o servidor não está apontado direto pra
 * pasta public/ (ex: rodando "php -S" a partir da raiz do projeto).
 */
function publicUrl(string $caminho): string
{
    static $prefixo = null;
    if ($prefixo === null) {
        $prefixo = '';
        $scriptFile = realpath($_SERVER['SCRIPT_FILENAME'] ?? '') ?: '';
        $publicDir = realpath(BASE_PATH . '/public') ?: '';
        $scriptUrl = $_SERVER['SCRIPT_NAME'] ?? '';

        if ($scriptFile !== '' && $publicDir !== '' && str_starts_with($scriptFile, $publicDir . DIRECTORY_SEPARATOR)) {
            $relativo = str_replace(DIRECTORY_SEPARATOR, '/', substr($scriptFile, strlen($publicDir) + 1));
            if (str_ends_with($scriptUrl, '/' . $relativo)) {
                $prefixo = substr($scriptUrl, 0, -strlen('/' . $relativo));
            }
        }
    }
    return $prefixo . $caminho;
}

/**
 * publicUrl() com "?v=" baseado na data de modificação do arquivo — assim,
 * toda vez que um CSS/JS muda, o navegador busca a versão nova sozinho, sem
 * precisar de um hard refresh manual pra enxergar a alteração.
 */
function assetUrl(string $caminho): string
{
    $caminhoFs = BASE_PATH . '/public' . $caminho;
    $versao = is_file($caminhoFs) ? filemtime($caminhoFs) : time();
    return publicUrl($caminho) . '?v=' . $versao;
}

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function formatMoney($value): string
{
    return 'R$ ' . number_format((float) $value, 2, ',', '.');
}

function formatDate(?string $value): string
{
    if (!$value) {
        return '-';
    }
    return date('d/m/Y', strtotime($value));
}

function formatDateTime(?string $value): string
{
    if (!$value) {
        return '-';
    }
    return date('d/m/Y H:i', strtotime($value));
}

function flash(string $tipo, string $mensagem): void
{
    $_SESSION['flash'][] = ['tipo' => $tipo, 'mensagem' => $mensagem];
}

function getFlashes(): array
{
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}

function input(string $key, $default = ''): string
{
    return trim((string) ($_POST[$key] ?? $default));
}

function decimalInput(string $key, $default = 0): float
{
    $raw = $_POST[$key] ?? $default;
    $raw = str_replace('.', '', (string) $raw);
    $raw = str_replace(',', '.', $raw);
    return (float) $raw;
}

function buscarConfiguracoes(PDO $pdo): array
{
    static $config = null;
    if ($config === null) {
        $config = $pdo->query('SELECT * FROM configuracoes WHERE id = 1')->fetch();
    }
    return $config;
}

/**
 * Garante que a pasta de upload existe e o servidor (usuário do Apache/PHP)
 * consegue gravar nela. Retorna null se está tudo certo, ou a mensagem com
 * o comando para liberar a pasta no servidor.
 */
function problemaPastaUpload(string $pasta): ?string
{
    if (!is_dir($pasta)) {
        @mkdir($pasta, 0775, true);
    }
    if (is_dir($pasta) && is_writable($pasta)) {
        return null;
    }
    $usuario = function_exists('posix_getpwuid') && function_exists('posix_geteuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? 'www-data') : 'www-data';
    return 'O servidor não tem permissão para gravar em ' . $pasta . '. No servidor, rode: sudo mkdir -p ' . $pasta
        . ' && sudo chown -R ' . $usuario . ':' . $usuario . ' ' . dirname($pasta);
}

// Nome do sistema como está em Configurações (o APP_NAME do config.php é
// só o valor de reserva, se a configuração estiver vazia).
function nomeSistema(): string
{
    $nome = trim((string) (buscarConfiguracoes(getDB())['nome_sistema'] ?? ''));
    return $nome !== '' ? $nome : (defined('APP_NAME') ? (string) APP_NAME : 'Sistema');
}

// Endereço do ícone da aba (favicon.php): muda junto com a logo e o ajuste
// de posição/zoom, para o navegador não ficar com o ícone antigo.
function urlIconeAba(): ?string
{
    $config = buscarConfiguracoes(getDB());
    if (empty($config['logo_sistema'])) {
        return null;
    }
    return '/favicon.php?v=' . substr(md5($config['logo_sistema'] . '|' . $config['logo_pos_x'] . '|' . $config['logo_pos_y'] . '|' . $config['logo_zoom']), 0, 10);
}

function formatPercent($value): string
{
    return number_format((float) $value, 2, ',', '.') . '%';
}

function contaStatusBadgeClass(string $status): string
{
    return match ($status) {
        'pendente' => 'text-bg-warning',
        'vencido' => 'text-bg-danger', // pendente com vencimento passado (só na tela)
        'pago' => 'text-bg-success',
        'cancelado' => 'text-bg-secondary',
        default => 'text-bg-light',
    };
}

function contaStatusLabel(string $status): string
{
    return match ($status) {
        'pendente' => 'Pendente',
        'vencido' => 'Vencido',
        'pago' => 'Pago',
        'cancelado' => 'Cancelado',
        default => ucfirst($status),
    };
}

// Monta um link "wa.me" — abre o WhatsApp (app ou web) com a mensagem já
// escrita na caixa de texto; quem envia de fato é sempre a pessoa, clicando
// em enviar (o WhatsApp não permite disparo automático via link).
function linkWhatsapp(?string $numero, string $mensagem = ''): ?string
{
    $digitos = preg_replace('/\D/', '', $numero ?? '');
    if ($digitos === '') {
        return null;
    }
    $url = 'https://wa.me/' . $digitos;
    if ($mensagem !== '') {
        $url .= '?text=' . rawurlencode($mensagem);
    }
    return $url;
}

function apenasDigitos(string $valor): string
{
    return preg_replace('/\D/', '', $valor) ?? '';
}

function validarCpf(string $cpf): bool
{
    $cpf = preg_replace('/\D/', '', $cpf);
    if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) {
        return false;
    }

    for ($posicao = 9; $posicao <= 10; $posicao++) {
        $soma = 0;
        for ($i = 0; $i < $posicao; $i++) {
            $soma += ((int) $cpf[$i]) * (($posicao + 1) - $i);
        }
        $digito = ($soma * 10) % 11;
        if ($digito === 10) {
            $digito = 0;
        }
        if ($digito !== (int) $cpf[$posicao]) {
            return false;
        }
    }

    return true;
}

function validarCnpj(string $cnpj): bool
{
    $cnpj = preg_replace('/\D/', '', $cnpj);
    if (strlen($cnpj) !== 14 || preg_match('/^(\d)\1{13}$/', $cnpj)) {
        return false;
    }

    $calcularDigito = function (string $base, array $pesos) {
        $soma = 0;
        foreach ($pesos as $i => $peso) {
            $soma += ((int) $base[$i]) * $peso;
        }
        $resto = $soma % 11;
        return $resto < 2 ? 0 : 11 - $resto;
    };

    $digito1 = $calcularDigito(substr($cnpj, 0, 12), [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]);
    if ($digito1 !== (int) $cnpj[12]) {
        return false;
    }

    $digito2 = $calcularDigito(substr($cnpj, 0, 13), [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]);
    return $digito2 === (int) $cnpj[13];
}

// Aceita CPF (11 dígitos) ou CNPJ (14 dígitos) no mesmo campo "documento"
// usado no cadastro de fornecedor.
function validarCpfCnpj(string $documento): bool
{
    $limpo = preg_replace('/\D/', '', $documento);
    if (strlen($limpo) === 11) {
        return validarCpf($limpo);
    }
    if (strlen($limpo) === 14) {
        return validarCnpj($limpo);
    }
    return false;
}

function matriculaStatusLabel(string $status): string
{
    return match ($status) {
        'pendente' => 'Aguardando pagamento',
        'ativa' => 'Ativa',
        'cancelada' => 'Cancelada',
        'concluida' => 'Concluída',
        default => ucfirst($status),
    };
}

function matriculaStatusBadgeClass(string $status): string
{
    return match ($status) {
        'pendente' => 'text-bg-warning',
        'ativa' => 'text-bg-success',
        'cancelada' => 'text-bg-danger',
        'concluida' => 'text-bg-secondary',
        default => 'text-bg-light',
    };
}

// Soma N meses a uma data preservando o dia sempre que ele existir no mês
// de destino — "31/01 + 1 mês" vira 28/02 (ou 29), não 03/03 como faria o
// strtotime('+1 month'), que pularia o mês de fevereiro inteiro.
function somarMeses(string $data, int $meses): string
{
    $base = new DateTimeImmutable($data);
    $primeiroDoMes = $base->modify('first day of this month')->modify("+{$meses} months");
    $dia = min((int) $base->format('d'), (int) $primeiroDoMes->format('t'));
    return $primeiroDoMes->setDate((int) $primeiroDoMes->format('Y'), (int) $primeiroDoMes->format('m'), $dia)->format('Y-m-d');
}
