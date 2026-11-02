<?php
// Gera o manual detalhado do sistema (docs/manual-detalhado.html) lendo o
// próprio código: cada tela com seus blocos, botões, formulários, links,
// permissões, funções e tabelas (com o número da linha); cada função dos
// includes com a explicação e quem a usa; cada tabela do banco com as
// colunas e quem lê ou grava; cada permissão e onde ela trava.
//
// Não acessa o banco nem a internet. Rode de novo depois de mudar o código:
//
//   php scripts/gerar_manual.php
//
// Nunca lê a pasta credenciais_itau.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$raiz = dirname(__DIR__);
$saida = $raiz . '/docs/manual-detalhado.html';

// ---------------------------------------------------------------------
// Utilidades
// ---------------------------------------------------------------------

function h(?string $t): string
{
    return htmlspecialchars((string) $t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function relativo(string $caminho): string
{
    global $raiz;
    return ltrim(substr($caminho, strlen($raiz)), '/');
}

function linhaDoOffset(string $texto, int $offset): int
{
    return substr_count($texto, "\n", 0, $offset) + 1;
}

function arquivosPhp(string $pasta): array
{
    $lista = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pasta, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $arq) {
        $c = $arq->getPathname();
        if (str_contains($c, '/credenciais_itau') || str_contains($c, '/uploads/')) {
            continue;
        }
        if (str_ends_with($c, '.php')) {
            $lista[] = $c;
        }
    }
    sort($lista);
    return $lista;
}

// Texto visível de um pedaço de HTML com PHP no meio: tira as tags, troca
// os trechos de PHP por "…" e junta os espaços.
function textoVisivel(string $html): string
{
    $html = preg_replace('/<\?(php|=).*?\?>/s', ' … ', $html);
    // HTML montado dentro de uma string PHP: '...' . $variavel . '...'
    $html = preg_replace("/'\\s*\\.\\s*.*?\\s*\\.\\s*'/s", ' … ', $html);
    $html = preg_replace('/\\$\\w+(\\[[^\\]]*\\])*/', '…', $html);
    $html = preg_replace('/<i class="bi ([^"]+)"[^>]*><\/i>/', '', $html);
    $t = trim(preg_replace('/\s+/', ' ', strip_tags($html)));
    $t = preg_replace('/(\s*…\s*)+/', ' … ', $t);
    return trim($t, " \t\n\r\0\x0B");
}

// Comentários (// e /* */) de um arquivo PHP, agrupados quando seguidos.
function comentariosPhp(string $codigo): array
{
    $grupos = [];
    $atual = null;
    foreach (token_get_all($codigo) as $tk) {
        if (!is_array($tk)) {
            continue;
        }
        [$tipo, $texto, $linha] = $tk;
        if ($tipo === T_COMMENT || $tipo === T_DOC_COMMENT) {
            $limpo = preg_replace('#^\s*(//+|/\*+|\*+/?|\*)\s?#m', '', $texto);
            $limpo = trim(preg_replace('#\*/\s*$#', '', $limpo));
            if ($limpo === '' || preg_match('/^[-=]{5,}$/', $limpo)) {
                continue;
            }
            $fim = $linha + substr_count($texto, "\n");
            if ($atual && $linha <= $atual['fim'] + 1) {
                $atual['texto'] .= "\n" . $limpo;
                $atual['fim'] = $fim;
            } else {
                if ($atual) {
                    $grupos[] = $atual;
                }
                $atual = ['linha' => $linha, 'fim' => $fim, 'texto' => $limpo];
            }
        }
    }
    if ($atual) {
        $grupos[] = $atual;
    }
    return $grupos;
}

// Funções definidas num arquivo: nome, linha, parâmetros e o comentário logo acima.
function funcoesDefinidas(string $codigo): array
{
    $tokens = token_get_all($codigo);
    $funcs = [];
    $ultimoComentario = null;
    $profundidade = 0;
    $n = count($tokens);
    for ($i = 0; $i < $n; $i++) {
        $tk = $tokens[$i];
        if (!is_array($tk)) {
            if ($tk === '{') {
                $profundidade++;
            } elseif ($tk === '}') {
                $profundidade--;
            }
            continue;
        }
        if ($tk[0] === T_CURLY_OPEN || $tk[0] === T_DOLLAR_OPEN_CURLY_BRACES) {
            $profundidade++;
            continue;
        }
        if ($tk[0] === T_COMMENT || $tk[0] === T_DOC_COMMENT) {
            $fim = $tk[2] + substr_count($tk[1], "\n");
            if ($ultimoComentario && $tk[2] <= $ultimoComentario['fim'] + 1) {
                $ultimoComentario['texto'] .= "\n" . $tk[1];
                $ultimoComentario['fim'] = $fim;
            } else {
                $ultimoComentario = ['texto' => $tk[1], 'fim' => $fim];
            }
            continue;
        }
        if ($tk[0] === T_FUNCTION && $profundidade === 0) {
            $j = $i + 1;
            while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                $j++;
            }
            if ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                $nome = $tokens[$j][1];
                $linha = $tokens[$j][2];
                // Assinatura até a abertura do corpo.
                $assin = '';
                for ($k = $j + 1; $k < $n; $k++) {
                    $t = $tokens[$k];
                    $s = is_array($t) ? $t[1] : $t;
                    if ($s === '{' || $s === ';') {
                        break;
                    }
                    $assin .= $s;
                }
                $coment = '';
                if ($ultimoComentario && $ultimoComentario['fim'] >= $linha - 1) {
                    $coment = preg_replace('#^\s*(//+|/\*+|\*+/?|\*)\s?#m', '', $ultimoComentario['texto']);
                    $coment = trim(preg_replace('#\*/\s*$#', '', $coment));
                }
                $funcs[] = ['nome' => $nome, 'linha' => $linha, 'assinatura' => trim(preg_replace('/\s+/', ' ', $assin)), 'comentario' => $coment];
            }
        }
        if ($tk[0] !== T_WHITESPACE) {
            if ($tk[0] !== T_COMMENT && $tk[0] !== T_DOC_COMMENT && $tk[0] !== T_FUNCTION) {
                // Um comentário só "pertence" à função logo abaixo dele.
                if ($ultimoComentario && $tk[2] > $ultimoComentario['fim'] + 1) {
                    $ultimoComentario = null;
                }
            }
        }
    }
    return $funcs;
}

// Chamadas de função (nome => [linhas]) num arquivo PHP.
function chamadasPhp(string $codigo): array
{
    $tokens = token_get_all($codigo);
    $chamadas = [];
    $n = count($tokens);
    for ($i = 0; $i < $n; $i++) {
        $tk = $tokens[$i];
        if (!is_array($tk) || $tk[0] !== T_STRING) {
            continue;
        }
        $j = $i + 1;
        while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
            $j++;
        }
        if ($j >= $n || $tokens[$j] !== '(') {
            continue;
        }
        $p = $i - 1;
        while ($p >= 0 && is_array($tokens[$p]) && $tokens[$p][0] === T_WHITESPACE) {
            $p--;
        }
        if ($p >= 0 && is_array($tokens[$p]) && in_array($tokens[$p][0], [T_FUNCTION, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NEW], true)) {
            continue;
        }
        $chamadas[$tk[1]][] = $tk[2];
    }
    return $chamadas;
}

// ---------------------------------------------------------------------
// Coleta
// ---------------------------------------------------------------------

$arquivosPublic = arquivosPhp($raiz . '/public');
$arquivosIncludes = arquivosPhp($raiz . '/includes');
$arquivosOutros = array_merge(arquivosPhp($raiz . '/scripts'), arquivosPhp($raiz . '/config'));
$todosPhp = array_merge($arquivosPublic, $arquivosIncludes, $arquivosOutros);

