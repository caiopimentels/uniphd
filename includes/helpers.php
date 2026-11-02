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

// Nome de pessoa padronizado: só a primeira letra de cada palavra maiúscula,
// e de/da/do/das/dos/e minúsculos no meio ("MARIA DA SILVA" e "maria da
// silva" viram "Maria da Silva"). Espaços repetidos saem.
function formatarNomePessoa(string $nome): string
{
    $nome = trim(preg_replace('/\s+/u', ' ', $nome));
    if ($nome === '') {
        return '';
    }
    $palavras = explode(' ', mb_strtolower($nome, 'UTF-8'));
    foreach ($palavras as $i => $palavra) {
        if ($i === 0 || !in_array($palavra, ['de', 'da', 'do', 'dos', 'das', 'e', "d'"], true)) {
            $palavras[$i] = mb_convert_case($palavra, MB_CASE_TITLE, 'UTF-8');
        }
    }
    // D'ávila → D'Ávila
    return preg_replace_callback("/'(\\p{Ll})/u", fn ($m) => "'" . mb_strtoupper($m[1], 'UTF-8'), implode(' ', $palavras));
}

function contaStatusBadgeClass(string $status): string
{
    return match ($status) {
        'pendente' => 'text-bg-warning',
        'vencido' => 'text-bg-danger', // pendente com vencimento passado (só na tela)
        'pago' => 'text-bg-success',
        'cancelado' => 'text-bg-secondary',
        'suspenso' => 'text-bg-info', // cancelada pelo trancamento; volta ao destrancar (só na tela)
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
        'suspenso' => 'Suspensa',
        default => ucfirst($status),
    };
}

/**
 * Situação da parcela a receber para mostrar: 'suspenso' quando parada pelo
 * trancamento da matrícula e, com $marcarVencida, 'vencido' quando passou
 * do vencimento sem pagar.
 */
function statusTelaParcela(array $c, bool $marcarVencida = true): string
{
    if ($c['status'] === 'cancelado' && !empty($c['suspensa_trancamento'])) {
        return 'suspenso';
    }
    return $marcarVencida && $c['status'] === 'pendente' && vencimentoEmAtraso($c['data_vencimento']) ? 'vencido' : $c['status'];
}

/**
 * Feriados nacionais do ano (data => nome): os fixos e os que dependem da
 * Páscoa (Carnaval, Sexta-feira Santa, Corpus Christi).
 */
function feriadosNacionais(int $ano): array
{
    // Páscoa (algoritmo de Meeus/Butcher; não depende da extensão calendar do PHP).
    $a = $ano % 19; $b = intdiv($ano, 100); $c = $ano % 100;
    $h = (19 * $a + $b - intdiv($b, 4) - intdiv($b - intdiv($b + 8, 25) + 1, 3) + 15) % 30;
    $l = (32 + 2 * ($b % 4) + 2 * intdiv($c, 4) - $h - $c % 4) % 7;
    $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $mes = intdiv($h + $l - 7 * $m + 114, 31);
    $pascoa = new DateTimeImmutable(sprintf('%04d-%02d-%02d', $ano, $mes, ($h + $l - 7 * $m + 114) % 31 + 1));
    $dias = [];
    foreach (['01-01' => 'Confraternização Universal', '04-21' => 'Tiradentes', '05-01' => 'Dia do Trabalho', '09-07' => 'Independência',
              '10-12' => 'Nossa Senhora Aparecida', '11-02' => 'Finados', '11-15' => 'Proclamação da República', '11-20' => 'Consciência Negra',
              '12-25' => 'Natal'] as $md => $nome) {
        $dias["{$ano}-{$md}"] = $nome;
    }
    foreach ([-48 => 'Carnaval (segunda)', -47 => 'Carnaval (terça)', -2 => 'Sexta-feira Santa', 60 => 'Corpus Christi'] as $desloc => $nome) {
        $dias[$pascoa->modify(($desloc >= 0 ? '+' : '') . $desloc . ' days')->format('Y-m-d')] = $nome;
    }
    ksort($dias);
    return $dias;
}

/**
 * Dias sem expediente bancário no ano: os nacionais e os da cidade, de
 * Configurações › Cadastros auxiliares › Feriados.
 */
function feriadosBancarios(int $ano): array
{
    static $cache = [];
    if (isset($cache[$ano])) {
        return $cache[$ano];
    }
    $dias = array_fill_keys(array_keys(feriadosNacionais($ano)), true);
    // Feriados da cidade (Cadastros auxiliares › Feriados).
    try {
        $stmt = getDB()->prepare('SELECT data, todo_ano FROM feriados WHERE todo_ano = 1 OR YEAR(data) = ?');
        $stmt->execute([$ano]);
        foreach ($stmt->fetchAll() as $f) {
            $md = substr($f['data'], 5);
            if (!$f['todo_ano'] || checkdate((int) substr($md, 0, 2), (int) substr($md, 3), $ano)) {
                $dias[$f['todo_ano'] ? "{$ano}-{$md}" : $f['data']] = true;
            }
        }
    } catch (Throwable $e) {
        // Tabela ainda não criada (atualização do banco pendente): só os nacionais.
    }
    return $cache[$ano] = $dias;
}

