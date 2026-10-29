<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirPermissaoAcao('gerenciar_materiais', 'Seu perfil não envia materiais.');

// Cria (um material por arquivo enviado, ou um link) ou edita um material
// (JSON). onde: turma (só a turma) ou curso (todas as turmas do curso).
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responderJson(['sucesso' => false]);
}
$pdo = getDB();
$ftp = ftpMateriais($pdo);
$id = (int) ($_POST['id'] ?? 0);
$liberacao = (string) ($_POST['liberacao'] ?? 'agora');
$liberarEm = $liberacao === 'data' && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', (string) ($_POST['liberar_em'] ?? '')) ? str_replace('T', ' ', $_POST['liberar_em']) . ':00' : null;
if ($liberacao === 'data' && !$liberarEm) {
    responderJson(['sucesso' => false, 'mensagem' => 'Informe a data de liberação.']);
}
$localId = (int) ($_POST['local_id'] ?? 0);
$stmt = $pdo->prepare('SELECT COUNT(*) FROM material_locais WHERE id = ?');
$stmt->execute([$localId]);
if (!(int) $stmt->fetchColumn()) {
    responderJson(['sucesso' => false, 'mensagem' => 'Escolha o local.']);
}
$titulo = mb_substr(trim((string) ($_POST['titulo'] ?? '')), 0, 200);
$descricao = mb_substr(trim((string) ($_POST['descricao'] ?? '')), 0, 1000) ?: null;
$tipo = ($_POST['tipo'] ?? '') === 'link' ? 'link' : 'arquivo';
$link = $tipo === 'link' ? linkMaterialValido((string) ($_POST['link'] ?? '')) : null;
if ($tipo === 'link' && !$link) {
    responderJson(['sucesso' => false, 'mensagem' => 'O link precisa começar com http:// ou https://.']);
}

// Arquivos enviados (validados antes de gravar qualquer coisa).
$arquivos = [];
foreach ((array) ($_FILES['arquivos']['name'] ?? []) as $i => $nome) {
    $erro = $_FILES['arquivos']['error'][$i] ?? UPLOAD_ERR_NO_FILE;
    if ($erro === UPLOAD_ERR_NO_FILE) {
        continue;
    }
    if ($erro !== UPLOAD_ERR_OK) {
        responderJson(['sucesso' => false, 'mensagem' => '"' . $nome . '" não chegou ' . (in_array($erro, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? '(grande demais para o servidor).' : '(erro no envio).')]);
    }
    if ($_FILES['arquivos']['size'][$i] > $ftp['max_mb'] * 1048576) {
        responderJson(['sucesso' => false, 'mensagem' => '"' . $nome . '" passa do limite de ' . $ftp['max_mb'] . ' MB.']);
    }
    $nomeLimpo = trim(preg_replace('/[\x00-\x1f\/\\\\:*?"<>|]+/', '_', basename((string) $nome))) ?: 'arquivo';
    // Tipo conferido pelo conteúdo; sem programas, macros, PDF com código, etc.
    $checagem = validarArquivoMaterial($_FILES['arquivos']['tmp_name'][$i], $nomeLimpo);
    if (!$checagem['ok']) {
        registrarAtividade('materiais', 'bloqueio', 'Arquivo recusado: ' . $checagem['mensagem']);
        responderJson(['sucesso' => false, 'mensagem' => $checagem['mensagem']]);
    }
    $arquivos[] = ['tmp' => $_FILES['arquivos']['tmp_name'][$i], 'nome' => $nomeLimpo, 'tamanho' => (int) $_FILES['arquivos']['size'][$i],
        'tipo' => $checagem['tipo']];
}

// Limite total de espaço (Configurações › Arquivos).
$cota = cotaMateriais($pdo);
$novos = array_sum(array_column($arquivos, 'tamanho'));
if ($novos > $cota['livre']) {
    responderJson(['sucesso' => false, 'mensagem' => 'Sem espaço: os materiais já usam ' . tamanhoLegivel($cota['usado']) . ' de ' . tamanhoLegivel($cota['limite'])
        . ' (sobram ' . tamanhoLegivel($cota['livre']) . '). Exclua materiais antigos ou peça para aumentar o limite.']);
}

$visivel = $liberacao === 'escondido' ? 0 : 1;
$modulo = (int) ($_POST['curso_modulo_id'] ?? 0) ?: null;

if ($id) {
    // Editar
    $stmt = $pdo->prepare('SELECT * FROM materiais WHERE id = ?');
    $stmt->execute([$id]);
    $material = $stmt->fetch();
    if (!$material || !podeEditarMaterial($pdo, $material)) {
        responderJson(['sucesso' => false, 'mensagem' => 'Você não pode editar este material.']);
    }
    if ($modulo) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM curso_modulos WHERE id = ? AND curso_id = (SELECT COALESCE(?, (SELECT curso_id FROM turmas WHERE id = ?)))');
        $stmt->execute([$modulo, $material['curso_id'], $material['turma_id']]);
        $modulo = (int) $stmt->fetchColumn() ? $modulo : null;
    }
    $campos = ['titulo = ?' => $titulo ?: $material['titulo'], 'descricao = ?' => $descricao, 'local_id = ?' => $localId, 'curso_modulo_id = ?' => $modulo, 'visivel = ?' => $visivel, 'liberar_em = ?' => $liberarEm];
    if ($material['tipo'] === 'link') {
        $campos['link = ?'] = $link ?: $material['link'];
    } elseif ($arquivos) {
        // Trocou o arquivo: grava o novo e apaga o antigo.
        $g = guardarArquivoMaterial($pdo, $arquivos[0]['tmp'], $arquivos[0]['nome'], $material['codigo'] . '-' . bin2hex(random_bytes(3)));
        if (!$g['ok']) {
            responderJson(['sucesso' => false, 'mensagem' => $g['mensagem']]);
        }
        apagarArquivoMaterial($pdo, $material);
        $campos += ['arquivo_nome = ?' => $arquivos[0]['nome'], 'arquivo_tipo = ?' => $arquivos[0]['tipo'], 'tamanho = ?' => $arquivos[0]['tamanho'], 'armazenamento = ?' => $g['armazenamento'], 'caminho = ?' => $g['caminho']];
    }
    $pdo->prepare('UPDATE materiais SET ' . implode(', ', array_keys($campos)) . ' WHERE id = ?')->execute([...array_values($campos), $id]);
    registrarAtividade('materiais', 'edicao', 'Material "' . ($titulo ?: $material['titulo']) . '" editado');
    responderJson(['sucesso' => true]);
}

