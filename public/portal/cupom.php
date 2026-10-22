<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

// Confere o cupom digitado na confirmação da inscrição e devolve os
// valores com o desconto (o cupom só é gravado ao confirmar a inscrição).
if (!alunoLogado()) {
    responderJson(['ok' => false, 'mensagem' => 'Entre na sua conta para usar o cupom.']);
}
$pdo = getDB();
$identificador = 'aluno' . alunoAtualId();
// Contra quem tenta adivinhar códigos: 10 erros em 15 minutos bloqueia.
if (limiteDeTentativasExcedido($pdo, 'cupom', $identificador, 10, 30, 15)) {
    responderJson(['ok' => false, 'mensagem' => 'Muitas tentativas. Aguarde alguns minutos e tente de novo.']);
}

$turma = turmasAbertasPortal($pdo, (int) ($_GET['turma_id'] ?? 0))[0] ?? null;
if (!$turma) {
    responderJson(['ok' => false, 'mensagem' => 'Esta turma não está mais com inscrições abertas.']);
}
$validacao = validarCupom($pdo, (string) ($_GET['codigo'] ?? ''), (int) $turma['curso_id'], alunoAtualId());
$parcelas = numParcelasPortal($turma);
$aplicado = $validacao['ok'] ? aplicarCupom($validacao['cupom'], valorTotalTurma($turma), (float) $turma['portal_taxa_matricula']) : $validacao;
if (!$aplicado['ok']) {
    registrarTentativaAcesso($pdo, 'cupom', $identificador);
    responderJson(['ok' => false, 'mensagem' => $aplicado['mensagem']]);
}

$mensalidade = mensalidadeDoTotal($aplicado['valor_total'], $parcelas);
responderJson([
    'ok' => true,
    'codigo' => $validacao['cupom']['codigo'],
    'mensagem' => 'Cupom ' . $validacao['cupom']['codigo'] . ' aplicado: ' . ($validacao['cupom']['tipo'] === 'matricula_zero'
        ? 'matrícula grátis!' : formatMoney($aplicado['desconto']) . ' de desconto no curso.'),
    'taxa' => formatMoney($aplicado['taxa']),
    'taxa_zero' => $aplicado['taxa'] <= 0,
    'curso' => formatMoney($aplicado['valor_total']),
    'parcelas' => $parcelas . 'x de ' . formatMoney($mensalidade),
]);
