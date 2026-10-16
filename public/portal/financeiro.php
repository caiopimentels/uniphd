<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirAluno();

$pdo = getDB();
$tituloPagina = 'Pagamentos';
$telaPortal = 'financeiro';
require __DIR__ . '/../../includes/portal_header.php';

// Tudo o que foi lançado para o aluno, agrupado por turma (cada matrícula)
// e, à parte, os lançamentos avulsos (material, taxa, segunda via...).
$stmt = $pdo->prepare(
    "SELECT cr.*, t.nome AS turma_nome, c.nome AS curso_nome,
            (SELECT COUNT(*) FROM cobrancas_bancarias cb WHERE cb.conta_receber_id = cr.id AND cb.status = 'registrada') AS tem_boleto
     FROM contas_receber cr
     LEFT JOIN matriculas m ON m.id = cr.matricula_id
     LEFT JOIN turmas t ON t.id = m.turma_id
     LEFT JOIN cursos c ON c.id = t.curso_id
     WHERE cr.aluno_id = ? AND cr.status IN ('pendente', 'pago')
     ORDER BY cr.matricula_id IS NULL, cr.matricula_id DESC, cr.data_vencimento, cr.id"
);
$stmt->execute([alunoAtualId()]);
$hoje = date('Y-m-d');

$grupos = [];
foreach ($stmt->fetchAll() as $p) {
    $chave = $p['matricula_id'] ? 'm' . (int) $p['matricula_id'] : 'avulso';
    $grupos[$chave] ??= [
        'curso' => $p['matricula_id'] ? $p['curso_nome'] : 'Outros lançamentos',
        'turma' => $p['matricula_id'] ? $p['turma_nome'] : 'Cobranças fora das mensalidades',
        'avulso' => !$p['matricula_id'],
        'parcelas' => [],
    ];
    $p['vencida'] = $p['status'] === 'pendente' && $p['data_vencimento'] < $hoje;
    $grupos[$chave]['parcelas'][] = $p;
}
// Situação de cada grupo (sem valores): vencida, próximo vencimento ou quitado.
foreach ($grupos as &$g) {
    $abertas = array_values(array_filter($g['parcelas'], fn ($p) => $p['status'] === 'pendente'));
    $g['vencida'] = (bool) array_filter($abertas, fn ($p) => $p['vencida']);
    $g['proximo'] = $abertas[0]['data_vencimento'] ?? null;
}
unset($g);
?>
<h1 class="portal-titulo">Pagamentos</h1>
<p class="portal-sub">Clique em um curso para ver as parcelas.</p>

<?php if (!$grupos): ?>
    <div class="portal-card portal-vazio"><i class="bi bi-emoji-smile"></i>Nenhum lançamento por aqui.</div>
<?php endif; ?>

<?php foreach ($grupos as $g): ?>
    <details class="grupo-financeiro" <?= count($grupos) === 1 ? 'open' : '' ?>>
        <summary>
            <i class="bi <?= $g['avulso'] ? 'bi-receipt' : 'bi-mortarboard' ?> fs-4" style="color: var(--accent);"></i>
            <div class="nome">
                <?= e($g['curso']) ?>
                <div class="s"><?= e($g['turma']) ?></div>
            </div>
            <?php if ($g['vencida']): ?>
                <span class="etiqueta vencida">Parcela vencida</span>
            <?php elseif ($g['proximo']): ?>
                <span class="etiqueta aberta">Próximo vencimento <?= formatDate($g['proximo']) ?></span>
            <?php else: ?>
                <span class="etiqueta paga">Tudo pago</span>
            <?php endif; ?>
            <i class="bi bi-chevron-down seta"></i>
        </summary>
        <div class="parcelas">
            <?php foreach ($g['parcelas'] as $p): $desconto = $p['status'] === 'pendente' ? descontoPontualidadeParcela($pdo, $p) : null; $descontoValendo = $desconto && $desconto['data_limite'] >= $hoje; ?>
                <div class="lista-linha">
                    <div class="principal">
                        <div class="t"><?= e($p['descricao']) ?></div>
                        <div class="s">
                            <?php if ($p['status'] === 'pago'): ?>
                                Pago em <?= formatDate($p['data_recebimento']) ?> <span class="etiqueta paga ms-1">Pago</span>
                            <?php else: ?>
                                Vence <?= formatDate($p['data_vencimento']) ?>
                                <span class="etiqueta <?= $p['vencida'] ? 'vencida' : 'aberta' ?> ms-1"><?= $p['vencida'] ? 'Vencida' : 'Em aberto' ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if ($descontoValendo): ?><div class="s text-success"><?= e($desconto['texto']) ?></div><?php endif; ?>
                    </div>
                    <div class="text-end">
                        <?php if ($p['status'] === 'pago'): ?>
                            <div class="valor"><?= formatMoney($p['valor_recebido'] ?? $p['valor']) ?></div>
                        <?php else: ?>
                            <?php if ($descontoValendo): ?>
                                <div class="valor-riscado"><?= formatMoney($p['valor']) ?></div>
                                <div class="valor text-success"><?= formatMoney($desconto['valor_com_desconto']) ?></div>
                            <?php else: ?>
                                <div class="valor"><?= formatMoney($p['valor']) ?></div>
                            <?php endif; ?>
                            <?php if (!$p['vencida'] || $p['tem_boleto']): ?>
                                <a href="/portal/pagar.php?id=<?= (int) $p['id'] ?>" class="btn btn-sm btn-brand mt-1">Pagar</a>
                            <?php else: ?>
                                <div class="small text-muted mt-1">fale com a secretaria</div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </details>
<?php endforeach; ?>
<?php require __DIR__ . '/../../includes/portal_footer.php'; ?>
