<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirAdmin();

// Pedidos da secretaria para matricular aluno inadimplente: o administrador
// aprova (vale para uma matrícula, por 2 dias) ou nega.
$pdo = getDB();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    $decisao = input('decisao') === 'aprovar' ? 'aprovada' : 'negada';
    $stmt = $pdo->prepare(
        "SELECT l.*, a.nome AS aluno_nome FROM liberacoes_inadimplencia l JOIN alunos a ON a.id = l.aluno_id WHERE l.id = ? AND l.status = 'pendente'"
    );
    $stmt->execute([$id]);
    $pedido = $stmt->fetch();
    if ($pedido) {
        $pdo->prepare('UPDATE liberacoes_inadimplencia SET status = ?, decidido_por = ?, decidido_em = NOW() WHERE id = ?')
            ->execute([$decisao, usuarioAtualId(), $id]);
        registrarAtividade('matriculas', 'edicao', 'Liberação de matrícula ' . ($decisao === 'aprovada' ? 'aprovada' : 'negada') . ': ' . $pedido['aluno_nome']);
        flash('success', 'Pedido de ' . $pedido['aluno_nome'] . ($decisao === 'aprovada' ? ' aprovado — a secretaria já pode matricular (vale por 2 dias).' : ' negado.'));
    }
    redirect('/matriculas/liberacoes.php');
}

$pedidos = $pdo->query(
    "SELECT l.*, a.nome AS aluno_nome, t.nome AS turma_nome, c.nome AS curso_nome, us.nome AS solicitante, ud.nome AS decisor
     FROM liberacoes_inadimplencia l
     JOIN alunos a ON a.id = l.aluno_id
     LEFT JOIN turmas t ON t.id = l.turma_id
     LEFT JOIN cursos c ON c.id = t.curso_id
     LEFT JOIN usuarios us ON us.id = l.solicitado_por
     LEFT JOIN usuarios ud ON ud.id = l.decidido_por
     ORDER BY l.status = 'pendente' DESC, l.criado_em DESC
     LIMIT 100"
)->fetchAll();
$rotulos = ['pendente' => ['Aguardando', 'text-bg-warning'], 'aprovada' => ['Aprovada', 'text-bg-success'], 'negada' => ['Negada', 'text-bg-secondary'], 'usada' => ['Usada na matrícula', 'text-bg-light']];

$pageTitle = 'Liberações de matrícula';
$pageBreadcrumb = 'Administração';
$activeMenu = 'liberacoes';
require __DIR__ . '/../../includes/layout_header.php';
?>
<p class="text-muted small">Pedidos para matricular aluno com parcela em atraso (Configurações › Matrículas › "Só com autorização de um administrador"). A aprovação vale para uma matrícula, por 2 dias.</p>
<div class="card">
    <div class="table-responsive">
        <table class="table mb-0 align-middle">
            <thead><tr><th>Pedido em</th><th>Aluno</th><th>Turma</th><th class="text-end">Em atraso</th><th>Pedido por</th><th>Situação</th><th class="text-end">Decisão</th></tr></thead>
            <tbody>
            <?php if (!$pedidos): ?><tr><td colspan="7" class="text-center text-muted py-4">Nenhum pedido.</td></tr><?php endif; ?>
            <?php foreach ($pedidos as $p): ?>
                <tr>
                    <td><?= formatDateTime($p['criado_em']) ?></td>
                    <td>
                        <a href="/alunos/form.php?id=<?= (int) $p['aluno_id'] ?>&tab=financeiro"><?= e($p['aluno_nome']) ?></a>
                        <?php if ($p['motivo']): ?><div class="small text-muted">“<?= e($p['motivo']) ?>”</div><?php endif; ?>
                    </td>
                    <td><?= $p['turma_nome'] ? e($p['curso_nome'] . ' · ' . $p['turma_nome']) : '-' ?></td>
                    <td class="text-end text-danger"><?= formatMoney($p['valor_atraso']) ?></td>
                    <td><?= e($p['solicitante'] ?: '-') ?></td>
                    <td>
                        <span class="badge <?= $rotulos[$p['status']][1] ?>"><?= $rotulos[$p['status']][0] ?></span>
                        <?php if ($p['decisor']): ?><div class="small text-muted">por <?= e($p['decisor']) ?> em <?= formatDateTime($p['decidido_em']) ?></div><?php endif; ?>
                    </td>
                    <td class="text-end text-nowrap">
                        <?php if ($p['status'] === 'pendente'): ?>
                            <form method="post" class="d-inline">
                                <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                                <button type="submit" name="decisao" value="aprovar" class="btn btn-sm btn-outline-success"><i class="bi bi-check-lg"></i> Aprovar</button>
                                <button type="submit" name="decisao" value="negar" class="btn btn-sm btn-outline-danger"><i class="bi bi-x-lg"></i> Negar</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
