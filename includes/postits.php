<?php

/**
 * Post-its no aluno: bilhetes colados na ficha do aluno (e na matrícula em
 * que foram feitos). O quadro fica em postits_quadro.php; o aviso que abre
 * sozinho fica no layout_footer.php; a lista de matrículas conta os abertos.
 *
 * O post-it fica no quadro até alguém marcar como resolvido. Escolhas:
 *  - Quem vê: todos que abrem o aluno, só quem escreveu, ou quem escreveu +
 *    pessoas escolhidas (usuários e/ou perfis). O administrador vê todos.
 *  - Aviso (opcional): abre sozinho quando as pessoas escolhidas entram no
 *    sistema (sem ninguém escolhido, avisa quem escreveu — um lembrete):
 *    toda vez que abrir o sistema, uma vez só ou N vezes seguidas ("abrir o
 *    sistema" é cada login), e só no período do aviso (inicio/fim, opcional:
 *    "avisar a partir de" e "até").
 */

/** Cores do post-it: chave => [nome, fundo, borda]. */
function postitCores(): array
{
    return [
        'amarelo' => ['Amarelo', '#fff4a8', '#e6cf45'],
        'rosa' => ['Rosa', '#ffd6e4', '#ef97b7'],
        'azul' => ['Azul', '#d4ebff', '#8fc3ef'],
        'verde' => ['Verde', '#d8f5cf', '#92d07f'],
    ];
}

/** Estilo inline do cartão (texto escuro também no tema escuro). */
function postitEstilo(string $cor): string
{
    $c = postitCores()[$cor] ?? postitCores()['amarelo'];
    return 'background:' . $c[1] . ';border-color:' . $c[2] . ';color:#2b2b2b;';
}

/** Identifica o login atual: muda a cada login (ver tentarLogin()). */
function postitSessaoAtual(): string
{
    return hash('sha256', (string) ($_SESSION['token_sessao'] ?? session_id()));
}

/**
 * Condição SQL "o usuário logado pode ver este post-it" ($p = apelido da
 * tabela aluno_postits na consulta). Os números entram já convertidos.
 */
function postitSqlVisivel(string $p = 'p'): string
{
    if (usuarioEhAdmin()) {
        return '1=1';
    }
    $u = (int) usuarioAtualId();
    $pf = (int) ($_SESSION['user_perfil_id'] ?? 0);
    return "($p.visibilidade = 'todos' OR $p.usuario_id = $u OR ($p.visibilidade = 'escolhidos' AND EXISTS ("
        . "SELECT 1 FROM aluno_postit_destinos dv WHERE dv.postit_id = $p.id AND (dv.usuario_id = $u OR dv.perfil_id = $pf))))";
}

/** Condição SQL "post-it em aberto" (não resolvido). */
function postitSqlAberto(string $p = 'p'): string
{
    return "($p.resolvido_em IS NULL)";
}

/** Condição SQL "dentro do período do aviso" (avisar a partir de / até). */
function postitSqlNoPeriodoAviso(string $p = 'p'): string
{
    return "(($p.inicio IS NULL OR $p.inicio <= NOW()) AND ($p.fim IS NULL OR $p.fim >= CURDATE()))";
}

/**
 * Post-its do aluno que o usuário pode ver, cada um com 'destinos' (lista de
 * ['tipo' => 'usuario'|'perfil', 'id', 'nome']), 'vistos' (quem já viu o
 * aviso e quantas vezes), 'matricula_nome' e 'situacao' (aberto ou resolvido).
 */
