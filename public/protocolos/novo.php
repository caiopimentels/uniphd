<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirPermissao('abrir_protocolo', 'Seu perfil não pode abrir protocolo em nome do aluno.');

// A secretaria abre um protocolo em nome do aluno (ex.: ele ligou ou trouxe
// o atestado no balcão). ?aluno=ID já escolhe o aluno (aba Protocolos do
// cadastro); trocar o aluno recarrega a página com as turmas dele.
$pdo = getDB();
$alunoId = (int) ($_POST['aluno_id'] ?? $_GET['aluno'] ?? 0);
// Veio da aba Protocolos do aluno (?de=aluno): Cancelar volta para ela; senão, para a lista.
$deAluno = ($_POST['de'] ?? $_GET['de'] ?? '') === 'aluno';
$aluno = null;
if ($alunoId) {
    $stmt = $pdo->prepare('SELECT id, nome, cpf, email FROM alunos WHERE id = ?');
    $stmt->execute([$alunoId]);
    $aluno = $stmt->fetch() ?: null;
}
$assuntos = assuntosProtocolo($pdo);
$matriculas = $aluno ? matriculasParaProtocolo($pdo, (int) $aluno['id']) : [];
$dados = ['assunto_id' => 0, 'matricula_id' => (int) ($_GET['matricula'] ?? 0), 'curso_modulo_id' => 0, 'data_referencia' => '', 'descricao' => ''];
$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dados = [
        'assunto_id' => (int) ($_POST['assunto_id'] ?? 0),
        'matricula_id' => (int) ($_POST['matricula_id'] ?? 0),
        'curso_modulo_id' => (int) ($_POST['curso_modulo_id'] ?? 0),
        'data_referencia' => (string) ($_POST['data_referencia'] ?? ''),
        'descricao' => (string) ($_POST['descricao'] ?? ''),
    ];
    if (!$aluno) {
        $erro = 'Escolha o aluno.';
    } else {
        $resultado = abrirProtocolo($pdo, (int) $aluno['id'], $dados, $_FILES['anexo'] ?? null, 'secretaria');
        if ($resultado['ok']) {
            registrarAtividade('protocolos', 'criacao', 'Protocolo nº ' . $resultado['numero'] . ' aberto em nome de ' . $aluno['nome']);
            if (!empty($_POST['avisar'])) {
                avisarAlunoProtocolo($pdo, buscarProtocolo($pdo, 'id', $resultado['id']), 'a secretaria registrou este protocolo para você — acompanhe por aqui.');
            }
            flash('success', $resultado['mensagem'] . (!empty($_POST['avisar']) && $aluno['email'] ? ' O aluno foi avisado por e-mail.' : ''));
            redirect(usuarioPodeVerProtocolos() ? '/protocolos/ver.php?id=' . $resultado['id'] : '/alunos/form.php?id=' . (int) $aluno['id']);
        }
        $erro = $resultado['mensagem'];
    }
}
$alunos = $pdo->query('SELECT id, nome, cpf FROM alunos WHERE ativo = 1 ORDER BY nome')->fetchAll();

$pageTitle = 'Novo protocolo';
$pageBreadcrumb = 'Secretaria · Protocolos';
$activeMenu = 'protocolos';
$pageAction = '<a href="' . e($deAluno && $aluno ? '/alunos/form.php?id=' . (int) $aluno['id'] . '&tab=protocolos' : '/protocolos/listar.php') . '" class="btn btn-outline-secondary">Cancelar</a>';
require __DIR__ . '/../../includes/layout_header.php';
?>
<?php if ($erro): ?><div class="alert alert-danger"><?= e($erro) ?></div><?php endif; ?>

<div class="secao" style="max-width: 900px;">
    <div class="secao-titulo"><i class="bi bi-person"></i> Aluno</div>
    <div class="typeahead">
        <input type="text" class="form-control" id="aluno-busca" autocomplete="off" placeholder="Pesquise pelo nome ou CPF do aluno...">
        <div class="typeahead-lista d-none" id="aluno-lista"></div>
    </div>
    <input type="hidden" id="aluno-escolhido" value="<?= $aluno ? (int) $aluno['id'] : '' ?>">
</div>
<script src="/assets/js/busca.js"></script>
<script>
// Pesquisa do aluno (nome ou CPF); escolhido, a página recarrega com as turmas dele.
const alunosProtocolo = <?= json_encode(array_map(fn ($a) => ['id' => (int) $a['id'], 'nome' => $a['nome'], 'cpf' => $a['cpf']], $alunos), JSON_UNESCAPED_UNICODE) ?>;
montarBusca({
    campo: 'aluno-busca', lista: 'aluno-lista', oculto: 'aluno-escolhido',
    itens: () => alunosProtocolo,
    combina: (a, termo, digitos) => normalizar(a.nome).includes(termo) || (digitos && String(a.cpf || '').replace(/\D/g, '').includes(digitos)),
    titulo: (a) => a.nome,
    detalhe: (a) => a.cpf ? 'CPF ' + String(a.cpf).replace(/^(\d{3})(\d{3})(\d{3})(\d{2})$/, '$1.$2.$3-$4') : 'Sem CPF cadastrado',
    vazio: 'Nenhum aluno encontrado.',
    aoEscolher: () => {
        const id = document.getElementById('aluno-escolhido').value;
        if (id && id !== '<?= $aluno ? (int) $aluno['id'] : '' ?>') location.href = '/protocolos/novo.php?aluno=' + id<?= $deAluno ? " + '&de=aluno'" : '' ?>;
    },
});
</script>

