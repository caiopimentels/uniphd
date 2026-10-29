<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

// Aviso visto (abriu ou fechou a faixa / a janela) ou confirmado ("Li e
// entendi"). Aluno: só aviso que ele recebe. Visitante sem login: só os de
// "todo mundo", guardado no cookie do navegador.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responderJson(['sucesso' => false]);
}
$pdo = getDB();
$avisoId = (int) ($_POST['id'] ?? 0);
if (!alunoLogado()) {
    $ehDoSite = in_array($avisoId, array_map(fn ($a) => (int) $a['id'], avisosVisitante($pdo)), true);
    if ($ehDoSite) {
        marcarVistoVisitante($avisoId);
    }
    responderJson(['sucesso' => $ehDoSite]);
}
$aviso = avisoDoAluno($pdo, alunoAtualId(), $avisoId);
if (!$aviso) {
    responderJson(['sucesso' => false]);
}
if (($_POST['confirmar'] ?? '') === '1') {
    confirmarAvisoAluno($pdo, (int) $aviso['id'], alunoAtualId());
} else {
    marcarAvisoVisto($pdo, (int) $aviso['id'], alunoAtualId());
}
responderJson(['sucesso' => true]);
