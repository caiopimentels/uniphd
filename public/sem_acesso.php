<?php
require_once __DIR__ . '/../includes/bootstrap.php';
exigirLogin();

$pageTitle = 'Sem acesso';
$activeMenu = '';
require __DIR__ . '/../includes/layout_header.php';
?>

<div class="card" style="max-width: 480px;">
    <div class="card-body text-center py-5">
        <i class="bi bi-shield-lock" style="font-size: 2.5rem; color: #9ca3af;"></i>
        <h5 class="mt-3">Nenhuma permissão liberada</h5>
        <p class="text-muted">Seu perfil ainda não tem acesso a nenhuma tela. Peça a um administrador para liberar as permissões necessárias em Perfis.</p>
    </div>
</div>

<?php require __DIR__ . '/../includes/layout_footer.php'; ?>