$conteudo = [];
foreach ($todosPhp as $c) {
    $conteudo[$c] = file_get_contents($c);
}

// Funções do sistema.
$funcoes = [];
foreach (array_merge($arquivosIncludes, $arquivosOutros, $arquivosPublic) as $c) {
    foreach (funcoesDefinidas($conteudo[$c]) as $f) {
        $f['arquivo'] = relativo($c);
        $funcoes[$f['nome']] ??= $f;
    }
}

// Chamadas em cada arquivo e o índice reverso (função => onde é usada).
$chamadasPorArquivo = [];
$usadaEm = [];
foreach ($todosPhp as $c) {
    $ch = array_intersect_key(chamadasPhp($conteudo[$c]), $funcoes);
    $chamadasPorArquivo[$c] = $ch;
    foreach ($ch as $nome => $linhas) {
        $usadaEm[$nome][relativo($c)] = $linhas;
    }
}

// Permissões: chave => descrição (schema + migrações).
$permissoes = [];
$sqls = glob($raiz . '/sql/*.sql');
sort($sqls);
foreach ($sqls as $sql) {
    $txt = file_get_contents($sql);
    if (preg_match_all('/INSERT\s+(?:IGNORE\s+)?INTO\s+permissoes\s*\([^)]*\)\s*VALUES(.*?);/is', $txt, $blocos)) {
        foreach ($blocos[1] as $b) {
            preg_match_all("/\(\s*'([a-z_]+)'\s*,\s*'((?:[^'\\\\]|\\\\.|'')*)'/", $b, $m, PREG_SET_ORDER);
            foreach ($m as $p) {
                $permissoes[$p[1]] = str_replace("''", "'", $p[2]);
            }
        }
    }
    if (preg_match_all("/UPDATE\s+permissoes\s+SET\s+descricao\s*=\s*'((?:[^']|'')*)'\s+WHERE\s+chave\s*=\s*'([a-z_]+)'/i", $txt, $m, PREG_SET_ORDER)) {
        foreach ($m as $p) {
            $permissoes[$p[2]] = str_replace("''", "'", $p[1]);
        }
    }
}

// Funções de auth.php que conferem permissões (diretamente ou por outra função).
$authFonte = $conteudo[$raiz . '/includes/auth.php'];
$corpos = [];
if (preg_match_all('/function\s+(\w+)\s*\([^)]*\)[^{]*\{(.*?)\n\}/s', $authFonte, $m, PREG_SET_ORDER)) {
    foreach ($m as $f) {
        $corpos[$f[1]] = $f[2];
    }
}
$chavesDaFuncao = [];
foreach ($corpos as $nome => $corpo) {
    preg_match_all("/(?:usuarioTemPermissao|exigirPermissao|exigirPermissaoAcao)\(\s*'([a-z_]+)'/", $corpo, $m);
    $chavesDaFuncao[$nome] = array_values(array_unique($m[1]));
}
for ($volta = 0; $volta < 5; $volta++) {
    foreach ($corpos as $nome => $corpo) {
        foreach ($corpos as $outra => $_) {
            if ($outra !== $nome && preg_match('/\b' . $outra . '\(/', $corpo)) {
                $chavesDaFuncao[$nome] = array_values(array_unique(array_merge($chavesDaFuncao[$nome], $chavesDaFuncao[$outra])));
            }
        }
    }
}
$soAdmin = ['exigirAdmin', 'usuarioEhAdmin'];

// Onde cada permissão é conferida.
$permissaoUsadaEm = [];
foreach ($todosPhp as $c) {
    $rel = relativo($c);
    if ($rel === 'includes/auth.php') {
        continue;
    }
    if (preg_match_all("/(usuarioTemPermissao|exigirPermissao|exigirPermissaoAcao)\(\s*'([a-z_]+)'/", $conteudo[$c], $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[2] as $k => $chave) {
            $permissaoUsadaEm[$chave[0]][$rel][] = linhaDoOffset($conteudo[$c], $chave[1]);
        }
    }
    foreach ($chamadasPorArquivo[$c] as $func => $linhas) {
        foreach ($chavesDaFuncao[$func] ?? [] as $chave) {
            foreach ($linhas as $l) {
                $permissaoUsadaEm[$chave][$rel][] = $l;
            }
        }
    }
}

