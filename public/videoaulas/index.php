<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirPermissao('gerenciar_materiais', 'Seu perfil não envia materiais.');

// Videoaulas (curso online): um cartão por curso — no topo as videoaulas do
// curso (valem para todas as turmas), embaixo as turmas dele, cada uma com
// as só dela. Busca na hora e filtro "ativas / todas". Professor ligado ao
// usuário vê só as turmas dele (e os cursos delas).
$pdo = getDB();
$prof = professorDoUsuario($pdo);

$sql = "SELECT t.id, t.nome, t.data_inicio, t.curso_id, s.nome AS situacao_nome, s.cor AS situacao_cor, s.comportamento, p.nome AS professor_nome,
               (SELECT COUNT(*) FROM videoaulas v WHERE v.turma_id = t.id) AS extras,
               (SELECT COUNT(*) FROM matriculas m WHERE m.turma_id = t.id AND m.status IN ('ativa', 'pendente', 'concluida')) AS alunos
        FROM turmas t JOIN situacoes_turma s ON s.id = t.situacao_id LEFT JOIN professores p ON p.id = t.professor_id";
if ($prof !== null) {
    $sql .= ' WHERE t.id IN (' . implode(',', turmasDoProfessor($pdo, $prof) ?: [0]) . ')';
}
$turmas = $pdo->query($sql . " ORDER BY FIELD(s.comportamento, 'em_andamento', 'aberta', 'concluida', 'cancelada'), t.data_inicio IS NULL, t.data_inicio DESC, t.nome")->fetchAll();

$porCurso = [];
foreach ($turmas as $t) {
    $porCurso[(int) $t['curso_id']][] = $t;
}
$filtroCursos = $prof !== null ? ' WHERE c.id IN (' . implode(',', array_keys($porCurso) ?: [0]) . ')' : ' WHERE c.ativo = 1 OR c.id IN (SELECT curso_id FROM videoaulas WHERE curso_id IS NOT NULL)';
$cursos = $pdo->query('SELECT c.id, c.nome, (SELECT COUNT(*) FROM videoaulas v WHERE v.curso_id = c.id) AS materiais FROM cursos c' . $filtroCursos . ' ORDER BY c.nome')->fetchAll();

// Números do topo (do que este usuário vê).
$idsTurmas = array_map(fn ($t) => (int) $t['id'], $turmas) ?: [0];
$idsCursos = array_map(fn ($c) => (int) $c['id'], $cursos) ?: [0];
$condicao = '(turma_id IN (' . implode(',', $idsTurmas) . ') OR curso_id IN (' . implode(',', $idsCursos) . '))';
$totalMateriais = (int) $pdo->query("SELECT COUNT(*) FROM videoaulas WHERE $condicao")->fetchColumn();
$alunosAbriram = (int) $pdo->query("SELECT COUNT(DISTINCT p.aluno_id) FROM videoaula_progresso p JOIN videoaulas v ON v.id = p.videoaula_id WHERE $condicao")->fetchColumn();
$concluidas = (int) $pdo->query("SELECT COUNT(*) FROM videoaula_progresso p JOIN videoaulas v ON v.id = p.videoaula_id WHERE p.concluido_em IS NOT NULL AND $condicao")->fetchColumn();
$ativa = fn ($t) => in_array($t['comportamento'], ['aberta', 'em_andamento'], true);

