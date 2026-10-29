<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirPermissao('disparar_email', 'Seu perfil não pode disparar e-mails em massa.');

// Disparos em massa: andamento de cada um (enviados, falhas).
$pdo = getDB();
$disparos = $pdo->query('SELECT * FROM email_disparos ORDER BY id DESC LIMIT 200')->fetchAll();
$situacoes = ['enviando' => ['Enviando', 'primary'], 'pausado' => ['Pausado', 'warning'], 'concluido' => ['Concluído', 'success'], 'cancelado' => ['Cancelado', 'secondary']];
$cfg = emailConfig($pdo);

$pageTitle = 'Disparo de e-mails';
$pageBreadcrumb = 'E-mail';
$activeMenu = 'email-disparos';
$pageAction = '<a href="/email/disparo_novo.php" class="btn btn-brand"><i class="bi bi-plus-lg"></i> Novo disparo</a>';
require __DIR__ . '/../../includes/layout_header.php';
?>
<p class="text-muted small">E-mail para muitos alunos de uma vez (turma, curso, todos...), com tags como <code>{nome_aluno}</code>. Saem aos poucos, até <?= (int) $cfg['disparo_por_hora'] ?> por hora, para o provedor não bloquear.</p>
<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead><tr><th>Assunto</th><th>Para</th><th>Situação</th><th style="width: 220px">Andamento</th><th>Criado</th></tr></thead>
            <tbody>
            <?php if (!$disparos): ?><tr><td colspan="5" class="text-center text-muted py-4">Nenhum disparo ainda.</td></tr><?php endif; ?>
            <?php foreach ($disparos as $d): [$st, $cor] = $situacoes[$d['status']]; $feitos = $d['enviados'] + $d['falhas']; $pct = $d['total'] ? round($feitos / $d['total'] * 100) : 0; ?>
                <tr class="cursor-pointer" onclick="location.href='/email/disparo.php?id=<?= (int) $d['id'] ?>'">
                    <td class="fw-semibold"><?= e($d['assunto']) ?><?php if ($d['anexos'] && $d['anexos'] !== '[]'): ?> <i class="bi bi-paperclip text-muted"></i><?php endif; ?></td>
                    <td class="small"><?= e(publicosDisparo()[$d['publico']]) ?></td>
                    <td><span class="badge text-bg-<?= $cor ?>"><?= $st ?></span></td>
                    <td>
                        <div class="progress" style="height: 8px"><div class="progress-bar bg-success" style="width: <?= $d['total'] ? $d['enviados'] / $d['total'] * 100 : 0 ?>%"></div><div class="progress-bar bg-danger" style="width: <?= $d['total'] ? $d['falhas'] / $d['total'] * 100 : 0 ?>%"></div></div>
                        <div class="small text-muted"><?= (int) $d['enviados'] ?> de <?= (int) $d['total'] ?> enviados<?= $d['falhas'] ? ' · <span class="text-danger">' . (int) $d['falhas'] . ' falha(s)</span>' : '' ?></div>
                    </td>
                    <td class="small text-nowrap"><?= formatDateTime($d['criado_em']) ?><div class="text-muted"><?= e($d['usuario_nome'] ?? '') ?></div></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