// Tabelas do banco (schema.sql): colunas e comentários.
$schema = file_get_contents($raiz . '/sql/schema.sql');
$tabelas = [];
if (preg_match_all('/((?:^--[^\n]*\n)*)CREATE TABLE (?:IF NOT EXISTS )?`?(\w+)`?\s*\((.*?)\n\)[^;]*;/ms', $schema, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
    foreach ($m as $t) {
        $nome = $t[2][0];
        $colunas = [];
        $comentCol = '';
        foreach (explode("\n", $t[3][0]) as $linhaCol) {
            $lc = trim($linhaCol);
            if (str_starts_with($lc, '--')) {
                $comentCol .= ($comentCol ? ' ' : '') . trim(substr($lc, 2));
                continue;
            }
            if (preg_match('/^`?(\w+)`?\s+([A-Za-z]+(?:\([^)]*\))?)(.*?),?$/', $lc, $cm)
                && !in_array(strtoupper($cm[1]), ['PRIMARY', 'KEY', 'UNIQUE', 'CONSTRAINT', 'INDEX', 'FOREIGN', 'FULLTEXT'], true)) {
                $extra = '';
                if (preg_match("/COMMENT\s+'([^']*)'/i", $cm[3], $cc)) {
                    $extra = $cc[1];
                }
                $colunas[] = ['nome' => $cm[1], 'tipo' => $cm[2] . (stripos($cm[3], 'NOT NULL') !== false ? '' : ''), 'nota' => trim($comentCol . ' ' . $extra)];
                $comentCol = '';
            } elseif (preg_match('/FOREIGN KEY \(`?(\w+)`?\) REFERENCES `?(\w+)`?/', $lc, $fk)) {
                foreach ($colunas as &$col) {
                    if ($col['nome'] === $fk[1]) {
                        $col['liga'] = $fk[2] . (stripos($lc, 'ON DELETE CASCADE') !== false ? ' (apaga junto)' : (stripos($lc, 'ON DELETE SET NULL') !== false ? ' (fica vazio)' : ' (impede excluir)'));
                    }
                }
                unset($col);
            }
        }
        $coment = trim(preg_replace('/^--\s?/m', '', $t[1][0]));
        $tabelas[$nome] = ['colunas' => $colunas, 'comentario' => $coment, 'linha' => linhaDoOffset($schema, $t[2][1])];
    }
}

// Quem lê e quem grava cada tabela.
$tabelaUsadaEm = [];
$tabelasPorArquivo = [];
foreach ($todosPhp as $c) {
    $rel = relativo($c);
    if (preg_match_all('/\b(FROM|JOIN|INTO|UPDATE|DELETE\s+FROM)\s+`?(\w+)`?/i', $conteudo[$c], $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        foreach ($m as $s) {
            $t = $s[2][0];
            if (!isset($tabelas[$t])) {
                continue;
            }
            $op = strtoupper(preg_replace('/\s+/', ' ', $s[1][0]));
            $tipo = in_array($op, ['INTO', 'UPDATE', 'DELETE FROM'], true) ? 'grava' : 'lê';
            if ($op === 'INTO' && !preg_match('/INSERT\s+(?:IGNORE\s+)?$/i', substr($conteudo[$c], max(0, $s[0][1] - 20), 20)) && !preg_match('/REPLACE\s+$/i', substr($conteudo[$c], max(0, $s[0][1] - 20), 20))) {
                continue;
            }
            $linha = linhaDoOffset($conteudo[$c], $s[0][1]);
            $tabelaUsadaEm[$t][$rel][$tipo][] = $linha;
            $tabelasPorArquivo[$rel][$t][$tipo][] = $linha;
        }
    }
}

// Menu do topo (layout_header.php).
$menu = [];
$cab = $conteudo[$raiz . '/includes/layout_header.php'];
if (preg_match_all("/'rotulo' => '([^']+)',\s*'itens' => \[(.*?)\n        \],/s", $cab, $m, PREG_SET_ORDER)) {
    foreach ($m as $g) {
        preg_match_all("/\['([^']+)',\s*'([^']+)'/", $g[2], $it, PREG_SET_ORDER);
        foreach ($it as $i) {
            $menu[ltrim($i[2], '/')] = $g[1] . ' › ' . $i[1];
        }
    }
}
if (preg_match_all('/class="dropdown-item" href="\/([^"]+)">([^<]+)</', $cab, $m, PREG_SET_ORDER)) {
    foreach ($m as $i) {
        $menu[$i[1]] ??= 'Menu do topo › ' . trim($i[2]);
    }
}
foreach (['abas_configuracoes.php' => 'Configurações › ', 'abas_cadastros_auxiliares.php' => 'Configurações › Cadastros auxiliares › ', 'abas_conta_bancaria.php' => 'Configurações › Contas bancárias › (conta) › '] as $arqAbas => $prefixo) {
    $txt = $conteudo[$raiz . '/includes/' . $arqAbas];
    if (preg_match_all('/href="\/([a-z_\/]+\.php)[^"]*"[^>]*>(.*?)<\/a>/s', $txt, $m, PREG_SET_ORDER)) {
        foreach ($m as $i) {
            $menu[$i[1]] ??= $prefixo . textoVisivel($i[2]);
        }
    }
}

// Detalhes de cada arquivo de public/.
function detalharPagina(string $c, string $codigo): array
{
    $d = [];
    $linhas = explode("\n", $codigo);

    if (preg_match('/\$pageTitle\s*=\s*(.+?);/', $codigo, $m)) {
        $d['titulo'] = $m[1];
    }
    $d['usaLayout'] = str_contains($codigo, 'layout_header.php') || str_contains($codigo, 'portal_header.php');

    // Includes.
    if (preg_match_all("/(?:require|include)(?:_once)?\s*\(?\s*__DIR__\s*\.\s*'([^']+)'/", $codigo, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[1] as $i) {
            $d['includes'][] = [basename($i[0]), linhaDoOffset($codigo, $i[1])];
        }
    }

    // Recebe envio de formulário?
    if (preg_match_all('/\$_SERVER\[\'REQUEST_METHOD\'\]\s*===\s*\'POST\'/', $codigo, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[0] as $i) {
            $d['post'][] = linhaDoOffset($codigo, $i[1]);
        }
    }
    if (preg_match_all("/(?:\\\$acao|input\('acao'\))\s*===\s*'([a-z_]+)'/", $codigo, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[1] as $i) {
            $d['acoes'][$i[0]] ??= linhaDoOffset($codigo, $i[1]);
        }
    }
    if (preg_match_all("/case\s+'([a-z_]+)'\s*:/", $codigo, $m, PREG_OFFSET_CAPTURE) && str_contains($codigo, "acao")) {
        foreach ($m[1] as $i) {
            $d['acoes'][$i[0]] ??= linhaDoOffset($codigo, $i[1]);
        }
    }

    // Parâmetros da URL.
    if (preg_match_all('/\$_GET\[\'(\w+)\'\]/', $codigo, $m)) {
        $d['get'] = array_values(array_unique($m[1]));
    }

    // Mensagens que o usuário vê.
    if (preg_match_all("/flash\('(success|danger|warning|info)',\s*('(?:[^'\\\\]|\\\\.)*'|\"(?:[^\"\\\\]|\\\\.)*\")/", $codigo, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        foreach ($m as $f) {
            $d['mensagens'][] = [$f[1][0], trim($f[2][0], "'\""), linhaDoOffset($codigo, $f[0][1])];
        }
    }

    // Redirecionamentos.
    if (preg_match_all("/redirect\('([^']+)/", $codigo, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[1] as $r) {
            $d['redirect'][] = [$r[0], linhaDoOffset($codigo, $r[1])];
        }
    }

    // Auditoria.
    if (preg_match_all("/registrarAtividade\('(\w+)',\s*'(\w+)'/", $codigo, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[0] as $k => $r) {
            $d['auditoria'][] = [$m[1][$k][0] . ' / ' . $m[2][$k][0], linhaDoOffset($codigo, $r[1])];
        }
    }

    // Blocos da tela: títulos, cabeçalhos de cartão, abas, janelas.
    $padroes = [
        'Título' => '/<h([1-6])\b[^>]*>(.*?)<\/h\1>/s',
        'Cartão' => '/<div class="[^"]*card-header[^"]*"[^>]*>(.*?)<\/div>/s',
        'Aba' => '/<(?:a|button)\b[^>]*class="[^"]*nav-link[^"]*"[^>]*>(.*?)<\/(?:a|button)>/s',
        'Rótulo' => '/<div class="kpi-rotulo">(.*?)<\/div>/s',
    ];
    foreach ($padroes as $tipo => $re) {
        if (preg_match_all($re, $codigo, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($m as $b) {
                $txt = textoVisivel(end($b)[0]);
                if ($txt === '' || $txt === '…' || mb_strlen($txt) > 120) {
                    continue;
                }
                $nomeTipo = $tipo === 'Título' && str_contains(substr($codigo, max(0, $b[0][1] - 0), 60), 'modal-title') ? 'Janela' : $tipo;
                if ($tipo === 'Título' && preg_match('/modal-title/', $b[0][0])) {
                    $nomeTipo = 'Janela';
                }
                $d['blocos'][] = [$nomeTipo, $txt, linhaDoOffset($codigo, $b[0][1])];
            }
        }
    }
    // Colunas de tabela.
    if (preg_match_all('/<thead\b.*?<\/thead>/s', $codigo, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[0] as $th) {
            preg_match_all('/<th\b[^>]*>(.*?)<\/th>/s', $th[0], $cols);
            $nomes = array_filter(array_map('textoVisivel', $cols[1]), fn ($x) => $x !== '' && $x !== '…');
            if ($nomes) {
                $d['blocos'][] = ['Tabela (colunas)', implode(' | ', $nomes), linhaDoOffset($codigo, $th[1])];
            }
        }
    }
    if (!empty($d['blocos'])) {
        usort($d['blocos'], fn ($a, $b) => $a[2] <=> $b[2]);
    }

    // Formulários.
    if (preg_match_all('/<form\b((?:[^>"]|"[^"]*")*)>/s', $codigo, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        foreach ($m as $f) {
            $atr = $f[1][0];
            $action = preg_match('/action="([^"]*)"/', $atr, $a) ? textoVisivel($a[1]) : '(a própria página)';
            $method = preg_match('/method="([^"]*)"/i', $atr, $a) ? strtoupper($a[1]) : 'GET';
            $conf = preg_match('/data-confirmar="([^"]*)"/', $atr, $a) ? textoVisivel($a[1]) : '';
            $d['forms'][] = [$action ?: '(a própria página)', $method, $conf, linhaDoOffset($codigo, $f[0][1])];
        }
    }

    // Botões e links com cara de botão.
    if (preg_match_all('/<button\b((?:[^>"]|"[^"]*")*)>(.*?)<\/button>/s', $codigo, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        foreach ($m as $b) {
            $atr = $b[1][0];
            $txt = textoVisivel($b[2][0]);
            if (preg_match('/^[\s…>]*$/u', $txt)) {
                $txt = '';
            }
            if ($txt === '' && preg_match('/title="([^"]*)"/', $atr, $t)) {
                $txt = '[ícone] ' . textoVisivel($t[1]);
            }
            if ($txt === '' && preg_match('/aria-label="([^"]*)"/', $atr, $t)) {
                $txt = '[ícone] ' . textoVisivel($t[1]);
            }
            if ($txt === '' && str_contains($b[2][0], '<?')) {
                $txt = '(texto montado no código)';
            }
            if ($txt === '' || str_contains($atr, 'btn-close')) {
                continue;
            }
            $faz = '';
            if (preg_match('/onclick="([^"]*)"/', $atr, $o)) {
                $faz = 'ao clicar: ' . textoVisivel($o[1]);
            } elseif (preg_match('/data-bs-target="([^"]*)"/', $atr, $o)) {
                $faz = 'abre ' . $o[1];
            } elseif (preg_match('/formaction="([^"]*)"/', $atr, $o)) {
                $faz = 'envia para ' . textoVisivel($o[1]);
            } elseif (preg_match('/type="submit"/', $atr) || !preg_match('/type=/', $atr)) {
                $faz = 'envia o formulário';
                if (preg_match('/name="acao" value="([^"]*)"/', $atr, $o)) {
                    $faz .= ' (ação ' . $o[1] . ')';
                }
            }
            if (preg_match('/data-confirmar="([^"]*)"/', $atr, $o)) {
                $faz .= ' · pergunta antes: "' . textoVisivel($o[1]) . '"';
            }
            $d['botoes'][] = [$txt, $faz, linhaDoOffset($codigo, $b[0][1])];
        }
    }
    if (preg_match_all('/<a\b(?=[^>]*class="[^"]*\bbtn\b)((?:[^>"]|"[^"]*")*)>(.*?)<\/a>/s', $codigo, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        foreach ($m as $b) {
            $txt = textoVisivel($b[2][0]);
            if ($txt === '' && preg_match('/title="([^"]*)"/', $b[1][0], $t)) {
                $txt = '[ícone] ' . textoVisivel($t[1]);
            }
            if ($txt === '') {
                continue;
            }
            $href = preg_match('/href="([^"]*)"/', $b[1][0], $hh) ? textoVisivel($hh[1]) : '';
            $d['botoes'][] = [$txt, $href ? 'abre ' . $href : '', linhaDoOffset($codigo, $b[0][1])];
        }
    }
    if (!empty($d['botoes'])) {
        usort($d['botoes'], fn ($a, $b) => $a[2] <=> $b[2]);
    }

    // Campos de formulário.
    if (preg_match_all('/<(input|select|textarea)\b((?:[^>"]|"[^"]*")*)>/s', $codigo, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        foreach ($m as $f) {
            $atr = $f[2][0];
            if (!preg_match('/name="([^"]+)"/', $atr, $nm)) {
                continue;
            }
            $tipo = $f[1][0] === 'input' ? (preg_match('/type="([^"]+)"/', $atr, $tt) ? $tt[1] : 'text') : $f[1][0];
            if (in_array($tipo, ['hidden', 'submit'], true)) {
                continue;
            }
            $extras = [];
            if (str_contains($atr, 'required')) {
                $extras[] = 'obrigatório';
            }
            if (preg_match('/data-mascara="([^"]+)"/', $atr, $mm)) {
                $extras[] = 'máscara ' . $mm[1];
            }
            $d['campos'][] = [textoVisivel($nm[1]), $tipo . ($extras ? ' (' . implode(', ', $extras) . ')' : ''), linhaDoOffset($codigo, $f[0][1])];
        }
    }

    // Chamadas ao servidor feitas pelo JavaScript da página.
    if (preg_match_all("/fetch\(\s*[`'\"]([^`'\"]+)/", $codigo, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[1] as $f) {
            $d['fetch'][] = [textoVisivel($f[0]), linhaDoOffset($codigo, $f[1])];
        }
    }
    // Funções JavaScript da página.
    if (preg_match_all('/<script\b(?![^>]*\bsrc=)[^>]*>(.*?)<\/script>/s', $codigo, $scripts, PREG_OFFSET_CAPTURE)) {
        foreach ($scripts[1] as $s) {
            if (preg_match_all('/(?:function\s+(\w+)\s*\(|(?:const|let)\s+(\w+)\s*=\s*(?:async\s*)?(?:\([^)]*\)|\w+)\s*=>)/', $s[0], $fm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                foreach ($fm as $fn) {
                    $nome = ($fn[1][0] ?? '') !== '' ? $fn[1][0] : ($fn[2][0] ?? '');
                    if ($nome !== '') {
                        $d['js'][] = [$nome, linhaDoOffset($codigo, $s[1] + $fn[0][1])];
                    }
                }
            }
        }
    }
    if (preg_match_all('/assets\/js\/([\w-]+\.js)/', $codigo, $m)) {
        $d['jsArquivos'] = array_values(array_unique($m[1]));
    }

    // Links para outras telas.
    if (preg_match_all('/(?:href|action|formaction)="(\/[a-z_\/]+\.php)/', $codigo, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[1] as $l) {
            $d['links'][ltrim($l[0], '/')][] = linhaDoOffset($codigo, $l[1]);
        }
    }
    if (preg_match_all("/(?:fetch\(|redirect\()\s*[`'\"](\/[a-z_\/]+\.php)/", $codigo, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[1] as $l) {
            $d['links'][ltrim($l[0], '/')][] = linhaDoOffset($codigo, $l[1]);
        }
    }
    $d['totalLinhas'] = count($linhas);
    return $d;
}

$paginas = [];
$chamadoPor = [];
foreach ($arquivosPublic as $c) {
    $rel = relativo($c);
    $url = substr($rel, strlen('public/'));
    $paginas[$url] = detalharPagina($c, $conteudo[$c]) + ['arquivo' => $rel, 'comentarios' => comentariosPhp($conteudo[$c])];
}
// Includes que desenham partes de tela também apontam para telas.
foreach (array_merge($arquivosPublic, $arquivosIncludes) as $c) {
    $rel = relativo($c);
    $codigo = $conteudo[$c];
    if (preg_match_all('/(?:href|action|formaction)="\/([a-z_\/]+\.php)|(?:fetch\(|redirect\()\s*[`\'"]\/([a-z_\/]+\.php)/', $codigo, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        foreach ($m as $l) {
            $alvo = ($l[1][0] ?? '') !== '' ? $l[1][0] : $l[2][0];
            if (isset($paginas[$alvo]) && 'public/' . $alvo !== $rel) {
                $chamadoPor[$alvo][$rel][] = linhaDoOffset($codigo, $l[0][1]);
            }
        }
    }
}

// JavaScript dos assets.
$jsAssets = [];
foreach (glob($raiz . '/public/assets/js/*.js') as $js) {
    $txt = file_get_contents($js);
    $nome = basename($js);
    $coment = '';
    if (preg_match('/^((?:\s*\/\/[^\n]*\n)+)/', $txt, $m)) {
        $coment = trim(preg_replace('/^\s*\/\/ ?/m', '', $m[1]));
    }
    preg_match_all('/function\s+(\w+)\s*\(/', $txt, $fm, PREG_OFFSET_CAPTURE);
    $funcsJs = [];
    foreach ($fm[1] as $f) {
        $funcsJs[] = [$f[0], linhaDoOffset($txt, $f[1])];
    }
    $carregadoEm = [];
    foreach (array_merge($arquivosPublic, $arquivosIncludes) as $c) {
        if (str_contains($conteudo[$c], 'assets/js/' . $nome)) {
            $carregadoEm[] = relativo($c);
        }
    }
    $jsAssets[$nome] = ['comentario' => $coment, 'funcoes' => $funcsJs, 'carregado' => $carregadoEm, 'linhas' => substr_count($txt, "\n") + 1];
}

// Rotinas do cron.
$rotinas = [];
if (preg_match_all("/case '(\w+)':/", $conteudo[$raiz . '/scripts/rotinas.php'], $m)) {
    $rotinas = $m[1];
}

// ---------------------------------------------------------------------
// Saída
// ---------------------------------------------------------------------

function idDe(string $prefixo, string $nome): string
{
    return $prefixo . '-' . preg_replace('/[^a-z0-9]+/i', '-', $nome);
}

function linkArquivo(string $rel, $linhas = null): string
{
    $txt = '<code>' . h($rel) . '</code>';
    if ($linhas) {
        $ls = array_values(array_unique((array) $linhas));
        sort($ls);
        $mostra = array_slice($ls, 0, 12);
        $txt .= ' <span class="ln">linha' . (count($ls) > 1 ? 's' : '') . ' ' . implode(', ', $mostra) . (count($ls) > 12 ? ' (+' . (count($ls) - 12) . ')' : '') . '</span>';
    }
    if (str_starts_with($rel, 'public/') && str_ends_with($rel, '.php')) {
        return '<a href="#' . idDe('p', substr($rel, 7)) . '">' . $txt . '</a>';
    }
    if (str_starts_with($rel, 'includes/')) {
        return '<a href="#' . idDe('i', basename($rel)) . '">' . $txt . '</a>';
    }
    return $txt;
}

function linkFuncao(string $nome): string
{
    return '<a href="#' . idDe('f', $nome) . '"><code>' . h($nome) . '()</code></a>';
}

function paragrafos(string $t): string
{
    $t = h($t);
    $t = preg_replace('/`([^`]+)`/', '<code>$1</code>', $t);
    return nl2br($t);
}

$areas = [
    'Entrada, painel e conta' => fn ($u) => !str_contains($u, '/') ,
    'Alunos' => fn ($u) => str_starts_with($u, 'alunos/'),
    'Cursos, turmas e professores' => fn ($u) => preg_match('#^(cursos|turmas|professores)/#', $u),
    'Matrículas' => fn ($u) => str_starts_with($u, 'matriculas/'),
    'Materiais e videoaulas' => fn ($u) => preg_match('#^(materiais|videoaulas)/#', $u),
    'Secretaria (protocolos, WhatsApp, certificados)' => fn ($u) => preg_match('#^(protocolos|whatsapp|documentos)/#', $u),
    'Avisos e e-mail' => fn ($u) => preg_match('#^(avisos|email)/#', $u),
    'Financeiro' => fn ($u) => preg_match('#^(financeiro|fornecedores|formas_pagamento|cupons)/#', $u),
    'Contas bancárias (Itaú)' => fn ($u) => str_starts_with($u, 'contas_bancarias/'),
    'Configurações e cadastros auxiliares' => fn ($u) => preg_match('#^(configuracoes|perfis|usuarios|situacoes_turma|tipos_graduacao|feriados|listas|sessao|notificacoes)/#', $u),
    'Portal do aluno' => fn ($u) => str_starts_with($u, 'portal/'),
    'Avisos de fora (webhooks)' => fn ($u) => str_starts_with($u, 'webhook/'),
];
$paginasPorArea = [];
foreach ($paginas as $url => $p) {
    foreach ($areas as $area => $teste) {
        if ($teste($url)) {
            $paginasPorArea[$area][$url] = $p;
            continue 2;
        }
    }
    $paginasPorArea['Outros'][$url] = $p;
}

$rotuloBloco = ['Título' => 'Título', 'Cartão' => 'Quadro', 'Aba' => 'Aba / link de navegação', 'Rótulo' => 'Informação', 'Janela' => 'Janela (modal)', 'Tabela (colunas)' => 'Tabela'];

ob_start();
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Manual detalhado do sistema</title>
<style>
:root{--bg:#f6f7f9;--card:#fff;--ink:#1d2433;--muted:#5b6474;--line:#dde1e8;--code:#eef1f5;--accent:#0d4ea6;--mark:#fff3b0}
@media (prefers-color-scheme:dark){:root:not([data-theme="light"]){--bg:#11151c;--card:#1a1f29;--ink:#e6e9ef;--muted:#9aa3b2;--line:#2c3340;--code:#242a36;--accent:#7fb0ff;--mark:#5a4b00}}
:root[data-theme="dark"]{--bg:#11151c;--card:#1a1f29;--ink:#e6e9ef;--muted:#9aa3b2;--line:#2c3340;--code:#242a36;--accent:#7fb0ff;--mark:#5a4b00}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);font:14.5px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}
.topo{position:sticky;top:0;z-index:5;background:var(--card);border-bottom:1px solid var(--line);padding:10px 16px;display:flex;gap:12px;align-items:center;flex-wrap:wrap}
.topo strong{font-size:15px}
.topo input{flex:1;min-width:200px;padding:7px 10px;border:1px solid var(--line);border-radius:6px;background:var(--bg);color:var(--ink);font:inherit}
.topo nav{display:flex;gap:10px;flex-wrap:wrap;font-size:13px}
main{max-width:1100px;margin:0 auto;padding:20px 16px 80px}
h1{font-size:26px;margin:8px 0 4px}h2{font-size:21px;margin:44px 0 10px;padding-top:10px;border-top:3px solid var(--line)}h3{font-size:16px;margin:26px 0 8px}
a{color:var(--accent)}
code{background:var(--code);border-radius:4px;padding:1px 5px;font:12.5px ui-monospace,Menlo,monospace;word-break:break-word}
.ln{color:var(--muted);font-size:12px}
.sub{color:var(--muted)}
details{background:var(--card);border:1px solid var(--line);border-radius:8px;margin:8px 0;padding:0 14px}
details[open]{padding-bottom:12px}
summary{cursor:pointer;padding:10px 0;font-weight:600}
summary .sub{font-weight:400;font-size:13px}
table{border-collapse:collapse;width:100%;margin:6px 0 12px;font-size:13.5px;display:block;overflow-x:auto}
th,td{border:1px solid var(--line);padding:5px 8px;text-align:left;vertical-align:top}
th{background:var(--code);white-space:nowrap}
.nota{background:var(--code);border-left:3px solid var(--accent);padding:8px 12px;margin:8px 0;white-space:normal;font-size:13.5px}
.nota .ln{display:block;margin-bottom:2px}
[id]{scroll-margin-top:72px}
.oculto{display:none!important}
mark{background:var(--mark);color:inherit}
h4{margin:14px 0 4px;font-size:13.5px;text-transform:uppercase;letter-spacing:.03em;color:var(--muted)}
@media print{.topo{display:none}details{border:0;padding:0}details:not([open])>*:not(summary){display:block}}
</style>
</head>
<body>
<div class="topo">
  <strong>Manual detalhado</strong>
  <input id="busca" type="search" placeholder="Buscar: nome de tela, botão, função, tabela, permissão…" autocomplete="off">
  <nav><a href="#telas">Telas</a><a href="#funcoes">Funções</a><a href="#js">JavaScript</a><a href="#banco">Banco</a><a href="#permissoes">Permissões</a><a href="#rotinas">Rotinas</a></nav>
</div>
<main>
<h1>Manual detalhado do sistema</h1>
<p class="sub">Gerado automaticamente a partir do código em <?= h(date('d/m/Y H:i')) ?> por <code>scripts/gerar_manual.php</code>. Depois de mudar o código, rode <code>php scripts/gerar_manual.php</code> para atualizar (os números de linha mudam).</p>
<p><?= count($paginas) ?> arquivos de tela/ação · <?= count($funcoes) ?> funções PHP · <?= count($jsAssets) ?> arquivos JavaScript · <?= count($tabelas) ?> tabelas · <?= count($permissoes) ?> permissões.</p>

<div class="nota"><strong>Como usar.</strong> Digite na busca do topo qualquer coisa que você vê na tela (o texto de um botão, o título de um quadro, o nome de uma coluna) ou um nome do código. Os itens que não combinam somem e os que combinam abrem. Cada item diz o arquivo e a linha. Clique nos nomes de arquivo e de função para ir até a explicação deles.</div>

<h2 id="telas">1. Telas e arquivos de ação (pasta public/)</h2>
<p>O endereço no navegador é o caminho do arquivo dentro de <code>public/</code>. Arquivos sem tela recebem um clique ou um envio, fazem a ação e voltam.</p>
<?php foreach ($paginasPorArea as $area => $lista): ?>
<h3><?= h($area) ?> <span class="sub">(<?= count($lista) ?>)</span></h3>
<?php foreach ($lista as $url => $p): ?>
<details class="item" id="<?= h(idDe('p', $url)) ?>">
<summary><code>/<?= h($url) ?></code> <span class="sub"><?= isset($menu[$url]) ? '· ' . h($menu[$url]) : '' ?><?= !empty($p['titulo']) ? ' · título: ' . h(trim($p['titulo'], "'")) : '' ?><?= $p['usaLayout'] ? ' · tela' : ' · sem tela (ação, impressão ou resposta)' ?> · <?= (int) $p['totalLinhas'] ?> linhas</span></summary>
<p>Arquivo: <code><?= h($p['arquivo']) ?></code><?php if (isset($menu[$url])): ?> · Onde fica no menu: <strong><?= h($menu[$url]) ?></strong><?php endif; ?></p>
<?php
$rel = $p['arquivo'];
$funcsArq = $chamadasPorArquivo[$GLOBALS['raiz'] . '/' . $rel] ?? [];
$checks = [];
foreach ($funcsArq as $fn => $ls) {
    if (in_array($fn, $soAdmin, true)) {
        $checks[] = linkFuncao($fn) . ' (só administrador) <span class="ln">linha ' . implode(', ', array_unique($ls)) . '</span>';
    } elseif (str_starts_with($fn, 'exigir') || ($chavesDaFuncao[$fn] ?? [])) {
        $ks = $chavesDaFuncao[$fn] ?? [];
        $checks[] = linkFuncao($fn) . ($ks ? ' → ' . implode(', ', array_map(fn ($k) => '<a href="#' . idDe('k', $k) . '"><code>' . h($k) . '</code></a>', $ks)) : '') . ' <span class="ln">linha ' . implode(', ', array_slice(array_unique($ls), 0, 8)) . '</span>';
    }
}
if (preg_match_all("/(?:usuarioTemPermissao|exigirPermissao|exigirPermissaoAcao)\(\s*'([a-z_]+)'/", $conteudo[$GLOBALS['raiz'] . '/' . $rel], $mm, PREG_OFFSET_CAPTURE)) {
    $porChave = [];
    foreach ($mm[1] as $k) {
        $porChave[$k[0]][] = linhaDoOffset($conteudo[$GLOBALS['raiz'] . '/' . $rel], $k[1]);
    }
    foreach ($porChave as $k => $ls) {
        $checks[] = 'permissão <a href="#' . idDe('k', $k) . '"><code>' . h($k) . '</code></a> <span class="ln">linha ' . implode(', ', array_slice($ls, 0, 8)) . '</span>';
    }
}
?>
<?php if ($p['comentarios']): ?>
<h4>Explicações escritas no código</h4>
<?php foreach (array_slice($p['comentarios'], 0, 40) as $cm): ?>
<div class="nota"><span class="ln">linha <?= (int) $cm['linha'] ?></span><?= paragrafos($cm['texto']) ?></div>
<?php endforeach; ?>
<?php endif; ?>
<?php if ($checks): ?>
<h4>Quem pode usar (travas de permissão)</h4>
<ul><?php foreach (array_unique($checks) as $ck): ?><li><?= $ck ?></li><?php endforeach; ?></ul>
<?php endif; ?>
<?php if (!empty($p['get'])): ?><p><strong>Parâmetros no endereço:</strong> <?= implode(', ', array_map(fn ($g) => '<code>?' . h($g) . '=</code>', $p['get'])) ?></p><?php endif; ?>
<?php if (!empty($p['includes'])): ?>
<h4>Peças que carrega (includes)</h4>
<ul><?php foreach ($p['includes'] as [$inc, $l]): ?><li><a href="#<?= h(idDe('i', $inc)) ?>"><code><?= h($inc) ?></code></a> <span class="ln">linha <?= (int) $l ?></span></li><?php endforeach; ?></ul>
<?php endif; ?>
<?php if (!empty($p['post']) || !empty($p['acoes'])): ?>
<h4>O que faz quando recebe um envio (POST)</h4>
<ul>
<?php foreach ($p['post'] ?? [] as $l): ?><li>Trata o envio do formulário a partir da <span class="ln">linha <?= (int) $l ?></span></li><?php endforeach; ?>
<?php foreach ($p['acoes'] ?? [] as $a => $l): ?><li>Ação <code><?= h($a) ?></code> <span class="ln">linha <?= (int) $l ?></span></li><?php endforeach; ?>
</ul>
<?php endif; ?>
<?php if (!empty($p['blocos'])): ?>
<h4>Blocos da tela (de cima para baixo)</h4>
<table><thead><tr><th>Linha</th><th>Tipo</th><th>O que aparece</th></tr></thead><tbody>
<?php foreach ($p['blocos'] as [$tipo, $txt, $l]): ?><tr><td><?= (int) $l ?></td><td><?= h($rotuloBloco[$tipo] ?? $tipo) ?></td><td><?= h($txt) ?></td></tr><?php endforeach; ?>
</tbody></table>
<?php endif; ?>
<?php if (!empty($p['botoes'])): ?>
<h4>Botões</h4>
<table><thead><tr><th>Linha</th><th>Botão</th><th>O que faz</th></tr></thead><tbody>
<?php foreach ($p['botoes'] as [$txt, $faz, $l]): ?><tr><td><?= (int) $l ?></td><td><?= h($txt) ?></td><td><?= h($faz) ?></td></tr><?php endforeach; ?>
</tbody></table>
<?php endif; ?>
<?php if (!empty($p['forms'])): ?>
<h4>Formulários</h4>
<table><thead><tr><th>Linha</th><th>Envia para</th><th>Método</th><th>Confirmação</th></tr></thead><tbody>
<?php foreach ($p['forms'] as [$act, $met, $conf, $l]): ?><tr><td><?= (int) $l ?></td><td><code><?= h($act) ?></code></td><td><?= h($met) ?></td><td><?= h($conf) ?></td></tr><?php endforeach; ?>
</tbody></table>
<?php endif; ?>
<?php if (!empty($p['campos'])): ?>
<h4>Campos</h4>
<table><thead><tr><th>Linha</th><th>Nome do campo</th><th>Tipo</th></tr></thead><tbody>
<?php foreach ($p['campos'] as [$nm, $tp, $l]): ?><tr><td><?= (int) $l ?></td><td><code><?= h($nm) ?></code></td><td><?= h($tp) ?></td></tr><?php endforeach; ?>
</tbody></table>
<?php endif; ?>
<?php if (!empty($p['mensagens'])): ?>
<h4>Mensagens que o usuário pode ver</h4>
<table><thead><tr><th>Linha</th><th>Tipo</th><th>Mensagem</th></tr></thead><tbody>
<?php foreach ($p['mensagens'] as [$tp, $msg, $l]): ?><tr><td><?= (int) $l ?></td><td><?= h(['success' => 'sucesso', 'danger' => 'erro', 'warning' => 'atenção', 'info' => 'informação'][$tp] ?? $tp) ?></td><td><?= h($msg) ?></td></tr><?php endforeach; ?>
</tbody></table>
<?php endif; ?>
<?php if (!empty($p['fetch'])): ?>
<h4>Chamadas ao servidor sem recarregar (fetch)</h4>
<ul><?php foreach ($p['fetch'] as [$u, $l]): ?><li><code><?= h($u) ?></code> <span class="ln">linha <?= (int) $l ?></span></li><?php endforeach; ?></ul>
<?php endif; ?>
<?php if (!empty($p['js']) || !empty($p['jsArquivos'])): ?>
<h4>JavaScript</h4>
<?php if (!empty($p['jsArquivos'])): ?><p>Arquivos: <?= implode(', ', array_map(fn ($j) => '<a href="#' . idDe('j', $j) . '"><code>' . h($j) . '</code></a>', $p['jsArquivos'])) ?></p><?php endif; ?>
<?php if (!empty($p['js'])): ?><p>Funções escritas na própria página: <?= implode(', ', array_map(fn ($j) => '<code>' . h($j[0]) . '</code> <span class="ln">' . (int) $j[1] . '</span>', $p['js'])) ?></p><?php endif; ?>
<?php endif; ?>
<?php if ($funcsArq): ?>
<h4>Funções do sistema que usa</h4>
<p><?php
$itens = [];
foreach ($funcsArq as $fn => $ls) {
    $itens[] = linkFuncao($fn) . ' <span class="ln">' . implode(', ', array_slice(array_unique($ls), 0, 6)) . '</span>';
}
echo implode(' · ', $itens);
?></p>
<?php endif; ?>
<?php if (!empty($tabelasPorArquivo[$rel])): ?>
<h4>Tabelas do banco</h4>
<p><?php
$itens = [];
foreach ($tabelasPorArquivo[$rel] as $t => $ops) {
    $itens[] = '<a href="#' . idDe('t', $t) . '"><code>' . h($t) . '</code></a> (' . implode(', ', array_map(fn ($op, $ls) => $op . ' na linha ' . implode(', ', array_slice(array_unique($ls), 0, 5)), array_keys($ops), $ops)) . ')';
}
echo implode(' · ', $itens);
?></p>
<?php endif; ?>
<?php if (!empty($p['auditoria'])): ?>
<p><strong>Grava na auditoria:</strong> <?= implode(', ', array_map(fn ($a) => '<code>' . h($a[0]) . '</code> <span class="ln">' . (int) $a[1] . '</span>', $p['auditoria'])) ?></p>
<?php endif; ?>
<?php if (!empty($p['links'])): ?>
<h4>Leva para (links, envios e redirecionamentos)</h4>
<p><?= implode(' · ', array_map(fn ($alvo, $ls) => linkArquivo('public/' . $alvo, $ls), array_keys($p['links']), $p['links'])) ?></p>
<?php endif; ?>
<?php if (!empty($chamadoPor[$url])): ?>
<h4>É chamado por</h4>
<p><?= implode(' · ', array_map(fn ($de, $ls) => linkArquivo($de, $ls), array_keys($chamadoPor[$url]), $chamadoPor[$url])) ?></p>
<?php endif; ?>
</details>
<?php endforeach; ?>
<?php endforeach; ?>

<h2 id="funcoes">2. Funções PHP (pasta includes/ e outras)</h2>
<p>Cada arquivo de <code>includes/</code> com a explicação do topo e todas as funções dele: o que fazem (comentário escrito acima delas no código), onde estão e quem usa. Mudar uma função muda todas as telas da lista "Usada em".</p>
<?php
$porArquivo = [];
foreach ($funcoes as $nome => $f) {
    $porArquivo[$f['arquivo']][] = $f;
}
ksort($porArquivo);
foreach ($arquivosIncludes as $c) {
    $porArquivo[relativo($c)] ??= [];
}
ksort($porArquivo);
foreach ($porArquivo as $arq => $lista):
    $base = basename($arq);
    $comentTopo = '';
    $cods = $conteudo[$GLOBALS['raiz'] . '/' . $arq] ?? '';
    $cms = comentariosPhp($cods);
    if ($cms && $cms[0]['linha'] <= 12) {
        $comentTopo = $cms[0]['texto'];
    }
    $usadoPor = [];
    foreach (array_merge($arquivosPublic, $arquivosIncludes) as $c2) {
        if (relativo($c2) !== $arq && preg_match("/(?:require|include)(?:_once)?[^;]*'[^']*\/" . preg_quote($base, '/') . "'/", $conteudo[$c2])) {
            $usadoPor[] = relativo($c2);
        }
    }
?>
<details class="item" id="<?= h(str_starts_with($arq, 'includes/') ? idDe('i', $base) : idDe('o', $arq)) ?>">
<summary><code><?= h($arq) ?></code> <span class="sub">· <?= count($lista) ?> função(ões)<?= $comentTopo ? ' · ' . h(mb_substr(strtok($comentTopo, "\n"), 0, 110)) : '' ?></span></summary>
<?php if ($comentTopo): ?><div class="nota"><?= paragrafos($comentTopo) ?></div><?php endif; ?>
<?php if ($usadoPor): ?><p><strong>Carregado diretamente por:</strong> <?= implode(', ', array_map('linkArquivo', $usadoPor)) ?></p><?php endif; ?>
<?php foreach ($lista as $f): ?>
<div id="<?= h(idDe('f', $f['nome'])) ?>" class="item" style="border-top:1px solid var(--line);padding-top:8px;margin-top:8px">
<p><strong><code><?= h($f['nome']) ?><?= h($f['assinatura']) ?></code></strong> <span class="ln">linha <?= (int) $f['linha'] ?></span></p>
<?php if ($f['comentario']): ?><div class="nota"><?= paragrafos($f['comentario']) ?></div><?php endif; ?>
<?php if (!empty($chavesDaFuncao[$f['nome']])): ?><p>Confere a(s) permissão(ões): <?= implode(', ', array_map(fn ($k) => '<a href="#' . idDe('k', $k) . '"><code>' . h($k) . '</code></a>', $chavesDaFuncao[$f['nome']])) ?></p><?php endif; ?>
<?php $us = $usadaEm[$f['nome']] ?? []; unset($us[$arq]); ?>
<?php if ($us): ?><p class="sub">Usada em (<?= count($us) ?>): <?= implode(' · ', array_map(fn ($de, $ls) => linkArquivo($de, $ls), array_keys($us), $us)) ?></p><?php else: ?><p class="sub">Usada só dentro do próprio arquivo.</p><?php endif; ?>
</div>
<?php endforeach; ?>
</details>
<?php endforeach; ?>

<h2 id="js">3. JavaScript (public/assets/js/)</h2>
<p>O código que roda no navegador. Os arquivos carregados no <code>layout_footer.php</code> valem para todas as telas internas.</p>
<?php foreach ($jsAssets as $nome => $j): ?>
<details class="item" id="<?= h(idDe('j', $nome)) ?>">
<summary><code><?= h($nome) ?></code> <span class="sub">· <?= (int) $j['linhas'] ?> linhas<?= $j['comentario'] ? ' · ' . h(mb_substr(strtok($j['comentario'], "\n"), 0, 110)) : '' ?></span></summary>
<?php if ($j['comentario']): ?><div class="nota"><?= paragrafos($j['comentario']) ?></div><?php endif; ?>
<?php if ($j['funcoes']): ?><p>Funções: <?= implode(', ', array_map(fn ($f) => '<code>' . h($f[0]) . '</code> <span class="ln">' . (int) $f[1] . '</span>', $j['funcoes'])) ?></p><?php endif; ?>
<p class="sub">Carregado em: <?= $j['carregado'] ? implode(', ', array_map('linkArquivo', $j['carregado'])) : '(nenhum lugar encontrado)' ?></p>
</details>
<?php endforeach; ?>
<p class="sub">Visual: <code>public/assets/css/app.css</code> (sistema), <code>portal.css</code> (portal), <code>relatorio.css</code> (impressões), <code>certificado.css</code>, <code>aviso-arte.css</code>.</p>

<h2 id="banco">4. Banco de dados (sql/schema.sql)</h2>
<p>Cada tabela com as colunas, as ligações com outras tabelas e os arquivos que leem ou gravam nela. "Impede excluir" quer dizer que o banco não deixa apagar a linha da outra tabela enquanto esta apontar para ela.</p>
<?php ksort($tabelas); foreach ($tabelas as $nome => $t): ?>
<details class="item" id="<?= h(idDe('t', $nome)) ?>">
<summary><code><?= h($nome) ?></code> <span class="sub">· <?= count($t['colunas']) ?> colunas<?= $t['comentario'] ? ' · ' . h(mb_substr(strtok($t['comentario'], "\n"), 0, 110)) : '' ?></span></summary>
<?php if ($t['comentario']): ?><div class="nota"><?= paragrafos($t['comentario']) ?></div><?php endif; ?>
<p class="sub">Definição: <code>sql/schema.sql</code> linha <?= (int) $t['linha'] ?></p>
<table><thead><tr><th>Coluna</th><th>Tipo</th><th>Ligada a</th><th>Observação</th></tr></thead><tbody>
<?php foreach ($t['colunas'] as $col): ?><tr><td><code><?= h($col['nome']) ?></code></td><td><?= h($col['tipo']) ?></td><td><?= isset($col['liga']) ? '<a href="#' . idDe('t', strtok($col['liga'], ' ')) . '">' . h($col['liga']) . '</a>' : '' ?></td><td><?= h($col['nota']) ?></td></tr><?php endforeach; ?>
</tbody></table>
<?php $us = $tabelaUsadaEm[$nome] ?? []; ?>
<?php if ($us): ?>
<table><thead><tr><th>Arquivo</th><th>Lê</th><th>Grava</th></tr></thead><tbody>
<?php foreach ($us as $arq => $ops): ?><tr><td><?= linkArquivo($arq) ?></td><td class="ln"><?= isset($ops['lê']) ? implode(', ', array_slice(array_unique($ops['lê']), 0, 10)) : '' ?></td><td class="ln"><?= isset($ops['grava']) ? implode(', ', array_slice(array_unique($ops['grava']), 0, 10)) : '' ?></td></tr><?php endforeach; ?>
</tbody></table>
<?php else: ?><p class="sub">Nenhum arquivo encontrado usando esta tabela pelo nome.</p><?php endif; ?>
</details>
<?php endforeach; ?>

<h2 id="permissoes">5. Permissões</h2>
<p>Cada permissão que os perfis podem receber e todos os lugares do código onde ela trava algo (diretamente ou por uma função de <code>includes/auth.php</code>). O administrador passa por todas. As permissões só valem depois que a pessoa sai e entra de novo.</p>
<?php ksort($permissoes); foreach ($permissoes as $k => $desc): ?>
<details class="item" id="<?= h(idDe('k', $k)) ?>">
<summary><code><?= h($k) ?></code> <span class="sub">· <?= h($desc) ?></span></summary>
<?php
$funcsQue = array_keys(array_filter($chavesDaFuncao, fn ($ks) => in_array($k, $ks, true)));
if ($funcsQue): ?><p>Conferida pelas funções: <?= implode(', ', array_map('linkFuncao', $funcsQue)) ?></p><?php endif; ?>
<?php $us = $permissaoUsadaEm[$k] ?? []; ksort($us); ?>
<?php if ($us): ?><p>Trava em (<?= count($us) ?> arquivos): <?= implode(' · ', array_map(fn ($de, $ls) => linkArquivo($de, $ls), array_keys($us), $us)) ?></p><?php else: ?><p class="sub">Não encontrada no código.</p><?php endif; ?>
</details>
<?php endforeach; ?>
<details class="item" id="k-administrador"><summary>Só administrador <span class="sub">· não dá para liberar por perfil</span></summary>
<p>Arquivos que usam <code>exigirAdmin()</code>: <?php
$adm = [];
foreach ($arquivosPublic as $c) {
    if (str_contains($conteudo[$c], 'exigirAdmin()')) {
        $adm[] = linkArquivo(relativo($c));
    }
}
echo implode(' · ', $adm);
?></p>
<p>Arquivos que mostram algo a mais para o administrador (<code>usuarioEhAdmin()</code>): <?= implode(' · ', array_map(fn ($de, $ls) => linkArquivo($de, $ls), array_keys($usadaEm['usuarioEhAdmin'] ?? []), $usadaEm['usuarioEhAdmin'] ?? [])) ?></p>
</details>

<h2 id="rotinas">6. Rotinas automáticas (cron)</h2>
<p>Em <code>scripts/rotinas.php</code>, chamadas pelo agendador do servidor como usuário <code>www-data</code>:</p>
<ul><?php foreach ($rotinas as $r): ?><li><code>php /var/www/uniphd/scripts/rotinas.php <?= h($r) ?></code></li><?php endforeach; ?></ul>
<?php foreach (comentariosPhp($conteudo[$raiz . '/scripts/rotinas.php']) as $cm): ?><div class="nota"><span class="ln">linha <?= (int) $cm['linha'] ?></span><?= paragrafos($cm['texto']) ?></div><?php endforeach; ?>

<h2>7. Menu do sistema</h2>
<table><thead><tr><th>Menu</th><th>Tela</th></tr></thead><tbody>
<?php foreach ($menu as $url => $rot): ?><tr><td><?= h($rot) ?></td><td><?= linkArquivo('public/' . $url) ?></td></tr><?php endforeach; ?>
</tbody></table>
</main>
<script>
// Busca: esconde o que não combina e abre o que combina.
(function () {
    const campo = document.getElementById('busca');
    const itens = Array.from(document.querySelectorAll('details.item'));
    const textos = itens.map((d) => d.textContent.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''));
    let espera;
    campo.addEventListener('input', () => {
        clearTimeout(espera);
        espera = setTimeout(() => {
            const termo = campo.value.trim().toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
            itens.forEach((d, i) => {
                const ok = !termo || textos[i].includes(termo);
                d.classList.toggle('oculto', !ok);
                d.open = !!termo && ok && termo.length > 2;
            });
        }, 200);
    });
    // Link para um item fechado: abre ele (e o pai).
    function abrirAlvo() {
        const alvo = location.hash && document.getElementById(decodeURIComponent(location.hash.slice(1)));
        if (!alvo) return;
        let el = alvo;
        while (el) {
            if (el.tagName === 'DETAILS') el.open = true;
            el = el.parentElement;
        }
        alvo.scrollIntoView();
    }
    window.addEventListener('hashchange', abrirAlvo);
    abrirAlvo();
})();
</script>
</body>
</html>
<?php
$html = ob_get_clean();
file_put_contents($saida, $html);
echo 'Manual gerado: ' . relativo($saida) . ' (' . number_format(strlen($html) / 1024, 0, ',', '.') . " KB)\n";
