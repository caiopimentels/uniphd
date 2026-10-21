<?php

// Documentos que a instituição emite para o aluno: certificado de conclusão,
// declaração de módulo... (modelos em Acadêmico › Documentos).
//
// O curso diz quais emite (curso_emissoes): nome, modelo e quando fica
// disponível — ao concluir o curso (modulo_id NULL) ou ao concluir um
// módulo na turma (turma_modulos). Cada documento liberado para uma
// matrícula é uma linha em "certificados", com o código de verificação.
//
// Liberação:
//   - automática (opção do curso): ao ficar disponível, libera para quem
//     não tem bloqueio; quem tem é liberado sozinho quando resolver (pagou,
//     teve o documento aprovado...) — ver sincronizarDocumentosMatricula;
//   - manual na turma ou na matrícula (liberar/bloquear). Bloqueio manual
//     (linha com liberado = 0) não é desfeito pela automática.
// Documento avulso: qualquer modelo liberado para um aluno só (ex.: uma
// declaração específica). Fica numa emissão com avulso = 1 e aparece só para
// quem o recebeu; a liberação automática não mexe nele.
// Bloqueios (opções do curso): aluno inadimplente (parcela vencida) e
// pendência de documentação (documento enviado pelo aluno que trava e não
// foi aprovado/dispensado). Valem também para abrir no portal.

function emissoesCurso(PDO $pdo, int $cursoId, bool $incluirAvulsos = false): array
{
    $stmt = $pdo->prepare(
        'SELECT e.*, cm.nome AS modulo_nome, cm.ordem AS modulo_ordem, mo.nome AS modelo_nome, mo.orientacao
         FROM curso_emissoes e
         LEFT JOIN curso_modulos cm ON cm.id = e.modulo_id
         LEFT JOIN certificado_modelos mo ON mo.id = e.modelo_id
         WHERE e.curso_id = ? AND e.ativo = 1' . ($incluirAvulsos ? '' : ' AND e.avulso = 0') . '
         ORDER BY e.avulso, e.modulo_id IS NULL, cm.ordem, e.ordem, e.id'
    );
    $stmt->execute([$cursoId]);
    return $stmt->fetchAll();
}

// Matrícula com o que as regras precisam (curso e opções).
function matriculaParaDocumentos(PDO $pdo, int $matriculaId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT m.*, t.curso_id, t.nome AS turma_nome, c.nome AS curso_nome, c.liberar_documentos_auto, c.bloquear_inadimplente, c.bloquear_pendencia_documental
         FROM matriculas m JOIN turmas t ON t.id = m.turma_id JOIN cursos c ON c.id = t.curso_id WHERE m.id = ?'
    );
    $stmt->execute([$matriculaId]);
    return $stmt->fetch() ?: null;
}

/**
 * O que impede o aluno de receber os documentos desta matrícula agora,
 * conforme as opções do curso. ['documentacao' => texto, 'financeiro' => texto].
 */
function bloqueiosDocumentosMatricula(PDO $pdo, array $matricula): array
{
    $bloqueios = [];
    if ($matricula['bloquear_pendencia_documental'] && ($faltam = pendenciasDocumentosMatricula($pdo, (int) $matricula['id']))) {
        $bloqueios['documentacao'] = 'Pendente de documentação (' . implode(', ', $faltam) . ')';
    }
    if ($matricula['bloquear_inadimplente']) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM contas_receber WHERE aluno_id = ? AND status = 'pendente' AND data_vencimento < CURDATE() AND excluido_em IS NULL");
        $stmt->execute([$matricula['aluno_id']]);
        if ((int) $stmt->fetchColumn() > 0) {
            $bloqueios['financeiro'] = 'Pendência financeira';
        }
    }
    return $bloqueios;
}

/**
 * Documentos do curso para a matrícula: cada um com disponivel (módulo
 * concluído na turma / curso concluído), o documento emitido (se houver) e
 * a situação para mostrar: liberado, bloqueado (manual), pendente (motivo)
 * ou indisponível (quando fica disponível).
 */
