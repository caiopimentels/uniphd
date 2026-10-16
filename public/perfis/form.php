<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirAdmin();

$pdo = getDB();
$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
$perfil = null;
$permissoesSelecionadas = [];

$todasPermissoes = $pdo->query('SELECT * FROM permissoes ORDER BY ordem, id')->fetchAll();
$outrosPerfis = $pdo->query('SELECT id, nome FROM perfis WHERE administrador = 0 ORDER BY nome')->fetchAll();
$perfisAlvoSelecionados = [];
$permissoesPorGrupo = [];
foreach ($todasPermissoes as $perm) {
    $permissoesPorGrupo[$perm['grupo']][] = $perm;
}

if ($id) {
    $stmt = $pdo->prepare('SELECT * FROM perfis WHERE id = ?');
    $stmt->execute([$id]);
    $perfil = $stmt->fetch();
    if (!$perfil) {
        flash('danger', 'Perfil não encontrado.');
        redirect('/perfis/listar.php');
    }

    $stmt = $pdo->prepare('SELECT permissao_id FROM perfil_permissoes WHERE perfil_id = ?');
    $stmt->execute([$id]);
    $permissoesSelecionadas = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $stmt = $pdo->prepare('SELECT perfil_alvo_id FROM perfil_perfis_gerenciaveis WHERE perfil_id = ?');
    $stmt->execute([$id]);
    $perfisAlvoSelecionados = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

$erros = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome = input('nome');
    $administrador = isset($_POST['administrador']) ? 1 : 0;
    $descontoMax = decimalInput('desconto_max', 0);
    $permissoesMarcadas = array_map('intval', $_POST['permissoes'] ?? []);
    $perfisAlvoMarcados = array_map('intval', $_POST['perfis_alvo'] ?? []);

    if ($nome === '') {
        $erros[] = 'O nome é obrigatório.';
    }
    if ($descontoMax < 0 || $descontoMax > 100) {
        $erros[] = 'O desconto máximo deve estar entre 0 e 100.';
    }

    if (!$erros) {
        $operacaoAuditoria = $id ? 'edicao' : 'criacao';
        $pdo->beginTransaction();

        if ($id) {
            $stmt = $pdo->prepare('UPDATE perfis SET nome=?, administrador=?, desconto_max=? WHERE id=?');
            $stmt->execute([$nome, $administrador, $descontoMax, $id]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO perfis (nome, administrador, desconto_max) VALUES (?,?,?)');
            $stmt->execute([$nome, $administrador, $descontoMax]);
            $id = (int) $pdo->lastInsertId();
        }

        $stmt = $pdo->prepare('DELETE FROM perfil_permissoes WHERE perfil_id = ?');
        $stmt->execute([$id]);

        if ($permissoesMarcadas) {
            $stmt = $pdo->prepare('INSERT INTO perfil_permissoes (perfil_id, permissao_id) VALUES (?, ?)');
            foreach ($permissoesMarcadas as $permissaoId) {
                $stmt->execute([$id, $permissaoId]);
            }
        }

        // Perfis que usuários com este perfil podem dar a outros usuários
        // (perfil de administrador nunca: só administrador cria administrador).
        $pdo->prepare('DELETE FROM perfil_perfis_gerenciaveis WHERE perfil_id = ?')->execute([$id]);
        if ($perfisAlvoMarcados) {
            $stmt = $pdo->prepare('INSERT INTO perfil_perfis_gerenciaveis (perfil_id, perfil_alvo_id) SELECT ?, id FROM perfis WHERE id = ? AND administrador = 0');
            foreach (array_unique($perfisAlvoMarcados) as $alvoId) {
                $stmt->execute([$id, $alvoId === 0 ? $id : $alvoId]);
            }
        }

        $pdo->commit();

        registrarAtividade('perfis', $operacaoAuditoria, ($operacaoAuditoria === 'criacao' ? 'Perfil de acesso criado: ' : 'Perfil de acesso editado: ') . $nome);

        flash('success', 'Perfil salvo com sucesso.');
        redirect('/perfis/listar.php');
    }

    $perfil = ['nome' => $nome, 'administrador' => $administrador, 'desconto_max' => $descontoMax];
    $permissoesSelecionadas = $permissoesMarcadas;
    $perfisAlvoSelecionados = $perfisAlvoMarcados;
}

$pageTitle = $id ? 'Editar perfil' : 'Novo perfil';
$pageBreadcrumb = 'Administração · Perfis';
$pageAction = '<a href="/perfis/listar.php" class="btn btn-outline-secondary">Cancelar</a>'
    . '<button type="submit" form="form-principal" class="btn btn-brand"><i class="bi bi-check-lg"></i> Salvar</button>';
$activeMenu = 'perfis';
require __DIR__ . '/../../includes/layout_header.php';
?>

<?php if ($erros): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($erros as $erro): ?><li><?= e($erro) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="secao" style="max-width: 760px;">
    <div class="secao-titulo"><i class="bi bi-shield-lock"></i> Dados do perfil</div>
        <form method="post" id="form-principal">
            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label">Nome *</label>
                    <input type="text" name="nome" class="form-control" required value="<?= e($perfil['nome'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Desconto/bolsa máxima na matrícula (%)</label>
                    <input type="text" name="desconto_max" class="form-control" value="<?= e(number_format($perfil['desconto_max'] ?? 0, 2, ',', '')) ?>">
                </div>
                <div class="col-12 form-check ps-4">
                    <input type="checkbox" name="administrador" class="form-check-input" id="administrador" <?= ($perfil['administrador'] ?? 0) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="administrador">Administrador (acesso total ao sistema, ignora todas as permissões abaixo)</label>
                </div>
                <div class="col-12" id="bloco-permissoes">
                    <label class="form-label">Permissões</label>
                    <div class="form-text mt-0 mb-2">Marque o que as pessoas com este perfil podem fazer. Cada usuário tem um perfil (Administração › Usuários); crie quantos perfis precisar (ex.: Secretaria, Financeiro, Coordenação).</div>
                    <?php foreach ($permissoesPorGrupo as $grupo => $permissoes): $chaveGrupo = 'g' . md5($grupo); ?>
                        <div class="border rounded p-3 mb-2">
                            <div class="form-check mb-2">
                                <input type="checkbox" class="form-check-input marcar-grupo" id="<?= $chaveGrupo ?>" data-grupo="<?= $chaveGrupo ?>">
                                <label class="form-check-label fw-semibold" for="<?= $chaveGrupo ?>"><?= e($grupo) ?></label>
                            </div>
                            <?php foreach ($permissoes as $perm): ?>
                                <div class="form-check ps-5">
                                    <input type="checkbox" name="permissoes[]" value="<?= (int) $perm['id'] ?>" class="form-check-input permissao-item" data-grupo="<?= $chaveGrupo ?>" id="perm-<?= (int) $perm['id'] ?>"
                                           <?= in_array((int) $perm['id'], $permissoesSelecionadas, true) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="perm-<?= (int) $perm['id'] ?>"><?= e($perm['descricao']) ?></label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                    <div class="border rounded p-3 mb-2">
                        <div class="fw-semibold mb-1">Usuários que este perfil pode cadastrar e editar</div>
                        <div class="form-text mt-0 mb-2">Vale para quem tem as permissões de "Usuários do sistema" acima. Ex.: Secretaria pode criar só Secretaria; Gerente pode criar Secretaria e Financeiro. Perfis de administrador só um administrador cria.</div>
                        <?php foreach ($outrosPerfis as $alvo): ?>
                            <div class="form-check ps-5">
                                <input type="checkbox" name="perfis_alvo[]" value="<?= (int) $alvo['id'] ?>" class="form-check-input" id="alvo-<?= (int) $alvo['id'] ?>"
                                       <?= in_array((int) $alvo['id'], $perfisAlvoSelecionados ?? [], true) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="alvo-<?= (int) $alvo['id'] ?>"><?= e($alvo['nome']) ?><?= (int) $alvo['id'] === (int) $id ? ' <span class="text-muted">(o próprio perfil)</span>' : '' ?></label>
                            </div>
                        <?php endforeach; ?>
                        <?php if (!$id): ?>
                            <div class="form-check ps-5">
                                <input type="checkbox" name="perfis_alvo[]" value="0" class="form-check-input" id="alvo-proprio" <?= in_array(0, $perfisAlvoSelecionados ?? [], true) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="alvo-proprio">Este novo perfil</label>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </form>
</div>

<script>
// "Marcar tudo" de cada área; com o perfil de administrador, as caixas não
// valem (ele pode tudo) e ficam esmaecidas.
function atualizarGrupos() {
    document.querySelectorAll('.marcar-grupo').forEach((g) => {
        const itens = [...document.querySelectorAll('.permissao-item[data-grupo="' + g.dataset.grupo + '"]')];
        const n = itens.filter((c) => c.checked).length;
        g.checked = n === itens.length;
        g.indeterminate = n > 0 && n < itens.length;
    });
}
document.querySelectorAll('.marcar-grupo').forEach((g) => g.addEventListener('change', () => {
    document.querySelectorAll('.permissao-item[data-grupo="' + g.dataset.grupo + '"]').forEach((c) => { c.checked = g.checked; });
    atualizarGrupos();
}));
document.querySelectorAll('.permissao-item').forEach((c) => c.addEventListener('change', atualizarGrupos));
function atualizarAdmin() {
    document.getElementById('bloco-permissoes').style.opacity = document.getElementById('administrador').checked ? .45 : 1;
}
document.getElementById('administrador').addEventListener('change', atualizarAdmin);
atualizarGrupos();
atualizarAdmin();
</script>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
