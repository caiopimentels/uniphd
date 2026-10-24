<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/documento.php';
exigirLogin();
exigirCursos();

// Lista de presença de um módulo da turma, para imprimir: os alunos (ativos
// e concluídos) com a presença marcada no sistema (janela "Presença" da
// tela da turma) e a coluna de assinatura.
$pdo = getDB();
$id = (int) ($_GET['id'] ?? 0);
$moduloId = (int) ($_GET['modulo'] ?? 0);
$stmt = $pdo->prepare(
    'SELECT t.*, c.nome AS curso_nome, p.nome AS professor_nome
     FROM turmas t JOIN cursos c ON c.id = t.curso_id LEFT JOIN professores p ON p.id = t.professor_id WHERE t.id = ?'
);
$stmt->execute([$id]);
$turma = $stmt->fetch();
$stmt = $pdo->prepare('SELECT id, nome, carga_horaria FROM curso_modulos WHERE curso_id = ? ORDER BY ordem, id');
$stmt->execute([$turma['curso_id'] ?? 0]);
$modulos = $stmt->fetchAll();
$numero = array_search($moduloId, array_map('intval', array_column($modulos, 'id')), true);
if (!$turma || $numero === false) {
    flash('danger', 'Módulo não encontrado nesta turma.');
    redirect($turma ? '/turmas/visualizar.php?id=' . $id : '/turmas/listar.php');
}
$modulo = $modulos[$numero];

$stmt = $pdo->prepare(
    "SELECT a.id, a.nome FROM matriculas m JOIN alunos a ON a.id = m.aluno_id
     WHERE m.turma_id = ? AND m.status IN ('ativa', 'concluida') ORDER BY a.nome"
);
$stmt->execute([$id]);
$alunos = $stmt->fetchAll();
$presencas = presencasModuloTurma($pdo, $id, $moduloId);
$presentes = count(array_filter($presencas, fn ($p) => $p['presente']));
$datas = array_unique(array_filter(array_column($presencas, 'data')));
sort($datas);

$doc = documentoInicio($pdo, 'Lista de presença · Módulo ' . ($numero + 1) . ' · ' . $turma['nome'], '/turmas/visualizar.php?id=' . $id);
documentoCabecalho($doc, 'Lista de presença', $turma['curso_nome'] . ' · ' . $turma['nome']);
?>
    <style>
        .presenca td { height: 9mm; }
        .presenca tr { break-inside: avoid; }
        .presenca .assinatura { border-left: 1px solid #ccc; width: 60mm; }
        .assinatura-professor { display: flex; justify-content: flex-end; margin-top: 14mm; break-inside: avoid; }
        .assinatura-professor div { width: 80mm; border-top: 1px solid #333; padding-top: 1.5mm; text-align: center; font-size: 11px; }
    </style>

    <div class="doc-secao">
        <div class="doc-grade">
            <div class="dois"><div class="rotulo">Curso</div><?= e($turma['curso_nome']) ?></div>
            <div><div class="rotulo">Turma</div><?= e($turma['nome']) ?></div>
            <div><div class="rotulo">Professor(a)</div><?= e($turma['professor_nome'] ?: '-') ?></div>
            <div class="dois"><div class="rotulo">Módulo</div><?= $numero + 1 ?>. <?= e($modulo['nome']) ?></div>
            <div><div class="rotulo">Carga horária do módulo</div><?= $modulo['carga_horaria'] ? (int) $modulo['carga_horaria'] . ' horas' : '-' ?></div>
            <div><div class="rotulo"><?= count($datas) > 1 ? 'Datas' : 'Data' ?></div><?= $datas ? implode(', ', array_map('formatDate', $datas)) : '-' ?></div>
        </div>
    </div>

    <table class="presenca">
        <thead><tr><th style="width: 9mm;">Nº</th><th>Aluno</th><th>Presença</th><th>Assinatura</th></tr></thead>
        <tbody>
        <?php if (!$alunos): ?>
            <tr><td colspan="4">Nenhum aluno ativo na turma.</td></tr>
        <?php endif; ?>
        <?php foreach ($alunos as $i => $a): $p = $presencas[(int) $a['id']] ?? null; ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td><?= e($a['nome']) ?></td>
                <td class="text-nowrap"><?= !$p ? '—' : ($p['presente'] ? 'Presente em ' . formatDate($p['data']) : '<strong>Falta</strong>') ?></td>
                <td class="assinatura"></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="relatorio-meta" style="margin-top: 3mm;"><?= $presentes ?> presente(s) de <?= count($alunos) ?> aluno(s)</p>

    <div class="assinatura-professor"><div><?= e($turma['professor_nome'] ?: 'Professor(a)') ?><br>Assinatura do(a) professor(a)</div></div>
<?php documentoFim(); ?>