function documentosMatricula(PDO $pdo, int $matriculaId, ?array $bloqueios = null, ?int $avulsoId = null): array
{
    $matricula = matriculaParaDocumentos($pdo, $matriculaId);
    if (!$matricula) {
        return [];
    }
    $bloqueios ??= bloqueiosDocumentosMatricula($pdo, $matricula);
    $stmt = $pdo->prepare('SELECT curso_modulo_id, concluido_em FROM turma_modulos WHERE turma_id = ?');
    $stmt->execute([$matricula['turma_id']]);
    $modulosConcluidos = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $stmt = $pdo->prepare('SELECT * FROM certificados WHERE matricula_id = ?');
    $stmt->execute([$matriculaId]);
    $emitidos = [];
    foreach ($stmt->fetchAll() as $linha) {
        $emitidos[(int) $linha['curso_emissao_id']] = $linha;
    }

    $lista = [];
    foreach (emissoesCurso($pdo, (int) $matricula['curso_id'], true) as $e) {
        $emitido = $emitidos[(int) $e['id']] ?? null;
        // Avulso: só para quem recebeu (ou o que está sendo liberado agora).
        if ($e['avulso'] && !$emitido && (int) $e['id'] !== $avulsoId) {
            continue;
        }
        // Curso concluído = todos os módulos concluídos; avulso vale na hora.
        $disponivel = $matricula['status'] === 'concluida'
            || ($e['avulso'] && $matricula['status'] === 'ativa')
            || ($e['modulo_id'] && $matricula['status'] === 'ativa' && isset($modulosConcluidos[(int) $e['modulo_id']]));
        if ($emitido && $emitido['liberado'] && !$bloqueios) {
            $situacao = ['liberado', 'success', 'Liberado'];
        } elseif ($emitido && !$emitido['liberado']) {
            $situacao = ['bloqueado', 'secondary', 'Bloqueado pela secretaria'];
        } elseif ($bloqueios && $emitido) {
            // Já liberado, mas a pendência impede o aluno de abrir.
            $situacao = ['pendente', 'danger', 'Liberado, mas o aluno só abre quando resolver: ' . implode(' · ', $bloqueios)];
        } elseif ($bloqueios && $disponivel) {
            $situacao = ['pendente', 'danger', implode(' · ', $bloqueios)];
        } elseif (!$disponivel) {
            $situacao = ['indisponivel', 'light', $e['avulso'] ? 'Só com matrícula ativa ou concluída' : ($e['modulo_id'] ? 'Ao concluir o módulo ' . $e['modulo_nome'] : 'Ao concluir o curso')];
        } else {
            $situacao = ['aguardando', 'warning', 'Disponível — aguardando liberação'];
        }
        $lista[] = $e + ['disponivel' => $disponivel, 'emitido' => $emitido, 'liberado' => $emitido && $emitido['liberado'], 'pendencias' => implode(' · ', $bloqueios), 'situacao' => $situacao[0], 'cor' => $situacao[1], 'texto' => $situacao[2]];
    }
    return $lista;
}

// Emissão avulsa de um modelo neste curso (cria na primeira vez).
function documentoAvulsoCurso(PDO $pdo, int $cursoId, int $modeloId): ?int
{
    $modelo = buscarModeloCertificado($pdo, $modeloId);
    if (!$modelo) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT id FROM curso_emissoes WHERE curso_id = ? AND modelo_id = ? AND avulso = 1 ORDER BY id LIMIT 1');
    $stmt->execute([$cursoId, $modeloId]);
    if ($id = (int) $stmt->fetchColumn()) {
        $pdo->prepare('UPDATE curso_emissoes SET ativo = 1, nome = ? WHERE id = ?')->execute([mb_substr($modelo['nome'], 0, 120), $id]);
        return $id;
    }
    $pdo->prepare('INSERT INTO curso_emissoes (curso_id, nome, modelo_id, modulo_id, avulso, ordem) VALUES (?, ?, ?, NULL, 1, 2000)')
        ->execute([$cursoId, mb_substr($modelo['nome'], 0, 120), $modeloId]);
    return (int) $pdo->lastInsertId();
}

function gerarCodigoDocumento(PDO $pdo): string
{
    // Sem 0/O/1/I, que confundem quem digita o código para conferir.
    $alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM certificados WHERE codigo = ?');
    do {
        $codigo = '';
        for ($i = 0; $i < 12; $i++) {
            $codigo .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
        }
        $stmt->execute([$codigo]);
    } while ($stmt->fetchColumn());
    return $codigo;
}

/**
 * Libera (ou bloqueia) um documento para a matrícula. Liberar respeita os
 * bloqueios do curso, a não ser com $forcar (administrador). O documento
 * precisa estar disponível (módulo/curso concluído), também salvo com $forcar.
 * Retorna ['ok' => bool, 'mensagem' => string].
 */
