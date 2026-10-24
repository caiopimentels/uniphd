<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/documento.php';
exigirLogin();
exigirCursos();

// Lista de alunos da turma para imprimir: os matriculados (ativos,
// concluídos e os que aguardam pagamento); com ?canceladas=1, também os
// cancelados. Contato (telefone e e-mail) só com ?contato=1.
$pdo = getDB();
$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare(
    'SELECT t.*, c.nome AS curso_nome, c.carga_horaria, p.nome AS professor_nome
     FROM turmas t JOIN cursos c ON c.id = t.curso_id LEFT JOIN professores p ON p.id = t.professor_id WHERE t.id = ?'
);
$stmt->execute([$id]);
$turma = $stmt->fetch();
if (!$turma) {
    flash('danger', 'Turma não encontrada.');
    redirect('/turmas/listar.php');
}

$comCanceladas = !empty($_GET['canceladas']);
$comContato = !empty($_GET['contato']);
$stmt = $pdo->prepare(
    "SELECT m.status, m.lista_espera, a.nome, a.cpf, a.telefone, a.email
     FROM matriculas m JOIN alunos a ON a.id = m.aluno_id
     WHERE m.turma_id = ? AND m.status IN ('ativa', 'concluida', 'pendente'" . ($comCanceladas ? ", 'cancelada'" : '') . ')
     ORDER BY a.nome'
);
$stmt->execute([$id]);
$alunos = $stmt->fetchAll();
$situacao = fn (array $a) => match (true) {
    $a['status'] === 'pendente' && $a['lista_espera'] => 'Lista de espera',
    $a['status'] === 'pendente' => 'Aguardando pagamento',
    default => matriculaStatusLabel($a['status']),
};

$doc = documentoInicio($pdo, 'Lista de alunos · ' . $turma['nome'], '/turmas/visualizar.php?id=' . $id);
// Mesma página trocando uma opção (as desligadas saem do endereço).
$link = fn (array $mudar) => '?' . http_build_query(array_filter(array_merge(['id' => $id, 'canceladas' => (int) $comCanceladas, 'contato' => (int) $comContato], $mudar)));
?>
    <div class="opcoes-impressao">
        <label><input type="checkbox" <?= $comContato ? 'checked' : '' ?> onchange="location.href='<?= e($link(['contato' => $comContato ? 0 : 1])) ?>'"> Mostrar telefone e e-mail</label>
        <label><input type="checkbox" <?= $comCanceladas ? 'checked' : '' ?> onchange="location.href='<?= e($link(['canceladas' => $comCanceladas ? 0 : 1])) ?>'"> Incluir cancelados</label>
    </div>
    <?php documentoCabecalho($doc, 'Lista de alunos', $turma['curso_nome'] . ' · ' . $turma['nome']); ?>

    <div class="doc-secao">
        <div class="doc-grade">
            <div class="dois"><div class="rotulo">Curso</div><?= e($turma['curso_nome']) ?></div>
            <div><div class="rotulo">Turma</div><?= e($turma['nome']) ?></div>
            <div><div class="rotulo">Período</div><?= e(periodoTurma($turma, true)) ?></div>
            <div class="dois"><div class="rotulo">Dias e horário</div><?= e(textoAgendaTurma($turma) ?: '-') ?></div>
            <div><div class="rotulo">Professor(a)</div><?= e($turma['professor_nome'] ?: 'A definir') ?></div>
            <div><div class="rotulo">Local</div><?= e($turma['local'] ?: '-') ?></div>
        </div>
    </div>

    <table>
        <thead><tr><th style="width: 9mm;">Nº</th><th>Aluno</th><th>CPF</th><?php if ($comContato): ?><th>Telefone</th><th>E-mail</th><?php endif; ?><th>Situação</th></tr></thead>
        <tbody>
        <?php if (!$alunos): ?>
            <tr><td colspan="<?= $comContato ? 6 : 4 ?>">Nenhum aluno matriculado.</td></tr>
        <?php endif; ?>
        <?php foreach ($alunos as $i => $a): ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td><?= e($a['nome']) ?></td>
                <td class="text-nowrap"><?= e(cpfFormatado($a['cpf']) ?: '-') ?></td>
                <?php if ($comContato): ?>
                    <td class="text-nowrap"><?= e(telefoneFormatado($a['telefone']) ?: '-') ?></td>
                    <td><?= e($a['email'] ?: '-') ?></td>
                <?php endif; ?>
                <td><?= e($situacao($a)) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="relatorio-meta" style="margin-top: 3mm;"><?= count($alunos) ?> aluno(s)</p>
<?php documentoFim(); ?>