<?php if ($aluno): ?>
<form method="post" enctype="multipart/form-data" class="secao" style="max-width: 900px;" onsubmit="this.querySelector('[type=submit]').disabled = true;">
    <div class="secao-titulo"><i class="bi bi-chat-left-text"></i> Protocolo</div>
    <input type="hidden" name="aluno_id" value="<?= (int) $aluno['id'] ?>">
    <input type="hidden" name="de" value="<?= $deAluno ? 'aluno' : '' ?>">
    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label">Assunto *</label>
            <select name="assunto_id" id="pr-assunto" class="form-select" required>
                <option value="">Escolha...</option>
                <?= opcoesAssuntosProtocolo($assuntos, (int) $dados['assunto_id']) ?>
            </select>
        </div>
        <div class="col-md-6 pr-turma">
            <label class="form-label">Turma *</label>
            <select name="matricula_id" id="pr-matricula" class="form-select">
                <option value="">Escolha...</option>
                <?php foreach ($matriculas as $m): ?>
                    <option value="<?= (int) $m['id'] ?>" <?= (int) $dados['matricula_id'] === (int) $m['id'] ? 'selected' : '' ?>><?= e($m['curso_nome'] . ' · ' . $m['turma_nome']) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if (!$matriculas): ?><div class="form-text text-danger">Este aluno não tem turma ativa.</div><?php endif; ?>
        </div>
        <div class="col-md-6 pr-turma" id="pr-modulo-col">
            <label class="form-label">Módulo</label>
            <select name="curso_modulo_id" id="pr-modulo" class="form-select"></select>
        </div>
        <div class="col-md-6 pr-data">
            <label class="form-label">Data *</label>
            <input type="date" name="data_referencia" class="form-control" value="<?= e($dados['data_referencia']) ?>">
        </div>
        <div class="col-12">
            <label class="form-label">Descrição *</label>
            <textarea name="descricao" class="form-control" rows="5" required maxlength="5000" placeholder="O que o aluno pediu (ex.: ligou avisando da falta por motivo de saúde)"><?= e($dados['descricao']) ?></textarea>
        </div>
        <div class="col-md-8 pr-anexo">
            <label class="form-label">Anexo <span class="small text-muted" id="pr-anexo-rotulo"></span></label>
            <input type="file" name="anexo" class="form-control" accept="application/pdf,image/jpeg,image/png">
        </div>
        <div class="col-12">
            <div class="form-check">
                <input type="checkbox" class="form-check-input" name="avisar" id="avisar" value="1" <?= $aluno['email'] ? 'checked' : 'disabled' ?>>
                <label class="form-check-label" for="avisar">Avisar o aluno por e-mail<?= $aluno['email'] ? ' (' . e($aluno['email']) . ')' : ' — aluno sem e-mail' ?></label>
            </div>
        </div>
    </div>
    <div class="mt-3"><button type="submit" class="btn btn-brand"><i class="bi bi-check-lg"></i> Abrir protocolo</button></div>
</form>
<script>
// Mesmos campos do portal: só o que o assunto pede; módulos da turma.
(() => {
    const modulos = <?= json_encode(array_column(array_map(fn ($m) => ['id' => $m['id'], 'modulos' => $m['modulos']], $matriculas), 'modulos', 'id'), JSON_UNESCAPED_UNICODE) ?>;
    const moduloInicial = <?= (int) $dados['curso_modulo_id'] ?>;
    const assunto = document.getElementById('pr-assunto');
    const matricula = document.getElementById('pr-matricula');
    const modulo = document.getElementById('pr-modulo');
    const atualizarModulos = () => {
        const lista = modulos[matricula.value] || [];
        modulo.innerHTML = '<option value="">—</option>' + lista.map((m) => '<option value="' + m.id + '"' + (Number(m.id) === moduloInicial ? ' selected' : '') + '>' + escapeHtml(m.nome) + '</option>').join('');
        document.getElementById('pr-modulo-col').style.display = lista.length && assunto.selectedOptions[0]?.dataset.turma === '1' ? '' : 'none';
    };
    const atualizar = () => {
        const o = assunto.selectedOptions[0];
        const turma = !!o && o.dataset.turma === '1';
        document.querySelectorAll('.pr-turma').forEach((el) => { el.style.display = turma ? '' : 'none'; });
        matricula.required = turma;
        const data = !!o && o.dataset.data === '1';
        document.querySelector('.pr-data').style.display = data ? '' : 'none';
        document.querySelector('[name=data_referencia]').required = data;
        const anexo = o ? o.dataset.anexo : 'opcional';
        document.querySelector('.pr-anexo').style.display = anexo === 'nao' ? 'none' : '';
        document.querySelector('[name=anexo]').required = anexo === 'obrigatorio';
        document.getElementById('pr-anexo-rotulo').textContent = anexo === 'obrigatorio' ? '(obrigatório)' : '(opcional — ex.: atestado entregue na secretaria)';
        atualizarModulos();
    };
    assunto.addEventListener('change', atualizar);
    matricula.addEventListener('change', atualizarModulos);
    atualizar();
})();
</script>
<?php endif; ?>
<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
