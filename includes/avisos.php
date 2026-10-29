<?php

// ---------------------------------------------------------------------
// Avisos (Portal › Avisos aos alunos). O conteúdo é uma arte livre,
// tipo Canva (avisos.arte), ou um texto com imagens e links
// (avisos.conteudo). Aparecem no portal: no mural (Meus avisos), numa
// janela ao entrar ou numa faixa no topo. Vão para todo mundo que entra no
// portal (com ou sem login), para todos os alunos com turma em andamento,
// para os de alguns cursos, de algumas turmas ou para alunos escolhidos.
// ---------------------------------------------------------------------

function formatosAviso(): array
{
    return [
        'mural' => ['Só no mural', 'Aparece em "Meus avisos" e no sininho do aluno.', 'bi-pin-angle'],
        'janela' => ['Janela ao entrar', 'Abre por cima da tela quando o aluno entra no portal (até ele fechar ou confirmar).', 'bi-window-stack'],
        'faixa' => ['Faixa no topo', 'Barra colorida no alto de todas as páginas do aluno, com o título e "ver aviso".', 'bi-megaphone'],
    ];
}

function coresAviso(): array
{
    return [
        'azul' => ['Azul (informação)', '#2563eb', '#eff6ff'],
        'verde' => ['Verde (boa notícia)', '#16a34a', '#f0fdf4'],
        'amarelo' => ['Amarelo (atenção)', '#ca8a04', '#fefce8'],
        'vermelho' => ['Vermelho (urgente)', '#dc2626', '#fef2f2'],
    ];
}

function publicosAviso(): array
{
    return [
        'site' => 'Todo mundo que entrar no portal',
        'todos' => 'Todos os alunos com turma em andamento',
        'curso' => 'Alunos de curso(s)',
        'turma' => 'Alunos de turma(s)',
        'aluno' => 'Aluno(s) escolhido(s)',
    ];
}

function repeticoesAviso(): array
{
    return [
        'uma_vez' => ['Uma vez só', 'Depois que a pessoa vê (ou fecha), não aparece mais para ela.'],
        'visita' => ['Uma vez por visita', 'Aparece de novo cada vez que a pessoa volta ao portal (não a cada página).'],
        'sempre' => ['Persistente', 'Aparece toda vez que a página abre (inclusive ao atualizar), até o fim do período. Ex.: promoção.'],
    ];
}

// No público "todo mundo que entrar no portal": quem vê.
function quemSiteAviso(): array
{
    return [
        'todos' => 'Logados e não logados',
        'visitantes' => 'Só quem não está logado (visitantes)',
        'logados' => 'Só alunos logados (qualquer aluno com cadastro, com ou sem turma)',
    ];
}

/**
 * Páginas do portal onde o aviso (janela / faixa) pode aparecer:
 * chave => rótulo. As do sistema seguem as chaves de portal_paginas; as
 * criadas no editor do portal são "p:slug".
 */
function paginasPortalAviso(PDO $pdo): array
{
    $paginas = [
        'inicio' => 'Início', 'cursos' => 'Cursos (vitrine)', 'turma' => 'Página de cada curso', 'inscrever' => 'Inscrição no curso',
        'entrar' => 'Entrar (login)', 'cadastro' => 'Criar conta', 'meus_cursos' => 'Meus cursos', 'financeiro' => 'Pagamentos',
        'calendario' => 'Calendário', 'certificados' => 'Certificados', 'documentos' => 'Meus documentos', 'protocolos' => 'Meus protocolos',
        'conta' => 'Meus dados',
    ];
    foreach ($pdo->query("SELECT slug, titulo FROM portal_paginas WHERE tipo = 'personalizada' AND slug IS NOT NULL ORDER BY ordem, titulo") as $p) {
        $paginas['p:' . $p['slug']] = $p['titulo'] . ' (página criada)';
    }
    return $paginas;
}

