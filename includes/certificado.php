<?php

// Documentos emitidos (certificado, declaração de módulo): modelos montados
// em Acadêmico › Documentos, deitados ou em pé. Cada curso diz quais emite e
// com que modelo (ver documentos_emitidos.php); sem modelo, vale o padrão.
// Um modelo tem uma ou mais folhas (frente, verso...), cada uma com a sua
// arte de fundo e os seus elementos:
//   texto   -> com marcadores ({aluno}, {curso}...) trocados pelos dados
//   imagem  -> assinatura, selo, logo (arquivo em uploads/portal)
//   qrcode  -> leva ao link de verificação do próprio certificado
//   modulos -> os módulos cadastrados no curso (conteúdo programático)
// Posição (x, y = centro do elemento), largura e altura em % da folha A4. Altura 0 = automática (cresce com o conteúdo); com altura
// definida, o texto que não cabe tem a letra reduzida até caber.
//
// Guardado em certificado_modelos.layout como {"paginas": [{"fundo": ...,
// "elementos": [...]}]}. Modelos antigos (lista de elementos + coluna
// fundo) são lidos como uma folha só.
//
// Verificação: cada certificado emitido ganha um código único guardado na
// tabela certificados (com a matrícula e a data). Quem recebe o
// certificado confere em <endereço de verificação>?codigo=XXXX-XXXX-XXXX.

const CERTIFICADO_MAX_FOLHAS = 4;

function marcadoresCertificado(): array
{
    return [
        '{aluno}' => 'Nome do aluno',
        '{cpf}' => 'CPF do aluno',
        '{filiacao}' => 'Nome da mãe e do pai',
        '{nascimento}' => 'Data de nascimento',
        '{ra}' => 'RA do aluno',
        '{documento}' => 'Nome do documento (ex.: Certificado de conclusão)',
        '{curso}' => 'Nome do curso',
        '{turma}' => 'Nome da turma',
        '{parceria}' => 'Parceria da turma (em branco se não tiver)',
        '{carga_horaria}' => 'Carga horária (horas)',
        '{data_inicio}' => 'Início da turma',
        '{data_termino}' => 'Término da turma (data de conclusão)',
        '{data_conclusao}' => 'Data de conclusão da turma',
        '{modulo}' => 'Módulo (documento de módulo)',
        '{modulo_carga_horaria}' => 'Carga horária do módulo',
        '{data_modulo}' => 'Data de conclusão do módulo',
        '{data_emissao}' => 'Data de emissão (por extenso)',
        '{data_evento}' => 'Data do evento, informada ao liberar (por extenso; ex.: dia da aula)',
        '{cidade}' => 'Cidade e estado da empresa (ex.: Candiba - BA)',
        '{empresa}' => 'Nome da instituição',
        '{codigo}' => 'Código de verificação',
        '{url_verificacao}' => 'Página de verificação (sem o código)',
        '{link_verificacao}' => 'Link completo, já com o código',
    ];
}

function tiposElementoCertificado(): array
{
    return ['texto' => 'Texto', 'imagem' => 'Imagem', 'qrcode' => 'QR Code de verificação', 'modulos' => 'Lista de módulos do curso'];
}

function alinhamentosCertificado(): array
{
    return ['left' => 'Esquerda', 'center' => 'Centro', 'right' => 'Direita', 'justify' => 'Justificado'];
}

function paginasPadraoCertificado(): array
{
    $texto = fn (string $t, float $y, float $largura, int $tamanho, string $cor, bool $negrito = false, array $extra = []) =>
        ['tipo' => 'texto', 'texto' => $t, 'x' => 50, 'y' => $y, 'largura' => $largura, 'altura' => 0, 'tamanho' => $tamanho, 'cor' => $cor,
         'negrito' => $negrito, 'alinhamento' => 'center', 'uma_linha' => false, 'maiusculo' => false] + $extra;
    return [[
        'fundo' => null,
        'elementos' => [
            $texto('CERTIFICADO', 20, 80, 44, '#071f4a', true),
            $texto('{aluno}', 38, 70, 32, '#071f4a', true, ['uma_linha' => true]),
            $texto('concluiu o curso {curso}, com carga horária de {carga_horaria} horas, realizado de {data_inicio} a {data_termino}.', 52, 72, 18, '#222222'),
            $texto('{cidade}, {data_emissao}.', 68, 60, 16, '#222222'),
            $texto('Código de verificação: {codigo} — confira em {url_verificacao}', 93, 70, 10, '#555555'),
            ['tipo' => 'qrcode', 'x' => 91, 'y' => 85, 'largura' => 9, 'altura' => 0],
        ],
    ]];
}

