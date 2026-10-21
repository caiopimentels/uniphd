<?php

// Ordenação por coluna, paginação e "registros por página" das listas
// (alunos, turmas, cursos, professores, contas a receber e a pagar).
//
// A tela busca tudo o que o filtro pede (os totais do painel lateral contam
// a lista inteira), ordena e só então mostra a página atual:
//
//   $lista = estadoListagem('alunos', ['nome' => 'nome', 'ra' => 'ra', ...]);
//   $alunos = ordenarLinhas($alunos, $lista);
//   $alunosPagina = paginarLinhas($alunos, $lista);
//   no cabeçalho da tabela: echo thOrdenavel('Nome', 'nome', $lista);
//   embaixo da tabela:      echo rodapePaginacao($lista);
//
// Coluna, direção e quantidade por página ficam lembradas na sessão (por
// tela); a página atual vem só da URL.

const OPCOES_POR_PAGINA = [10, 25, 50, 100, 0]; // 0 = todos
const POR_PAGINA_PADRAO = 25;

/**
 * $colunas: chave da URL => campo da linha ou função que recebe a linha e
 * devolve o valor a comparar. Sem coluna escolhida, fica a ordem da
 * consulta (a padrão da tela).
 */
function estadoListagem(string $tela, array $colunas): array
{
    $salvo = $_SESSION['listagem'][$tela] ?? ['ordem' => '', 'dir' => 'asc', 'por_pagina' => POR_PAGINA_PADRAO];
    if (isset($_GET['ordem'])) {
        $salvo['ordem'] = array_key_exists((string) $_GET['ordem'], $colunas) ? (string) $_GET['ordem'] : '';
        $salvo['dir'] = ($_GET['dir'] ?? '') === 'desc' ? 'desc' : 'asc';
    }
    if (isset($_GET['por_pagina']) && in_array((int) $_GET['por_pagina'], OPCOES_POR_PAGINA, true)) {
        $salvo['por_pagina'] = (int) $_GET['por_pagina'];
    }
    if (!array_key_exists($salvo['ordem'], $colunas)) {
        $salvo['ordem'] = '';
    }
    $_SESSION['listagem'][$tela] = $salvo;

    return $salvo + [
        'colunas' => $colunas,
        'pagina' => max(1, (int) ($_GET['pagina'] ?? 1)),
        'total' => 0,
        'paginas' => 1,
    ];
}

// Texto comparável: sem acento e sem diferença de maiúscula.
function chaveOrdenacaoTexto(string $texto): string
{
    $texto = mb_strtolower($texto, 'UTF-8');
    return strtr($texto, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'é' => 'e', 'ê' => 'e', 'è' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ò' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n']);
}

// Ordena pela coluna escolhida; vazios sempre no fim.
function ordenarLinhas(array $linhas, array $lista): array
{
    if ($lista['ordem'] === '') {
        return $linhas;
    }
    $campo = $lista['colunas'][$lista['ordem']];
    $valor = is_callable($campo) ? $campo : fn ($linha) => $linha[$campo] ?? null;
    $sinal = $lista['dir'] === 'desc' ? -1 : 1;
    usort($linhas, function ($a, $b) use ($valor, $sinal) {
        $va = $valor($a);
        $vb = $valor($b);
        $vazioA = $va === null || $va === '';
        $vazioB = $vb === null || $vb === '';
        if ($vazioA || $vazioB) {
            return $vazioA <=> $vazioB;
        }
        $comparacao = is_numeric($va) && is_numeric($vb)
            ? (float) $va <=> (float) $vb
            : strcmp(chaveOrdenacaoTexto((string) $va), chaveOrdenacaoTexto((string) $vb));
        return $comparacao * $sinal;
    });
    return $linhas;
}

// Recorta a página atual e completa $lista com total e número de páginas.
function paginarLinhas(array $linhas, array &$lista): array
{
    $lista['total'] = count($linhas);
    if ($lista['por_pagina'] === 0) {
        $lista['pagina'] = 1;
        $lista['paginas'] = 1;
        return $linhas;
    }
    $lista['paginas'] = max(1, (int) ceil($lista['total'] / $lista['por_pagina']));
    $lista['pagina'] = min($lista['pagina'], $lista['paginas']);
    return array_slice($linhas, ($lista['pagina'] - 1) * $lista['por_pagina'], $lista['por_pagina']);
}