function definirLiberacaoDocumento(PDO $pdo, int $matriculaId, int $emissaoId, bool $liberar, bool $forcar = false, ?string $quem = null, ?string $dataEvento = null): array
{
    $matricula = matriculaParaDocumentos($pdo, $matriculaId);
    $documento = null;
    foreach ($matricula ? documentosMatricula($pdo, $matriculaId, null, $emissaoId) : [] as $d) {
        if ((int) $d['id'] === $emissaoId) {
            $documento = $d;
        }
    }
    if (!$documento) {
        return ['ok' => false, 'mensagem' => 'Documento não encontrado neste curso.'];
    }
    $stmt = $pdo->prepare('SELECT a.nome FROM alunos a WHERE a.id = ?');
    $stmt->execute([$matricula['aluno_id']]);
    $aluno = (string) $stmt->fetchColumn();
    $quem ??= usuarioLogado() ? usuarioAtualNome() : 'Sistema';

    if ($liberar) {
        if (!$forcar && !$documento['disponivel']) {
            return ['ok' => false, 'mensagem' => $aluno . ': "' . $documento['nome'] . '" ainda não está disponível (' . mb_strtolower($documento['texto']) . ').'];
        }
        if (!$forcar && ($bloqueios = bloqueiosDocumentosMatricula($pdo, $matricula))) {
            return ['ok' => false, 'mensagem' => $aluno . ': ' . implode(' · ', $bloqueios) . '.'];
        }
    }
    // Data do evento ({data_evento}): só muda quando informada.
    if ($documento['emitido']) {
        $pdo->prepare('UPDATE certificados SET liberado = ?, liberado_em = NOW(), liberado_por = ?, data_evento = COALESCE(?, data_evento) WHERE id = ?')
            ->execute([$liberar ? 1 : 0, $quem, $dataEvento, $documento['emitido']['id']]);
    } else {
        $pdo->prepare('INSERT INTO certificados (matricula_id, curso_emissao_id, data_evento, codigo, liberado, liberado_em, liberado_por) VALUES (?, ?, ?, ?, ?, NOW(), ?)')
            ->execute([$matriculaId, $emissaoId, $dataEvento, gerarCodigoDocumento($pdo), $liberar ? 1 : 0, $quem]);
    }
    registrarHistoricoAluno($pdo, (int) $matricula['aluno_id'], 'documento', ($liberar ? 'Liberado' : 'Bloqueado') . ': "' . $documento['nome'] . '" — ' . $matricula['curso_nome']
        . ($liberar && $forcar ? ' (liberado mesmo com pendência)' : ''), $matriculaId);
    return ['ok' => true, 'mensagem' => $aluno . ': "' . $documento['nome'] . '" ' . ($liberar ? 'liberado' : 'bloqueado') . '.'];
}

/**
 * Liberação automática (se o curso usa): libera os documentos disponíveis
 * que ainda não foram liberados nem bloqueados, para quem não tem bloqueio.
 * Retorna quantos liberou. Chamada quando algo muda: módulo/turma concluída,
 * pagamento, documento aprovado, abertura da turma.
 */
function sincronizarDocumentosMatricula(PDO $pdo, int $matriculaId): int
{
    $matricula = matriculaParaDocumentos($pdo, $matriculaId);
    if (!$matricula || !$matricula['liberar_documentos_auto'] || !in_array($matricula['status'], ['ativa', 'concluida'], true)) {
        return 0;
    }
    if (bloqueiosDocumentosMatricula($pdo, $matricula)) {
        return 0;
    }
    $liberados = 0;
    foreach (documentosMatricula($pdo, $matriculaId, []) as $d) {
        if ($d['disponivel'] && !$d['emitido']) {
            $r = definirLiberacaoDocumento($pdo, $matriculaId, (int) $d['id'], true, false, 'Liberação automática');
            $liberados += $r['ok'] ? 1 : 0;
        }
    }
    return $liberados;
}

function sincronizarDocumentosTurma(PDO $pdo, int $turmaId): int
{
    $stmt = $pdo->prepare("SELECT id FROM matriculas WHERE turma_id = ? AND status IN ('ativa', 'concluida')");
    $stmt->execute([$turmaId]);
    $total = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $matriculaId) {
        $total += sincronizarDocumentosMatricula($pdo, (int) $matriculaId);
    }
    return $total;
}

function sincronizarDocumentosAluno(PDO $pdo, int $alunoId): int
{
    $stmt = $pdo->prepare("SELECT id FROM matriculas WHERE aluno_id = ? AND status IN ('ativa', 'concluida')");
    $stmt->execute([$alunoId]);
    $total = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $matriculaId) {
        $total += sincronizarDocumentosMatricula($pdo, (int) $matriculaId);
    }
    return $total;
}

/**
 * Documento emitido com tudo para desenhar e conferir: aluno, curso, turma,
 * nome do documento, modelo e módulo. $campo: 'id' ou 'codigo'.
 */
