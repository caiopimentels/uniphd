<?php
// Abas das telas de configuração. Espera $abaAtiva ('sistema', 'empresa', 'contas', 'situacoes' ou 'auditoria').
$abaAtiva = $abaAtiva ?? 'sistema';
?>
<ul class="nav nav-tabs mb-4">
    <li class="nav-item">
        <a class="nav-link <?= $abaAtiva === 'sistema' ? 'active' : '' ?>" href="/configuracoes/form.php">
            <i class="bi bi-sliders"></i> Sistema
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $abaAtiva === 'empresa' ? 'active' : '' ?>" href="/configuracoes/empresa.php">
            <i class="bi bi-building"></i> Empresa
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $abaAtiva === 'contas' ? 'active' : '' ?>" href="/contas_bancarias/listar.php">
            <i class="bi bi-bank"></i> Contas bancárias
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $abaAtiva === 'situacoes' ? 'active' : '' ?>" href="/situacoes_turma/listar.php">
            <i class="bi bi-list-check"></i> Cadastros auxiliares
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $abaAtiva === 'auditoria' ? 'active' : '' ?>" href="/configuracoes/auditoria.php">
            <i class="bi bi-clock-history"></i> Auditoria
        </a>
    </li>
</ul>
