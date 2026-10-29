<?php

// Contratos: modelos em texto (editor tipo Word, em Portal › Documentos (modelos))
// com variáveis como {aluno_nome}, preenchidas com os dados da matrícula.
// O contrato sai impresso na matrícula e, se a turma permitir
// (turmas.contrato_portal), aparece para o aluno no portal, em Documentos.
// Cada curso escolhe o modelo (cursos.contrato_modelo_id); sem escolha, o padrão.

require_once __DIR__ . '/documento.php';

/** Variáveis do contrato, por grupo: grupo => [variável => descrição]. */
function variaveisContrato(): array
{
    return [
        'Aluno' => [
            '{aluno_nome}' => 'Nome do aluno',
            '{aluno_cpf}' => 'CPF',
            '{aluno_rg}' => 'RG',
            '{aluno_nascimento}' => 'Data de nascimento',
            '{aluno_endereco}' => 'Endereço completo (rua, nº, bairro, cidade/UF, CEP)',
            '{aluno_telefone}' => 'Telefone',
            '{aluno_email}' => 'E-mail',
            '{aluno_ra}' => 'RA',
            '{aluno_mae}' => 'Nome da mãe',
            '{aluno_pai}' => 'Nome do pai',
        ],
        'Curso e turma' => [
            '{curso}' => 'Nome do curso',
            '{turma}' => 'Nome da turma',
            '{carga_horaria}' => 'Carga horária (horas)',
            '{periodo}' => 'Período da turma (início a término)',
            '{data_inicio}' => 'Início da turma',
            '{data_termino}' => 'Término da turma',
            '{horario}' => 'Dias e horário das aulas',
            '{local}' => 'Local das aulas',
            '{professor}' => 'Professor(a)',
        ],
        'Valores' => [
            '{valor_curso}' => 'Valor do curso (cheio)',
            '{valor_curso_extenso}' => 'Valor do curso por extenso',
            '{desconto}' => 'Desconto no curso (cupom + bolsa)',
            '{valor_final}' => 'Valor do curso com desconto',
            '{valor_final_extenso}' => 'Valor com desconto por extenso',
            '{num_parcelas}' => 'Número de mensalidades',
            '{valor_parcela}' => 'Valor da mensalidade',
            '{primeiro_vencimento}' => '1º vencimento',
            '{dia_vencimento}' => 'Dia do vencimento (ex.: 10)',
            '{taxa_matricula}' => 'Taxa de matrícula',
            '{forma_pagamento}' => 'Forma de pagamento das mensalidades',
            '{forma_pagamento_matricula}' => 'Forma de pagamento da matrícula',
            '{cupom}' => 'Cupom usado (em branco se não tiver)',
            '{tabela_parcelas}' => 'Tabela com todas as parcelas',
        ],
        'Matrícula e empresa' => [
            '{numero_matricula}' => 'Número da matrícula',
            '{data_matricula}' => 'Data da matrícula',
            '{data_hoje}' => 'Data de hoje (por extenso)',
            '{empresa}' => 'Nome da instituição',
            '{empresa_razao_social}' => 'Razão social',
            '{empresa_cnpj}' => 'CNPJ',
            '{empresa_endereco}' => 'Endereço da instituição',
            '{cidade}' => 'Cidade e estado da instituição',
        ],
    ];
}

function modelosContrato(PDO $pdo): array
{
    return $pdo->query(
        'SELECT cm.*, (SELECT GROUP_CONCAT(c.nome ORDER BY c.nome SEPARATOR ", ") FROM cursos c WHERE c.contrato_modelo_id = cm.id) AS cursos
         FROM contrato_modelos cm ORDER BY cm.padrao DESC, cm.nome'
    )->fetchAll();
}

function buscarModeloContrato(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM contrato_modelos WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

// Modelo da matrícula: o do curso ou, sem escolha, o padrão.
function modeloContratoDoCurso(PDO $pdo, ?int $modeloCursoId): ?array
{
    if ($modeloCursoId && ($modelo = buscarModeloContrato($pdo, $modeloCursoId))) {
        return $modelo;
    }
    return $pdo->query('SELECT * FROM contrato_modelos WHERE padrao = 1 ORDER BY id LIMIT 1')->fetch() ?: null;
}

/**
 * Limpa o HTML do editor: só formatação de texto (parágrafos, negrito,
 * listas, títulos, tabelas simples, alinhamento). Tira script, eventos
 * (onclick...), estilos e links que não sejam http(s) — o contrato aparece
 * no portal para o aluno.
 */
function limparHtmlContrato(string $html, bool $comImagens = false): string
{
    if (trim($html) === '') {
        return '';
    }
    $permitidas = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'h1', 'h2', 'h3', 'h4', 'ol', 'ul', 'li', 'span', 'a',
        'blockquote', 'sub', 'sup', 'div', 'table', 'thead', 'tbody', 'tr', 'td', 'th', 'hr'];
    // Avisos aceitam imagens: só as enviadas ao sistema ou de endereço https.
    if ($comImagens) {
        $permitidas[] = 'img';
    }
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8"?><div id="raiz">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    $raiz = $doc->getElementById('raiz');
    if (!$raiz) {
        return '';
    }
    $limpar = function (DOMNode $no) use (&$limpar, $permitidas, $comImagens): void {
        foreach (iterator_to_array($no->childNodes) as $filho) {
            if ($filho instanceof DOMElement) {
                $tag = strtolower($filho->tagName);
                if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'textarea', 'select'], true)) {
                    $no->removeChild($filho);
                    continue;
                }
                $limpar($filho);
                if (!in_array($tag, $permitidas, true)) {
                    // Tag desconhecida: fica só o conteúdo dela.
                    while ($filho->firstChild) {
                        $no->insertBefore($filho->firstChild, $filho);
                    }
                    $no->removeChild($filho);
                    continue;
                }
                foreach (iterator_to_array($filho->attributes) as $atributo) {
                    $nome = strtolower($atributo->name);
                    $valor = (string) $atributo->value;
                    if ($nome === 'style') {
                        // Do style, só a cor do texto e a de fundo (marca-texto).
                        $estilo = estiloCorContrato($valor);
                        if ($estilo !== '') {
                            $filho->setAttribute('style', $estilo);
                            continue;
                        }
                    }
                    $manter = ($nome === 'class' && preg_match('/^(ql-[a-z0-9-]+\s*)+$/', $valor))
                        || ($nome === 'href' && $tag === 'a' && preg_match('#^https?://#i', $valor))
                        || (in_array($nome, ['colspan', 'rowspan'], true) && ctype_digit($valor))
                        || ($comImagens && $tag === 'img' && $nome === 'src' && preg_match('#^(/uploads/portal/[\w.-]+|https://[^\s"<>]+)$#i', $valor))
                        || ($comImagens && $tag === 'img' && $nome === 'alt');
                    if (!$manter) {
                        $filho->removeAttribute($atributo->name);
                    }
                }
                if ($tag === 'img' && !$filho->hasAttribute('src')) {
                    $no->removeChild($filho);
                    continue;
                }
                if ($tag === 'a') {
                    $filho->setAttribute('target', '_blank');
                    $filho->setAttribute('rel', 'noopener');
                }
            } elseif ($filho instanceof DOMComment) {
                $no->removeChild($filho);
            }
        }
    };
    $limpar($raiz);
    $saida = '';
    foreach ($raiz->childNodes as $filho) {
        $saida .= $doc->saveHTML($filho);
    }
    return $saida;
}

