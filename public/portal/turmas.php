<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirTelaPortal('cursos');

$pdo = getDB();
$tituloPagina = 'Cursos com inscrições abertas';
$telaPortal = 'cursos';
require __DIR__ . '/../../includes/portal_header.php';
$turmasCartao = turmasAbertasPortal($pdo);
?>
<h1 class="portal-titulo">Cursos com inscrições abertas</h1>
<p class="portal-sub">Escolha a turma, veja os detalhes e garanta sua vaga.</p>
<?php require __DIR__ . '/../../includes/portal_bloco_turmas.php'; ?>
<?php require __DIR__ . '/../../includes/portal_footer.php'; ?>
