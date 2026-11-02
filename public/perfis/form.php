<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirAdmin();

$pdo = getDB();
$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
$perfil = null;
$permissoesSelecionadas = [];

require_once __DIR__ . '/../../includes/permissoes_layout.php';
$todasPermissoes = $pdo->query('SELECT * FROM permissoes ORDER BY ordem, id')->fetchAll();
$idPorChave = array_column($todasPermissoes, 'id', 'chave');
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
    // permissoes[] = caixas de uma permissão (e as mantidas de uma caixa
    // "pela metade"); permissoes_juntas[] = caixas que valem por várias
    // ("Inclusão e edição"), com os ids separados por vírgula.
    $permissoesMarcadas = array_map('intval', (array) ($_POST['permissoes'] ?? []));
    foreach ((array) ($_POST['permissoes_juntas'] ?? []) as $lista) {
        array_push($permissoesMarcadas, ...array_map('intval', explode(',', (string) $lista)));
    }
    $permissoesMarcadas = array_values(array_unique(array_filter($permissoesMarcadas)));
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

<div class="secao" style="max-width: 1000px;">
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
                    <?php
                    // Caixa que vale por uma ou mais permissões. Marcada só se tem
                    // todas; "pela metade" (traço) se tem parte — aí as que já tem
                    // vão escondidas e continuam até alguém mexer na caixa.
                    $marcadas = array_map('intval', $permissoesSelecionadas);
                    $usadas = [];
                    $descricaoPorId = array_column($todasPermissoes, 'descricao', 'id');
                    $caixa = function (array $chaves, string $rotulo, string $classe, string $linha, ?string $dica = null) use ($idPorChave, $marcadas, $descricaoPorId, &$usadas): string {
                        $ids = array_values(array_filter(array_map(fn ($c) => $idPorChave[$c] ?? null, $chaves)));
                        if (!$ids) {
                            return '';
                        }
                        array_push($usadas, ...$ids);
                        $tem = array_values(array_intersect($ids, $marcadas));
                        $todas = count($tem) === count($ids);
                        $parcial = $tem && !$todas;
                        $idHtml = 'perm-' . implode('-', $ids);
                        $nome = count($ids) > 1 ? 'permissoes_juntas[]' : 'permissoes[]';
                        $html = '<div class="form-check' . ($classe === 'principal' ? ' fw-semibold' : '') . '">'
                            . '<input type="checkbox" class="form-check-input perm ' . $classe . '" name="' . $nome . '" value="' . implode(',', $ids) . '" id="' . $idHtml . '" data-linha="' . $linha . '"'
                            . ($todas ? ' checked' : '') . ($parcial ? ' data-parcial="1"' : '') . '>'
                            . '<label class="form-check-label" for="' . $idHtml . '">' . e($rotulo) . '</label>';
                        foreach ($parcial ? $tem : [] as $mantida) {
                            $html .= '<input type="hidden" name="permissoes[]" value="' . $mantida . '" class="manter" data-de="' . $idHtml . '">';
                        }
                        if ($parcial) {
                            $html .= ' <span class="badge text-bg-light border fw-normal" title="Hoje tem só parte desta caixa. Marque para liberar tudo ou desmarque para tirar.">hoje só: '
                                . e(implode(', ', array_map(fn ($id) => $descricaoPorId[$id], $tem))) . '</span>';
                        }
                        return $html . ($dica ? '<div class="form-text mt-0">' . e($dica) . '</div>' : '') . '</div>';
                    };
                    $abasPermissoes = estruturaPermissoes();
                    ?>
                    <ul class="nav nav-tabs" role="tablist">
                        <?php $n = 0; foreach (array_keys($abasPermissoes) as $nomeAba): ?>
                            <li class="nav-item"><button type="button" class="nav-link <?= $n++ === 0 ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#aba-perm-<?= $n ?>"><?= e($nomeAba) ?></button></li>
                        <?php endforeach; ?>
                        <li class="nav-item d-none" id="aba-outras-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#aba-perm-outras">Outras</button></li>
                    </ul>
                    <div class="tab-content border border-top-0 rounded-bottom p-3 mb-3">
                        <?php $n = 0; foreach ($abasPermissoes as $nomeAba => $linhas): $n++; ?>
                            <div class="tab-pane fade <?= $n === 1 ? 'show active' : '' ?>" id="aba-perm-<?= $n ?>">
                                <div class="form-check mb-2">
                                    <input type="checkbox" class="form-check-input marcar-aba" id="marcar-aba-<?= $n ?>">
                                    <label class="form-check-label" for="marcar-aba-<?= $n ?>">Selecionar todos</label>
                                </div>
                                <?php foreach ($linhas as $k => $l): $linha = 'l' . $n . '-' . $k; ?>
                                    <div class="border rounded p-3 mb-2 linha-perm">
                                        <div class="row g-2">
                                            <div class="col-md-5">
                                                <?php if ($l['ver']): ?>
                                                    <?= $caixa([$l['ver']], $l['titulo'], 'principal', $linha, $l['dica'] ?? null) ?>
                                                <?php else: ?>
                                                    <div class="fw-semibold"><?= e($l['titulo']) ?></div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="col-md-7 d-flex flex-wrap gap-4">
                                                <?php if (!empty($l['incluir'])): ?><div><?= $caixa($l['incluir'], 'Inclusão e edição', 'sub', $linha, $l['dica_incluir'] ?? null) ?></div><?php endif; ?>
                                                <?php if (!empty($l['excluir'])): ?><div><?= $caixa($l['excluir'], 'Exclusão', 'sub', $linha, $l['dica_excluir'] ?? null) ?></div><?php endif; ?>
                                            </div>
                                        </div>
                                        <?php if (!empty($l['extras'])): ?>
                                            <div class="ps-4 mt-1">
                                                <?php foreach ($l['extras'] as $chave => $rotulo): ?><?= $caixa([$chave], $rotulo, 'sub', $linha) ?><?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                        <?php $outras = array_filter($todasPermissoes, fn ($p) => !in_array((int) $p['id'], $usadas, true)); ?>
                        <div class="tab-pane fade" id="aba-perm-outras" data-tem="<?= $outras ? '1' : '' ?>">
                            <?php foreach ($outras as $p): ?><?= $caixa([$p['chave']], $p['descricao'], 'sub', 'outras') ?><?php endforeach; ?>
                        </div>
                    </div>
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
// Caixas das permissões (estilo Bling): "pela metade" quando o perfil tem só
// parte do que a caixa libera; desmarcar a principal (ver/usar) de uma linha
// desmarca as outras dela; marcar qualquer uma marca a principal.
// Com o perfil de administrador, as caixas não valem e ficam esmaecidas.
const caixasPerm = [...document.querySelectorAll('.perm')];
caixasPerm.filter((c) => c.dataset.parcial).forEach((c) => { c.indeterminate = true; });
const soltarParcial = (c) => {
    c.indeterminate = false;
    delete c.dataset.parcial;
    document.querySelectorAll('.manter[data-de="' + c.id + '"]').forEach((h) => h.remove());
    c.closest('.form-check').querySelector('.badge')?.remove();
};
function atualizarAbas() {
    document.querySelectorAll('.tab-pane').forEach((aba) => {
        const todo = aba.querySelector('.marcar-aba');
        if (!todo) return;
        const itens = [...aba.querySelectorAll('.perm')];
        const n = itens.filter((c) => c.checked).length;
        todo.checked = n === itens.length;
        todo.indeterminate = n > 0 && n < itens.length;
    });
}
caixasPerm.forEach((c) => c.addEventListener('change', () => {
    soltarParcial(c);
    const daLinha = caixasPerm.filter((o) => o.dataset.linha === c.dataset.linha);
    if (c.classList.contains('principal') && !c.checked) {
        daLinha.forEach((o) => { soltarParcial(o); o.checked = false; });
    } else if (c.classList.contains('sub') && c.checked) {
        daLinha.filter((o) => o.classList.contains('principal')).forEach((o) => { soltarParcial(o); o.checked = true; });
    }
    atualizarAbas();
}));
document.querySelectorAll('.marcar-aba').forEach((todo) => todo.addEventListener('change', () => {
    todo.closest('.tab-pane').querySelectorAll('.perm').forEach((c) => { soltarParcial(c); c.checked = todo.checked; });
    atualizarAbas();
}));
if (document.getElementById('aba-perm-outras').dataset.tem) {
    document.getElementById('aba-outras-item').classList.remove('d-none');
}
function atualizarAdmin() {
    document.getElementById('bloco-permissoes').style.opacity = document.getElementById('administrador').checked ? .45 : 1;
}
document.getElementById('administrador').addEventListener('change', atualizarAdmin);
atualizarAbas();
atualizarAdmin();
</script>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
