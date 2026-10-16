<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/acoes_massa.php';
exigirLogin();

// Ativar, desativar ou excluir os cursos marcados na lista (painel lateral).
executarAcaoMassaCadastro(getDB(), [
    'tabela' => 'cursos',
    'modulo' => 'cursos',
    'rotulo' => 'curso',
    'lista' => '/cursos/listar.php',
    'perm_ativar' => 'editar_curso',
    'perm_excluir' => 'excluir_curso',
    'impedimento' => function (PDO $pdo, int $id): ?string {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM turmas WHERE curso_id = ?');
        $stmt->execute([$id]);
        return (int) $stmt->fetchColumn() > 0 ? 'tem turma' : null;
    },
]);