$pageTitle = 'Videoaulas';
$pageBreadcrumb = $prof !== null ? 'Minhas turmas' : 'Acadêmico';
$activeMenu = 'videoaulas';
require __DIR__ . '/../../includes/layout_header.php';
?>
<style>
    .mat-kpi { display: flex; align-items: center; gap: 14px; }
    .mat-kpi .icone { width: 44px; height: 44px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.25rem; background: var(--accent-soft); color: var(--accent-dark, var(--accent)); flex: none; }
    .mat-barra { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin-bottom: 16px; }
    .mat-barra .busca { flex: 1; min-width: 240px; max-width: 420px; }
    .mat-cursos { columns: 3 400px; column-gap: 16px; }
    .mat-curso { background: var(--bs-body-bg); border: 1px solid var(--bs-border-color); border-radius: 14px; overflow: hidden; break-inside: avoid; margin-bottom: 16px; display: inline-block; width: 100%; }
    .mat-curso-topo { display: flex; align-items: center; gap: 12px; padding: 14px 16px; border-bottom: 1px solid var(--bs-border-color); background: var(--bs-tertiary-bg); }
    .mat-curso-topo .nome { font-weight: 600; line-height: 1.25; flex: 1; min-width: 0; }
    .mat-curso-material { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; padding: 5px 10px; border-radius: 8px; border: 1px solid var(--bs-border-color); background: var(--bs-body-bg); color: inherit; text-decoration: none; white-space: nowrap; }
    .mat-curso-material:hover { border-color: var(--accent); color: var(--accent-dark, var(--accent)); }
    .mat-curso-material .n { font-weight: 700; }
    .mat-turma { display: flex; align-items: center; gap: 12px; padding: 11px 16px; color: inherit; text-decoration: none; border-bottom: 1px solid var(--bs-border-color-translucent); }
    .mat-turma:last-child { border-bottom: 0; }
    .mat-turma:hover { background: var(--accent-soft); }
    .mat-turma .ponto { width: 9px; height: 9px; border-radius: 50%; flex: none; }
    .mat-turma .info { flex: 1; min-width: 0; }
    .mat-turma .titulo { font-weight: 500; }
    .mat-turma .meta { font-size: 12.5px; color: var(--bs-secondary-color); }
    .mat-turma .extras { font-size: 12px; font-weight: 600; padding: 3px 9px; border-radius: 20px; background: var(--accent-soft); color: var(--accent-dark, var(--accent)); white-space: nowrap; }
    .mat-turma .extras.zero { background: transparent; color: var(--bs-secondary-color); font-weight: 400; }
    .mat-vazio-curso { padding: 14px 16px; font-size: 13px; color: var(--bs-secondary-color); }
</style>

<div class="row g-3 mb-4">
    <div class="col-md-4"><div class="card h-100"><div class="card-body mat-kpi">
        <span class="icone"><i class="bi bi-play-btn"></i></span>
        <div><div class="kpi-rotulo">Videoaulas publicadas</div><div class="kpi-valor"><?= $totalMateriais ?></div></div>
    </div></div></div>
    <div class="col-md-4"><div class="card h-100"><div class="card-body mat-kpi">
        <span class="icone"><i class="bi bi-eye"></i></span>
        <div><div class="kpi-rotulo">Alunos que já assistiram</div><div class="kpi-valor"><?= $alunosAbriram ?></div></div>
    </div></div></div>
    <div class="col-md-4"><div class="card h-100"><div class="card-body mat-kpi">
        <span class="icone"><i class="bi bi-check2-circle"></i></span>
        <div><div class="kpi-rotulo">Aulas concluídas pelos alunos</div><div class="kpi-valor"><?= $concluidas ?></div></div>
    </div></div></div>
</div>

<div class="mat-barra">
    <div class="input-group busca">
        <span class="input-group-text"><i class="bi bi-search"></i></span>
        <input type="search" class="form-control" id="busca" placeholder="Procurar curso ou turma..." oninput="filtrar()" autocomplete="off">
    </div>
    <div class="btn-group" role="group">
        <input type="radio" class="btn-check" name="mostrar" id="mostrar-ativas" value="ativas" checked onchange="filtrar()"><label class="btn btn-outline-secondary" for="mostrar-ativas">Turmas ativas</label>
        <input type="radio" class="btn-check" name="mostrar" id="mostrar-todas" value="todas" onchange="filtrar()"><label class="btn btn-outline-secondary" for="mostrar-todas">Todas</label>
    </div>
    <span class="small text-muted ms-auto"><i class="bi bi-info-circle"></i> <strong>Videoaulas do curso</strong> valem para todas as turmas; as da turma, só para ela.</span>
</div>

<?php if (!$cursos): ?>
    <div class="secao text-center text-muted py-5"><i class="bi bi-play-btn fs-1 d-block mb-2"></i>
        <?= $prof !== null ? 'Você ainda não está em nenhuma turma. Peça à secretaria para te liberar na turma.' : 'Nenhum curso cadastrado.' ?></div>
<?php endif; ?>