// Chave da página do portal aberta agora (ver paginasPortalAviso).
function chavePaginaPortalAtual(): string
{
    $arquivo = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''), '.php');
    return match ($arquivo) {
        'index' => 'inicio',
        'turmas' => 'cursos',
        'protocolo' => 'protocolos',
        'pagina' => 'p:' . (string) ($_GET['p'] ?? ''),
        default => $arquivo,
    };
}

function paginasDoAviso(array $aviso): array
{
    return array_values(array_filter(explode(',', (string) ($aviso['paginas'] ?? ''))));
}

// Nenhuma página escolhida = todas.
function avisoNaPagina(array $aviso, string $chave): bool
{
    $paginas = paginasDoAviso($aviso);
    return !$paginas || in_array($chave, $paginas, true);
}

// Condição SQL (sobre o alias "av") de "este aluno recebe o aviso"; leva o
// id do aluno 4 vezes nos parâmetros. Turma em andamento = matrícula ativa
// ou pendente.
function condicaoDestinoAviso(): string
{
    return "(
        (av.publico = 'site' AND av.quem_site IN ('todos', 'logados'))
        OR (av.publico = 'todos' AND EXISTS (SELECT 1 FROM matriculas m WHERE m.aluno_id = ? AND m.status IN ('ativa', 'pendente')))
        OR (av.publico = 'aluno' AND EXISTS (SELECT 1 FROM aviso_destinos d WHERE d.aviso_id = av.id AND d.ref_id = ?))
        OR (av.publico = 'turma' AND EXISTS (SELECT 1 FROM aviso_destinos d JOIN matriculas m ON m.turma_id = d.ref_id
                WHERE d.aviso_id = av.id AND m.aluno_id = ? AND m.status IN ('ativa', 'pendente')))
        OR (av.publico = 'curso' AND EXISTS (SELECT 1 FROM aviso_destinos d JOIN turmas t ON t.curso_id = d.ref_id JOIN matriculas m ON m.turma_id = t.id
                WHERE d.aviso_id = av.id AND m.aluno_id = ? AND m.status IN ('ativa', 'pendente')))
    )";
}

/**
 * Avisos no ar para o aluno (ativos e dentro do período), mais novos
 * primeiro, com visto_em / confirmado_em dele.
 */