// Criar
$onde = ($_POST['onde'] ?? '') === 'curso' ? 'curso' : 'turma';
$turmaId = (int) ($_POST['turma_id'] ?? 0);
$cursoId = (int) ($_POST['curso_id'] ?? 0);
if ($onde === 'turma') {
    if (!$turmaId || !podeMaterialTurma($pdo, $turmaId)) {
        responderJson(['sucesso' => false, 'mensagem' => 'Você não pode enviar materiais para esta turma.']);
    }
    $stmt = $pdo->prepare('SELECT curso_id FROM turmas WHERE id = ?');
    $stmt->execute([$turmaId]);
    $cursoDaTurma = (int) $stmt->fetchColumn();
    [$destCurso, $destTurma] = [null, $turmaId];
} else {
    if (!$cursoId || !podeMaterialCurso($pdo, $cursoId)) {
        responderJson(['sucesso' => false, 'mensagem' => 'Você não pode enviar materiais para este curso.']);
    }
    $cursoDaTurma = $cursoId;
    [$destCurso, $destTurma] = [$cursoId, null];
}
if ($modulo) {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM curso_modulos WHERE id = ? AND curso_id = ?');
    $stmt->execute([$modulo, $cursoDaTurma]);
    $modulo = (int) $stmt->fetchColumn() ? $modulo : null;
}
if ($tipo === 'arquivo' && !$arquivos) {
    responderJson(['sucesso' => false, 'mensagem' => 'Escolha pelo menos um arquivo.']);
}
if ($tipo === 'link' && $titulo === '') {
    responderJson(['sucesso' => false, 'mensagem' => 'Dê um título para o link.']);
}

$inserir = $pdo->prepare('INSERT INTO materiais (curso_id, turma_id, local_id, curso_modulo_id, titulo, descricao, tipo, link, arquivo_nome, arquivo_tipo, tamanho, armazenamento, caminho, codigo, visivel, liberar_em, usuario_id, usuario_nome)
                          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
$criados = 0;
if ($tipo === 'link') {
    $inserir->execute([$destCurso, $destTurma, $localId, $modulo, $titulo, $descricao, 'link', $link, null, null, 0, null, null, bin2hex(random_bytes(16)), $visivel, $liberarEm, usuarioAtualId(), usuarioAtualNome()]);
    $criados = 1;
}
foreach ($arquivos as $a) {
    $codigo = bin2hex(random_bytes(16));
    $g = guardarArquivoMaterial($pdo, $a['tmp'], $a['nome'], $codigo);
    if (!$g['ok']) {
        responderJson(['sucesso' => false, 'mensagem' => ($criados ? $criados . ' arquivo(s) enviados; "' . $a['nome'] . '" falhou: ' : '') . $g['mensagem']]);
    }
    $tituloArquivo = count($arquivos) === 1 && $titulo !== '' ? $titulo : preg_replace('/\.[A-Za-z0-9]{1,6}$/', '', $a['nome']);
    $inserir->execute([$destCurso, $destTurma, $localId, $modulo, mb_substr($tituloArquivo, 0, 200), $descricao, 'arquivo', null, $a['nome'], $a['tipo'], $a['tamanho'], $g['armazenamento'], $g['caminho'], $codigo, $visivel, $liberarEm, usuarioAtualId(), usuarioAtualNome()]);
    $criados++;
}
registrarAtividade('materiais', 'criacao', $criados . ' material(is) enviado(s) para ' . ($onde === 'turma' ? 'a turma #' . $turmaId : 'o curso #' . $cursoId));
responderJson(['sucesso' => true, 'criados' => $criados]);