// Do style ficam só: cores (#hex ou rgb()) e formatação simples de texto
// (fonte, tamanho, alinhamento, negrito, itálico, sublinhado). O resto sai.
function estiloCorContrato(string $style): string
{
    $mantidas = [];
    foreach (explode(';', $style) as $declaracao) {
        if (preg_match('/^\s*(color|background-color)\s*:\s*(#[0-9a-fA-F]{3,6}|rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*(,\s*[0-9.]+\s*)?\))\s*$/', $declaracao, $m)) {
            $mantidas[] = strtolower($m[1]) . ': ' . $m[2];
        }
        // Formatação de texto em estilo embutido (editor do e-mail e assinatura).
        elseif (preg_match('/^\s*(font-family)\s*:\s*([A-Za-z0-9 ,\'"-]{1,80})\s*$/', $declaracao, $m)
            || preg_match('/^\s*(font-size)\s*:\s*(\d{1,3}(?:\.\d+)?(?:px|pt|em|%))\s*$/', $declaracao, $m)
            || preg_match('/^\s*(text-align)\s*:\s*(left|center|right|justify)\s*$/i', $declaracao, $m)
            || preg_match('/^\s*(font-weight)\s*:\s*(bold|normal|[1-9]00)\s*$/i', $declaracao, $m)
            || preg_match('/^\s*(font-style)\s*:\s*(italic|normal)\s*$/i', $declaracao, $m)
            || preg_match('/^\s*(text-decoration)\s*:\s*(underline|line-through|none)\s*$/i', $declaracao, $m)) {
            $mantidas[] = strtolower($m[1]) . ': ' . $m[2];
        }
    }
    return implode('; ', $mantidas);
}

/** Distância entre linhas: chave (classe ql-entrelinha-*) => [rótulo, line-height]. */
function entrelinhasContrato(): array
{
    return ['1' => ['Simples (1,0)', '1.15'], '1-15' => ['1,15', '1.3'], '1-5' => ['1,5', '1.6'], '2' => ['Dupla (2,0)', '2.1'], '2-5' => ['2,5', '2.6'], '3' => ['3,0', '3.1']];
}

/** Espaço depois do parágrafo: chave (classe ql-espaco-*) => [rótulo, margem]. */
function espacosParagrafoContrato(): array
{
    return ['0' => ['Nenhum', '0'], '3' => ['Pequeno (3 pt)', '3pt'], '12' => ['Grande (12 pt)', '12pt']];
}

/** Fontes do editor: chave (classe ql-font-*) => [nome, família CSS]. */
function fontesContrato(): array
{
    // Com alternativas que existem no Linux do servidor (Liberation/DejaVu),
    // do mesmo tamanho das originais, para o PDF sair igual.
    return [
        'arial' => ['Arial', "Arial, 'Liberation Sans', Helvetica, sans-serif"],
        'times' => ['Times New Roman', "'Times New Roman', 'Liberation Serif', Times, serif"],
        'georgia' => ['Georgia', "Georgia, 'DejaVu Serif', serif"],
        'verdana' => ['Verdana', "Verdana, 'DejaVu Sans', sans-serif"],
        'tahoma' => ['Tahoma', "Tahoma, 'DejaVu Sans', sans-serif"],
        'trebuchet' => ['Trebuchet MS', "'Trebuchet MS', 'DejaVu Sans', sans-serif"],
        'courier' => ['Courier New', "'Courier New', 'Liberation Mono', Courier, monospace"],
    ];
}

