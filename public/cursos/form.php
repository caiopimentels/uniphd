<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();
exigirCursos();

$pdo = getDB();
$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
$curso = null;

if ($id) {
    $stmt = $pdo->prepare('SELECT * FROM cursos WHERE id = ?');
    $stmt->execute([$id]);
    $curso = $stmt->fetch();
    if (!$curso) {
        flash('danger', 'Curso não encontrado.');
        redirect('/cursos/listar.php');
    }
}

$erros = [];

// Módulos (conteúdo programático), na ordem em que aparecem.
$modulos = [];
if ($id) {
    $stmt = $pdo->prepare('SELECT id, nome, carga_horaria FROM curso_modulos WHERE curso_id = ? ORDER BY ordem, id');
    $stmt->execute([$id]);
    $modulos = $stmt->fetchAll();
}
// Documentos que o curso emite: o certificado de conclusão (só escolhe o
// modelo) e, opcional, um documento por módulo (na linha do módulo).
$modeloCertificadoCurso = null;
$documentoDoModulo = [];
foreach ($id ? emissoesCurso($pdo, (int) $id) : [] as $emi) {
    if ($emi['modulo_id']) {
        $documentoDoModulo[(int) $emi['modulo_id']] ??= (int) $emi['modelo_id'];
    } else {
        $modeloCertificadoCurso ??= $emi['modelo_id'] ? (int) $emi['modelo_id'] : 0;
    }
}
foreach ($modulos as &$modulo) {
    $modulo['documento_modelo'] = $documentoDoModulo[(int) $modulo['id']] ?? null;
}
unset($modulo);

// Documentos que o aluno precisa enviar para este curso.
$documentos = [];
if ($id) {
    $stmt = $pdo->prepare('SELECT * FROM curso_documentos WHERE curso_id = ? AND ativo = 1 ORDER BY ordem, id');
    $stmt->execute([$id]);
    $documentos = $stmt->fetchAll();
}