<div class="mat-cursos" id="lista-cursos">
    <?php foreach ($cursos as $c): $lista = $porCurso[(int) $c['id']] ?? []; ?>
        <div class="mat-curso" data-busca="<?= e(mb_strtolower($c['nome'] . ' ' . implode(' ', array_column($lista, 'nome')))) ?>" data-tem-ativa="<?= array_filter($lista, $ativa) ? 1 : 0 ?>" data-tem-material="<?= (int) $c['materiais'] > 0 ? 1 : 0 ?>">
            <div class="mat-curso-topo">
                <div class="nome"><?= e($c['nome']) ?></div>
                <?php if ($prof === null || podeMaterialCurso($pdo, (int) $c['id'])): ?>
                    <a class="mat-curso-material" href="/videoaulas/gerenciar.php?curso=<?= (int) $c['id'] ?>" title="Videoaulas do curso (aparecem em todas as turmas)">
                        <i class="bi bi-collection-play"></i> Aulas do curso <span class="n"><?= (int) $c['materiais'] ?></span>
                    </a>
                <?php endif; ?>
            </div>
            <?php foreach ($lista as $t): ?>
                <a class="mat-turma" href="/videoaulas/gerenciar.php?turma=<?= (int) $t['id'] ?>" data-ativa="<?= $ativa($t) ? 1 : 0 ?>" data-busca="<?= e(mb_strtolower($c['nome'] . ' ' . $t['nome'])) ?>">
                    <span class="ponto" style="background: <?= e($t['situacao_cor'] ?: '#9ca3af') ?>" title="<?= e($t['situacao_nome']) ?>"></span>
                    <span class="info">
                        <span class="titulo d-block text-truncate"><?= e($t['nome']) ?></span>
                        <span class="meta"><?= e($t['situacao_nome']) ?> · <?= $t['data_inicio'] ? 'início ' . formatDate($t['data_inicio']) : 'início a definir' ?> · <?= (int) $t['alunos'] ?> aluno<?= (int) $t['alunos'] === 1 ? '' : 's' ?><?= $t['professor_nome'] ? ' · ' . e($t['professor_nome']) : '' ?></span>
                    </span>
                    <span class="extras <?= (int) $t['extras'] ? '' : 'zero' ?>"><?= (int) $t['extras'] ? (int) $t['extras'] . ' da turma' : 'só as do curso' ?></span>
                    <i class="bi bi-chevron-right text-muted"></i>
                </a>
            <?php endforeach; ?>
            <div class="mat-vazio-curso" <?= $lista ? 'style="display:none"' : '' ?>><?= $lista ? 'Nenhuma turma ativa — clique em "Todas" para ver as encerradas.' : 'Sem turmas cadastradas.' ?></div>
        </div>
    <?php endforeach; ?>
</div>
<div class="text-center text-muted py-5 d-none" id="nada"><i class="bi bi-search fs-2 d-block mb-2"></i>Nada encontrado.</div>

<script>
const semAcento = (t) => String(t || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
function filtrar() {
    const termo = semAcento(document.getElementById('busca').value.trim());
    const todas = document.getElementById('mostrar-todas').checked;
    let visiveis = 0;
    document.querySelectorAll('.mat-curso').forEach((curso) => {
        const cursoCombina = !termo || semAcento(curso.querySelector('.nome').textContent).includes(termo);
        let turmasVisiveis = 0;
        const turmas = curso.querySelectorAll('.mat-turma');
        turmas.forEach((t) => {
            const mostra = (todas || t.dataset.ativa === '1') && (!termo || cursoCombina || semAcento(t.dataset.busca).includes(termo));
            t.style.display = mostra ? '' : 'none';
            if (mostra) turmasVisiveis++;
        });
        // Curso aparece se combina com a busca e tem turma visível, ou (em "Todas"/busca) mesmo sem turma.
        const mostraCurso = turmasVisiveis > 0 || (cursoCombina && (todas || termo !== '' || curso.dataset.temMaterial === '1'));
        curso.style.display = mostraCurso ? '' : 'none';
        const vazio = curso.querySelector('.mat-vazio-curso');
        vazio.style.display = mostraCurso && turmasVisiveis === 0 ? '' : 'none';
        vazio.textContent = turmas.length ? 'Nenhuma turma ativa — clique em "Todas" para ver as outras.' : 'Sem turmas cadastradas.';
        if (mostraCurso) visiveis++;
    });
    document.getElementById('nada').classList.toggle('d-none', visiveis > 0);
}
filtrar();
</script>
<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