/** Valores das variáveis para uma matrícula (texto puro, exceto a tabela). */
function valoresContratoMatricula(PDO $pdo, int $matriculaId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT m.*, a.nome AS aluno_nome, a.cpf, a.rg, a.data_nascimento, a.telefone, a.email, a.ra, a.nome_mae, a.nome_pai,
                t.nome AS turma_nome, t.data_inicio, t.data_termino, t.minimo_alunos, t.duracao_meses, t.duracao_unidade,
                t.dias_semana, t.hora_inicio, t.hora_fim, t.agenda, t.horario, t.local, t.contrato_portal,
                c.id AS curso_id, c.nome AS curso_nome, c.carga_horaria, c.contrato_modelo_id, p.nome AS professor_nome,
                ft.nome AS forma_matricula_nome, fm.nome AS forma_mensalidade_nome, cp.codigo AS cupom_codigo, cp.tipo AS cupom_tipo
         FROM matriculas m
         JOIN alunos a ON a.id = m.aluno_id
         JOIN turmas t ON t.id = m.turma_id
         JOIN cursos c ON c.id = t.curso_id
         LEFT JOIN professores p ON p.id = t.professor_id
         LEFT JOIN formas_pagamento ft ON ft.id = m.forma_pagamento_id
         LEFT JOIN formas_pagamento fm ON fm.id = COALESCE(m.forma_mensalidade_id, m.forma_pagamento_id)
         LEFT JOIN cupons cp ON cp.id = m.cupom_id
         WHERE m.id = ?'
    );
    $stmt->execute([$matriculaId]);
    $m = $stmt->fetch();
    if (!$m) {
        return null;
    }
    $config = buscarConfiguracoes($pdo);
    $endereco = enderecoPrincipalAluno($pdo, (int) $m['aluno_id']);
    $stmt = $pdo->prepare("SELECT * FROM contas_receber WHERE matricula_id = ? AND status <> 'cancelado' ORDER BY data_vencimento, id");
    $stmt->execute([$matriculaId]);
    $parcelas = $stmt->fetchAll();

    // Curso: valor cheio (antes do cupom) e com desconto (cupom + bolsa).
    $cupomNoCurso = $m['cupom_tipo'] && $m['cupom_tipo'] !== 'matricula_zero';
    $totalGravado = $m['valor_total'] !== null ? (float) $m['valor_total'] : (float) $m['valor_mensalidade'] * max(1, (int) $m['num_parcelas']);
    $valorCheio = round($totalGravado + ($cupomNoCurso ? (float) $m['cupom_desconto'] : 0), 2);
    $valorFinal = round($totalGravado * (1 - (float) $m['desconto_percentual'] / 100), 2);
    $mensalidade = mensalidadeDoTotal($valorFinal, max(1, (int) $m['num_parcelas']));

    $tabela = '<table class="tabela-parcelas"><thead><tr><th>Parcela</th><th>Vencimento</th><th>Valor</th></tr></thead><tbody>';
    foreach ($parcelas as $p) {
        $tabela .= '<tr><td>' . e($p['descricao']) . '</td><td>' . formatDate($p['data_vencimento']) . '</td><td>' . formatMoney($p['valor']) . '</td></tr>';
    }
    $tabela .= '<tr><td colspan="2"><strong>Total</strong></td><td><strong>' . formatMoney(array_sum(array_column($parcelas, 'valor'))) . '</strong></td></tr></tbody></table>';

    $enderecoTexto = $endereco ? implode(', ', array_filter([$endereco['logradouro'], $endereco['numero'], $endereco['complemento'], $endereco['bairro']]))
        . ($endereco['cidade'] ? ' — ' . $endereco['cidade'] . ($endereco['uf'] ? '/' . $endereco['uf'] : '') : '')
        . ($endereco['cep'] ? ' — CEP ' . $endereco['cep'] : '') : '';
    $empresaEndereco = trim(implode(', ', array_filter([$config['empresa_logradouro'] ?? '', $config['empresa_numero'] ?? '', $config['empresa_bairro'] ?? ''])));

    return [
        'matricula' => $m,
        'valores' => [
            '{aluno_nome}' => $m['aluno_nome'],
            '{aluno_cpf}' => cpfFormatado($m['cpf']),
            '{aluno_rg}' => (string) $m['rg'],
            '{aluno_nascimento}' => $m['data_nascimento'] ? formatDate($m['data_nascimento']) : '',
            '{aluno_endereco}' => $enderecoTexto,
            '{aluno_telefone}' => telefoneFormatado($m['telefone']),
            '{aluno_email}' => (string) $m['email'],
            '{aluno_ra}' => (string) $m['ra'],
            '{aluno_mae}' => (string) $m['nome_mae'],
            '{aluno_pai}' => (string) $m['nome_pai'],
            '{curso}' => $m['curso_nome'],
            '{turma}' => $m['turma_nome'],
            '{carga_horaria}' => $m['carga_horaria'] ? (string) (int) $m['carga_horaria'] : '',
            '{periodo}' => periodoTurma($m, true),
            '{data_inicio}' => $m['data_inicio'] ? formatDate($m['data_inicio']) : 'a definir',
            '{data_termino}' => $m['data_termino'] ? formatDate($m['data_termino']) : 'a definir',
            '{horario}' => textoAgendaTurma($m),
            '{local}' => (string) $m['local'],
            '{professor}' => $m['professor_nome'] ?: 'a definir',
            '{valor_curso}' => formatMoney($valorCheio),
            '{valor_curso_extenso}' => valorPorExtenso($valorCheio),
            '{desconto}' => formatMoney(max(0, $valorCheio - $valorFinal)),
            '{valor_final}' => formatMoney($valorFinal),
            '{valor_final_extenso}' => valorPorExtenso($valorFinal),
            '{num_parcelas}' => (string) (int) $m['num_parcelas'],
            '{valor_parcela}' => formatMoney($mensalidade),
            '{primeiro_vencimento}' => formatDate($m['primeiro_vencimento']),
            '{dia_vencimento}' => (string) (int) date('j', strtotime($m['primeiro_vencimento'])),
            '{taxa_matricula}' => formatMoney($m['taxa_matricula']),
            '{forma_pagamento}' => (string) $m['forma_mensalidade_nome'],
            '{forma_pagamento_matricula}' => (string) $m['forma_matricula_nome'],
            '{cupom}' => $m['cupom_codigo'] ? $m['cupom_codigo'] . ' (' . ($m['cupom_tipo'] === 'matricula_zero' ? 'matrícula isenta' : formatMoney($m['cupom_desconto']) . ' de desconto') . ')' : '',
            '{tabela_parcelas}' => $tabela,
            '{numero_matricula}' => str_pad((string) $m['id'], 6, '0', STR_PAD_LEFT),
            '{data_matricula}' => formatDate($m['data_matricula']),
            '{data_hoje}' => dataPorExtenso(date('Y-m-d')),
            '{empresa}' => $config['empresa_nome_fantasia'] ?: ($config['empresa_razao_social'] ?: nomeSistema()),
            '{empresa_razao_social}' => (string) ($config['empresa_razao_social'] ?? ''),
            '{empresa_cnpj}' => cnpjFormatado($config['empresa_cnpj'] ?? ''),
            '{empresa_endereco}' => $empresaEndereco,
            '{cidade}' => cidadeCertificado($config),
        ],
    ];
}

