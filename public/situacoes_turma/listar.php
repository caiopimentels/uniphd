<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirAdmin();

$pdo = getDB();
$situacoes = $pdo->query(
    'SELECT s.*, (SELECT COUNT(*) FROM turmas t WHERE t.situacao_id = s.id) AS turmas
     FROM situacoes_turma s
     ORDER BY s.ordem, s.nome'
)->fetchAll();
$rotulosComportamento = rotulosComportamentoTurma();

$pageTitle = 'Configurações';
$pageBreadcrumb = 'Administração';
$pageAction = '<a href="/situacoes_turma/form.php" class="btn btn-brand"><i class="bi bi-plus-lg"></i> Incluir situação</a>';
$activeMenu = 'configuracoes';
require __DIR__ . '/../../includes/layout_header.php';
$abaAtiva = 'situacoes';
require __DIR__ . '/../../includes/abas_configuracoes.php';
?>

<p class="text-muted">
    As situações que aparecem nas turmas. O nome e a cor são livres; a <strong>regra</strong> define o que o sistema faz com a turma nessa situação.
    <br><span class="small"><i class="bi bi-grip-vertical"></i> Arraste pela alça à esquerda para mudar a ordem.</span>
    <span id="ordem-status" class="ms-2"></span>
</p>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead>
                <tr>
                    <th style="width:44px;"></th>
                    <th>Situação</th>
                    <th>Regra</th>
                    <th class="text-center">Turmas</th>
                    <th>Padrão</th>
                    <th>Ativa</th>
                    <th class="text-end">Ações</th>
                </tr>
            </thead>
            <tbody id="status-corpo">
            <?php if (!$situacoes): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">Nenhuma situação cadastrada.</td></tr>
            <?php endif; ?>
            <?php foreach ($situacoes as $s): ?>
                <tr class="cursor-pointer linha-status" data-id="<?= (int) $s['id'] ?>" onclick="abrirSituacao(event, <?= (int) $s['id'] ?>)">
                    <td class="alca-arrastar" title="Arraste para reordenar" onclick="event.stopPropagation();"><i class="bi bi-grip-vertical"></i></td>
                    <td><?= badgeSituacaoTurma($s['nome'], $s['cor']) ?></td>
                    <td class="small"><?= e($rotulosComportamento[$s['comportamento']] ?? $s['comportamento']) ?></td>
                    <td class="text-center"><?= (int) $s['turmas'] ?></td>
                    <td><?= $s['padrao'] ? '<span class="badge text-bg-info">Turmas novas</span>' : '<span class="text-muted">—</span>' ?></td>
                    <td><?= $s['ativo'] ? '<span class="badge text-bg-success">Ativa</span>' : '<span class="badge text-bg-secondary">Inativa</span>' ?></td>
                    <td class="text-end" onclick="event.stopPropagation();">
                        <form action="/situacoes_turma/excluir.php" method="post" class="d-inline" onsubmit="return confirm('Ativar/desativar esta situação?');">
                            <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-secondary" title="Ativar/desativar"><i class="bi <?= $s['ativo'] ? 'bi-toggle-on' : 'bi-toggle-off' ?>"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<style>
.alca-arrastar { cursor: grab; color: var(--text-muted); text-align: center; }
.alca-arrastar:active { cursor: grabbing; }
.linha-status.arrastando { opacity: .45; }
.linha-status.alvo-drop { box-shadow: inset 0 2px 0 var(--accent); }
#ordem-status { font-size: .82rem; }
</style>

<script>
(function () {
    const corpo = document.getElementById('status-corpo');
    if (!corpo) return;

    let linhaArrastada = null;
    let houveArraste = false;

    // A linha só vira arrastável a partir da alça, para o clique normal na
    // linha continuar abrindo o cadastro.
    corpo.querySelectorAll('.alca-arrastar').forEach(function (alca) {
        alca.addEventListener('mousedown', function () {
            alca.closest('tr').draggable = true;
        });
    });

    corpo.addEventListener('dragstart', function (ev) {
        const linha = ev.target.closest('tr');
        if (!linha) return;
        linhaArrastada = linha;
        houveArraste = true;
        linha.classList.add('arrastando');
        ev.dataTransfer.effectAllowed = 'move';
        ev.dataTransfer.setData('text/plain', linha.dataset.id);
    });

    corpo.addEventListener('dragover', function (ev) {
        ev.preventDefault();
        const alvo = ev.target.closest('tr');
        if (!alvo || !linhaArrastada || alvo === linhaArrastada) return;

        corpo.querySelectorAll('.alvo-drop').forEach((l) => l.classList.remove('alvo-drop'));
        alvo.classList.add('alvo-drop');

        const retangulo = alvo.getBoundingClientRect();
        const soltarDepois = ev.clientY > retangulo.top + retangulo.height / 2;
        corpo.insertBefore(linhaArrastada, soltarDepois ? alvo.nextSibling : alvo);
    });

    corpo.addEventListener('dragend', function () {
        if (linhaArrastada) {
            linhaArrastada.classList.remove('arrastando');
            linhaArrastada.draggable = false;
        }
        corpo.querySelectorAll('.alvo-drop').forEach((l) => l.classList.remove('alvo-drop'));
        linhaArrastada = null;
        salvarOrdem();
        setTimeout(function () { houveArraste = false; }, 80);
    });

    function salvarOrdem() {
        const dados = new URLSearchParams();
        corpo.querySelectorAll('tr[data-id]').forEach(function (linha) {
            dados.append('ids[]', linha.dataset.id);
        });

        const status = document.getElementById('ordem-status');
        status.textContent = 'Salvando ordem...';
        status.className = 'text-muted';

        fetch('/situacoes_turma/ordenar.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
            body: dados.toString(),
        })
            .then((r) => r.json())
            .then((data) => {
                if (!data.sucesso) {
                    status.textContent = data.mensagem || 'Não foi possível salvar a ordem.';
                    status.className = 'text-danger';
                    return;
                }
                status.textContent = 'Ordem salva.';
                status.className = 'text-success';
                setTimeout(function () { status.textContent = ''; }, 2500);
            })
            .catch(function () {
                status.textContent = 'Erro de rede ao salvar a ordem.';
                status.className = 'text-danger';
            });
    }

    window.abrirSituacao = function (ev, id) {
        if (houveArraste) return;
        window.location = '/situacoes_turma/form.php?id=' + id;
    };
})();
</script>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