function documentoEmitidoCompleto(PDO $pdo, string $campo, $valor): ?array
{
    $coluna = $campo === 'codigo' ? 'ce.codigo' : 'ce.id';
    $stmt = $pdo->prepare(
        "SELECT ce.*, m.aluno_id, m.turma_id, a.nome AS aluno_nome, a.cpf, a.ra, c.id AS curso_id, c.nome AS curso_nome, c.carga_horaria, a.nome_mae, a.nome_pai,
                a.data_nascimento, c.certificado_modelo_id, t.nome AS turma_nome, t.parceria, t.data_inicio, t.data_termino, t.data_conclusao,
                e.nome AS documento_nome, e.modelo_id, e.modulo_id, cm.nome AS modulo_nome, cm.carga_horaria AS modulo_carga, tm.concluido_em AS modulo_concluido_em
         FROM certificados ce
         JOIN matriculas m ON m.id = ce.matricula_id
         JOIN alunos a ON a.id = m.aluno_id
         JOIN turmas t ON t.id = m.turma_id
         JOIN cursos c ON c.id = t.curso_id
         LEFT JOIN curso_emissoes e ON e.id = ce.curso_emissao_id
         LEFT JOIN curso_modulos cm ON cm.id = e.modulo_id
         LEFT JOIN turma_modulos tm ON tm.turma_id = m.turma_id AND tm.curso_modulo_id = e.modulo_id
         WHERE {$coluna} = ?"
    );
    $stmt->execute([$valor]);
    return $stmt->fetch() ?: null;
}

// Modelo do documento: o escolhido no curso; senão o padrão.
function modeloDoDocumento(PDO $pdo, array $documento): ?array
{
    $id = (int) ($documento['modelo_id'] ?? 0);
    if (!$id && empty($documento['modulo_id'])) {
        $id = (int) ($documento['certificado_modelo_id'] ?? 0); // modelo antigo do curso
    }
    return ($id ? buscarModeloCertificado($pdo, $id) : null) ?? modeloPadraoCertificado($pdo);
}

// Turma: concluir / reabrir um módulo (libera os documentos ligados a ele).
function concluirModuloTurma(PDO $pdo, int $turmaId, int $moduloId, ?string $data): array
{
    if ($data === null) {
        $pdo->prepare('DELETE FROM turma_modulos WHERE turma_id = ? AND curso_modulo_id = ?')->execute([$turmaId, $moduloId]);
        return ['ok' => true, 'mensagem' => 'Módulo reaberto. Os documentos já liberados continuam liberados.', 'liberados' => 0];
    }
    if (!strtotime($data) || $data > date('Y-m-d')) {
        return ['ok' => false, 'mensagem' => 'Informe a data de conclusão do módulo (não pode ser no futuro).', 'liberados' => 0];
    }
    $pdo->prepare('INSERT INTO turma_modulos (turma_id, curso_modulo_id, concluido_em, concluido_por) VALUES (?, ?, ?, ?)
                   ON DUPLICATE KEY UPDATE concluido_em = VALUES(concluido_em), concluido_por = VALUES(concluido_por)')
        ->execute([$turmaId, $moduloId, $data, usuarioAtualNome()]);
    $liberados = sincronizarDocumentosTurma($pdo, $turmaId);
    return ['ok' => true, 'mensagem' => 'Módulo concluído em ' . formatDate($data) . '.' . ($liberados ? " {$liberados} documento(s) liberado(s) automaticamente." : ''), 'liberados' => $liberados];
}

// Parcela paga: o aluno pode ter saído da inadimplência — libera o que
// estava esperando só por isso.
function sincronizarDocumentosPelaParcela(PDO $pdo, int $parcelaId): int
{
    $stmt = $pdo->prepare('SELECT aluno_id FROM contas_receber WHERE id = ?');
    $stmt->execute([$parcelaId]);
    $alunoId = (int) $stmt->fetchColumn();
    return $alunoId ? sincronizarDocumentosAluno($pdo, $alunoId) : 0;
}

function sincronizarDocumentosCurso(PDO $pdo, int $cursoId): int
{
    $stmt = $pdo->prepare("SELECT m.id FROM matriculas m JOIN turmas t ON t.id = m.turma_id WHERE t.curso_id = ? AND m.status IN ('ativa', 'concluida')");
    $stmt->execute([$cursoId]);
    $total = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $matriculaId) {
        $total += sincronizarDocumentosMatricula($pdo, (int) $matriculaId);
    }
    return $total;
}

/**
 * Rede de segurança da liberação automática: confere todas as matrículas
 * dos cursos que liberam sozinhos (pega quem ficou em dia por algo que não
 * avisa — parcela excluída, opção do curso mudada...).
 */
function sincronizarDocumentosTodos(PDO $pdo): int
{
    $ids = $pdo->query(
        "SELECT m.id FROM matriculas m JOIN turmas t ON t.id = m.turma_id JOIN cursos c ON c.id = t.curso_id
         WHERE c.liberar_documentos_auto = 1 AND m.status IN ('ativa', 'concluida')"
    )->fetchAll(PDO::FETCH_COLUMN);
    $total = 0;
    foreach ($ids as $matriculaId) {
        $total += sincronizarDocumentosMatricula($pdo, (int) $matriculaId);
    }
    return $total;
}
