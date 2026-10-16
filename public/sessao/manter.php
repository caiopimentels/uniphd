<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();

header('Content-Type: application/json');
echo json_encode(['sucesso' => true, 'restante' => sessaoSegundosRestantes()]);
