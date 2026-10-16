<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();
exigirCursos();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/turmas/listar.php');
}

$pdo = getDB();
$ids = array_values(array_unique(array_filter(array_map('intval', $_POST['ids'] ?? []))));
$situacaoId = (int) ($_POST['situacao_id'] ?? 0);

// Só aceita voltar para dentro do módulo de turmas (evita redirecionamento
// para fora do sistema por um campo manipulado).
$voltar = (string) ($_POST['voltar'] ?? '');
if (!str_starts_with($voltar, '/turmas/')) {
    $voltar = '/turmas/listar.php';
}

$stmt = $pdo->prepare('SELECT * FROM situacoes_turma WHERE id = ? AND ativo = 1');
$stmt->execute([$situacaoId]);
$situacao = $stmt->fetch();

if (!$ids || !$situacao) {
    flash('danger', 'Selecione ao menos uma turma e uma situação válida.');
    redirect($voltar);
}

// Cada turma em sua própria transação: uma barrada (ex.: cancelar com
// alunos ativos) não impede as outras de mudarem.
$alteradas = 0;
$concluidas = $canceladas = $reativadas = 0;
$recusas = [];
// Concluir e reabrir turma concluída pedem concluir_turma; as demais
// mudanças de situação são de quem edita turmas (editar_turma).
$stmtAtual = $pdo->prepare('SELECT t.nome, s.comportamento FROM turmas t JOIN situacoes_turma s ON s.id = t.situacao_id WHERE t.id = ?');
foreach ($ids as $turmaId) {
    $stmtAtual->execute([$turmaId]);
    $atual = $stmtAtual->fetch();
    $mexeConclusao = $situacao['comportamento'] === 'concluida' || ($atual && $atual['comportamento'] === 'concluida');
    if ($mexeConclusao ? !usuarioPodeConcluirTurma() : !usuarioPodeEditarTurma()) {
        $recusas[] = 'Turma ' . ($atual['nome'] ?? $turmaId) . ': seu perfil não pode ' . ($mexeConclusao ? 'concluir nem reabrir turmas.' : 'mudar a situação da turma.');
        continue;
    }
    $pdo->beginTransaction();
    $resultado = aplicarSituacaoTurma($pdo, $turmaId, $situacao, input('data_conclusao') ?: null, input('data_inicio') ?: null);
    if ($resultado['ok']) {
        $pdo->commit();
        $alteradas++;
        $concluidas += $resultado['concluidas'];
        $canceladas += $resultado['canceladas'];
        $reativadas += $resultado['reativadas'];
    } else {
        $pdo->rollBack();
        $recusas[] = $resultado['mensagem'];
    }
}

if ($alteradas) {
    registrarAtividade('turmas', 'edicao', "Situação de {$alteradas} turma(s) alterada para {$situacao['nome']}");
    flash('success', "{$alteradas} turma(s) agora em \"{$situacao['nome']}\"." . resumoSituacaoTurma($concluidas, $canceladas, $reativadas));
}
foreach ($recusas as $mensagem) {
    flash('warning', $mensagem);
}

redirect($voltar);
