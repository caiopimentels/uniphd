<?php
// Abas de "Cadastros auxiliares" (dentro de Configurações). Espera
// $abaAuxiliar ('situacoes' ou 'graduacao').
?>
<ul class="nav nav-pills mb-3">
    <li class="nav-item"><a class="nav-link <?= $abaAuxiliar === 'situacoes' ? 'active' : '' ?>" href="/situacoes_turma/listar.php"><i class="bi bi-bookmark-check"></i> Situações da turma</a></li>
    <li class="nav-item"><a class="nav-link <?= $abaAuxiliar === 'graduacao' ? 'active' : '' ?>" href="/tipos_graduacao/listar.php"><i class="bi bi-mortarboard"></i> Tipos de graduação</a></li>
</ul>
