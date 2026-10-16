<?php

// Calendário de aulas da turma: gerado a partir dos dias da semana e do
// horário cadastrados na turma, e depois ajustado à mão (cancelar,
// remarcar, aula avulsa).

function diasSemanaNomes(): array
{
    return [1 => 'Segunda', 2 => 'Terça', 3 => 'Quarta', 4 => 'Quinta', 5 => 'Sexta', 6 => 'Sábado', 7 => 'Domingo'];
}

// "1,3" -> [1, 3]
function diasSemanaTurma(?string $valor): array
{
    $dias = array_map('intval', array_filter(explode(',', (string) $valor)));
    return array_values(array_unique(array_filter($dias, fn ($d) => $d >= 1 && $d <= 7)));
}

/**
 * Horário de cada dia de aula: [dia => ['inicio' => '19:00', 'fim' => '22:00']]
 * (dia 1 = segunda ... 7 = domingo), em ordem. Vem do campo agenda; turma
 * antiga (só dias_semana + um horário) vale o mesmo horário para todos.
 */
function agendaTurma(array $turma): array
{
    $agenda = [];
    $lista = json_decode((string) ($turma['agenda'] ?? ''), true);
    if (is_array($lista) && $lista) {
        foreach ($lista as $item) {
            $dia = (int) ($item['dia'] ?? 0);
            if ($dia >= 1 && $dia <= 7) {
                $agenda[$dia] = ['inicio' => substr((string) ($item['inicio'] ?? ''), 0, 5), 'fim' => substr((string) ($item['fim'] ?? ''), 0, 5)];
            }
        }
    } else {
        foreach (diasSemanaTurma($turma['dias_semana'] ?? null) as $dia) {
            $agenda[$dia] = ['inicio' => substr((string) ($turma['hora_inicio'] ?? ''), 0, 5), 'fim' => substr((string) ($turma['hora_fim'] ?? ''), 0, 5)];
        }
    }
    ksort($agenda);
    return $agenda;
}

// "Seg a Sex, 19:00 às 22:00 · Sáb, 12:00 às 15:00" — junta os dias de
// mesmo horário; três ou mais dias seguidos viram "Seg a Sex".
function textoAgendaTurma(array $turma): string
{
    $agenda = agendaTurma($turma);
    if (!$agenda) {
        return (string) ($turma['horario'] ?? '');
    }
    $grupos = [];
    foreach ($agenda as $dia => $hora) {
        $chave = $hora['inicio'] . '|' . $hora['fim'];
        $grupos[$chave]['hora'] = $hora;
        $grupos[$chave]['dias'][] = $dia;
    }
    $abrev = fn (int $d) => mb_substr(diasSemanaNomes()[$d], 0, 3);
    $partes = [];
    foreach ($grupos as $grupo) {
        // Quebra os dias em sequências seguidas (1,2,3,4,5 / 6).
        $sequencias = [];
        foreach ($grupo['dias'] as $dia) {
            $ultima = count($sequencias) - 1;
            if ($ultima >= 0 && end($sequencias[$ultima]) === $dia - 1) {
                $sequencias[$ultima][] = $dia;
            } else {
                $sequencias[] = [$dia];
            }
        }
        $nomes = [];
        foreach ($sequencias as $seq) {
            if (count($seq) >= 3) {
                $nomes[] = $abrev($seq[0]) . ' a ' . $abrev(end($seq));
            } else {
                array_push($nomes, ...array_map($abrev, $seq));
            }
        }
        $ultimoNome = array_pop($nomes);
        $texto = $nomes ? implode(', ', $nomes) . ' e ' . $ultimoNome : $ultimoNome;
        if ($grupo['hora']['inicio'] !== '') {
            $texto .= ', ' . $grupo['hora']['inicio'] . ($grupo['hora']['fim'] !== '' ? ' às ' . $grupo['hora']['fim'] : '');
        }
        $partes[] = $texto;
    }
    return implode(' · ', $partes);
}

/**
 * (Re)gera as aulas da turma do início ao término nos dias da semana dela,
 * cada dia com o seu horário. Aulas passadas e aulas avulsas ficam como
 * estão; as futuras geradas antes são refeitas (é assim que uma mudança de
 * dia/horário se aplica). Retorna quantas aulas foram criadas, ou uma
 * mensagem de erro.
 */
function gerarAulasTurma(PDO $pdo, int $turmaId): array
{
    $stmt = $pdo->prepare('SELECT * FROM turmas WHERE id = ?');
    $stmt->execute([$turmaId]);
    $turma = $stmt->fetch();
    $agenda = $turma ? agendaTurma($turma) : [];
    if (!$turma || !$agenda) {
        return ['ok' => false, 'mensagem' => 'Marque os dias da semana das aulas na turma antes de gerar o calendário.', 'criadas' => 0];
    }
    if (!$turma['data_inicio']) {
        return ['ok' => false, 'mensagem' => 'A turma ainda está sem data de início. Defina a data na turma antes de gerar o calendário.', 'criadas' => 0];
    }

    $hoje = date('Y-m-d');
    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM turma_aulas WHERE turma_id = ? AND avulsa = 0 AND data >= ?')->execute([$turmaId, $hoje]);

    $stmt = $pdo->prepare('SELECT data FROM turma_aulas WHERE turma_id = ?');
    $stmt->execute([$turmaId]);
    $existentes = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));

    $insere = $pdo->prepare('INSERT INTO turma_aulas (turma_id, data, hora_inicio, hora_fim) VALUES (?, ?, ?, ?)');
    $criadas = 0;
    $data = max($turma['data_inicio'], $hoje);
    // data_termino é o início + duração: a última aula é no dia anterior.
    while ($data < $turma['data_termino']) {
        $dia = (int) date('N', strtotime($data));
        if (isset($agenda[$dia]) && !isset($existentes[$data])) {
            $insere->execute([$turmaId, $data, $agenda[$dia]['inicio'] ?: null, $agenda[$dia]['fim'] ?: null]);
            $criadas++;
        }
        $data = date('Y-m-d', strtotime($data . ' +1 day'));
    }
    $pdo->commit();

    return ['ok' => true, 'mensagem' => "{$criadas} aula(s) no calendário.", 'criadas' => $criadas];
}

// Aulas de um conjunto de turmas num intervalo (para o portal e a turma).
function buscarAulas(PDO $pdo, array $turmaIds, ?string $de = null, ?string $ate = null): array
{
    if (!$turmaIds) {
        return [];
    }
    $sql = 'SELECT ta.*, t.nome AS turma_nome, t.local, c.nome AS curso_nome
            FROM turma_aulas ta JOIN turmas t ON t.id = ta.turma_id JOIN cursos c ON c.id = t.curso_id
            WHERE ta.turma_id IN (' . implode(',', array_fill(0, count($turmaIds), '?')) . ')';
    $parametros = array_values($turmaIds);
    if ($de) {
        $sql .= ' AND ta.data >= ?';
        $parametros[] = $de;
    }
    if ($ate) {
        $sql .= ' AND ta.data <= ?';
        $parametros[] = $ate;
    }
    $stmt = $pdo->prepare($sql . ' ORDER BY ta.data, ta.hora_inicio');
    $stmt->execute($parametros);
    return $stmt->fetchAll();
}
