<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

// Página inicial: só os blocos montados em Acadêmico › Portal do aluno.
$pdo = getDB();
$inicio = buscarPaginaPortal($pdo, 'chave', 'inicio');
$tituloPagina = 'Início';
require __DIR__ . '/../../includes/portal_header.php';

$blocosRender = $inicio ? blocosPagina($pdo, (int) $inicio['id']) : [];
require __DIR__ . '/../../includes/portal_blocos_render.php';

// Página sem nenhum bloco: mostra ao menos as turmas abertas.
if (!$blocosRender) {
    $turmasCartao = turmasAbertasPortal($pdo);
    require __DIR__ . '/../../includes/portal_bloco_turmas.php';
}

require __DIR__ . '/../../includes/portal_footer.php';
