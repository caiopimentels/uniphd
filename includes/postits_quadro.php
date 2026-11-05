<?php
// Quadro de post-its do aluno + janela de criar/alterar. Incluído na ficha
// do aluno (alunos/form.php) e na matrícula (matriculas/visualizar.php).
// Espera $pdo, $postitAlunoId e $postitMatriculaId (null fora da matrícula).
// O botão "Post-it" do topo da tela vem de postitBotaoNovo(). Excluir pede
// confirmação pelo modal_confirmar.php, que a tela precisa incluir.
// Mostra os em aberto; agendados e resolvidos/encerrados ficam recolhidos.

$postitsDoAluno = buscarPostitsAluno($pdo, (int) $postitAlunoId);
$postitsPorSituacao = ['aberto' => [], 'agendado' => [], 'fechado' => []];
foreach ($postitsDoAluno as $p) {
    $postitsPorSituacao[in_array($p['situacao'], ['resolvido', 'encerrado'], true) ? 'fechado' : $p['situacao']][] = $p;
}
$podeCriarPostit = usuarioTemPermissao('usar_postits');
$postitVoltar = $_SERVER['REQUEST_URI'] ?? '/alunos/form.php?id=' . (int) $postitAlunoId;

// Dados do post-it para a janela de alterar (preenchida pelo JavaScript).
$postitParaForm = fn (array $p): array => [
    'id' => (int) $p['id'],
    'texto' => $p['texto'],
    'cor' => $p['cor'],
    'visibilidade' => $p['visibilidade'],
    'usuarios' => array_column(array_filter($p['destinos'], fn ($d) => $d['tipo'] === 'usuario'), 'id'),
    'perfis' => array_column(array_filter($p['destinos'], fn ($d) => $d['tipo'] === 'perfil'), 'id'),
    'inicio' => $p['inicio'] ? date('Y-m-d\TH:i', strtotime($p['inicio'])) : '',
    'fim' => (string) $p['fim'],
    'avisar' => (bool) $p['avisar'],
    'repeticao' => $p['repeticao'],
    'repeticao_vezes' => (int) $p['repeticao_vezes'],
];

$postitCartao = function (array $p) use ($postitParaForm, $postitVoltar, $postitMatriculaId): void {
    $pdo = getDB();
    $fechado = in_array($p['situacao'], ['resolvido', 'encerrado'], true);
    $podeAlterar = usuarioPodeAlterarPostit($p);
    $podeResolver = usuarioTemPermissao('usar_postits')
        || postitAvisaUsuario($pdo, (int) $p['id'], (int) usuarioAtualId(), (int) ($_SESSION['user_perfil_id'] ?? 0));
    $linhas = array_filter([
        'bi-lock' => descreverVisibilidadePostit($p),
        'bi-calendar-event' => descreverPeriodoPostit($p),
        'bi-bell' => descreverAvisoPostit($p),
    ]);
    $vistos = array_map(fn ($v) => $v['nome'] . ' (' . (int) $v['vezes'] . 'x' . ($v['dispensado_em'] ? ', pediu para não ver mais' : '') . ')', $p['vistos']);
    ?>
    <div class="postit postit-<?= e($p['situacao']) ?>" style="<?= e(postitEstilo($p['cor'])) ?>">
        <?php if ($p['situacao'] === 'agendado'): ?><div class="postit-selo"><i class="bi bi-clock"></i> Agendado</div><?php endif; ?>
        <?php if ($p['situacao'] === 'encerrado'): ?><div class="postit-selo">Período encerrado</div><?php endif; ?>
        <div class="postit-texto"><?= nl2br(e($p['texto'])) ?></div>
        <?php if ($linhas || $vistos): ?>
            <div class="postit-info">
                <?php foreach ($linhas as $icone => $texto): ?>
                    <div><i class="bi <?= e($icone) ?>"></i> <?= e($texto) ?></div>
                <?php endforeach; ?>
                <?php if ($vistos): ?>
                    <div title="<?= e(implode("\n", $vistos)) ?>"><i class="bi bi-eye"></i> Aviso visto por <?= e(implode(', ', $vistos)) ?></div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <div class="postit-rodape">
            <span>
                <?= e($p['usuario_nome']) ?> · <?= formatDateTime($p['criado_em']) ?>
                <?php if ($p['matricula_nome'] && (int) $p['matricula_id'] !== (int) $postitMatriculaId): ?>
                    <br><a href="/matriculas/visualizar.php?id=<?= (int) $p['matricula_id'] ?>" class="link-dark"><i class="bi bi-mortarboard"></i> <?= e($p['matricula_nome']) ?></a>
                <?php endif; ?>
                <?php if ($p['situacao'] === 'resolvido'): ?>
                    <br><i class="bi bi-check2-circle"></i> Resolvido por <?= e($p['resolvido_por']) ?> em <?= formatDateTime($p['resolvido_em']) ?>
                <?php endif; ?>
            </span>
            <span class="postit-acoes">
                <?php if ($podeResolver && $p['situacao'] !== 'encerrado'): $resolvido = $p['situacao'] === 'resolvido'; ?>
                    <form method="post" action="/alunos/postit_acao.php" class="d-inline">
                        <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                        <input type="hidden" name="acao" value="<?= $resolvido ? 'reabrir' : 'resolver' ?>">
                        <input type="hidden" name="voltar" value="<?= e($postitVoltar) ?>">
                        <button type="submit" class="btn btn-sm btn-link link-dark p-0" title="<?= $resolvido ? 'Reabrir' : 'Marcar como resolvido (tarefa feita; para de avisar)' ?>">
                            <i class="bi <?= $resolvido ? 'bi-arrow-counterclockwise' : 'bi-check2-circle' ?>"></i>
                        </button>
                    </form>
                <?php endif; ?>
                <?php if ($podeAlterar && $p['situacao'] !== 'resolvido'): ?>
                    <button type="button" class="btn btn-sm btn-link link-dark p-0" title="Alterar"
                            data-postit="<?= e(json_encode($postitParaForm($p), JSON_UNESCAPED_UNICODE)) ?>" onclick="abrirPostit(this)">
                        <i class="bi bi-pencil"></i>
                    </button>
                <?php endif; ?>
                <?php if ($podeAlterar): ?>
                    <form method="post" action="/alunos/postit_acao.php" class="d-inline postit-excluir">
                        <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                        <input type="hidden" name="acao" value="excluir">
                        <input type="hidden" name="voltar" value="<?= e($postitVoltar) ?>">
                        <button type="submit" class="btn btn-sm btn-link link-dark p-0" title="Excluir"><i class="bi bi-trash"></i></button>
                    </form>
                <?php endif; ?>
            </span>
        </div>
    </div>
    <?php
};
?>