/** Exemplo para a pré-visualização no editor. */
function valoresExemploContrato(PDO $pdo): array
{
    $config = buscarConfiguracoes($pdo);
    return [
        '{aluno_nome}' => 'Maria Aparecida da Silva Souza', '{aluno_cpf}' => '123.456.789-09', '{aluno_rg}' => '12.345.678',
        '{aluno_nascimento}' => '15/03/1995', '{aluno_endereco}' => 'Rua das Flores, 100, Centro — Guanambi/BA — CEP 46430-000',
        '{aluno_telefone}' => '(77) 99999-1234', '{aluno_email}' => 'maria@exemplo.com', '{aluno_ra}' => 'RA0000123',
        '{aluno_mae}' => 'Ana da Silva', '{aluno_pai}' => 'José de Souza',
        '{curso}' => 'Curso de Exemplo', '{turma}' => 'Turma 2026', '{carga_horaria}' => '120', '{periodo}' => '10/11/2026 a 10/05/2027',
        '{data_inicio}' => '10/11/2026', '{data_termino}' => '10/05/2027', '{horario}' => 'Sáb e Dom, 08:00 às 17:00', '{local}' => 'Sede',
        '{professor}' => 'Prof. Exemplo', '{valor_curso}' => 'R$ 5.000,00', '{valor_curso_extenso}' => 'cinco mil reais',
        '{desconto}' => 'R$ 500,00', '{valor_final}' => 'R$ 4.500,00', '{valor_final_extenso}' => 'quatro mil e quinhentos reais',
        '{num_parcelas}' => '6', '{valor_parcela}' => 'R$ 750,00', '{primeiro_vencimento}' => '10/11/2026', '{dia_vencimento}' => '10',
        '{taxa_matricula}' => 'R$ 300,00', '{forma_pagamento}' => 'Boleto + Pix', '{forma_pagamento_matricula}' => 'Pix',
        '{cupom}' => 'BEMVINDO10 (R$ 500,00 de desconto)',
        '{tabela_parcelas}' => '<table class="tabela-parcelas"><thead><tr><th>Parcela</th><th>Vencimento</th><th>Valor</th></tr></thead><tbody>'
            . '<tr><td>Taxa de matrícula</td><td>10/10/2026</td><td>R$ 300,00</td></tr><tr><td>Mensalidade 1/6</td><td>10/11/2026</td><td>R$ 750,00</td></tr>'
            . '<tr><td colspan="2">…</td><td></td></tr></tbody></table>',
        '{numero_matricula}' => '000123', '{data_matricula}' => date('d/m/Y'), '{data_hoje}' => dataPorExtenso(date('Y-m-d')),
        '{empresa}' => $config['empresa_nome_fantasia'] ?: ($config['empresa_razao_social'] ?: nomeSistema()),
        '{empresa_razao_social}' => (string) ($config['empresa_razao_social'] ?? ''), '{empresa_cnpj}' => cnpjFormatado($config['empresa_cnpj'] ?? ''),
        '{empresa_endereco}' => trim(implode(', ', array_filter([$config['empresa_logradouro'] ?? '', $config['empresa_numero'] ?? '', $config['empresa_bairro'] ?? '']))),
        '{cidade}' => cidadeCertificado($config),
    ];
}

/** Troca as variáveis pelos valores (escapados; a tabela já vem em HTML). */
function preencherContrato(string $conteudo, array $valores): string
{
    $troca = [];
    foreach ($valores as $variavel => $valor) {
        $troca[$variavel] = $variavel === '{tabela_parcelas}' ? $valor : e((string) $valor);
    }
    $html = strtr(limparHtmlContrato($conteudo), $troca);
    // A tabela sozinha num parágrafo vira <p><table>: tira o <p> em volta.
    return preg_replace('#<p>\s*(<table class="tabela-parcelas">.*?</table>)\s*</p>#s', '$1', $html);
}