function buscarPostitsAluno(PDO $pdo, int $alunoId): array
{
    $stmt = $pdo->prepare(
        "SELECT p.*, CONCAT(c.nome, ' · ', t.nome) AS matricula_nome
           FROM aluno_postits p
           LEFT JOIN matriculas m ON m.id = p.matricula_id
           LEFT JOIN turmas t ON t.id = m.turma_id
           LEFT JOIN cursos c ON c.id = t.curso_id
          WHERE p.aluno_id = ? AND " . postitSqlVisivel('p') . "
          ORDER BY COALESCE(p.resolvido_em, p.criado_em) DESC, p.id DESC"
    );
    $stmt->execute([$alunoId]);
    $postits = [];
    foreach ($stmt->fetchAll() as $p) {
        $p['destinos'] = [];
        $p['vistos'] = [];
        $p['situacao'] = $p['resolvido_em'] ? 'resolvido' : 'aberto';
        $postits[(int) $p['id']] = $p;
    }
    if (!$postits) {
        return [];
    }

    $ids = implode(',', array_keys($postits));
    $destinos = $pdo->query(
        "SELECT d.postit_id, d.usuario_id, d.perfil_id, COALESCE(u.nome, pf.nome) AS nome
           FROM aluno_postit_destinos d
           LEFT JOIN usuarios u ON u.id = d.usuario_id
           LEFT JOIN perfis pf ON pf.id = d.perfil_id
          WHERE d.postit_id IN ($ids)
          ORDER BY d.perfil_id IS NULL, nome"
    )->fetchAll();
    foreach ($destinos as $d) {
        $postits[(int) $d['postit_id']]['destinos'][] = $d['usuario_id']
            ? ['tipo' => 'usuario', 'id' => (int) $d['usuario_id'], 'nome' => $d['nome']]
            : ['tipo' => 'perfil', 'id' => (int) $d['perfil_id'], 'nome' => $d['nome']];
    }

    $vistos = $pdo->query(
        "SELECT v.postit_id, v.vezes, v.ultima_vez, v.dispensado_em, u.nome
           FROM aluno_postit_vistos v JOIN usuarios u ON u.id = v.usuario_id
          WHERE v.postit_id IN ($ids) AND v.vezes > 0
          ORDER BY u.nome"
    )->fetchAll();
    foreach ($vistos as $v) {
        $postits[(int) $v['postit_id']]['vistos'][] = $v;
    }

    return array_values($postits);
}

/** Nomes das pessoas escolhidas ("Maria, perfil Secretaria"). */
function postitNomesPessoas(array $p): string
{
    return implode(', ', array_map(fn ($d) => ($d['tipo'] === 'perfil' ? 'perfil ' : '') . $d['nome'], $p['destinos']));
}

/** Quem vê, em poucas palavras (vazio quando é todo mundo). */
function descreverVisibilidadePostit(array $p): string
{
    $meu = (int) $p['usuario_id'] === (int) usuarioAtualId();
    return match ($p['visibilidade']) {
        'eu' => $meu ? 'Só você vê' : 'Só ' . $p['usuario_nome'] . ' vê',
        'escolhidos' => 'Só ' . ($meu ? 'você' : $p['usuario_nome']) . ' e ' . postitNomesPessoas($p) . ' veem',
        default => '',
    };
}

/** Período do aviso ("a partir de 10/11 09:00 até 15/11"; vazio quando não tem). */
function descreverPeriodoPostit(array $p): string
{
    $inicio = $p['inicio'] ? strtotime($p['inicio']) : null;
    $fim = $p['fim'];
    if ($inicio && $fim && date('Y-m-d', $inicio) === $fim) {
        return 'só no dia ' . formatDate($fim) . (date('H:i', $inicio) !== '00:00' ? ', a partir das ' . date('H:i', $inicio) : '');
    }
    $partes = [];
    if ($inicio) {
        $partes[] = 'a partir de ' . date('d/m/Y', $inicio) . (date('H:i', $inicio) !== '00:00' ? ' ' . date('H:i', $inicio) : '');
    }
    if ($fim) {
        $partes[] = 'até ' . formatDate($fim);
    }
    return implode(' ', $partes);
}

/** O aviso: para quem e quantas vezes (vazio sem aviso). */
function descreverAvisoPostit(array $p): string
{
    if (!$p['avisar']) {
        return '';
    }
    $quem = $p['destinos'] ? postitNomesPessoas($p) : ((int) $p['usuario_id'] === (int) usuarioAtualId() ? 'você' : $p['usuario_nome']);
    $vezes = [
        'sempre' => 'toda vez que abrir o sistema',
        'uma_vez' => 'uma vez',
        'vezes' => (int) $p['repeticao_vezes'] . ' vezes seguidas',
    ][$p['repeticao']] ?? '';
    return implode(' · ', array_filter(['Avisa ' . $quem, descreverPeriodoPostit($p), $vezes]));
}

/**
 * Resumo para o Histórico do aluno (que todos que veem o aluno leem): o
 * texto só aparece se o post-it for visível para todos.
 */
function resumoHistoricoPostit(string $texto, string $visibilidade): string
{
    return $visibilidade === 'todos'
        ? mb_strimwidth(preg_replace('/\s+/', ' ', trim($texto)), 0, 150, '…')
        : 'post-it com visibilidade restrita';
}

/** Botão "Post-it" para o topo da tela (vazio sem a permissão). */
function postitBotaoNovo(): string
{
    return usuarioTemPermissao('usar_postits')
        ? '<button type="button" class="btn btn-outline-secondary" onclick="abrirPostit()" title="Colar um recado ou tarefa neste aluno"><i class="bi bi-sticky"></i> Post-it</button>'
        : '';
}

