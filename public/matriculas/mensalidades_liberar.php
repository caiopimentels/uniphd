<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();
exigirMatriculas();

// Turma › Financeiro: lança as mensalidades que ficaram pendentes (turma com
// início a definir — na matrícula só entrou a taxa). Um ou vários alunos;
// 1º vencimento escolhido e as demais no mesmo dia dos meses seguintes.
$pdo = getDB();
$turmaId = (int) ($_POST['turma_id'] ?? 0);
$voltar = '/turmas/visualizar.php?id=' . $turmaId;
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$turmaId) {
    redirect('/turmas/listar.php');
}

$primeiro = (string) ($_POST['primeiro_vencimento'] ?? '');
$data = DateTime::createFromFormat('!Y-m-d', $primeiro);
if (!$data || $data->format('Y-m-d') !== $primeiro) {
    flash('warning', 'Escolha a data do 1º vencimento.');
    redirect($voltar);
}
if ($primeiro < date('Y-m-d', strtotime('-60 days')) || $primeiro > date('Y-m-d', strtotime('+2 years'))) {
    flash('warning', 'O 1º vencimento parece errado (' . formatDate($primeiro) . '). Confira a data.');
    redirect($voltar);
}

// Desconto por pontualidade: "manter" (o de cada matrícula), "nenhum", ou um
// novo para todos os marcados (mesmas regras e alçada da matrícula).
$modoPontualidade = in_array($_POST['pontualidade'] ?? '', ['nenhum', 'valor', 'percentual'], true) ? $_POST['pontualidade'] : 'manter';
$pontualidade = null;
if ($modoPontualidade !== 'manter') {
    if (!usuarioTemPermissao('editar_financeiro_aluno')) {
        flash('danger', 'Seu perfil não pode alterar o desconto por pontualidade. Escolha "Manter como está".');
        redirect($voltar);
    }
    $pontualidade = [
        'tipo' => $modoPontualidade,
        'valor' => $modoPontualidade === 'nenhum' ? 0 : decimalInput('pontualidade_valor', 0),
        'dias' => $modoPontualidade === 'nenhum' ? 0 : (int) ($_POST['pontualidade_dias'] ?? 0),
    ];
}

// Só matrículas desta turma.
$ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])))));
$stmt = $pdo->prepare('SELECT id FROM matriculas WHERE turma_id = ? AND mensalidades_pendentes = 1 AND id IN (' . ($ids ? implode(',', $ids) : '0') . ')');
$stmt->execute([$turmaId]);
$validos = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
if (!$validos) {
    flash('warning', 'Nenhum dos alunos marcados está com o financeiro pendente.');
    redirect($voltar);
}

$feitos = 0;
$parcelas = 0;
$erros = [];
foreach ($validos as $matriculaId) {
    $r = liberarMensalidadesMatricula($pdo, $matriculaId, $primeiro, usuarioAtualId(), $pontualidade);
    if ($r['ok']) {
        $feitos++;
        $parcelas += $r['parcelas'];
    } else {
        $erros[] = $r['mensagem'];
    }
}
if ($feitos) {
    registrarAtividade('matriculas', 'edicao', "Mensalidades lançadas na turma #{$turmaId}: {$feitos} aluno(s), {$parcelas} parcela(s), 1º vencimento " . formatDate($primeiro));
}
flash($erros ? 'warning' : 'success', ($feitos ? "Financeiro liberado para {$feitos} aluno(s): {$parcelas} mensalidade(s) lançada(s) em contas a receber, a partir de " . formatDate($primeiro) . '.' : '') . ($erros ? ' ' . implode(' ', $erros) : ''));
redirect($voltar);
