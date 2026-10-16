<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

// Página criada pela operadora (Sobre nós, Contato, Promoções...).
$pdo = getDB();
$pagina = buscarPaginaPortal($pdo, 'slug', (string) ($_GET['p'] ?? ''));
if (!$pagina || $pagina['tipo'] !== 'personalizada' || (!$pagina['ativo'] && !emPreviaPortal())) {
    http_response_code(404);
    $tituloPagina = 'Página não encontrada';
    require __DIR__ . '/../../includes/portal_header.php';
    echo '<div class="portal-card portal-vazio"><i class="bi bi-signpost-split"></i>Página não encontrada. <a href="/portal/index.php">Voltar ao início</a></div>';
    require __DIR__ . '/../../includes/portal_footer.php';
    exit;
}
if ($pagina['publico'] === 'alunos' && !emPreviaPortal()) {
    exigirAluno();
}

$tituloPagina = $pagina['titulo'];
require __DIR__ . '/../../includes/portal_header.php';
$blocosRender = blocosPagina($pdo, (int) $pagina['id']);
require __DIR__ . '/../../includes/portal_blocos_render.php';
if (!$blocosRender) {
    echo '<div class="portal-card portal-vazio"><i class="bi bi-hammer"></i>Página em construção.</div>';
}
require __DIR__ . '/../../includes/portal_footer.php';