// Sem a permissão, a ficha abre só para consulta.
$podeEditar = $id ? usuarioPodeEditarCurso() : usuarioPodeCriarCurso();
if (!$podeEditar && (!$id || $_SERVER['REQUEST_METHOD'] === 'POST')) {
    flash('danger', 'Seu perfil não pode cadastrar ou editar cursos.');
    redirect($id ? '/cursos/form.php?id=' . $id : '/cursos/listar.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $modulos = [];
    foreach ((array) ($_POST['modulo_nome'] ?? []) as $i => $nomeModulo) {
        $nomeModulo = mb_substr(trim((string) $nomeModulo), 0, 150);
        $cargaModulo = (int) ($_POST['modulo_carga'][$i] ?? 0);
        if ($nomeModulo !== '') {
            $modeloModulo = (int) ($_POST['modulo_doc'][$i] ?? 0) ?: null;
            $modulos[] = [
                'id' => (int) ($_POST['modulo_id'][$i] ?? 0) ?: null, 'nome' => $nomeModulo, 'carga_horaria' => $cargaModulo > 0 ? $cargaModulo : null,
                'documento_modelo' => $modeloModulo && buscarModeloCertificado($pdo, $modeloModulo) ? $modeloModulo : null,
            ];
        }
    }
    $modeloCertificadoCurso = (int) ($_POST['certificado_modelo'] ?? 0) ?: null;
    if ($modeloCertificadoCurso && !buscarModeloCertificado($pdo, $modeloCertificadoCurso)) {
        $modeloCertificadoCurso = null;
    }
    $opcoesDocumentos = [
        'liberar_documentos_auto' => isset($_POST['liberar_documentos_auto']) ? 1 : 0,
        'bloquear_inadimplente' => isset($_POST['bloquear_inadimplente']) ? 1 : 0,
        'bloquear_pendencia_documental' => isset($_POST['bloquear_pendencia_documental']) ? 1 : 0,
    ];
    $documentos = [];
    foreach ((array) ($_POST['doc_nome'] ?? []) as $i => $nomeDocumento) {
        $nomeDocumento = mb_substr(trim((string) $nomeDocumento), 0, 120);
        if ($nomeDocumento === '') {
            continue;
        }
        $tiposDocumento = $_POST['doc_tipos'][$i] ?? 'ambos';
        $documentos[] = [
            'id' => (int) ($_POST['doc_id'][$i] ?? 0) ?: null,
            'nome' => $nomeDocumento,
            'instrucao' => mb_substr(trim((string) ($_POST['doc_instrucao'][$i] ?? '')), 0, 255),
            'aceita_pdf' => $tiposDocumento !== 'imagem' ? 1 : 0,
            'aceita_imagem' => $tiposDocumento !== 'pdf' ? 1 : 0,
            'trava_certificado' => ($_POST['doc_trava'][$i] ?? '1') === '1' ? 1 : 0,
        ];
    }
    $nome = input('nome');
    $cargaHoraria = (int) ($_POST['carga_horaria'] ?? 0);
    $descricao = input('descricao');
    $ativo = isset($_POST['ativo']) ? 1 : 0;

    if ($nome === '') {
        $erros[] = 'O nome do curso é obrigatório.';
    }
    if ($cargaHoraria < 0) {
        $erros[] = 'A carga horária não pode ser negativa.';
    }

    if (!$erros) {
        $pdo->beginTransaction();
        if ($id) {
            $stmt = $pdo->prepare('UPDATE cursos SET nome=?, carga_horaria=?, descricao=?, ativo=?, liberar_documentos_auto=?, bloquear_inadimplente=?, bloquear_pendencia_documental=? WHERE id=?');
            $stmt->execute([$nome, $cargaHoraria ?: null, $descricao ?: null, $ativo, ...array_values($opcoesDocumentos), $id]);
            registrarAtividade('cursos', 'edicao', 'Curso editado: ' . $nome);
        } else {
            $stmt = $pdo->prepare('INSERT INTO cursos (nome, carga_horaria, descricao, ativo, liberar_documentos_auto, bloquear_inadimplente, bloquear_pendencia_documental) VALUES (?,?,?,?,?,?,?)');
            $stmt->execute([$nome, $cargaHoraria ?: null, $descricao ?: null, $ativo, ...array_values($opcoesDocumentos)]);
            $id = (int) $pdo->lastInsertId();
            registrarAtividade('cursos', 'criacao', 'Curso criado: ' . $nome);
        }
        // Módulos mantêm o id (documentos e a conclusão na turma dependem dele).
        $modulosMantidos = [];
        $atualizaModulo = $pdo->prepare('UPDATE curso_modulos SET nome = ?, carga_horaria = ?, ordem = ? WHERE id = ? AND curso_id = ?');
        $insere = $pdo->prepare('INSERT INTO curso_modulos (curso_id, nome, carga_horaria, ordem) VALUES (?, ?, ?, ?)');
        foreach ($modulos as $ordem => &$modulo) {
            if ($modulo['id']) {
                $atualizaModulo->execute([$modulo['nome'], $modulo['carga_horaria'], ($ordem + 1) * 10, $modulo['id'], $id]);
                $modulosMantidos[] = $modulo['id'];
            } else {
                $insere->execute([$id, $modulo['nome'], $modulo['carga_horaria'], ($ordem + 1) * 10]);
                $modulo['id'] = (int) $pdo->lastInsertId();
                $modulosMantidos[] = $modulo['id'];
            }
        }
        unset($modulo);
        // Documento de cada módulo (opcional): um por módulo, com o nome do modelo.
        $nomesModelos = array_column(modelosCertificado($pdo), 'nome', 'id');
        $buscaDocModulo = $pdo->prepare('SELECT id FROM curso_emissoes WHERE curso_id = ? AND modulo_id = ? ORDER BY ativo DESC, id LIMIT 1');
        foreach ($modulos as $ordem => $modulo) {
            $buscaDocModulo->execute([$id, $modulo['id']]);
            $emissaoModulo = (int) $buscaDocModulo->fetchColumn();
            if (!$modulo['documento_modelo']) {
                $pdo->prepare('UPDATE curso_emissoes SET ativo = 0 WHERE curso_id = ? AND modulo_id = ?')->execute([$id, $modulo['id']]);
                continue;
            }
            $nomeDoc = mb_substr(($nomesModelos[$modulo['documento_modelo']] ?? 'Declaração') . ' — ' . $modulo['nome'], 0, 120);
            if ($emissaoModulo) {
                $pdo->prepare('UPDATE curso_emissoes SET nome = ?, modelo_id = ?, ordem = ?, ativo = 1 WHERE id = ?')
                    ->execute([$nomeDoc, $modulo['documento_modelo'], ($ordem + 1) * 10, $emissaoModulo]);
                $pdo->prepare('UPDATE curso_emissoes SET ativo = 0 WHERE curso_id = ? AND modulo_id = ? AND id <> ?')->execute([$id, $modulo['id'], $emissaoModulo]);
            } else {
                $pdo->prepare('INSERT INTO curso_emissoes (curso_id, nome, modelo_id, modulo_id, ordem) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$id, $nomeDoc, $modulo['documento_modelo'], $modulo['id'], ($ordem + 1) * 10]);
            }
        }
        $stmt = $pdo->prepare('SELECT id FROM curso_modulos WHERE curso_id = ?');
        $stmt->execute([$id]);
        foreach (array_diff(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), $modulosMantidos) as $removido) {
            $pdo->prepare('UPDATE curso_emissoes SET ativo = 0 WHERE modulo_id = ?')->execute([$removido]);
            $pdo->prepare('DELETE FROM turma_modulos WHERE curso_modulo_id = ?')->execute([$removido]);
            $pdo->prepare('DELETE FROM curso_modulos WHERE id = ?')->execute([$removido]);
        }

        // Certificado de conclusão: um por curso; só muda o modelo (os já
        // liberados continuam ligados a ele).
        $stmt = $pdo->prepare('SELECT id FROM curso_emissoes WHERE curso_id = ? AND modulo_id IS NULL AND avulso = 0 ORDER BY ativo DESC, id LIMIT 1');
        $stmt->execute([$id]);
        if ($certificadoId = (int) $stmt->fetchColumn()) {
            $pdo->prepare('UPDATE curso_emissoes SET modelo_id = ?, ativo = 1 WHERE id = ?')->execute([$modeloCertificadoCurso, $certificadoId]);
            $pdo->prepare('UPDATE curso_emissoes SET ativo = 0 WHERE curso_id = ? AND modulo_id IS NULL AND avulso = 0 AND id <> ?')->execute([$id, $certificadoId]);
        } else {
            $pdo->prepare('INSERT INTO curso_emissoes (curso_id, nome, modelo_id, modulo_id, ordem) VALUES (?, ?, ?, NULL, 1000)')
                ->execute([$id, 'Certificado de conclusão', $modeloCertificadoCurso]);
        }
        $pdo->prepare('UPDATE cursos SET certificado_modelo_id = ? WHERE id = ?')->execute([$modeloCertificadoCurso, $id]);

        // Documentos: os que já existem são atualizados (o envio dos alunos
        // continua ligado a eles); os removidos saem — ou, se algum aluno já
        // enviou, ficam desativados para não perder o arquivo.
        $mantidos = [];
        $atualizaDoc = $pdo->prepare('UPDATE curso_documentos SET nome = ?, instrucao = ?, aceita_pdf = ?, aceita_imagem = ?, trava_certificado = ?, ordem = ?, ativo = 1 WHERE id = ? AND curso_id = ?');
        $insereDoc = $pdo->prepare('INSERT INTO curso_documentos (curso_id, nome, instrucao, aceita_pdf, aceita_imagem, trava_certificado, ordem) VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach ($documentos as $ordem => $doc) {
            $valores = [$doc['nome'], $doc['instrucao'] ?: null, $doc['aceita_pdf'], $doc['aceita_imagem'], $doc['trava_certificado'], ($ordem + 1) * 10];
            if ($doc['id']) {
                $atualizaDoc->execute([...$valores, $doc['id'], $id]);
                $mantidos[] = $doc['id'];
            } else {
                $insereDoc->execute([$id, ...$valores]);
                $mantidos[] = (int) $pdo->lastInsertId();
            }
        }
        $stmt = $pdo->prepare('SELECT cd.id, (SELECT COUNT(*) FROM aluno_documentos ad WHERE ad.curso_documento_id = cd.id) AS envios FROM curso_documentos cd WHERE cd.curso_id = ?');
        $stmt->execute([$id]);
        foreach ($stmt->fetchAll() as $existente) {
            if (!in_array((int) $existente['id'], $mantidos, true)) {
                $pdo->prepare($existente['envios'] ? 'UPDATE curso_documentos SET ativo = 0 WHERE id = ?' : 'DELETE FROM curso_documentos WHERE id = ?')->execute([$existente['id']]);
            }
        }
        $pdo->commit();
        sincronizarDocumentosCurso($pdo, (int) $id); // documento novo / opção mudada: libera para quem está em dia
        flash('success', 'Curso salvo com sucesso.');
        redirect('/cursos/form.php?id=' . $id);
    }

    $curso = ['nome' => $nome, 'carga_horaria' => $cargaHoraria, 'descricao' => $descricao, 'ativo' => $ativo] + $opcoesDocumentos;
}

$turmas = [];
if ($id) {
    $stmt = $pdo->prepare(
        "SELECT t.*, p.nome AS professor_nome, s.nome AS situacao_nome, s.cor AS situacao_cor,
                (SELECT COUNT(*) FROM matriculas m WHERE m.turma_id = t.id AND m.status = 'ativa') AS alunos_ativos
         FROM turmas t
         JOIN situacoes_turma s ON s.id = t.situacao_id
         LEFT JOIN professores p ON p.id = t.professor_id
         WHERE t.curso_id = ?
         ORDER BY t.data_inicio DESC"
    );
    $stmt->execute([$id]);
    $turmas = $stmt->fetchAll();
}

$pageTitle = $id ? e($curso['nome']) : 'Novo curso';
$pageBreadcrumb = 'Acadêmico · Cursos';
$activeMenu = 'cursos';
$modelosDocumento = modelosCertificado($pdo);
$pageAction = $podeEditar
    ? '<a href="/cursos/listar.php" class="btn btn-outline-secondary">Cancelar</a>'
        . '<button type="submit" form="form-principal" class="btn btn-brand"><i class="bi bi-check-lg"></i> Salvar</button>'
    : '<a href="/cursos/listar.php" class="btn btn-outline-secondary">Voltar</a>';
require __DIR__ . '/../../includes/layout_header.php';
?>

<?php if ($erros): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($erros as $erro): ?><li><?= e($erro) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="secao" style="max-width: 760px;">
    <div class="secao-titulo"><i class="bi bi-book"></i> Dados do curso</div>
    <form method="post" id="form-principal">
        <div class="row g-3">
            <div class="col-md-9">
                <label class="form-label">Nome do curso *</label>
                <input type="text" name="nome" class="form-control" required maxlength="150" value="<?= e($curso['nome'] ?? '') ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Carga horária (horas)</label>
                <input type="number" name="carga_horaria" class="form-control" min="0" value="<?= e((string) ($curso['carga_horaria'] ?? '')) ?>">
            </div>
            <div class="col-12">
                <label class="form-label">Descrição</label>
                <textarea name="descricao" class="form-control" rows="5"><?= e($curso['descricao'] ?? '') ?></textarea>
            </div>
            <div class="col-12">
                <div class="d-flex align-items-center mb-2">
                    <label class="form-label mb-0 fw-semibold">Módulos do curso</label>
                    <span class="small text-muted ms-2" id="soma-modulos"></span>
                    <button type="button" class="btn btn-sm btn-outline-secondary ms-auto" onclick="adicionarModulo()"><i class="bi bi-plus-lg"></i> Módulo</button>
                </div>
                <div id="lista-modulos"></div>
                <div class="form-text">Aparecem na página do curso no portal e podem ir no certificado (elemento "Lista de módulos"). Use as setas para mudar a ordem.
                    Documento do módulo (opcional): liberado ao aluno quando o módulo é concluído na turma.</div>
                <template id="modelo-modulo">
                    <div class="input-group input-group-sm mb-1 linha-modulo">
                        <span class="input-group-text numero"></span>
                        <input type="hidden" name="modulo_id[]">
                        <input type="text" name="modulo_nome[]" class="form-control" maxlength="150" placeholder="Nome do módulo">
                        <input type="number" name="modulo_carga[]" class="form-control" min="0" style="max-width: 110px;" placeholder="Horas" oninput="somarModulos()">
                        <select name="modulo_doc[]" class="form-select" style="max-width: 230px;" title="Documento liberado ao concluir o módulo (opcional)">
                            <option value="">Sem documento</option>
                            <?php foreach ($modelosDocumento as $modeloOpcao): ?><option value="<?= (int) $modeloOpcao['id'] ?>"><?= e($modeloOpcao['nome']) ?> (<?= ($modeloOpcao['orientacao'] ?? 'horizontal') === 'vertical' ? 'vertical' : 'horizontal' ?>)</option><?php endforeach; ?>
                        </select>
                        <button type="button" class="btn btn-outline-secondary" onclick="moverModulo(this, -1)" title="Subir"><i class="bi bi-arrow-up"></i></button>
                        <button type="button" class="btn btn-outline-secondary" onclick="moverModulo(this, 1)" title="Descer"><i class="bi bi-arrow-down"></i></button>
                        <button type="button" class="btn btn-outline-danger" onclick="this.closest('.linha-modulo').remove(); numerarModulos();" title="Remover"><i class="bi bi-x-lg"></i></button>
                    </div>
                </template>
            </div>
            <div class="col-md-6">
                <label class="form-label">Modelo do certificado de conclusão</label>
                <?php $modeloAtual = $modeloCertificadoCurso ?: ($curso['certificado_modelo_id'] ?? null); ?>
                <select name="certificado_modelo" class="form-select">
                    <option value="">Modelo padrão</option>
                    <?php foreach ($modelosDocumento as $modeloOpcao): ?><option value="<?= (int) $modeloOpcao['id'] ?>" <?= (int) $modeloAtual === (int) $modeloOpcao['id'] ? 'selected' : '' ?>><?= e($modeloOpcao['nome']) ?> (<?= ($modeloOpcao['orientacao'] ?? 'horizontal') === 'vertical' ? 'vertical' : 'horizontal' ?>)</option><?php endforeach; ?>
                </select>
                <div class="form-text">Modelos em Acadêmico › Documentos<?php if (usuarioPodeGerenciarPortal()): ?> — <a href="/configuracoes/certificado.php" target="_blank">ver modelos</a><?php endif; ?>.</div>
            </div>
            <div class="col-12">
                <div class="fw-semibold mb-1">Liberação dos documentos</div>
                <div class="form-check"><input type="checkbox" class="form-check-input" name="liberar_documentos_auto" id="liberar-auto" <?= ($curso['liberar_documentos_auto'] ?? 1) ? 'checked' : '' ?>><label class="form-check-label" for="liberar-auto">Liberar automaticamente ao concluir a turma (e cada módulo, nos documentos de módulo)</label></div>
                <div class="form-check"><input type="checkbox" class="form-check-input" name="bloquear_inadimplente" id="bloq-inad" <?= ($curso['bloquear_inadimplente'] ?? 0) ? 'checked' : '' ?>><label class="form-check-label" for="bloq-inad">Bloquear documentação para aluno inadimplente (com parcela vencida)</label></div>
                <div class="form-check"><input type="checkbox" class="form-check-input" name="bloquear_pendencia_documental" id="bloq-doc" <?= ($curso['bloquear_pendencia_documental'] ?? 1) ? 'checked' : '' ?>><label class="form-check-label" for="bloq-doc">Bloquear documentação para aluno com pendência na documentação (documentos obrigatórios abaixo)</label></div>
                <div class="form-text">Quem tem pendência fica de fora da liberação automática e é liberado sozinho quando resolver.</div>
            </div>
            <div class="col-12">
                <div class="d-flex align-items-center mb-2">
                    <label class="form-label mb-0 fw-semibold">Documentos obrigatórios</label>
                    <button type="button" class="btn btn-sm btn-outline-secondary ms-auto" onclick="adicionarDocumento()"><i class="bi bi-plus-lg"></i> Documento</button>
                </div>
                <div id="lista-documentos"></div>
                <div class="form-text">O aluno envia pelo portal (em "Meus documentos"). Só PDF, JPG e PNG; o sistema comprime e confere cada arquivo. "Trava o certificado": enquanto não for aprovado (ou dispensado), o certificado não é liberado.</div>
                <template id="modelo-documento">
                    <div class="border rounded p-2 mb-2 linha-documento">
                        <input type="hidden" name="doc_id[]">
                        <div class="row g-2 align-items-center">
                            <div class="col-md-4"><input type="text" name="doc_nome[]" class="form-control form-control-sm" maxlength="120" placeholder="Nome (ex.: Diploma de graduação)"></div>
                            <div class="col-md-3">
                                <select name="doc_tipos[]" class="form-select form-select-sm" title="Tipos de arquivo aceitos">
                                    <option value="ambos">PDF ou foto (JPG/PNG)</option>
                                    <option value="pdf">Só PDF</option>
                                    <option value="imagem">Só foto (JPG/PNG)</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <select name="doc_trava[]" class="form-select form-select-sm">
                                    <option value="1">Trava o certificado</option>
                                    <option value="0">Não trava o certificado</option>
                                </select>
                            </div>
                            <div class="col-md-2 text-end">
                                <button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('.linha-documento').remove();" title="Remover"><i class="bi bi-x-lg"></i></button>
                            </div>
                            <div class="col-12"><input type="text" name="doc_instrucao[]" class="form-control form-control-sm" maxlength="255" placeholder="Instrução para o aluno (opcional) — ex.: frente e verso, legível"></div>
                        </div>
                    </div>
                </template>
            </div>
            <div class="col-12 form-check ps-4">
                <input type="checkbox" name="ativo" class="form-check-input" id="ativo" <?= ($curso['ativo'] ?? 1) ? 'checked' : '' ?>>
                <label class="form-check-label" for="ativo">Curso ativo (disponível para abrir turmas)</label>
            </div>
        </div>
    </form>
</div>

<?php if ($id): ?>
<div class="secao">
    <div class="secao-titulo">
        <i class="bi bi-people"></i> Turmas deste curso
        <?php if ($curso['ativo']): ?>
            <?php if (usuarioPodeCriarTurma()): ?><a href="/turmas/form.php?curso_id=<?= (int) $id ?>" class="btn btn-sm btn-brand ms-auto"><i class="bi bi-plus-lg"></i> Nova turma</a><?php endif; ?>
        <?php endif; ?>
    </div>
    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead><tr><th>Turma</th><th>Professor</th><th>Período</th><th class="text-center">Alunos</th><th>Situação</th></tr></thead>
                <tbody>
                <?php if (!$turmas): ?>
                    <tr><td colspan="5" class="text-center text-muted py-3">Nenhuma turma aberta para este curso.</td></tr>
                <?php endif; ?>
                <?php foreach ($turmas as $t): ?>
                    <tr class="cursor-pointer" onclick="window.location='/turmas/visualizar.php?id=<?= (int) $t['id'] ?>'">
                        <td><?= e($t['nome']) ?></td>
                        <td><?= e($t['professor_nome'] ?: '-') ?></td>
                        <td><?= e(periodoTurma($t)) ?></td>
                        <td class="text-center"><?= (int) $t['alunos_ativos'] ?>/<?= (int) $t['vagas'] ?></td>
                        <td><?= badgeSituacaoTurma($t['situacao_nome'], $t['situacao_cor']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
function adicionarModulo(nome = '', carga = '', id = '', documento = '') {
    const linha = document.getElementById('modelo-modulo').content.firstElementChild.cloneNode(true);
    linha.querySelector('[name="modulo_id[]"]').value = id || '';
    linha.querySelector('[name="modulo_nome[]"]').value = nome;
    linha.querySelector('[name="modulo_carga[]"]').value = carga ?? '';
    linha.querySelector('[name="modulo_doc[]"]').value = documento || '';
    document.getElementById('lista-modulos').appendChild(linha);
    numerarModulos();
    if (!nome) linha.querySelector('[name="modulo_nome[]"]').focus();
}
function moverModulo(botao, direcao) {
    const linha = botao.closest('.linha-modulo');
    const vizinha = direcao < 0 ? linha.previousElementSibling : linha.nextElementSibling;
    if (vizinha) direcao < 0 ? vizinha.before(linha) : vizinha.after(linha);
    numerarModulos();
}
function numerarModulos() {
    document.querySelectorAll('#lista-modulos .numero').forEach((el, i) => { el.textContent = i + 1; });
    somarModulos();
}
// Mostra a soma das horas dos módulos ao lado da carga horária do curso.
function somarModulos() {
    const soma = [...document.querySelectorAll('[name="modulo_carga[]"]')].reduce((t, c) => t + (parseInt(c.value, 10) || 0), 0);
    const total = parseInt(document.querySelector('[name="carga_horaria"]').value, 10) || 0;
    document.getElementById('soma-modulos').textContent = soma ? `soma: ${soma} h` + (total && soma !== total ? ` (carga do curso: ${total} h)` : '') : '';
}
<?php foreach ($modulos as $modulo): ?>
adicionarModulo(<?= json_encode($modulo['nome']) ?>, <?= json_encode($modulo['carga_horaria']) ?>, <?= json_encode($modulo['id'] ?? '') ?>, <?= json_encode($modulo['documento_modelo'] ?? '') ?>);
<?php endforeach; ?>
function adicionarDocumento(doc = {}) {
    const linha = document.getElementById('modelo-documento').content.firstElementChild.cloneNode(true);
    linha.querySelector('[name="doc_id[]"]').value = doc.id || '';
    linha.querySelector('[name="doc_nome[]"]').value = doc.nome || '';
    linha.querySelector('[name="doc_instrucao[]"]').value = doc.instrucao || '';
    linha.querySelector('[name="doc_tipos[]"]').value = doc.aceita_pdf === 0 ? 'imagem' : (doc.aceita_imagem === 0 ? 'pdf' : 'ambos');
    linha.querySelector('[name="doc_trava[]"]').value = doc.trava_certificado === 0 ? '0' : '1';
    document.getElementById('lista-documentos').appendChild(linha);
    if (!doc.nome) linha.querySelector('[name="doc_nome[]"]').focus();
}
<?php foreach ($documentos as $doc): ?>
adicionarDocumento(<?= json_encode(['id' => $doc['id'], 'nome' => $doc['nome'], 'instrucao' => $doc['instrucao'], 'aceita_pdf' => (int) $doc['aceita_pdf'], 'aceita_imagem' => (int) $doc['aceita_imagem'], 'trava_certificado' => (int) $doc['trava_certificado']], JSON_UNESCAPED_UNICODE) ?>);
<?php endforeach; ?>
document.querySelector('[name="carga_horaria"]').addEventListener('input', somarModulos);
</script>

<?php if (!$podeEditar): ?>
<script>
// Só consulta: trava os campos (o servidor também recusa o envio).
document.querySelectorAll('#form-principal input, #form-principal select, #form-principal textarea, #form-principal button').forEach((el) => { el.disabled = true; });
</script>
<?php endif; ?>
<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