/** Quem criou o post-it (ou o administrador) pode editar e excluir. */
function usuarioPodeAlterarPostit(array $p): bool
{
    return usuarioEhAdmin() || ((int) $p['usuario_id'] === (int) usuarioAtualId() && usuarioTemPermissao('usar_postits'));
}

/** O usuário é uma das pessoas escolhidas (direto ou pelo perfil)? */
function postitAvisaUsuario(PDO $pdo, int $postitId, int $usuarioId, int $perfilId): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM aluno_postit_destinos WHERE postit_id = ? AND (usuario_id = ? OR perfil_id = ?) LIMIT 1');
    $stmt->execute([$postitId, $usuarioId, $perfilId]);
    return (bool) $stmt->fetchColumn();
}

/** O usuário logado pode ver o post-it? */
function usuarioVePostit(PDO $pdo, int $postitId): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM aluno_postits p WHERE p.id = ? AND ' . postitSqlVisivel('p'));
    $stmt->execute([$postitId]);
    return (bool) $stmt->fetchColumn();
}

/**
 * Grava (cria ou altera) o post-it a partir do formulário. Devolve o id ou
 * lança InvalidArgumentException com a mensagem para o usuário.
 *
 * $dados: texto, cor, visibilidade (todos|eu|escolhidos), inicio
 * (Y-m-d\TH:i, opcional), fim (Y-m-d, opcional), avisar (1), repeticao,
 * repeticao_vezes, usuarios[], perfis[].
 */