<?php if ($postitsDoAluno): ?>
<div class="postits-quadro mb-3">
    <?php foreach ($postitsPorSituacao['aberto'] as $p) { $postitCartao($p); } ?>
    <?php if ($postitsPorSituacao['agendado'] || $postitsPorSituacao['fechado']): ?>
        <div class="w-100 d-flex gap-3">
            <?php if ($postitsPorSituacao['agendado']): ?>
                <button type="button" class="btn btn-sm btn-link text-muted p-0" onclick="document.getElementById('postits-agendados').classList.toggle('d-none')">
                    <i class="bi bi-clock"></i> Agendados (<?= count($postitsPorSituacao['agendado']) ?>)
                </button>
            <?php endif; ?>
            <?php if ($postitsPorSituacao['fechado']): ?>
                <button type="button" class="btn btn-sm btn-link text-muted p-0" onclick="document.getElementById('postits-fechados').classList.toggle('d-none')">
                    <i class="bi bi-sticky"></i> Resolvidos e encerrados (<?= count($postitsPorSituacao['fechado']) ?>)
                </button>
            <?php endif; ?>
        </div>
        <div id="postits-agendados" class="postits-quadro w-100 d-none">
            <?php foreach ($postitsPorSituacao['agendado'] as $p) { $postitCartao($p); } ?>
        </div>
        <div id="postits-fechados" class="postits-quadro w-100 d-none">
            <?php foreach ($postitsPorSituacao['fechado'] as $p) { $postitCartao($p); } ?>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($podeCriarPostit):
    $postitUsuarios = $pdo->query('SELECT id, nome FROM usuarios WHERE ativo = 1 ORDER BY nome')->fetchAll();
    $postitPerfis = $pdo->query('SELECT id, nome FROM perfis ORDER BY nome')->fetchAll();
