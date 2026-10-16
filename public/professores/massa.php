<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/acoes_massa.php';
exigirLogin();

// Ativar, desativar ou excluir os professores marcados na lista (painel lateral).
executarAcaoMassaCadastro(getDB(), [
    'tabela' => 'professores',
    'modulo' => 'professores',
    'rotulo' => 'professor',
    'lista' => '/professores/listar.php',
    'perm_ativar' => 'editar_professor',
    'perm_excluir' => 'excluir_professor',
    'impedimento' => function (PDO $pdo, int $id): ?string {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM turmas WHERE professor_id = ?');
        $stmt->execute([$id]);
        return (int) $stmt->fetchColumn() > 0 ? 'tem turma' : null;
    },
]);