function salvarPostit(PDO $pdo, int $alunoId, ?int $matriculaId, array $dados, ?int $id = null): int
{
    $texto = trim((string) ($dados['texto'] ?? ''));
    if ($texto === '') {
        throw new InvalidArgumentException('Escreva o texto do post-it.');
    }
    if (mb_strlen($texto) > 2000) {
        throw new InvalidArgumentException('O post-it pode ter no máximo 2.000 letras.');
    }
    $cor = array_key_exists($dados['cor'] ?? '', postitCores()) ? $dados['cor'] : 'amarelo';
    $visibilidade = in_array($dados['visibilidade'] ?? '', ['todos', 'eu', 'escolhidos'], true) ? $dados['visibilidade'] : 'todos';
    $avisar = !empty($dados['avisar']);

    // Pessoas: quem vê (escolhidos) e/ou quem é avisado. "Só eu" não tem pessoas.
    $usuarios = array_values(array_unique(array_filter(array_map('intval', (array) ($dados['usuarios'] ?? [])))));
    $perfis = array_values(array_unique(array_filter(array_map('intval', (array) ($dados['perfis'] ?? [])))));
    if ($visibilidade === 'eu' || ($visibilidade === 'todos' && !$avisar)) {
        $usuarios = [];
        $perfis = [];
    }
    if ($visibilidade === 'escolhidos' && !$usuarios && !$perfis) {
        throw new InvalidArgumentException('Marque quem pode ver o post-it (ou escolha "Todos" ou "Só eu").');
    }

    // Período do aviso: só conta com o aviso ligado.
    $inicio = null;
    $fim = null;
    $valor = !$avisar ? '' : str_replace('T', ' ', trim((string) ($dados['inicio'] ?? '')));
    if ($valor !== '') {
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $valor) || !strtotime($valor)) {
            throw new InvalidArgumentException('A data de "avisar a partir de" está errada.');
        }
        $inicio = $valor . ':00';
    }
    $valor = !$avisar ? '' : trim((string) ($dados['fim'] ?? ''));
    if ($valor !== '') {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) || !strtotime($valor)) {
            throw new InvalidArgumentException('A data de "avisar até" está errada.');
        }
        if ($inicio && $valor < substr($inicio, 0, 10)) {
            throw new InvalidArgumentException('O "avisar até" não pode ser antes do "avisar a partir de".');
        }
        $fim = $valor;
    }

    $repeticao = in_array($dados['repeticao'] ?? '', ['sempre', 'uma_vez', 'vezes'], true) ? $dados['repeticao'] : 'uma_vez';
    $vezes = max(2, min(50, (int) ($dados['repeticao_vezes'] ?? 3)));

    $pdo->beginTransaction();
    try {
        if ($id) {
            $pdo->prepare(
                'UPDATE aluno_postits SET texto = ?, cor = ?, visibilidade = ?, inicio = ?, fim = ?, avisar = ?, repeticao = ?, repeticao_vezes = ?, atualizado_em = NOW()
                  WHERE id = ?'
            )->execute([$texto, $cor, $visibilidade, $inicio, $fim, (int) $avisar, $repeticao, $vezes, $id]);
            $pdo->prepare('DELETE FROM aluno_postit_destinos WHERE postit_id = ?')->execute([$id]);
            // Alterou: o aviso começa de novo para todo mundo.
            $pdo->prepare('DELETE FROM aluno_postit_vistos WHERE postit_id = ?')->execute([$id]);
        } else {
            $pdo->prepare(
                'INSERT INTO aluno_postits (aluno_id, matricula_id, texto, cor, visibilidade, inicio, fim, avisar, repeticao, repeticao_vezes, usuario_id, usuario_nome)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([$alunoId, $matriculaId, $texto, $cor, $visibilidade, $inicio, $fim, (int) $avisar, $repeticao, $vezes, usuarioAtualId(), usuarioAtualNome()]);
            $id = (int) $pdo->lastInsertId();
        }

        $inserir = $pdo->prepare('INSERT INTO aluno_postit_destinos (postit_id, usuario_id, perfil_id) VALUES (?,?,?)');
        foreach ($usuarios as $u) {
            $inserir->execute([$id, $u, null]);
        }
        foreach ($perfis as $pf) {
            $inserir->execute([$id, null, $pf]);
        }

        // Quem acabou de escrever não precisa ser avisado neste mesmo login.
        if ($avisar) {
            $pdo->prepare(
                'INSERT INTO aluno_postit_vistos (postit_id, usuario_id, vezes, ultima_sessao) VALUES (?,?,0,?)
                 ON DUPLICATE KEY UPDATE ultima_sessao = VALUES(ultima_sessao)'
            )->execute([$id, usuarioAtualId(), postitSessaoAtual()]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return $id;
}

/**
 * Post-its que devem aparecer agora para o usuário (no máximo 20) — e já
 * conta esta vez. Cada um aparece uma vez por login, dentro do período, até
 * acabar a repetição, ser resolvido ou o usuário pedir para não ver mais.
 * Avisa as pessoas escolhidas; sem ninguém escolhido, quem escreveu.
 */
function postitsParaAvisarAgora(PDO $pdo, int $usuarioId, int $perfilId): array
{
    $sessao = postitSessaoAtual();
    $stmt = $pdo->prepare(
        "SELECT p.*, a.nome AS aluno_nome, CONCAT(c.nome, ' · ', t.nome) AS matricula_nome
           FROM aluno_postits p
           JOIN alunos a ON a.id = p.aluno_id
           LEFT JOIN matriculas m ON m.id = p.matricula_id
           LEFT JOIN turmas t ON t.id = m.turma_id
           LEFT JOIN cursos c ON c.id = t.curso_id
           LEFT JOIN aluno_postit_vistos v ON v.postit_id = p.id AND v.usuario_id = ?
          WHERE p.avisar = 1 AND " . postitSqlAberto('p') . " AND " . postitSqlNoPeriodoAviso('p') . "
            AND (EXISTS (SELECT 1 FROM aluno_postit_destinos d WHERE d.postit_id = p.id AND (d.usuario_id = ? OR d.perfil_id = ?))
                 OR (p.usuario_id = ? AND NOT EXISTS (SELECT 1 FROM aluno_postit_destinos d2 WHERE d2.postit_id = p.id)))
            AND v.dispensado_em IS NULL
            AND (v.ultima_sessao IS NULL OR v.ultima_sessao <> ?)
            AND (p.repeticao = 'sempre' OR COALESCE(v.vezes, 0) < IF(p.repeticao = 'uma_vez', 1, p.repeticao_vezes))
          ORDER BY COALESCE(p.inicio, p.criado_em), p.id
          LIMIT 20"
    );
    $stmt->execute([$usuarioId, $usuarioId, $perfilId, $usuarioId, $sessao]);
    $postits = $stmt->fetchAll();

    $contar = $pdo->prepare(
        'INSERT INTO aluno_postit_vistos (postit_id, usuario_id, vezes, ultima_sessao, ultima_vez) VALUES (?,?,1,?,NOW())
         ON DUPLICATE KEY UPDATE vezes = vezes + 1, ultima_sessao = VALUES(ultima_sessao), ultima_vez = NOW()'
    );
    foreach ($postits as $p) {
        $contar->execute([$p['id'], $usuarioId, $sessao]);
    }

    return $postits;
}
