<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/acoes_massa.php';
exigirLogin();

// Ativar, desativar ou excluir os alunos marcados na lista (painel lateral).
executarAcaoMassaCadastro(getDB(), [
    'tabela' => 'alunos',
    'modulo' => 'alunos',
    'rotulo' => 'aluno',
    'lista' => '/alunos/listar.php',
    'perm_ativar' => 'ativar_aluno',
    'perm_excluir' => 'excluir_aluno',
    'impedimento' => function (PDO $pdo, int $id): ?string {
        $stmt = $pdo->prepare('SELECT (SELECT COUNT(*) FROM matriculas WHERE aluno_id = ?) + (SELECT COUNT(*) FROM contas_receber WHERE aluno_id = ?)');
        $stmt->execute([$id, $id]);
        return (int) $stmt->fetchColumn() > 0 ? 'tem matrícula ou lançamento financeiro' : null;
    },
]);
