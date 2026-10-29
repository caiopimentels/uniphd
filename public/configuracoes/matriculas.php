<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirAdmin();

// Configurações › Matrículas: regras do trancamento de matrícula.
$pdo = getDB();
$op = opcoesTrancamento();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $valor = fn (string $campo, string $grupo, string $padrao) => isset($op[$grupo][input($campo)]) ? input($campo) : $padrao;
    $pdo->prepare('UPDATE configuracoes SET trancamento_parcelas = ?, trancamento_vaga = ?, trancamento_retorno = ?, trancamento_portal = ? WHERE id = 1')
        ->execute([$valor('trancamento_parcelas', 'parcelas', 'continuar'), $valor('trancamento_vaga', 'vaga', 'liberar'), $valor('trancamento_retorno', 'retorno', 'escolher'), isset($_POST['trancamento_portal']) ? 1 : 0]);
    registrarAtividade('configuracoes', 'edicao', 'Regras do trancamento de matrícula atualizadas');
    flash('success', 'Regras do trancamento salvas. Valem para os próximos trancamentos.');
    redirect('/configuracoes/matriculas.php');
}

$config = buscarConfiguracoes($pdo);
$pageTitle = 'Configurações';
$abaAtiva = 'matriculas';
require __DIR__ . '/../../includes/layout_header.php';
require __DIR__ . '/../../includes/abas_configuracoes.php';
$grupos = [
    'parcelas' => ['trancamento_parcelas', 'bi-cash-stack', 'Mensalidades que ainda vão vencer'],
    'vaga' => ['trancamento_vaga', 'bi-person-badge', 'Vaga na turma'],
    'retorno' => ['trancamento_retorno', 'bi-arrow-return-right', 'Ao destrancar, volta para'],
];
?>
<form method="post" style="max-width: 820px;">
    <div class="secao">
        <div class="secao-titulo"><i class="bi bi-pause-circle"></i> Trancamento de matrícula</div>
        <p class="small text-muted">As regras valem para os próximos trancamentos; quem já está trancado segue a regra do dia em que trancou. As parcelas já vencidas continuam cobradas em qualquer caso.</p>
        <?php foreach ($grupos as $grupo => [$campo, $icone, $titulo]): ?>
            <div class="card mb-3"><div class="card-body">
                <div class="fw-semibold mb-2"><i class="bi <?= $icone ?>"></i> <?= e($titulo) ?></div>
                <?php foreach ($op[$grupo] as $chave => [$rotulo, $explica]): ?>
                    <label class="d-flex gap-2 align-items-start mb-2" style="cursor: pointer">
                        <input type="radio" class="form-check-input mt-1" name="<?= $campo ?>" value="<?= $chave ?>" <?= $config[$campo] === $chave ? 'checked' : '' ?>>
                        <span><span class="fw-semibold"><?= e($rotulo) ?></span><span class="d-block small text-muted"><?= e($explica) ?></span></span>
                    </label>
                <?php endforeach; ?>
            </div></div>
        <?php endforeach; ?>
        <div class="card mb-3"><div class="card-body">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="trancamento_portal" id="tp" <?= $config['trancamento_portal'] ? 'checked' : '' ?>>
                <label class="form-check-label" for="tp"><span class="fw-semibold">Aluno trancado continua vendo materiais e videoaulas no portal</span></label>
            </div>
        </div></div>
        <button class="btn btn-brand"><i class="bi bi-check-lg"></i> Salvar</button>
    </div>
</form>
<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