// Folha nova (verso): título e a lista de módulos já posicionados.
function folhaVersoPadraoCertificado(): array
{
    return [
        'fundo' => null,
        'elementos' => [
            ['tipo' => 'texto', 'texto' => 'CONTEÚDO PROGRAMÁTICO', 'x' => 50, 'y' => 12, 'largura' => 80, 'altura' => 0, 'tamanho' => 26,
             'cor' => '#071f4a', 'negrito' => true, 'alinhamento' => 'center', 'uma_linha' => true, 'maiusculo' => false],
            ['tipo' => 'modulos', 'x' => 50, 'y' => 52, 'largura' => 80, 'altura' => 64, 'tamanho' => 14, 'cor' => '#222222',
             'negrito' => false, 'alinhamento' => 'left', 'colunas' => 1, 'numerar' => true, 'mostrar_carga' => true],
        ],
    ];
}

/**
 * Folhas do modelo, já limpas. Aceita o formato atual ({paginas}) e o
 * antigo (lista de elementos + coluna fundo).
 */
function paginasModeloCertificado(?array $modelo): array
{
    $layout = json_decode((string) ($modelo['layout'] ?? ''), true);
    if (!is_array($layout) || !$layout) {
        $paginas = paginasPadraoCertificado();
        $paginas[0]['fundo'] = $modelo['fundo'] ?? null;
        return normalizarPaginasCertificado($paginas);
    }
    if (array_is_list($layout)) {
        return normalizarPaginasCertificado([['fundo' => $modelo['fundo'] ?? null, 'elementos' => $layout]]);
    }
    return normalizarPaginasCertificado((array) ($layout['paginas'] ?? []));
}

function imagemUploadValida($url): bool
{
    return is_string($url) && str_starts_with($url, '/uploads/portal/') && !str_contains($url, '..');
}

function normalizarPaginasCertificado(array $paginas): array
{
    $limpas = [];
    foreach (array_slice(array_values($paginas), 0, CERTIFICADO_MAX_FOLHAS) as $pagina) {
        if (!is_array($pagina)) {
            continue;
        }
        $limpas[] = [
            'fundo' => imagemUploadValida($pagina['fundo'] ?? null) ? $pagina['fundo'] : null,
            'elementos' => normalizarElementosCertificado((array) ($pagina['elementos'] ?? [])),
        ];
    }
    return $limpas ?: normalizarPaginasCertificado(paginasPadraoCertificado());
}

// Limpa o que veio do editor: só campos conhecidos, números dentro da folha.
// Elementos salvos antes dos tipos são todos "texto".
function normalizarElementosCertificado(array $elementos): array
{
    $numero = fn ($v, float $min, float $max, float $padrao) => max($min, min($max, round((float) ($v ?? $padrao), 2)));
    $cor = fn ($v) => preg_match('/^#[0-9a-fA-F]{6}$/', (string) $v) ? $v : '#000000';
    $limpo = [];
    foreach (array_slice($elementos, 0, 40) as $c) {
        if (!is_array($c)) {
            continue;
        }
        $tipo = array_key_exists($c['tipo'] ?? '', tiposElementoCertificado()) ? $c['tipo'] : 'texto';
        $base = [
            'tipo' => $tipo,
            'x' => $numero($c['x'] ?? null, 0, 100, 50),
            'y' => $numero($c['y'] ?? null, 0, 100, 50),
            'largura' => $numero($c['largura'] ?? null, 2, 100, 80),
            // 0 = automática. QR Code é sempre quadrado (a largura manda).
            'altura' => $tipo === 'qrcode' ? 0 : $numero($c['altura'] ?? 0, 0, 100, 0),
        ];
        if ($base['altura'] > 0 && $base['altura'] < 2) {
            $base['altura'] = 2;
        }
        $estiloTexto = [
            'tamanho' => (int) $numero($c['tamanho'] ?? null, 6, 120, 16),
            'cor' => $cor($c['cor'] ?? null),
            'negrito' => !empty($c['negrito']),
            'alinhamento' => array_key_exists($c['alinhamento'] ?? '', alinhamentosCertificado()) ? $c['alinhamento'] : ($tipo === 'modulos' ? 'left' : 'center'),
        ];
        if ($tipo === 'imagem') {
            if (imagemUploadValida($c['imagem'] ?? null)) {
                $limpo[] = $base + ['imagem' => $c['imagem']];
            }
        } elseif ($tipo === 'qrcode') {
            $limpo[] = $base;
        } elseif ($tipo === 'modulos') {
            $limpo[] = $base + $estiloTexto + [
                'colunas' => (int) $numero($c['colunas'] ?? 1, 1, 3, 1),
                'numerar' => !empty($c['numerar']),
                'mostrar_carga' => !empty($c['mostrar_carga']),
            ];
        } elseif (trim((string) ($c['texto'] ?? '')) !== '') {
            $limpo[] = $base + ['texto' => mb_substr((string) $c['texto'], 0, 2000)] + $estiloTexto + [
                'uma_linha' => !empty($c['uma_linha']),
                'maiusculo' => !empty($c['maiusculo']),
            ];
        }
    }
    return $limpo;
}

