<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();
exigirPermissaoAcao('criar_turma', 'Seu perfil não pode criar turmas.');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    responderJson(['sucesso' => false, 'mensagem' => 'Método inválido.']);
}

$pdo = getDB();

$cursoId = (int) ($_POST['curso_id'] ?? 0);
$professorId = (int) ($_POST['professor_id'] ?? 0) ?: null;
$nome = input('nome');
$vagas = (int) ($_POST['vagas'] ?? 0);
$dataInicio = input('data_inicio');
$duracaoMeses = (int) ($_POST['duracao_meses'] ?? 0);
$duracaoUnidade = input('duracao_unidade') === 'dia' ? 'dia' : 'mes';
// Valor do curso; a mensalidade sai de total ÷ parcelas (uma por mês; curso em dias: uma só).
$valorTotal = decimalInput('valor_total', 0);
$valorMensalidade = mensalidadeDoTotal($valorTotal, parcelasPadraoTurma(['duracao_meses' => max(1, $duracaoMeses), 'duracao_unidade' => $duracaoUnidade]));

$erros = [];
$stmt = $pdo->prepare('SELECT nome FROM cursos WHERE id = ? AND ativo = 1');
$stmt->execute([$cursoId]);
$cursoNome = $stmt->fetchColumn();
if (!$cursoNome) {
    $erros[] = 'Escolha um curso ativo.';
}
if ($nome === '') {
    $erros[] = 'Dê um nome à turma.';
}
if ($vagas < 1) {
    $erros[] = 'O número máximo de alunos deve ser pelo menos 1.';
}
if (!$dataInicio) {
    $erros[] = 'Informe a data de início.';
}
if ($duracaoMeses < 1) {
    $erros[] = 'Informe a duração da turma.';
}
if ($valorTotal < 0) {
    $erros[] = 'O valor do curso não pode ser negativo.';
}

// A turma nasce na situação padrão — que sempre aceita matrícula (regra do
// cadastro de situações), então já pode receber o aluno em seguida.
$situacaoId = situacaoPadraoTurmaId($pdo);
if (!$situacaoId) {
    $erros[] = 'Não há situação de turma ativa cadastrada (Configurações › Situações de turma).';
}

if ($erros) {
    responderJson(['sucesso' => false, 'mensagem' => implode(' ', $erros)]);
}

$stmt = $pdo->prepare(
    'INSERT INTO turmas (curso_id, professor_id, nome, vagas, data_inicio, duracao_meses, duracao_unidade, valor_mensalidade, valor_total, situacao_id)
     VALUES (?,?,?,?,?,?,?,?,?,?)'
);
$stmt->execute([$cursoId, $professorId, $nome, $vagas, $dataInicio, $duracaoMeses, $duracaoUnidade, $valorMensalidade, $valorTotal, $situacaoId]);
$id = (int) $pdo->lastInsertId();

registrarAtividade('turmas', 'criacao', 'Turma criada (cadastro rápido): ' . $nome);

$stmt = $pdo->prepare('SELECT data_termino FROM turmas WHERE id = ?');
$stmt->execute([$id]);

responderJson([
    'sucesso' => true,
    'turma' => [
        'id' => $id,
        'nome' => $nome,
        'curso_nome' => $cursoNome,
        'vagas' => $vagas,
        'ocupadas' => 0,
        'inicio' => $dataInicio,
        'termino' => $stmt->fetchColumn(),
        'meses' => parcelasPadraoTurma(['duracao_meses' => $duracaoMeses, 'duracao_unidade' => $duracaoUnidade]),
        'unidade' => $duracaoUnidade,
        'duracao' => textoDuracaoTurma(['duracao_meses' => $duracaoMeses, 'duracao_unidade' => $duracaoUnidade]),
        'mensalidade' => $valorMensalidade,
        'total' => $valorTotal,
        'taxa' => 0,
        'parcelas' => 0,
        'dia' => 0,
        'forma_taxa' => null,
        'forma_mensalidade' => null,
    ],
]);