?>
<div class="modal fade" id="modal-postit" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <form method="post" action="/alunos/postit_salvar.php" class="modal-content" id="form-postit">
            <input type="hidden" name="id" value="">
            <input type="hidden" name="aluno_id" value="<?= (int) $postitAlunoId ?>">
            <input type="hidden" name="matricula_id" value="<?= (int) $postitMatriculaId ?>">
            <input type="hidden" name="voltar" value="<?= e($postitVoltar) ?>">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-sticky"></i> <span id="postit-titulo">Novo post-it</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <textarea name="texto" class="form-control postit-campo" rows="4" maxlength="2000" required placeholder="Escreva o recado ou a tarefa sobre este aluno…"></textarea>
                <div class="d-flex gap-2 mt-2 align-items-center">
                    <span class="small text-muted">Cor:</span>
                    <?php foreach (postitCores() as $chave => [$nomeCor, $fundo, $borda]): ?>
                        <label class="postit-cor" title="<?= e($nomeCor) ?>" style="background:<?= e($fundo) ?>;border-color:<?= e($borda) ?>">
                            <input type="radio" name="cor" value="<?= e($chave) ?>" <?= $chave === 'amarelo' ? 'checked' : '' ?>>
                        </label>
                    <?php endforeach; ?>
                </div>

                <div class="row g-3 mt-1">
                    <div class="col-md-6">
                        <div class="postit-bloco-titulo"><i class="bi bi-eye"></i> Quem pode ver</div>
                        <label class="form-check"><input type="radio" class="form-check-input" name="visibilidade" value="todos" checked> Todos que abrem este aluno</label>
                        <label class="form-check"><input type="radio" class="form-check-input" name="visibilidade" value="eu"> Só eu</label>
                        <label class="form-check"><input type="radio" class="form-check-input" name="visibilidade" value="escolhidos"> Só eu e as pessoas que eu escolher</label>
                    </div>
                    <div class="col-md-6">
                        <div class="postit-bloco-titulo"><i class="bi bi-calendar-event"></i> Período <span class="fw-normal text-muted small">(opcional)</span></div>
                        <div class="row g-2">
                            <div class="col-7"><label class="form-label small mb-1">De</label><input type="datetime-local" name="inicio" class="form-control form-control-sm"></div>
                            <div class="col-5"><label class="form-label small mb-1">Até</label><input type="date" name="fim" class="form-control form-control-sm"></div>
                        </div>
                    </div>
                </div>

                <hr>
                <label class="form-check form-switch">
                    <input type="checkbox" class="form-check-input" name="avisar" value="1">
                    <span class="fw-semibold"><i class="bi bi-bell"></i> Avisar quando entrar no sistema</span>
                    <span class="small text-muted">(opcional)</span>
                </label>
                <div id="postit-repeticao" class="ms-4 mt-1">
                    <label class="form-check form-check-inline"><input type="radio" class="form-check-input" name="repeticao" value="sempre"> Toda vez que abrir o sistema</label>
                    <label class="form-check form-check-inline"><input type="radio" class="form-check-input" name="repeticao" value="uma_vez" checked> Uma vez</label>
                    <label class="form-check form-check-inline d-inline-flex align-items-center gap-2">
                        <input type="radio" class="form-check-input" name="repeticao" value="vezes">
                        <input type="number" name="repeticao_vezes" class="form-control form-control-sm" style="width:64px" min="2" max="50" value="3"> vezes seguidas
                    </label>
                </div>

                <div id="postit-pessoas" class="mt-3">
                    <div class="postit-bloco-titulo"><i class="bi bi-people"></i> Pessoas <span class="fw-normal text-muted small" id="postit-pessoas-dica"></span></div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="small text-muted mb-1">Usuários</div>
                            <div class="postit-lista border rounded p-2">
                                <?php foreach ($postitUsuarios as $u): ?>
                                    <label class="form-check"><input type="checkbox" class="form-check-input" name="usuarios[]" value="<?= (int) $u['id'] ?>"> <?= e($u['nome']) ?><?= (int) $u['id'] === (int) usuarioAtualId() ? ' <span class="text-muted">(você)</span>' : '' ?></label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="small text-muted mb-1">Perfis (todos os usuários do perfil)</div>
                            <div class="postit-lista border rounded p-2">
                                <?php foreach ($postitPerfis as $pf): ?>
                                    <label class="form-check"><input type="checkbox" class="form-check-input" name="perfis[]" value="<?= (int) $pf['id'] ?>"> <?= e($pf['nome']) ?></label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-brand"><i class="bi bi-check-lg"></i> Salvar post-it</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<style>