// Fundos usados em alguma folha do modelo (para não excluir da galeria).
function fundosDoModeloCertificado(array $modelo): array
{
    return array_values(array_filter(array_column(paginasModeloCertificado($modelo), 'fundo')));
}

function modelosCertificado(PDO $pdo): array
{
    return $pdo->query(
        'SELECT cm.*, (SELECT GROUP_CONCAT(DISTINCT c.nome ORDER BY c.nome SEPARATOR ", ") FROM curso_emissoes e JOIN cursos c ON c.id = e.curso_id WHERE e.modelo_id = cm.id AND e.ativo = 1 AND e.avulso = 0) AS cursos
         FROM certificado_modelos cm ORDER BY cm.padrao DESC, cm.nome'
    )->fetchAll();
}

function buscarModeloCertificado(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM certificado_modelos WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function modeloPadraoCertificado(PDO $pdo): ?array
{
    return $pdo->query('SELECT * FROM certificado_modelos ORDER BY padrao DESC, id LIMIT 1')->fetch() ?: null;
}

function modulosDoCurso(PDO $pdo, int $cursoId): array
{
    $stmt = $pdo->prepare('SELECT nome, carga_horaria FROM curso_modulos WHERE curso_id = ? ORDER BY ordem, id');
    $stmt->execute([$cursoId]);
    return $stmt->fetchAll();
}

function modulosExemploCertificado(): array
{
    return [
        ['nome' => 'Fundamentos e introdução', 'carga_horaria' => 40],
        ['nome' => 'Planejamento e escopo', 'carga_horaria' => 60],
        ['nome' => 'Cronograma e custos', 'carga_horaria' => 60],
        ['nome' => 'Gestão de riscos', 'carga_horaria' => 40],
        ['nome' => 'Liderança de equipes', 'carga_horaria' => 60],
        ['nome' => 'Metodologias ágeis', 'carga_horaria' => 60],
        ['nome' => 'Trabalho de conclusão', 'carga_horaria' => 40],
    ];
}

function dataPorExtenso(string $data): string
{
    $meses = [1 => 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
    $t = strtotime($data);
    return date('j', $t) . ' de ' . $meses[(int) date('n', $t)] . ' de ' . date('Y', $t);
}

// Página onde se confere o certificado: a configurada ou a do portal.
function urlVerificacaoCertificado(array $config): string
{
    return trim((string) ($config['certificado_url_verificacao'] ?? '')) ?: urlAbsoluta('/portal/verificar.php');
}

function linkVerificacaoCertificado(array $config, string $codigoFormatado): string
{
    $base = urlVerificacaoCertificado($config);
    return $base . (str_contains($base, '?') ? '&' : '?') . 'codigo=' . rawurlencode($codigoFormatado);
}

function formatarCodigoCertificado(string $codigo): string
{
    return implode('-', str_split($codigo, 4));
}

function valoresCertificado(array $certificado, array $config): array
{
    $cpf = apenasDigitos((string) $certificado['cpf']);
    $codigo = formatarCodigoCertificado($certificado['codigo']);
    $pai = trim(mb_strtoupper($certificado['nome_pai'] ?? '', 'UTF-8'));
    $mae = trim(mb_strtoupper($certificado['nome_mae'] ?? '', 'UTF-8'));

    $nomes = array_filter([$pai, $mae], fn($nome) => $nome !== '');

    if (count($nomes) === 2) {
        $filiacao = 'filho(a) de ' . implode(' e ', $nomes);
    } elseif (count($nomes) === 1) {
        $filiacao = 'filho(a) de ' . reset($nomes);
    } else {
        $filiacao = '';
    }

    return [
        '{aluno}' => mb_strtoupper($certificado['aluno_nome']),
        '{ra}' => (string) ($certificado['ra'] ?? ''),
        '{filiacao}' => (string) $filiacao,
        '{nascimento}' => formatDate($certificado['data_nascimento']),
        '{documento}' => (string) ($certificado['documento_nome'] ?? 'Certificado'),
        '{modulo}' => (string) ($certificado['modulo_nome'] ?? ''),
        '{modulo_carga_horaria}' => !empty($certificado['modulo_carga']) ? (string) (int) $certificado['modulo_carga'] : '—',
        '{data_modulo}' => !empty($certificado['modulo_concluido_em']) ? formatDate($certificado['modulo_concluido_em']) : '-',
        '{cpf}' => strlen($cpf) === 11 ? substr($cpf, 0, 3) . '.' . substr($cpf, 3, 3) . '.' . substr($cpf, 6, 3) . '-' . substr($cpf, 9) : $cpf,
        '{curso}' => $certificado['curso_nome'],
        '{turma}' => $certificado['turma_nome'],
        '{parceria}' => (string) ($certificado['parceria'] ?? ''),
        '{carga_horaria}' => $certificado['carga_horaria'] ? (string) (int) $certificado['carga_horaria'] : '—',
        '{data_inicio}' => formatDate($certificado['data_inicio']),
        // Término = data de conclusão informada ao concluir a turma; turma
        // antiga sem ela: início + duração (a última aula é no dia anterior).
        '{data_termino}' => $certificado['data_conclusao'] ? formatDate($certificado['data_conclusao'])
            : ($certificado['data_termino'] ? formatDate(date('Y-m-d', strtotime($certificado['data_termino'] . ' -1 day'))) : '-'),
        '{data_conclusao}' => $certificado['data_conclusao'] ? formatDate($certificado['data_conclusao']) : '-',
        '{data_emissao}' => dataPorExtenso($certificado['emitido_em']),
        '{data_evento}' => !empty($certificado['data_evento']) ? dataPorExtenso($certificado['data_evento']) : '',
        '{cidade}' => cidadeCertificado($config),
        '{empresa}' => $config['empresa_razao_social'] ?: ($config['nome_sistema'] ?? ''),
        '{codigo}' => $codigo,
        '{url_verificacao}' => urlVerificacaoCertificado($config),
        '{link_verificacao}' => linkVerificacaoCertificado($config, $codigo),
    ];
}

// Valores de exemplo para a pré-visualização no editor.
function valoresExemploCertificado(array $config): array
{
    return [
        '{aluno}' => 'Maria Aparecida da Silva Souza', '{cpf}' => '123.456.789-09', '{ra}' => 'RA0000123', '{parceria}' => 'em parceria com a Faculdade Exemplo',
        '{filiacao}' => 'filho de JOANA DA SILVA SOUZA e JOÃO PAULO SOUZA', '{nascimento}' => '26/10/1993',
        '{documento}' => 'Certificado de conclusão', '{modulo}' => 'Planejamento e escopo', '{modulo_carga_horaria}' => '60', '{data_modulo}' => '30/06/2026', '{curso}' => 'Gestão de Projetos',
        '{turma}' => '2026/1 - Noite', '{carga_horaria}' => '360', '{data_inicio}' => '02/03/2026', '{data_termino}' => '30/11/2026', '{data_conclusao}' => '30/11/2026',
        '{data_emissao}' => dataPorExtenso(date('Y-m-d')), '{data_evento}' => dataPorExtenso('2026-09-11'), '{cidade}' => cidadeCertificado($config) ?: 'São Paulo - SP',
        '{empresa}' => $config['empresa_razao_social'] ?: ($config['nome_sistema'] ?? 'Instituição'),
        '{codigo}' => 'ABCD-EFGH-JKLM', '{url_verificacao}' => urlVerificacaoCertificado($config),
        '{link_verificacao}' => linkVerificacaoCertificado($config, 'ABCD-EFGH-JKLM'),
    ];
}

// Fundos de um modelo (cada modelo tem a sua galeria).
function fundosCertificado(PDO $pdo, int $modeloId): array
{
    $stmt = $pdo->prepare('SELECT * FROM certificado_fundos WHERE modelo_id = ? ORDER BY criado_em DESC, id DESC');
    $stmt->execute([$modeloId]);
    return $stmt->fetchAll();
}

// Apaga o arquivo do fundo se nenhum modelo tem mais esse fundo (a cópia de
// um modelo reaproveita os mesmos arquivos).
function apagarFundoSemUso(PDO $pdo, string $imagem): void
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM certificado_fundos WHERE imagem = ?');
    $stmt->execute([$imagem]);
    $usado = (int) $stmt->fetchColumn() > 0;
    foreach (modelosCertificado($pdo) as $m) {
        $usado = $usado || in_array($imagem, fundosDoModeloCertificado($m), true);
    }
    if (!$usado) {
        apagarImagemPortal($imagem);
    }
}

/**
 * Marca d'água do modelo (logo do sistema repetida em diagonal): ativa,
 * tamanho de cada logo e espaço entre elas (em % da largura da folha) e
 * intensidade (% de opacidade). Sem configuração: ligada, no padrão.
 */
function marcaDaguaModelo(?array $modelo): array
{
    $dados = json_decode((string) ($modelo['marca_dagua'] ?? ''), true);
    $dados = is_array($dados) ? $dados : [];
    $numero = fn ($chave, $padrao, $min, $max) => round(min($max, max($min, (float) ($dados[$chave] ?? $padrao))), 1);
    return [
        'ativa' => (bool) ($dados['ativa'] ?? true),
        'tamanho' => $numero('tamanho', 8, 3, 25),
        'espaco' => $numero('espaco', 1.5, 0, 10),
        'opacidade' => $numero('opacidade', 10, 2, 40),
    ];
}

// ---------------------------------------------------------------------
// PDF do documento: o aluno recebe um arquivo fechado (não a página HTML,
// que dá para mexer na tela). Gerado pelo Chrome/Chromium sem janela
// (headless) a partir do mesmo HTML; fica guardado em storage e só é feito
// de novo quando algo do documento muda. Servidor: apt install chromium
// (ou defina CHROME_PATH em config/config.php).
// ---------------------------------------------------------------------

function navegadorParaPdf(): ?string
{
    $opcoes = array_filter([
        defined('CHROME_PATH') ? CHROME_PATH : null,
        '/usr/bin/chromium', '/usr/bin/chromium-browser', '/usr/bin/google-chrome', '/usr/bin/google-chrome-stable',
        '/snap/bin/chromium', '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
    ]);
    foreach ($opcoes as $caminho) {
        if (is_file($caminho) && is_executable($caminho)) {
            return $caminho;
        }
    }
    return null;
}

/**
 * PDF do documento a partir do HTML da página. Retorna o caminho do arquivo
 * (já gerado antes ou feito agora) ou null se não deu (sem navegador, erro).
 * $documentoId: id do documento emitido ou outra chave (ex.: 'contrato-12').
 */
function pdfDocumentoEmitido(int|string $documentoId, string $html): ?string
{
    $pasta = caminhoStorage('documentos_pdf');
    if (!is_dir($pasta)) {
        mkdir($pasta, 0700, true);
    }
    $arquivo = $pasta . '/' . $documentoId . '-' . sha1($html) . '.pdf';
    if (is_file($arquivo) && filesize($arquivo) > 1000) {
        return $arquivo;
    }
    $navegador = navegadorParaPdf();
    if (!$navegador) {
        error_log('[PDF documento] Chrome/Chromium não encontrado (instale o chromium ou defina CHROME_PATH).');
        return null;
    }

    // O navegador abre um arquivo local (sem pedir nada ao próprio site):
    // CSS, imagens e fontes do sistema viram caminhos de arquivo, e a logo
    // da marca d'água vai embutida (o desenho dela usa canvas).
    $publico = BASE_PATH . '/public';
    $html = preg_replace_callback('~data-logo="(/[^"?]+)[^"]*"~', function ($m) use ($publico) {
        $caminho = realpath($publico . $m[1]);
        // Logo que não está no disco: sai sem marca d'água (com o arquivo
        // faltando, o navegador ficava esperando e o PDF nunca terminava).
        if (!$caminho || !str_starts_with($caminho, $publico) || !is_file($caminho)) {
            return '';
        }
        $tipo = mime_content_type($caminho) ?: 'image/png';
        return 'data-logo="data:' . $tipo . ';base64,' . base64_encode(file_get_contents($caminho)) . '"';
    }, $html);
    $html = preg_replace('~(["\'(])/(assets|uploads)/~', '$1file://' . $publico . '/$2/', $html);

    $temporario = $pasta . '/tmp-' . bin2hex(random_bytes(6)) . '.html';
    file_put_contents($temporario, $html);
    $perfil = caminhoStorage('chrome');
    if (!is_dir($perfil)) {
        mkdir($perfil, 0700, true);
    }
    $saida = $arquivo . '.tmp';
    $argumentos = ['--headless=new', '--disable-gpu', '--no-first-run', '--no-default-browser-check', '--hide-scrollbars',
        '--allow-file-access-from-files', '--user-data-dir=' . $perfil, '--virtual-time-budget=8000',
        '--no-pdf-header-footer', '--print-to-pdf=' . $saida,
        // Sem cofre de senhas do sistema: no Mac evita o pedido de acesso às
        // "Chaves" a cada PDF; no Linux, a procura por um chaveiro que não existe.
        '--use-mock-keychain', '--password-store=basic', '--disable-extensions', '--disable-sync'];
    if (PHP_OS_FAMILY === 'Linux') {
        $argumentos[] = '--no-sandbox'; // usuário do Apache (www-data) não tem sandbox do Chrome
    }
    $comando = escapeshellarg($navegador) . ' ' . implode(' ', array_map('escapeshellarg', $argumentos))
        . ' ' . escapeshellarg('file://' . $temporario);
    $ambiente = getenv('HOME');
    putenv('HOME=' . $perfil);
    [$codigo, $log] = executarComTempoLimite($comando, 60);
    putenv('HOME=' . ($ambiente ?: ''));
    @unlink($temporario);

    if (!is_file($saida) || filesize($saida) < 1000) {
        @unlink($saida);
        error_log('[PDF documento] falhou (código ' . $codigo . '): ' . mb_strimwidth(implode(' ', $log), 0, 500));
        return null;
    }
    // Versões antigas deste documento saem (só a atual fica guardada).
    foreach (glob($pasta . '/' . $documentoId . '-*.pdf') ?: [] as $antigo) {
        @unlink($antigo);
    }
    rename($saida, $arquivo);
    return $arquivo;
}

/**
 * Roda um comando e o encerra se passar de $segundos (o navegador que gera
 * o PDF pode travar numa página; sem limite, a página do usuário ficava
 * carregando até a conexão cair). Retorna [código de saída, linhas da saída].
 */
function executarComTempoLimite(string $comando, int $segundos): array
{
    $processo = proc_open('exec ' . $comando, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $tubos);
    if (!is_resource($processo)) {
        return [-1, ['não foi possível iniciar o navegador']];
    }
    stream_set_blocking($tubos[1], false);
    $saida = '';
    $limite = microtime(true) + $segundos;
    while (($estado = proc_get_status($processo))['running']) {
        $saida .= (string) stream_get_contents($tubos[1]);
        if (microtime(true) > $limite) {
            proc_terminate($processo, 9);
            $saida .= ' [encerrado: passou de ' . $segundos . 's]';
            break;
        }
        usleep(100000);
    }
    $saida .= (string) stream_get_contents($tubos[1]);
    fclose($tubos[1]);
    $codigo = proc_close($processo);
    return [$estado['running'] ? 124 : ($estado['exitcode'] ?? $codigo), explode("\n", trim($saida))];
}

// Cidade da empresa para o documento: "Vitória da Conquista - BA" (só a
// primeira letra de cada palavra maiúscula; de/da/do/dos/das/e minúsculos).
function cidadeCertificado(array $config): string
{
    $cidade = formatarNomePessoa((string) ($config['empresa_cidade'] ?? ''));
    $uf = mb_strtoupper(trim((string) ($config['empresa_uf'] ?? '')), 'UTF-8');
    return $cidade !== '' && $uf !== '' ? $cidade . ' - ' . $uf : $cidade;
}