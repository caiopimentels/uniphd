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
        $where .= ' AND (a.nome LIKE ? OR a.cpf LIKE ? OR a.telefone LIKE ? OR a.email LIKE ? OR a.ra LIKE ?)';
        $params = array_fill(0, 5, "%{$busca}%");
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
                  WHERE cr.aluno_id = a.id AND cr.status = 'pendente' AND cr.data_vencimento < CURDATE()) AS valor_vencido
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
                  WHERE m.turma_id = t.id AND cr.status = 'pendente' AND cr.data_vencimento < CURDATE()) AS valor_vencido
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
        'alunos' => 'alunos_ativos',
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