// Link da própria tela trocando só alguns parâmetros (mantém busca/filtros da URL).
function urlListagem(array $trocar): string
{
    $parametros = array_merge($_GET, $trocar);
    return '?' . http_build_query(array_filter($parametros, fn ($v) => $v !== null));
}

// Cabeçalho clicável: 1º clique crescente, 2º decrescente.
function thOrdenavel(string $rotulo, string $coluna, array $lista, string $classe = ''): string
{
    $ativa = $lista['ordem'] === $coluna;
    $proxima = $ativa && $lista['dir'] === 'asc' ? 'desc' : 'asc';
    $icone = $ativa ? ($lista['dir'] === 'asc' ? 'bi-caret-up-fill' : 'bi-caret-down-fill') : 'bi-chevron-expand';
    return '<th class="' . e(trim($classe . ' th-ordenavel' . ($ativa ? ' ativa' : ''))) . '">'
        . '<a href="' . e(urlListagem(['ordem' => $coluna, 'dir' => $proxima, 'pagina' => null])) . '" title="Ordenar por ' . e(mb_strtolower($rotulo, 'UTF-8')) . '">'
        . e($rotulo) . ' <i class="bi ' . $icone . '"></i></a></th>';
}

// Rodapé: "1–25 de 120", páginas e quantos por página.
function rodapePaginacao(array $lista): string
{
    if ($lista['total'] === 0) {
        return '';
    }
    $inicio = $lista['por_pagina'] === 0 ? 1 : ($lista['pagina'] - 1) * $lista['por_pagina'] + 1;
    $fim = $lista['por_pagina'] === 0 ? $lista['total'] : min($lista['total'], $lista['pagina'] * $lista['por_pagina']);

    $paginas = '';
    if ($lista['paginas'] > 1) {
        $item = fn (int $p, string $texto, bool $desabilitado = false) => '<li class="page-item' . ($p === $lista['pagina'] && !$desabilitado ? ' active' : '') . ($desabilitado ? ' disabled' : '') . '">'
            . '<a class="page-link" href="' . e(urlListagem(['pagina' => $p])) . '">' . $texto . '</a></li>';
        $paginas .= $item(max(1, $lista['pagina'] - 1), '<i class="bi bi-chevron-left"></i>', $lista['pagina'] === 1);
        $anterior = 0;
        for ($p = 1; $p <= $lista['paginas']; $p++) {
            // Primeira, última e as vizinhas da atual; o resto vira "…".
            if ($p !== 1 && $p !== $lista['paginas'] && abs($p - $lista['pagina']) > 2) {
                continue;
            }
            if ($anterior && $p - $anterior > 1) {
                $paginas .= '<li class="page-item disabled"><span class="page-link">…</span></li>';
            }
            $paginas .= $item($p, (string) $p);
            $anterior = $p;
        }
        $paginas .= $item(min($lista['paginas'], $lista['pagina'] + 1), '<i class="bi bi-chevron-right"></i>', $lista['pagina'] === $lista['paginas']);
        $paginas = '<ul class="pagination pagination-sm mb-0">' . $paginas . '</ul>';
    }

    $opcoes = '';
    foreach (OPCOES_POR_PAGINA as $n) {
        $opcoes .= '<option value="' . e(urlListagem(['por_pagina' => $n, 'pagina' => null])) . '"' . ($n === $lista['por_pagina'] ? ' selected' : '') . '>' . ($n === 0 ? 'Todos' : $n) . '</option>';
    }

    return '<div class="rodape-paginacao">'
        . '<span class="text-muted small">' . $inicio . '–' . $fim . ' de ' . $lista['total'] . '</span>'
        . $paginas
        . '<label class="small text-muted d-flex align-items-center gap-2 mb-0">Por página'
        . '<select class="form-select form-select-sm" style="width:auto" onchange="window.location = this.value">' . $opcoes . '</select></label>'
        . '</div>';
}