// Estilo do texto do contrato (impressão, portal e editor): o mesmo do Word.
function cssContrato(): string
{
    // Formatação do editor (classes ql-*): vale no texto, no cabeçalho e no
    // rodapé, na tela e na impressão.
    $css = '';
    foreach (fontesContrato() as $chave => [, $familia]) {
        $css .= '.ql-font-' . $chave . ' { font-family: ' . $familia . '; }' . "\n";
    }
    foreach (entrelinhasContrato() as $chave => [, $altura]) {
        $css .= '.ql-entrelinha-' . $chave . ' { line-height: ' . $altura . ' !important; }' . "\n";
    }
    foreach (espacosParagrafoContrato() as $chave => [, $margem]) {
        $css .= '.ql-espaco-' . $chave . ' { margin-bottom: ' . $margem . ' !important; }' . "\n";
    }
    return $css . '.texto-contrato { font-family: ' . fontesContrato()['arial'][1] . '; font-size: 12.5px; line-height: 1.35; color: #111; }
/* Padrão: entrelinha 1,15 e 6 pt depois do parágrafo (como no Word). */
.texto-contrato p { margin: 0 0 6pt; }
.texto-contrato h1 { font-size: 17px; margin: .4em 0 .6em; } .texto-contrato h2 { font-size: 14.5px; margin: .8em 0 .4em; } .texto-contrato h3 { font-size: 13px; margin: .6em 0 .3em; }
/* Espaços repetidos e TAB ficam como foram digitados. */
.texto-contrato :is(p, h1, h2, h3, h4, li), .texto-area :is(p, h1, h2, h3, h4, li) { white-space: pre-wrap; }
.texto-area { line-height: 1.35; } .texto-area p { margin: 0; }
/* Linha em branco (Enter sem texto) ocupa uma linha, como no editor. */
.texto-contrato :is(p, h1, h2, h3, h4):empty::before, .texto-area :is(p, h1, h2, h3, h4):empty::before { content: "\00a0"; }
.ql-tab { display: inline-block; font-size: 0; white-space: nowrap; overflow: hidden; vertical-align: baseline; }
.ql-align-center { text-align: center; } .ql-align-right { text-align: right; } .ql-align-justify { text-align: justify; }
/* Mesmas medidas do editor (Quill), para a impressão sair igual ao que se vê. */
.ql-size-small { font-size: .75em; } .ql-size-large { font-size: 1.5em; } .ql-size-huge { font-size: 2.5em; }
.ql-indent-1 { padding-left: 3em; } .ql-indent-2 { padding-left: 6em; } .ql-indent-3 { padding-left: 9em; } .ql-indent-4 { padding-left: 12em; }
.texto-contrato ol, .texto-contrato ul, .texto-area ol, .texto-area ul { padding-left: 1.5em; margin: 0 0 6pt; }
.texto-contrato table.tabela-parcelas { width: 100%; border-collapse: collapse; margin: .5em 0 1em; font-size: 11.5px; }
.texto-contrato table.tabela-parcelas th, .texto-contrato table.tabela-parcelas td { border: 1px solid #bbb; padding: 3px 6px; text-align: left; }
.texto-contrato table.tabela-parcelas td:last-child, .texto-contrato table.tabela-parcelas th:last-child { text-align: right; }';
}

/** Texto inicial do contrato padrão (a escola revisa e ajusta). */
function textoContratoPadrao(): string
{
    return '<h1 class="ql-align-center">CONTRATO DE PRESTAÇÃO DE SERVIÇOS EDUCACIONAIS</h1>'
        . '<p class="ql-align-justify"><strong>CONTRATADA:</strong> {empresa_razao_social}, inscrita no CNPJ sob o nº {empresa_cnpj}, com sede em {empresa_endereco}, {cidade}.</p>'
        . '<p class="ql-align-justify"><strong>CONTRATANTE:</strong> {aluno_nome}, CPF {aluno_cpf}, RG {aluno_rg}, nascido(a) em {aluno_nascimento}, residente em {aluno_endereco}, telefone {aluno_telefone}, e-mail {aluno_email}.</p>'
        . '<h2>CLÁUSULA 1ª — DO OBJETO</h2>'
        . '<p class="ql-align-justify">O presente contrato tem por objeto a prestação de serviços educacionais referentes ao curso <strong>{curso}</strong>, turma {turma}, com carga horária de {carga_horaria} horas, no período de {periodo}, {horario}.</p>'
        . '<h2>CLÁUSULA 2ª — DO VALOR E DA FORMA DE PAGAMENTO</h2>'
        . '<p class="ql-align-justify">Pelo curso, o(a) CONTRATANTE pagará o valor de {valor_final} ({valor_final_extenso}), em {num_parcelas} mensalidade(s) de {valor_parcela}, com vencimento todo dia {dia_vencimento}, a partir de {primeiro_vencimento}, por {forma_pagamento}, além da taxa de matrícula de {taxa_matricula}.</p>'
        . '<p>{tabela_parcelas}</p>'
        . '<h2>CLÁUSULA 3ª — DAS DISPOSIÇÕES GERAIS</h2>'
        . '<p class="ql-align-justify">(Escreva aqui as regras da instituição: frequência, atraso, cancelamento, reposição de aulas, certificado etc.)</p>'
        . '<p class="ql-align-justify">E, por estarem de acordo, as partes assinam o presente contrato.</p>'
        . '<p class="ql-align-right">{cidade}, {data_hoje}.</p>';
}

// Cria o contrato padrão na primeira vez (para a escola só ajustar).
function garantirContratoPadrao(PDO $pdo): void
{
    if (!(int) $pdo->query('SELECT COUNT(*) FROM contrato_modelos')->fetchColumn()) {
        $pdo->prepare("INSERT INTO contrato_modelos (nome, conteudo, padrao) VALUES ('Contrato padrão', ?, 1)")->execute([textoContratoPadrao()]);
    }
}

/* ---------------- Página: margens, cabeçalho e rodapé ---------------- */

function cabecalhosContrato(): array
{
    return ['instituicao' => 'Dados da instituição (logo, nome, CNPJ e endereço)', 'personalizado' => 'Texto próprio', 'nenhum' => 'Sem cabeçalho'];
}

/** Configuração de página do modelo, com os padrões preenchidos. */
function paginaContrato(?string $json): array
{
    $dados = json_decode((string) $json, true) ?: [];
    $margem = fn (string $lado) => max(0.5, min(5.0, round((float) ($dados['margens'][$lado] ?? 2), 1)));
    return [
        'margens' => ['superior' => $margem('superior'), 'inferior' => $margem('inferior'), 'esquerda' => $margem('esquerda'), 'direita' => $margem('direita')],
        'cabecalho' => array_key_exists($dados['cabecalho'] ?? '', cabecalhosContrato()) ? $dados['cabecalho'] : 'instituicao',
        'cabecalho_html' => limparHtmlContrato((string) ($dados['cabecalho_html'] ?? '')),
        'rodape_html' => limparHtmlContrato((string) ($dados['rodape_html'] ?? '')),
        'numero_pagina' => (int) ($dados['numero_pagina'] ?? 1) ? 1 : 0,
        'imagens' => imagensContrato($dados['imagens'] ?? []),
        // Marcas de tabulação da régua, em cm a partir da margem esquerda.
        'tabulacoes' => array_values(array_unique(array_map(fn ($cm) => round(max(0.25, min(19, (float) $cm)), 2),
            array_slice(array_filter((array) ($dados['tabulacoes'] ?? []), 'is_numeric'), 0, 30)))),
    ];
}

/**
 * Imagens soltas na folha, por cima do texto (logo, assinatura, carimbo):
 * posição e largura em mm a partir do canto da folha; "todas" = repete em
 * todas as páginas (senão, só na 1ª).
 */
function imagensContrato($imagens): array
{
    $limpas = [];
    foreach (is_array($imagens) ? array_slice($imagens, 0, 20) : [] as $img) {
        if (!is_array($img) || !imagemUploadValida($img['url'] ?? null)) {
            continue;
        }
        $limpas[] = [
            'url' => (string) $img['url'],
            'x' => round(max(-50, min(210, (float) ($img['x'] ?? 0))), 1),
            'y' => round(max(-50, min(297, (float) ($img['y'] ?? 0))), 1),
            'largura' => round(max(3, min(210, (float) ($img['largura'] ?? 40))), 1),
            'todas' => !empty($img['todas']) ? 1 : 0,
        ];
    }
    return $limpas;
}

function paginaContratoDoPost(): string
{
    $margens = [];
    foreach (['superior', 'inferior', 'esquerda', 'direita'] as $lado) {
        $margens[$lado] = (float) str_replace(',', '.', (string) ($_POST['margem_' . $lado] ?? 2));
    }
    $pagina = paginaContrato(json_encode([
        'margens' => $margens,
        'cabecalho' => (string) ($_POST['cabecalho'] ?? 'instituicao'),
        'cabecalho_html' => (string) ($_POST['cabecalho_html'] ?? ''),
        'rodape_html' => (string) ($_POST['rodape_html'] ?? ''),
        'numero_pagina' => isset($_POST['numero_pagina']) ? 1 : 0,
        'imagens' => json_decode((string) ($_POST['imagens_json'] ?? '[]'), true) ?: [],
        'tabulacoes' => json_decode((string) ($_POST['tabulacoes_json'] ?? '[]'), true) ?: [],
    ]));
    return json_encode($pagina, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * Texto pronto com os dados da instituição, para o cabeçalho (o editor usa
 * nos modelos que estavam com "dados da instituição"; a logo vai como imagem).
 */
function textoCabecalhoInstituicao(array $doc): string
{
    $c = $doc['config'];
    $linhas = ['<p><strong class="ql-size-large" style="color: ' . e($doc['cor']) . '">' . e($doc['nome']) . '</strong></p>'];
    if (!empty($c['empresa_razao_social']) && $c['empresa_razao_social'] !== $doc['nome']) {
        $linhas[] = '<p>' . e($c['empresa_razao_social']) . (!empty($c['empresa_cnpj']) ? ' — CNPJ ' . e(cnpjFormatado($c['empresa_cnpj'])) : '') . '</p>';
    }
    if ($endereco = trim($doc['endereco'] . ($doc['endereco'] && $doc['cidade'] ? ' — ' : '') . $doc['cidade'])) {
        $linhas[] = '<p>' . e($endereco) . '</p>';
    }
    if ($contato = implode(' · ', array_filter([$c['empresa_telefone'] ?? '', $c['empresa_email'] ?? '']))) {
        $linhas[] = '<p>' . e($contato) . '</p>';
    }
    return implode('', $linhas);
}

/** Cabeçalho "dados da instituição": logo, nome, razão social, CNPJ, endereço e contato. */
function htmlCabecalhoInstituicao(array $doc): string
{
    $c = $doc['config'];
    return '<div class="cab-instituicao">'
        . (!empty($c['logo_sistema']) ? '<img src="' . e($c['logo_sistema']) . '" alt="">' : '')
        . '<div><strong>' . e($doc['nome']) . '</strong>'
        . (!empty($c['empresa_razao_social']) && $c['empresa_razao_social'] !== $doc['nome'] ? e($c['empresa_razao_social']) . '<br>' : '')
        . (!empty($c['empresa_cnpj']) ? 'CNPJ ' . e(cnpjFormatado($c['empresa_cnpj'])) . '<br>' : '')
        . e(trim($doc['endereco'] . ($doc['endereco'] && $doc['cidade'] ? ' — ' : '') . $doc['cidade']))
        . (($contato = implode(' · ', array_filter([$c['empresa_telefone'] ?? '', $c['empresa_email'] ?? '']))) ? '<br>' . e($contato) : '')
        . '</div></div>';
}

/** CSS da folha (tela e impressão) com as margens do modelo. */
function cssPaginaContrato(array $pagina, string $cor): string
{
    $m = $pagina['margens'];
    // Número da página na faixa de baixo da página, alinhado à margem direita
    // (a página impressa não tem margem dos lados — ver abaixo).
    $numero = $pagina['numero_pagina'] ? '@bottom-right { content: "Página " counter(page) " de " counter(pages); font: 8.5pt Arial, sans-serif; color: #777;'
        . ' text-align: right; vertical-align: top; padding: 2mm ' . $m['direita'] . 'cm 0 0; }' : '';
    return cssContrato() . "
:root { --cor-doc: {$cor}; }
/* Impressão: a página começa no canto da folha (margem só embaixo, onde vai
   o número da página); as margens de cima e dos lados vêm do espaço reservado
   em cada página e do recuo da folha — assim as imagens soltas caem no mesmo
   ponto do editor. */
@page { size: A4; margin: 0 0 {$m['inferior']}cm 0; {$numero} }
.img-livre { position: absolute; z-index: 3; } .img-livre img { width: 100%; display: block; }
.folha-contrato { position: relative; box-sizing: border-box; width: 21cm; min-height: 29.7cm; margin: 0 auto 30px; background: #fff;
    padding: {$m['superior']}cm {$m['direita']}cm {$m['inferior']}cm {$m['esquerda']}cm; box-shadow: 0 2px 10px rgba(0,0,0,.12); font-family: Arial, Helvetica, sans-serif; }
.folha-contrato .cabecalho-contrato { position: absolute; top: {$m['superior']}cm; left: {$m['esquerda']}cm; right: {$m['direita']}cm; }
.folha-contrato .rodape-contrato { position: absolute; bottom: {$m['inferior']}cm; left: {$m['esquerda']}cm; right: {$m['direita']}cm; }
.cabecalho-contrato { padding-bottom: 3mm; border-bottom: 1.5px solid var(--cor-doc); font-size: 11px; font-family: Arial, 'Liberation Sans', sans-serif; }
.rodape-contrato { padding-top: 2mm; border-top: 1px solid #ccc; font-size: 9.5px; color: #555; font-family: Arial, 'Liberation Sans', sans-serif; }
.rodape-contrato p, .cabecalho-contrato p { margin: 0; }
.cab-instituicao { display: flex; align-items: center; gap: 5mm; color: #555; line-height: 1.45; }
.cab-instituicao img { max-height: 16mm; max-width: 38mm; object-fit: contain; }
.cab-instituicao strong { display: block; font-size: 14px; color: var(--cor-doc); }
.texto-contrato h1 { color: var(--cor-doc); }
table.layout-contrato { width: 100%; border-collapse: collapse; }
table.layout-contrato > thead > tr > td, table.layout-contrato > tfoot > tr > td, table.layout-contrato > tbody > tr > td { padding: 0; border: 0; }
.espaco-cabecalho { height: var(--altura-cabecalho, 0); } .espaco-rodape { height: var(--altura-rodape, 0); }
.assinaturas { display: flex; gap: 12mm; margin-top: 20mm; page-break-inside: avoid; }
.assinaturas div { flex: 1; border-top: 1px solid #333; padding-top: 1.5mm; text-align: center; font-size: 11px; }
.numero-pagina-tela { float: right; color: #777; }
@media print {
    body { background: #fff !important; }
    .folha-contrato { width: auto; min-height: 0; margin: 0; padding: 0 {$m['direita']}cm 0 {$m['esquerda']}cm; box-shadow: none; }
    .folha-contrato .cabecalho-contrato { position: fixed; top: {$m['superior']}cm; left: {$m['esquerda']}cm; right: {$m['direita']}cm; }
    .folha-contrato .rodape-contrato { position: fixed; bottom: 0; left: {$m['esquerda']}cm; right: {$m['direita']}cm; }
    .espaco-cabecalho { height: calc({$m['superior']}cm + var(--altura-cabecalho, 0px)); }
    .img-livre.todas { position: fixed; }
    .numero-pagina-tela { display: none; }
}";
}

/**
 * Página do contrato (impressão na matrícula, no portal e a pré-visualização):
 * folha A4 com as margens do modelo; cabeçalho e rodapé repetem em todas as
 * páginas na impressão. $valores = variáveis já com os dados.
 */
function renderizarPaginaContrato(PDO $pdo, array $modelo, array $valores, string $titulo, string $voltar, string $assinaturas, string $aviso = ''): void
{
    $doc = documentoDados($pdo);
    $pagina = paginaContrato($modelo['pagina'] ?? null);
    $cabecalho = match ($pagina['cabecalho']) {
        'instituicao' => htmlCabecalhoInstituicao($doc),
        'personalizado' => preencherContrato($pagina['cabecalho_html'], $valores),
        default => '',
    };
    $rodape = preencherContrato($pagina['rodape_html'], $valores);
    ?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($titulo) ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="<?= e(assetUrl('/assets/css/relatorio.css')) ?>">
<style>
    body { background: #e9ecef; }
    .toolbar a { display: inline-block; margin-right: 6px; border-radius: 7px; padding: 10px 22px; background: #fff; color: #333; text-decoration: none; font-weight: 700; border: 1px solid #ccc; }
    .aviso-contrato { text-align: center; font-size: 12px; color: #666; margin: -10px 0 14px; }
    <?= cssPaginaContrato($pagina, $doc['cor']) ?>
</style>
</head>
<body>
<div class="toolbar">
    <a href="<?= e($voltar) ?>"><i class="bi bi-arrow-left"></i> Voltar</a>
    <button type="button" onclick="window.print()"><i class="bi bi-printer"></i> Imprimir</button>
</div>
<?php if ($aviso !== ''): ?><div class="aviso-contrato toolbar-aviso"><?= e($aviso) ?></div><?php endif; ?>
<div class="folha-contrato">
    <?php foreach ($pagina['imagens'] as $img): ?>
        <div class="img-livre<?= $img['todas'] ? ' todas' : '' ?>" style="left: <?= $img['x'] ?>mm; top: <?= $img['y'] ?>mm; width: <?= $img['largura'] ?>mm;"><img src="<?= e($img['url']) ?>" alt=""></div>
    <?php endforeach; ?>
    <?php if (trim(strip_tags($cabecalho, '<img>')) !== ''): ?><div class="cabecalho-contrato"><div class="texto-area"><?= $cabecalho ?></div></div><?php endif; ?>
    <table class="layout-contrato">
        <thead><tr><td><div class="espaco-cabecalho"></div></td></tr></thead>
        <tbody><tr><td>
            <div class="texto-contrato"><?= preencherContrato((string) $modelo['conteudo'], $valores) ?></div>
            <?= $assinaturas ?>
        </td></tr></tbody>
        <tfoot><tr><td><div class="espaco-rodape"></div></td></tr></tfoot>
    </table>
    <?php if (trim(strip_tags($rodape)) !== '' || $pagina['numero_pagina']): ?>
        <div class="rodape-contrato"><?php if ($pagina['numero_pagina']): ?><span class="numero-pagina-tela">Página 1</span><?php endif; ?><div class="texto-area"><?= $rodape ?></div></div>
    <?php endif; ?>
</div>
<script src="<?= e(assetUrl('/assets/js/contrato-tabs.js')) ?>"></script>
<script>
// TAB até as marcas da régua; depois reserva o espaço do cabeçalho e do
// rodapé (que ficam por cima, fixos em toda página na impressão), para o
// texto não passar por baixo deles.
(() => {
    const marcas = <?= json_encode($pagina['tabulacoes']) ?>;
    const ajustar = () => {
        document.querySelectorAll('.texto-contrato, .cabecalho-contrato, .rodape-contrato').forEach((area) => aplicarTabulacoes(area, marcas));
        const cab = document.querySelector('.cabecalho-contrato');
        const rod = document.querySelector('.rodape-contrato');
        document.documentElement.style.setProperty('--altura-cabecalho', cab ? (cab.offsetHeight + 18) + 'px' : '0px');
        document.documentElement.style.setProperty('--altura-rodape', rod ? (rod.offsetHeight + 14) + 'px' : '0px');
    };
    ajustar();
    window.addEventListener('load', ajustar);
    window.addEventListener('beforeprint', ajustar);
})();
</script>
</body>
</html>
    <?php
}

/**
 * Gera o PDF do contrato da matrícula (como os certificados: Chrome sem
 * janela, guardado até algo do contrato mudar) e manda para o navegador.
 * Retorna false se não deu para gerar (quem chama decide o que fazer).
 */
function entregarPdfContrato(PDO $pdo, array $modelo, array $dados): bool
{
    $m = $dados['matricula'];
    $assinaturas = '<div class="assinaturas"><div>' . e($m['aluno_nome']) . '<br>Contratante</div><div>' . e(documentoDados($pdo)['nome']) . '<br>Contratada</div></div>';
    ob_start();
    // O mesmo PDF serve à secretaria e ao portal (a barra de botões não sai no PDF).
    renderizarPaginaContrato($pdo, $modelo, $dados['valores'], $modelo['nome'] . ' · ' . $m['aluno_nome'], '#', $assinaturas);
    $arquivo = pdfDocumentoEmitido('contrato-' . (int) $m['id'], (string) ob_get_clean());
    if (!$arquivo) {
        return false;
    }
    $nome = 'contrato-' . trim(preg_replace('/[^a-z0-9]+/', '-', chaveOrdenacaoTexto($m['aluno_nome'] . ' ' . $m['curso_nome'])), '-') . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $nome . '"');
    header('Content-Length: ' . filesize($arquivo));
    header('Cache-Control: private, no-store');
    readfile($arquivo);
    return true;
}

/** Miniatura da 1ª folha do contrato (lista de Documentos), com dados de exemplo. */
function miniaturaContrato(array $modelo, array $valores, array $doc): string
{
    $pagina = paginaContrato($modelo['pagina'] ?? null);
    $m = $pagina['margens'];
    $cabecalho = match ($pagina['cabecalho']) {
        'instituicao' => $pagina['cabecalho_html'] ?: textoCabecalhoInstituicao($doc),
        'nenhum' => '',
        default => $pagina['cabecalho_html'],
    };
    $imagens = '';
    foreach ($pagina['imagens'] as $img) {
        if ($img['todas'] || $img['y'] < 297) {
            $imagens .= '<div class="img-livre" style="left: ' . $img['x'] . 'mm; top: ' . $img['y'] . 'mm; width: ' . $img['largura'] . 'mm"><img src="' . e($img['url']) . '" alt="" loading="lazy"></div>';
        }
    }
    return '<div class="mini-contrato"><div class="mini-folha" data-tabulacoes="' . e(json_encode($pagina['tabulacoes'])) . '" style="padding: ' . $m['superior'] . 'cm ' . $m['direita'] . 'cm 0 ' . $m['esquerda'] . 'cm">'
        . $imagens
        . (trim(strip_tags($cabecalho)) !== '' ? '<div class="mini-cabecalho texto-area">' . preencherContrato($cabecalho, $valores) . '</div>' : '')
        . '<div class="texto-contrato">' . preencherContrato((string) $modelo['conteudo'], $valores) . '</div>'
        . '</div></div>';
}