function avisosDoAluno(PDO $pdo, int $alunoId, ?string $formato = null): array
{
    $sql = 'SELECT av.*, l.visto_em, l.confirmado_em FROM avisos av
            LEFT JOIN aviso_leituras l ON l.aviso_id = av.id AND l.aluno_id = ?
            WHERE av.ativo = 1 AND av.inicio <= NOW() AND (av.fim IS NULL OR av.fim >= NOW()) AND ' . condicaoDestinoAviso();
    $params = array_fill(0, 5, $alunoId);
    if ($formato !== null) {
        $sql .= ' AND av.formato = ?';
        $params[] = $formato;
    }
    $stmt = $pdo->prepare($sql . ' ORDER BY av.inicio DESC, av.id DESC');
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// Um aviso, só se este aluno o recebe (e está no ar).
function avisoDoAluno(PDO $pdo, int $alunoId, int $avisoId): ?array
{
    foreach (avisosDoAluno($pdo, $alunoId) as $aviso) {
        if ((int) $aviso['id'] === $avisoId) {
            return $aviso;
        }
    }
    return null;
}

/**
 * Avisos no ar para quem não entrou no portal (só os de "todo mundo").
 * O que o visitante já viu fica num cookie do navegador (ids).
 */
function avisosVisitante(PDO $pdo): array
{
    $vistos = avisosVistosVisitante();
    $avisos = $pdo->query("SELECT * FROM avisos WHERE ativo = 1 AND publico = 'site' AND quem_site IN ('todos', 'visitantes') AND inicio <= NOW() AND (fim IS NULL OR fim >= NOW()) ORDER BY inicio DESC, id DESC")->fetchAll();
    foreach ($avisos as &$a) {
        $a['visto_em'] = in_array((int) $a['id'], $vistos, true) ? date('Y-m-d H:i:s') : null;
        $a['confirmado_em'] = null;
        $a['exige_confirmacao'] = 0; // "Li e entendi" é só para aluno logado
    }
    return $avisos;
}

function avisosVistosVisitante(): array
{
    return array_values(array_filter(array_map('intval', explode('.', (string) ($_COOKIE['avisos_vistos'] ?? '')))));
}

function marcarVistoVisitante(int $avisoId): void
{
    $vistos = array_slice(array_unique([...avisosVistosVisitante(), $avisoId]), -50);
    $_COOKIE['avisos_vistos'] = implode('.', $vistos);
    if (!headers_sent()) {
        setcookie('avisos_vistos', $_COOKIE['avisos_vistos'], ['expires' => time() + 365 * 86400, 'path' => '/', 'samesite' => 'Lax', 'httponly' => true]);
    }
}

// Pendentes do aluno: não vistos, ou que pedem "Li e entendi" sem confirmação.
function avisoPendente(array $aviso): bool
{
    return !$aviso['visto_em'] || ($aviso['exige_confirmacao'] && !$aviso['confirmado_em']);
}

// Aparece (janela / faixa)? Persistente e "por visita": sempre, até o fim
// do período (a visita é controlada na sessão); uma vez: enquanto pendente.
function avisoAparece(array $aviso): bool
{
    return $aviso['repeticao'] !== 'uma_vez' || avisoPendente($aviso);
}

// O aviso tem algo para abrir além do título (arte ou texto)?
function avisoTemConteudo(array $aviso): bool
{
    if ($aviso['montagem'] === 'arte') {
        $arte = arteAviso($aviso);
        return $arte['elementos'] || $arte['fundo_imagem'];
    }
    return trim(strip_tags((string) $aviso['conteudo'], '<img>')) !== '';
}

function marcarAvisoVisto(PDO $pdo, int $avisoId, int $alunoId): void
{
    $pdo->prepare('INSERT IGNORE INTO aviso_leituras (aviso_id, aluno_id) VALUES (?, ?)')->execute([$avisoId, $alunoId]);
}

function confirmarAvisoAluno(PDO $pdo, int $avisoId, int $alunoId): void
{
    $pdo->prepare('INSERT INTO aviso_leituras (aviso_id, aluno_id, confirmado_em) VALUES (?, ?, NOW())
                   ON DUPLICATE KEY UPDATE confirmado_em = COALESCE(confirmado_em, NOW())')->execute([$avisoId, $alunoId]);
}

/**
 * Alunos que recebem o aviso, com a leitura de cada um (relatório "quem
 * leu"). Para "todos", "curso" e "turma" vale quem tem turma em andamento
 * hoje; quem já leu aparece mesmo que tenha saído da turma depois.
 */
function destinatariosAviso(PDO $pdo, array $aviso): array
{
    $id = (int) $aviso['id'];
    if ($aviso['publico'] === 'site' && $aviso['quem_site'] === 'visitantes') {
        return [];
    }
    $ativa = "m.status IN ('ativa', 'pendente')";
    $alvo = match ($aviso['publico']) {
        'aluno' => 'SELECT ref_id FROM aviso_destinos WHERE aviso_id = :id',
        'turma' => "SELECT m.aluno_id FROM matriculas m JOIN aviso_destinos d ON d.ref_id = m.turma_id AND d.aviso_id = :id WHERE $ativa",
        'curso' => "SELECT m.aluno_id FROM matriculas m JOIN turmas t ON t.id = m.turma_id JOIN aviso_destinos d ON d.ref_id = t.curso_id AND d.aviso_id = :id WHERE $ativa",
        'site' => 'SELECT id FROM alunos WHERE ativo = 1 AND :id > 0', // qualquer aluno com cadastro
        default => "SELECT m.aluno_id FROM matriculas m WHERE $ativa AND :id > 0",
    };
    $stmt = $pdo->prepare(
        "SELECT a.id, a.nome, a.cpf, a.email, l.visto_em, l.confirmado_em
         FROM alunos a LEFT JOIN aviso_leituras l ON l.aviso_id = :id2 AND l.aluno_id = a.id
         WHERE a.id IN ($alvo) OR l.aluno_id IS NOT NULL
         ORDER BY a.nome"
    );
    $stmt->execute(['id' => $id, 'id2' => $id]);
    return $stmt->fetchAll();
}

// Contagem para a lista: [recebem, viram, confirmaram].
function resumoLeiturasAviso(PDO $pdo, array $aviso): array
{
    $lista = destinatariosAviso($pdo, $aviso);
    return [
        count($lista),
        count(array_filter($lista, fn ($a) => $a['visto_em'])),
        count(array_filter($lista, fn ($a) => $a['confirmado_em'])),
    ];
}

// Nomes dos destinos escolhidos (cursos, turmas ou alunos) do aviso.
function nomesDestinosAviso(PDO $pdo, array $aviso): array
{
    $sql = match ($aviso['publico']) {
        'curso' => 'SELECT c.id, c.nome FROM aviso_destinos d JOIN cursos c ON c.id = d.ref_id WHERE d.aviso_id = ? ORDER BY c.nome',
        'turma' => "SELECT t.id, CONCAT(c.nome, ' · ', t.nome) AS nome FROM aviso_destinos d JOIN turmas t ON t.id = d.ref_id JOIN cursos c ON c.id = t.curso_id WHERE d.aviso_id = ? ORDER BY c.nome, t.nome",
        'aluno' => 'SELECT a.id, a.nome FROM aviso_destinos d JOIN alunos a ON a.id = d.ref_id WHERE d.aviso_id = ? ORDER BY a.nome',
        default => null,
    };
    if (!$sql) {
        return [];
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute([(int) $aviso['id']]);
    return $stmt->fetchAll();
}

// Situação do aviso para a equipe: no ar, agendado, encerrado ou desligado.
function situacaoAviso(array $aviso): array
{
    $agora = date('Y-m-d H:i:s');
    return match (true) {
        !$aviso['ativo'] => ['Desligado', 'secondary'],
        $aviso['inicio'] > $agora => ['Agendado', 'info'],
        $aviso['fim'] && $aviso['fim'] < $agora => ['Encerrado', 'light'],
        default => ['No ar', 'success'],
    };
}

// HTML do aviso já limpo ao salvar; aqui só garante de novo na saída.
function htmlAviso(?string $conteudo): string
{
    return limparHtmlContrato((string) $conteudo, true);
}

/**
 * Imagens coladas no editor chegam dentro do HTML (data:image/...;base64).
 * Viram arquivos em uploads/portal, como as enviadas pelo botão; as que
 * não forem imagem de verdade ou passarem de 5 MB saem.
 */
function salvarImagensColadasAviso(string $html): string
{
    return preg_replace_callback('#src="data:image/(png|jpe?g|gif|webp);base64,([A-Za-z0-9+/=\s]+)"#i', function ($m) {
        $dados = base64_decode(preg_replace('/\s+/', '', $m[2]), true);
        if ($dados === false || strlen($dados) > 5 * 1024 * 1024 || problemaPastaUpload(pastaUploadsPortal())) {
            return 'src=""';
        }
        $tipos = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($dados);
        if (!isset($tipos[$mime]) || !@getimagesizefromstring($dados)) {
            return 'src=""';
        }
        $nome = 'aviso-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.' . $tipos[$mime];
        return file_put_contents(pastaUploadsPortal() . '/' . $nome, $dados) ? 'src="/uploads/portal/' . $nome . '"' : 'src=""';
    }, $html) ?? $html;
}

// Imagens enviadas para o aviso, no texto e na arte (para apagar do disco ao excluir).
function imagensDoAviso(array $aviso): array
{
    preg_match_all('#src="(/uploads/portal/aviso-[\w.-]+)"#', (string) $aviso['conteudo'], $m);
    $arte = arteAviso($aviso);
    $urls = [...$m[1], $arte['fundo_imagem'], ...array_column($arte['elementos'], 'imagem')];
    return array_values(array_unique(array_filter($urls, fn ($u) => is_string($u) && str_starts_with($u, '/uploads/portal/aviso-'))));
}

// ---------------------------------------------------------------------
// Arte do aviso (tipo Canva). Folha com proporção, fundo (cor e/ou imagem)
// e elementos soltos: texto, imagem, botão e forma. Posição e tamanho em %
// da folha (x/y = canto de cima à esquerda); letra e cantos em "px numa
// folha de 1000 px" (viram cqw na tela). O editor (aviso-arte.js) desenha
// igual a htmlArteAviso().
// ---------------------------------------------------------------------

function proporcoesArteAviso(): array
{
    // chave => [rótulo, largura, altura, largura máxima da janela no portal (px)]
    return [
        '1:1' => ['Quadrado (post)', 1, 1, 540],
        '4:5' => ['Retrato (feed)', 4, 5, 500],
        '9:16' => ['Story (em pé)', 9, 16, 400],
        '16:9' => ['Paisagem', 16, 9, 800],
        '3:1' => ['Banner largo', 3, 1, 960],
    ];
}

function fontesArteAviso(): array
{
    return [
        'Inter' => "'Inter', sans-serif",
        'Montserrat' => "'Montserrat', sans-serif",
        'Poppins' => "'Poppins', sans-serif",
        'Oswald' => "'Oswald', sans-serif",
        'Anton' => "'Anton', sans-serif",
        'Bebas Neue' => "'Bebas Neue', sans-serif",
        'Playfair Display' => "'Playfair Display', serif",
        'Lora' => "'Lora', serif",
        'Pacifico' => "'Pacifico', cursive",
        'Dancing Script' => "'Dancing Script', cursive",
    ];
}

// Link das fontes da arte (Google Fonts), para o editor e o portal.
function urlFontesArteAviso(): string
{
    $familias = array_map(fn ($f) => 'family=' . str_replace(' ', '+', $f) . (in_array($f, ['Anton', 'Bebas Neue', 'Pacifico'], true) ? '' : ':ital,wght@0,400;0,700;1,400;1,700'), array_keys(fontesArteAviso()));
    return 'https://fonts.googleapis.com/css2?' . implode('&', $familias) . '&display=swap';
}

function arteVaziaAviso(): array
{
    return ['proporcao' => '1:1', 'fundo_cor' => '#ffffff', 'fundo_imagem' => null, 'elementos' => []];
}

function arteAviso(array $aviso): array
{
    $dados = json_decode((string) ($aviso['arte'] ?? ''), true);
    return normalizarArteAviso(is_array($dados) ? $dados : []);
}

// Link de elemento: endereço https/http ou caminho do próprio sistema.
function linkArteAviso($link): ?string
{
    $link = trim((string) $link);
    if ($link === '') {
        return null;
    }
    if (preg_match('#^www\.#i', $link)) {
        $link = 'https://' . $link;
    }
    return preg_match('~^(https?://[^\s"<>]+|/[A-Za-z0-9_./?=&%#-]*)$~i', $link) && !str_starts_with($link, '//') ? mb_substr($link, 0, 500) : null;
}

// Limpa o que veio do editor: só campos conhecidos, números nos limites.
function normalizarArteAviso(array $dados): array
{
    $num = fn ($v, float $min, float $max, float $padrao) => max($min, min($max, round(is_numeric($v) ? (float) $v : $padrao, 2)));
    $cor = fn ($v, string $padrao = '#000000') => preg_match('/^#[0-9a-fA-F]{6}$/', (string) $v) ? strtolower($v) : $padrao;
    $imagem = fn ($v) => is_string($v) && preg_match('#^/uploads/portal/[\w.-]+$#', $v) ? $v : null;
    $arte = [
        'proporcao' => array_key_exists($dados['proporcao'] ?? '', proporcoesArteAviso()) ? $dados['proporcao'] : '1:1',
        'fundo_cor' => $cor($dados['fundo_cor'] ?? null, '#ffffff'),
        'fundo_imagem' => $imagem($dados['fundo_imagem'] ?? null),
        'elementos' => [],
    ];
    foreach (array_slice((array) ($dados['elementos'] ?? []), 0, 60) as $e) {
        if (!is_array($e) || !in_array($e['tipo'] ?? '', ['texto', 'imagem', 'botao', 'forma'], true)) {
            continue;
        }
        $base = [
            'tipo' => $e['tipo'],
            'x' => $num($e['x'] ?? 0, -100, 200, 10),
            'y' => $num($e['y'] ?? 0, -100, 200, 10),
            'w' => $num($e['w'] ?? 0, 1, 300, 50),
            'h' => $num($e['h'] ?? 0, 0.5, 300, 10),
            'giro' => $num($e['giro'] ?? 0, -180, 180, 0),
            'opacidade' => $num($e['opacidade'] ?? 100, 0, 100, 100),
            'raio' => $num($e['raio'] ?? 0, 0, 500, 0),
            'sombra' => !empty($e['sombra']),
            'link' => linkArteAviso($e['link'] ?? ''),
        ];
        $letra = [
            'fonte' => array_key_exists($e['fonte'] ?? '', fontesArteAviso()) ? $e['fonte'] : 'Inter',
            'tamanho' => $num($e['tamanho'] ?? 40, 6, 600, 40),
            'cor' => $cor($e['cor'] ?? null, '#111111'),
            'negrito' => !empty($e['negrito']),
            'italico' => !empty($e['italico']),
            'maiusculo' => !empty($e['maiusculo']),
        ];
        if ($e['tipo'] === 'texto') {
            $texto = mb_substr(str_replace("\r", '', (string) ($e['texto'] ?? '')), 0, 3000);
            if (trim($texto) === '') {
                continue;
            }
            $arte['elementos'][] = $base + $letra + [
                'texto' => $texto,
                'alinhamento' => in_array($e['alinhamento'] ?? '', ['left', 'center', 'right'], true) ? $e['alinhamento'] : 'center',
                'entrelinha' => $num($e['entrelinha'] ?? 1.2, 0.8, 3, 1.2),
                'fundo' => preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($e['fundo'] ?? '')) ? strtolower($e['fundo']) : '',
                'fundo_opacidade' => $num($e['fundo_opacidade'] ?? 100, 0, 100, 100),
            ];
        } elseif ($e['tipo'] === 'botao') {
            $arte['elementos'][] = $base + $letra + [
                'texto' => mb_substr(trim((string) ($e['texto'] ?? '')), 0, 120) ?: 'Saiba mais',
                'fundo' => $cor($e['fundo'] ?? null, '#16a34a'),
            ];
        } elseif ($e['tipo'] === 'imagem') {
            if ($url = $imagem($e['imagem'] ?? null)) {
                $arte['elementos'][] = $base + ['imagem' => $url, 'ajuste' => ($e['ajuste'] ?? '') === 'contain' ? 'contain' : 'cover'];
            }
        } else {
            $arte['elementos'][] = $base + ['cor' => $cor($e['cor'] ?? null, '#2563eb'), 'circulo' => !empty($e['circulo'])];
        }
    }
    return $arte;
}

function corComOpacidade(string $hex, float $opacidade): string
{
    [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');
    return "rgba($r, $g, $b, " . round($opacidade / 100, 2) . ')';
}

/**
 * HTML da arte (portal, mural, prévia da secretaria). Igual ao desenho do
 * editor (aviso-arte.js › htmlArteAviso). Precisa de aviso-arte.css.
 */
function htmlArteAviso(array $arte): string
{
    [, $pw, $ph] = proporcoesArteAviso()[$arte['proporcao']];
    $fontes = fontesArteAviso();
    $estiloFolha = "aspect-ratio: $pw / $ph; background-color: {$arte['fundo_cor']};"
        . ($arte['fundo_imagem'] ? " background-image: url('" . e($arte['fundo_imagem']) . "');" : '');
    $html = '<div class="arte-aviso" style="' . $estiloFolha . '">';
    $cqw = fn ($px) => round($px / 10, 3) . 'cqw';
    foreach ($arte['elementos'] as $e) {
        $fora = "left: {$e['x']}%; top: {$e['y']}%; width: {$e['w']}%; height: {$e['h']}%;"
            . ($e['giro'] ? " transform: rotate({$e['giro']}deg);" : '')
            . ($e['opacidade'] < 100 ? ' opacity: ' . round($e['opacidade'] / 100, 2) . ';' : '');
        $dentro = '';
        $miolo = '';
        $letra = fn () => "font-family: {$fontes[$e['fonte']]}; font-size: {$cqw($e['tamanho'])}; color: {$e['cor']};"
            . ' font-weight: ' . ($e['negrito'] ? 700 : 400) . ';' . ($e['italico'] ? ' font-style: italic;' : '')
            . ($e['maiusculo'] ? ' text-transform: uppercase;' : '');
        if ($e['tipo'] === 'texto') {
            $dentro = $letra() . " text-align: {$e['alinhamento']}; line-height: {$e['entrelinha']};"
                . ($e['fundo'] ? ' background: ' . corComOpacidade($e['fundo'], $e['fundo_opacidade']) . "; padding: 0 {$cqw(16)};" : '')
                . ($e['raio'] ? " border-radius: {$cqw($e['raio'])};" : '')
                . ($e['sombra'] ? ' text-shadow: 0 0.3cqw 1cqw rgba(0,0,0,.45);' : '');
            $miolo = e($e['texto']);
        } elseif ($e['tipo'] === 'botao') {
            $dentro = $letra() . " background: {$e['fundo']}; border-radius: {$cqw($e['raio'])};"
                . ($e['sombra'] ? ' box-shadow: 0 0.6cqw 2cqw rgba(0,0,0,.3);' : '');
            $miolo = e($e['texto']);
        } elseif ($e['tipo'] === 'imagem') {
            $dentro = "border-radius: {$cqw($e['raio'])};" . ($e['sombra'] ? ' box-shadow: 0 0.6cqw 2cqw rgba(0,0,0,.3);' : '');
            $miolo = '<img src="' . e($e['imagem']) . '" alt="" style="object-fit: ' . $e['ajuste'] . '">';
        } else {
            $dentro = "background: {$e['cor']}; border-radius: " . ($e['circulo'] ? '50%' : $cqw($e['raio'])) . ';'
                . ($e['sombra'] ? ' box-shadow: 0 0.6cqw 2cqw rgba(0,0,0,.3);' : '');
        }
        $abre = $e['link']
            ? '<a class="arte-el arte-' . $e['tipo'] . '" href="' . e($e['link']) . '"' . (str_starts_with($e['link'], 'http') ? ' target="_blank" rel="noopener"' : '') . ' style="' . $fora . '">'
            : '<div class="arte-el arte-' . $e['tipo'] . '" style="' . $fora . '">';
        $html .= $abre . '<div class="arte-in" style="' . e($dentro) . '">' . $miolo . '</div>' . ($e['link'] ? '</a>' : '</div>');
    }
    return $html . '</div>';
}

// Conteúdo do aviso pronto para mostrar (arte ou texto).
function conteudoAvisoHtml(array $aviso): string
{
    return $aviso['montagem'] === 'arte' ? htmlArteAviso(arteAviso($aviso)) : '<div class="aviso-conteudo">' . htmlAviso($aviso['conteudo']) . '</div>';
}

// Largura do aviso na tela (px): a escolhida no aviso ou, sem ela, pela
// proporção da arte.
function larguraJanelaAviso(array $aviso): int
{
    if (!empty($aviso['largura'])) {
        return (int) $aviso['largura'];
    }
    return $aviso['montagem'] === 'arte' ? proporcoesArteAviso()[arteAviso($aviso)['proporcao']][3] : 800;
}