function diaUtilBancario(string $data): bool
{
    return (int) date('N', strtotime($data)) < 6 && !isset(feriadosBancarios((int) substr($data, 0, 4))[$data]);
}

/** O próprio dia, se for útil; senão o próximo dia útil. */
function proximoDiaUtil(string $data): string
{
    for ($i = 0; $i < 15 && !diaUtilBancario($data); $i++) {
        $data = date('Y-m-d', strtotime($data . ' +1 day'));
    }
    return $data;
}

/**
 * Vencimentos ANTES desta data estão em atraso hoje. Quem venceu em fim de
 * semana ou feriado pode pagar no próximo dia útil: venceu no sábado e
 * segunda é feriado → só fica em atraso na quarta.
 */
function dataCorteAtraso(?string $hoje = null): string
{
    static $cache = [];
    $hoje ??= date('Y-m-d');
    if (!isset($cache[$hoje])) {
        $corte = $hoje;
        for ($i = 0; $i < 15; $i++) {
            $anterior = date('Y-m-d', strtotime($corte . ' -1 day'));
            if (proximoDiaUtil($anterior) < $hoje) {
                break;
            }
            $corte = $anterior;
        }
        $cache[$hoje] = $corte;
    }
    return $cache[$hoje];
}

function vencimentoEmAtraso(?string $vencimento): bool
{
    return $vencimento !== null && $vencimento !== '' && $vencimento < dataCorteAtraso();
}

/** A data de corte pronta para SQL: "data_vencimento < " . sqlCorteAtraso(). */
function sqlCorteAtraso(): string
{
    return "'" . dataCorteAtraso() . "'";
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

// CPF e telefone ficam no banco só com números; na tela, sempre com máscara.
function cpfFormatado(?string $cpf): string
{
    $d = preg_replace('/\D/', '', (string) $cpf);
    return strlen($d) === 11 ? substr($d, 0, 3) . '.' . substr($d, 3, 3) . '.' . substr($d, 6, 3) . '-' . substr($d, 9) : (string) $cpf;
}

// CPF do aluno na área da equipe: só os 4 primeiros dígitos (800.3**.***-**).
// Quem tem a permissão ver_cpf_completo vê o olhinho que busca o número em
// /alunos/ver_cpf.php (e fica registrado). Documentos oficiais (contrato,
// certificado, comprovante, recibo) e o boleto continuam com o CPF inteiro.
function cpfMascarado(?string $cpf): string
{
    $d = preg_replace('/\D/', '', (string) $cpf);
    if ($d === '') {
        return '';
    }
    return strlen($d) === 11 ? substr($d, 0, 3) . '.' . $d[3] . '**.***-**' : str_repeat('*', strlen($d));
}

/** HTML: CPF mascarado e, para quem pode, o olhinho que mostra o completo. */
function cpfTela(?string $cpf, ?int $alunoId): string
{
    if (preg_replace('/\D/', '', (string) $cpf) === '') {
        return '';
    }
    $html = '<span class="cpf-mascarado">' . e(cpfMascarado($cpf)) . '</span>';
    if ($alunoId && usuarioVeCpfCompleto()) {
        $html .= ' <button type="button" class="btn btn-link btn-sm p-0 align-baseline ver-cpf" data-aluno="' . (int) $alunoId . '" title="Mostrar o CPF completo (fica registrado)"><i class="bi bi-eye"></i></button>';
    }
    return '<span class="cpf-campo text-nowrap">' . $html . '</span>';
}

/** Texto (impressões e planilhas): completo só para quem tem a permissão. */
function cpfTexto(?string $cpf): string
{
    return usuarioVeCpfCompleto() ? cpfFormatado($cpf) : cpfMascarado($cpf);
}

/** O que vai para as buscas feitas no navegador: sem a permissão, só os 4 primeiros dígitos. */
function cpfBusca(?string $cpf): string
{
    $d = preg_replace('/\D/', '', (string) $cpf);
    return usuarioVeCpfCompleto() ? $d : substr($d, 0, 4);
}

// (77) 98100-4911 (celular) ou (77) 3421-1234 (fixo); outro tamanho sai como veio.
function telefoneFormatado(?string $telefone): string
{
    $d = preg_replace('/\D/', '', (string) $telefone);
    return match (strlen($d)) {
        11 => '(' . substr($d, 0, 2) . ') ' . substr($d, 2, 5) . '-' . substr($d, 7),
        10 => '(' . substr($d, 0, 2) . ') ' . substr($d, 2, 4) . '-' . substr($d, 6),
        default => (string) $telefone,
    };
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
        'trancada' => 'Trancada',
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
        'trancada' => 'text-bg-info',
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
