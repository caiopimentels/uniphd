<?php

// Consultas das listas de alunos, turmas e cursos — as mesmas na tela e na
// impressão, para as duas mostrarem a mesma coisa. Junto vão as colunas que
// dá para ordenar (ver includes/listagem.php).

// Filtro salvo da tela de alunos: busca, situacao (ativo/inativo) e
// financeiro (atraso/em_dia — só para quem pode ver).
function buscarListaAlunos(PDO $pdo, array $filtro, bool $comFinanceiro): array
{
    $where = '1=1';
    $params = [];
    $busca = trim((string) ($filtro['busca'] ?? ''));
    if ($busca !== '') {
        $where .= ' AND (a.nome LIKE ? OR a.cpf LIKE ? OR a.telefone LIKE ? OR a.email LIKE ? OR a.ra LIKE ?';
        $params = array_fill(0, 5, "%{$busca}%");
        // CPF e telefone ficam só com números: "045.365" ou "(77) 9810" também acham.
        $digitos = apenasDigitos($busca);
        if (strlen($digitos) >= 3) {
            $where .= ' OR a.cpf LIKE ? OR a.telefone LIKE ?';
            array_push($params, "%{$digitos}%", "%{$digitos}%");
        }
        $where .= ')';
    }
    if (($filtro['situacao'] ?? '') === 'ativo') {
        $where .= ' AND a.ativo = 1';
    } elseif (($filtro['situacao'] ?? '') === 'inativo') {
        $where .= ' AND a.ativo = 0';
    }
    $having = '';
    if ($comFinanceiro && ($filtro['financeiro'] ?? '') === 'atraso') {
        $having = 'HAVING valor_vencido > 0';
    } elseif ($comFinanceiro && ($filtro['financeiro'] ?? '') === 'em_dia') {
        $having = 'HAVING valor_vencido = 0';
    }
    $stmt = $pdo->prepare(
        "SELECT a.*,
                (SELECT COUNT(*) FROM matriculas m WHERE m.aluno_id = a.id AND m.status = 'ativa') AS matriculas_ativas,
                (SELECT COALESCE(SUM(cr.valor), 0) FROM contas_receber cr
                  WHERE cr.aluno_id = a.id AND cr.status = 'pendente' AND cr.data_vencimento < " . sqlCorteAtraso() . ") AS valor_vencido
         FROM alunos a
         WHERE {$where}
         {$having}
         ORDER BY a.nome ASC"
    );
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function colunasListaAlunos(): array
{
    return [
        'ra' => 'ra',
        'nome' => 'nome',
        'cpf' => 'cpf',
        'telefone' => 'telefone',
        'email' => 'email',
        'nascimento' => 'data_nascimento',
        'matriculas' => 'matriculas_ativas',
        'financeiro' => 'valor_vencido',
        'situacao' => fn ($a) => $a['ativo'] ? 'Ativo' : 'Inativo',
    ];
}

// Filtro salvo da tela de turmas: busca, situacoes[] e cursos[].
function buscarListaTurmas(PDO $pdo, array $filtro): array
{
    $where = '1=1';
    $params = [];
    $busca = trim((string) ($filtro['busca'] ?? ''));
    if ($busca !== '') {
        $where .= ' AND (t.nome LIKE ? OR c.nome LIKE ? OR p.nome LIKE ?)';
        array_push($params, "%{$busca}%", "%{$busca}%", "%{$busca}%");
    }
    if (!empty($filtro['situacoes'])) {
        $where .= ' AND t.situacao_id IN (' . implode(',', array_fill(0, count($filtro['situacoes']), '?')) . ')';
        array_push($params, ...$filtro['situacoes']);
    }
    if (!empty($filtro['cursos'])) {
        $where .= ' AND t.curso_id IN (' . implode(',', array_fill(0, count($filtro['cursos']), '?')) . ')';
        array_push($params, ...$filtro['cursos']);
    }
    $stmt = $pdo->prepare(
        "SELECT t.*, c.nome AS curso_nome, p.nome AS professor_nome,
                s.nome AS situacao_nome, s.cor AS situacao_cor, s.comportamento,
                (SELECT COUNT(*) FROM matriculas m WHERE m.turma_id = t.id AND m.status = 'ativa') AS alunos_ativos,
                " . SQL_RESERVAS_TURMA . " AS reservas,
                (SELECT COALESCE(SUM(cr.valor), 0) FROM contas_receber cr JOIN matriculas m ON m.id = cr.matricula_id
                  WHERE m.turma_id = t.id AND cr.status = 'pendente' AND cr.data_vencimento < " . sqlCorteAtraso() . ") AS valor_vencido
         FROM turmas t
         JOIN cursos c ON c.id = t.curso_id
         JOIN situacoes_turma s ON s.id = t.situacao_id
         LEFT JOIN professores p ON p.id = t.professor_id
         WHERE {$where}
         ORDER BY t.data_inicio IS NULL DESC, t.data_inicio DESC, t.nome"
    );
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function colunasListaTurmas(): array
{
    return [
        'turma' => 'nome',
        'curso' => 'curso_nome',
        'professor' => 'professor_nome',
        'periodo' => 'data_inicio',
        'mensalidade' => 'valor_mensalidade',
        'valor_total' => 'valor_total',
        'alunos' => 'alunos_ativos',
        'vagas_livres' => fn ($t) => max(0, (int) $t['vagas'] - (int) $t['alunos_ativos'] - (int) $t['reservas']),
        'situacao' => 'situacao_nome',
    ];
}

// Busca por nome do curso.
function buscarListaCursos(PDO $pdo, string $busca): array
{
    $where = '1=1';
    $params = [];
    if ($busca !== '') {
        $where .= ' AND c.nome LIKE ?';
        $params[] = "%{$busca}%";
    }
    $stmt = $pdo->prepare(
        "SELECT c.*,
                (SELECT COUNT(*) FROM turmas t JOIN situacoes_turma s ON s.id = t.situacao_id
                  WHERE t.curso_id = c.id AND s.comportamento IN ('aberta', 'em_andamento')) AS turmas_abertas,
                (SELECT COUNT(*) FROM turmas t WHERE t.curso_id = c.id) AS turmas_total
         FROM cursos c
         WHERE {$where}
         ORDER BY c.ativo DESC, c.nome ASC"
    );
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function colunasListaCursos(): array
{
    return [
        'curso' => 'nome',
        'carga' => 'carga_horaria',
        'abertas' => 'turmas_abertas',
        'total' => 'turmas_total',
        'situacao' => fn ($c) => $c['ativo'] ? 'Ativo' : 'Inativo',
    ];
}

// Lista de todas as matrículas (menu Acadêmico > Matrículas). Filtro salvo:
// busca (aluno), status[], cursos[], turmas[], postit ('com' = só com post-it em
// aberto) e financeiro (atraso | em_dia, só com $comFinanceiro).
function buscarListaMatriculas(PDO $pdo, array $filtro, bool $comFinanceiro): array
{
    $where = '1=1';
    $params = [];
    $busca = trim((string) ($filtro['busca'] ?? ''));
    if ($busca !== '') {
        $where .= ' AND (a.nome LIKE ? OR a.ra LIKE ? OR a.email LIKE ?';
        $params = array_fill(0, 3, "%{$busca}%");
        $digitos = apenasDigitos($busca);
        if (strlen($digitos) >= 3) {
            $where .= ' OR a.cpf LIKE ? OR a.telefone LIKE ?';
            array_push($params, "%{$digitos}%", "%{$digitos}%");
        }
        $where .= ')';
    }
    $status = array_values(array_intersect((array) ($filtro['status'] ?? []), ['pendente', 'ativa', 'trancada', 'concluida', 'cancelada']));
    if ($status) {
        $where .= ' AND m.status IN (' . implode(',', array_fill(0, count($status), '?')) . ')';
        array_push($params, ...$status);
    }
    $cursos = array_values(array_filter(array_map('intval', (array) ($filtro['cursos'] ?? []))));
    if ($cursos) {
        $where .= ' AND t.curso_id IN (' . implode(',', $cursos) . ')';
    }
    $turmas = array_values(array_filter(array_map('intval', (array) ($filtro['turmas'] ?? []))));
    if ($turmas) {
        $where .= ' AND m.turma_id IN (' . implode(',', $turmas) . ')';
    }
    $having = [];
    if (($filtro['postit'] ?? '') === 'com') {
        $having[] = 'postits > 0';
    }
    if ($comFinanceiro && ($filtro['financeiro'] ?? '') === 'atraso') {
        $having[] = 'valor_vencido > 0';
    } elseif ($comFinanceiro && ($filtro['financeiro'] ?? '') === 'em_dia') {
        $having[] = 'valor_vencido = 0';
    }
    $stmt = $pdo->prepare(
        "SELECT m.id, m.aluno_id, m.turma_id, m.status, m.origem, m.data_matricula, m.valor_mensalidade, m.desconto_percentual,
                m.num_parcelas, m.pagamento_avista, m.criado_em,
                a.nome AS aluno_nome, a.ra, a.cpf, a.telefone, t.nome AS turma_nome, t.curso_id, c.nome AS curso_nome,
                u.nome AS usuario_nome,
                (SELECT COUNT(*) FROM aluno_postits p WHERE p.aluno_id = m.aluno_id AND " . postitSqlAberto('p') . " AND " . postitSqlVisivel('p') . ") AS postits,
                " . ($comFinanceiro
                    ? "(SELECT COALESCE(SUM(cr.valor), 0) FROM contas_receber cr
                        WHERE cr.matricula_id = m.id AND cr.status = 'pendente' AND cr.excluido_em IS NULL AND cr.data_vencimento < " . sqlCorteAtraso() . ")"
                    : '0') . " AS valor_vencido
         FROM matriculas m
         JOIN alunos a ON a.id = m.aluno_id
         JOIN turmas t ON t.id = m.turma_id
         JOIN cursos c ON c.id = t.curso_id
         LEFT JOIN usuarios u ON u.id = m.usuario_id
         WHERE {$where}
         " . ($having ? 'HAVING ' . implode(' AND ', $having) : '') . "
         ORDER BY m.data_matricula DESC, m.id DESC"
    );
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function colunasListaMatriculas(): array
{
    return [
        'aluno' => 'aluno_nome',
        'ra' => 'ra',
        'cpf' => 'cpf',
        'telefone' => 'telefone',
        'curso' => 'curso_nome',
        'turma' => 'turma_nome',
        'data' => 'data_matricula',
        'status' => 'status',
        'origem' => 'origem',
        'mensalidade' => fn ($m) => valorMensalidadeComDesconto((float) $m['valor_mensalidade'], (float) $m['desconto_percentual']),
        'parcelas' => 'num_parcelas',
        'financeiro' => 'valor_vencido',
        'postits' => 'postits',
        'matriculado_por' => 'usuario_nome',
    ];
}