.postits-quadro { display: flex; flex-wrap: wrap; gap: .75rem; }
.postit {
    width: 260px; min-height: 120px; padding: .7rem .8rem .5rem; border: 1px solid; border-radius: 4px;
    box-shadow: 2px 3px 6px rgba(0, 0, 0, .12); display: flex; flex-direction: column; gap: .4rem; font-size: .9rem;
}
.postit:nth-child(odd) { transform: rotate(-.6deg); }
.postit:nth-child(even) { transform: rotate(.5deg); }
.postit-texto { white-space: normal; word-break: break-word; flex: 1; }
.postit-info { font-size: .75rem; opacity: .85; display: flex; flex-direction: column; gap: 2px; }
.postit-selo { font-size: .7rem; font-weight: 600; text-transform: uppercase; letter-spacing: .03em; opacity: .75; }
.postit-rodape { display: flex; justify-content: space-between; align-items: flex-end; gap: .5rem; font-size: .72rem; opacity: .8; }
.postit-acoes { display: flex; gap: .45rem; flex: none; }
.postit-acoes .btn { font-size: .95rem; line-height: 1; }
.postit-agendado { opacity: .75; border-style: dashed !important; }
.postit-resolvido, .postit-encerrado { opacity: .6; }
.postit-resolvido .postit-texto { text-decoration: line-through; }
.postit-lista { max-height: 160px; overflow-y: auto; }
.postit-bloco-titulo { font-weight: 600; margin-bottom: .35rem; }
.postit-cor { width: 26px; height: 26px; border: 2px solid; border-radius: 4px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; }
.postit-cor input { margin: 0; }
textarea.postit-campo { background: #fffbe0; color: #2b2b2b; }
</style>
<script>
(() => {
    const form = document.getElementById('form-postit');

    // As pessoas servem para "quem vê" (escolhidos) e/ou para o aviso; a
    // repetição só vale com o aviso ligado.
    function atualizar() {
        if (!form) return;
        const vis = form.elements.visibilidade.value;
        const avisar = form.elements.avisar.checked;
        const caixaPessoas = document.getElementById('postit-pessoas');
        const usaPessoas = vis === 'escolhidos' || (vis === 'todos' && avisar);
        caixaPessoas.style.display = usaPessoas ? '' : 'none';
        caixaPessoas.querySelectorAll('input').forEach((el) => { el.disabled = !usaPessoas; });
        document.getElementById('postit-pessoas-dica').textContent = vis === 'escolhidos'
            ? (avisar ? '— quem pode ver e vai ser avisado' : '— quem pode ver (além de você)')
            : '— quem vai ser avisado (sem ninguém marcado, avisa só você)';
        const repeticao = document.getElementById('postit-repeticao');
        repeticao.style.opacity = avisar ? '' : '.45';
        repeticao.querySelectorAll('input').forEach((el) => { el.disabled = !avisar; });
    }

    window.abrirPostit = (botao) => {
        if (!form) return;
        const p = botao && botao.dataset.postit ? JSON.parse(botao.dataset.postit) : null;
        form.reset();
        form.elements.id.value = p ? p.id : '';
        document.getElementById('postit-titulo').textContent = p ? 'Alterar post-it' : 'Novo post-it';
        if (p) {
            form.elements.texto.value = p.texto;
            ['cor', 'visibilidade', 'repeticao'].forEach((n) => form.querySelectorAll('[name="' + n + '"]').forEach((r) => { r.checked = r.value === p[n]; }));
            form.querySelectorAll('[name="usuarios[]"]').forEach((c) => { c.checked = p.usuarios.includes(Number(c.value)); });
            form.querySelectorAll('[name="perfis[]"]').forEach((c) => { c.checked = p.perfis.includes(Number(c.value)); });
            ['inicio', 'fim', 'repeticao_vezes'].forEach((n) => { form.elements[n].value = p[n]; });
            form.elements.avisar.checked = p.avisar;
        }
        atualizar();
        bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-postit')).show();
        setTimeout(() => form.elements.texto.focus(), 300);
    };

    if (form) {
        form.addEventListener('change', atualizar);
        form.elements.repeticao_vezes.addEventListener('focus', () => { form.querySelector('[name="repeticao"][value="vezes"]').checked = true; });
        atualizar();
    }

    document.querySelectorAll('form.postit-excluir').forEach((f) => f.addEventListener('submit', async (ev) => {
        if (f.dataset.ok) return;
        ev.preventDefault();
        if (await confirmarNoModal({ titulo: 'Excluir post-it', html: 'O post-it some da ficha e para de avisar. Se a tarefa só foi feita, prefira marcar como resolvido.', botao: 'Excluir', classeBotao: 'btn-danger' })) {
            f.dataset.ok = '1';
            f.submit();
        }
    }));
})();
</script>
