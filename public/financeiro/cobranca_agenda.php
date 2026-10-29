<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirInadimplencia();

// Agenda de cobrança (JSON), usada pela ficha em Financeiro › Inadimplência:
// GET  ?aluno=ID               → ficha: atraso, contatos, respostas, agenda
// POST acao=registrar          → novo contato (+ próxima cobrança, se vier)
// POST acao=resposta  id=      → anota a resposta / situação de um contato
// POST acao=agendar   aluno=   → marca (data vazia = desmarca) a próxima cobrança
// POST acao=excluir   id=      → apaga um contato anotado à mão
$pdo = getDB();
$data = fn (string $campo) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_POST[$campo] ?? '')) ? $_POST[$campo] : null;
$diaSemana = ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'];
$formatarAgenda = fn (?array $a) => $a ? [
    'data' => $a['data'], 'data_br' => formatDate($a['data']) . ' (' . $diaSemana[(int) date('w', strtotime($a['data']))] . ')',
    'nota' => (string) $a['nota'], 'quem' => (string) $a['usuario_nome'],
    'situacao' => $a['data'] < date('Y-m-d') ? 'atrasada' : ($a['data'] === date('Y-m-d') ? 'hoje' : 'futura'),
] : null;

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $alunoId = (int) ($_GET['aluno'] ?? 0);
    $stmt = $pdo->prepare('SELECT id, nome, telefone, email FROM alunos WHERE id = ?');
    $stmt->execute([$alunoId]);
    $aluno = $stmt->fetch();
    if (!$aluno) {
        responderJson(['sucesso' => false, 'mensagem' => 'Aluno não encontrado.']);
    }
    $stmt = $pdo->prepare("SELECT descricao, data_vencimento, valor FROM contas_receber
                           WHERE aluno_id = ? AND status = 'pendente' AND excluido_em IS NULL AND data_vencimento < CURDATE() ORDER BY data_vencimento");
    $stmt->execute([$alunoId]);
    $parcelas = $stmt->fetchAll();
    $ficha = fichaCobranca($pdo, $alunoId);
    $canais = canaisCobranca();
    $situacoes = situacoesCobranca();
    responderJson([
        'sucesso' => true,
        'aluno' => [
            'id' => (int) $aluno['id'], 'nome' => $aluno['nome'], 'email' => (string) $aluno['email'],
            'telefone' => telefoneFormatado($aluno['telefone']), 'link_wa' => linkWhatsapp(numeroWhatsappAluno($aluno['telefone'])),
        ],
        'parcelas' => array_map(fn ($p) => ['descricao' => $p['descricao'], 'vencimento' => formatDate($p['data_vencimento']), 'valor' => formatMoney($p['valor']),
            'dias' => (int) ((strtotime(date('Y-m-d')) - strtotime($p['data_vencimento'])) / 86400)], $parcelas),
        'total' => formatMoney(array_sum(array_column($parcelas, 'valor'))),
        'agenda' => $formatarAgenda($ficha['agenda']),
        'itens' => array_map(fn ($i) => $i['tipo'] === 'whatsapp'
            ? ['tipo' => 'whatsapp', 'quando' => formatDateTime($i['contato_em']), 'descricao' => $i['descricao']]
            : [
                'tipo' => 'registro', 'id' => (int) $i['id'], 'quando' => formatDateTime($i['contato_em']),
                'canal' => $i['canal'], 'canal_nome' => $canais[$i['canal']][0], 'icone' => $canais[$i['canal']][1], 'cor' => $canais[$i['canal']][2],
                'automatico' => (bool) $i['automatico'], 'descricao' => $i['descricao'], 'resposta' => (string) $i['resposta'],
                'situacao' => $i['situacao'], 'situacao_nome' => $situacoes[$i['situacao']][0], 'situacao_cor' => $situacoes[$i['situacao']][1],
                'promessa' => $i['promessa_data'] ? formatDate($i['promessa_data']) : '', 'quem' => (string) $i['usuario_nome'],
                'pode_excluir' => !$i['automatico'] && (usuarioEhAdmin() || (int) $i['usuario_id'] === (int) usuarioAtualId()),
            ], $ficha['itens']),
    ]);
}

$acao = (string) ($_POST['acao'] ?? '');
$alunoId = (int) ($_POST['aluno'] ?? 0);
if ($acao === 'resposta' || $acao === 'excluir') {
    $stmt = $pdo->prepare('SELECT * FROM cobranca_registros WHERE id = ?');
    $stmt->execute([(int) ($_POST['id'] ?? 0)]);
    $registro = $stmt->fetch();
    if (!$registro) {
        responderJson(['sucesso' => false, 'mensagem' => 'Registro não encontrado.']);
    }
    $alunoId = (int) $registro['aluno_id'];
}
$stmt = $pdo->prepare('SELECT nome FROM alunos WHERE id = ?');
$stmt->execute([$alunoId]);
$nomeAluno = $stmt->fetchColumn();
if (!$nomeAluno) {
    responderJson(['sucesso' => false, 'mensagem' => 'Aluno não encontrado.']);
}

if ($acao === 'registrar') {
    $descricao = trim((string) ($_POST['descricao'] ?? ''));
    if ($descricao === '') {
        responderJson(['sucesso' => false, 'mensagem' => 'Escreva como foi a cobrança.']);
    }
    $quando = str_replace('T', ' ', (string) ($_POST['contato_em'] ?? ''));
    $quando = preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $quando) ? $quando . ':00' : date('Y-m-d H:i:s');
    registrarCobranca($pdo, $alunoId, (string) ($_POST['canal'] ?? 'outro'), $descricao, [
        'contato_em' => min($quando, date('Y-m-d H:i:s')),
        'resposta' => (string) ($_POST['resposta'] ?? ''),
        'situacao' => (string) ($_POST['situacao'] ?? ''),
        'promessa_data' => $data('promessa_data'),
    ]);
    if ($proxima = $data('proxima_data')) {
        salvarAgendaCobranca($pdo, $alunoId, $proxima, (string) ($_POST['proxima_nota'] ?? ''));
    }
    registrarHistoricoAluno($pdo, $alunoId, 'cobranca', 'Contato de cobrança anotado (' . (canaisCobranca()[$_POST['canal'] ?? ''][0] ?? 'Outro') . ')' . ($proxima ? ' — próxima cobrança em ' . formatDate($proxima) : ''));
    responderJson(['sucesso' => true, 'mensagem' => 'Contato registrado.' . ($proxima ? ' Próxima cobrança em ' . formatDate($proxima) . '.' : '')]);
}

if ($acao === 'resposta') {
    $situacao = isset(situacoesCobranca()[$_POST['situacao'] ?? '']) ? $_POST['situacao'] : 'respondeu';
    $pdo->prepare('UPDATE cobranca_registros SET resposta = ?, situacao = ?, promessa_data = ? WHERE id = ?')
        ->execute([trim((string) ($_POST['resposta'] ?? '')) ?: null, $situacao, $situacao === 'prometeu' ? $data('promessa_data') : null, $registro['id']]);
    if ($proxima = $data('proxima_data')) {
        salvarAgendaCobranca($pdo, $alunoId, $proxima, (string) ($_POST['proxima_nota'] ?? ''));
    }
    responderJson(['sucesso' => true, 'mensagem' => 'Resposta anotada.']);
}

if ($acao === 'agendar') {
    $proxima = $data('data');
    salvarAgendaCobranca($pdo, $alunoId, $proxima, (string) ($_POST['nota'] ?? ''));
    $stmt = $pdo->prepare('SELECT * FROM cobranca_agenda WHERE aluno_id = ?');
    $stmt->execute([$alunoId]);
    responderJson(['sucesso' => true, 'mensagem' => $proxima ? 'Próxima cobrança marcada para ' . formatDate($proxima) . '.' : 'Agenda desmarcada.', 'agenda' => $formatarAgenda($stmt->fetch() ?: null)]);
}

if ($acao === 'excluir') {
    if ($registro['automatico'] || (!usuarioEhAdmin() && (int) $registro['usuario_id'] !== (int) usuarioAtualId())) {
        responderJson(['sucesso' => false, 'mensagem' => 'Só quem anotou (ou um administrador) pode apagar, e as mensagens enviadas pelo sistema ficam.']);
    }
    $pdo->prepare('DELETE FROM cobranca_registros WHERE id = ?')->execute([$registro['id']]);
    registrarAtividade('financeiro', 'exclusao', 'Contato de cobrança apagado: ' . $nomeAluno . ' (' . formatDateTime($registro['contato_em']) . ')');
    responderJson(['sucesso' => true, 'mensagem' => 'Registro apagado.']);
}

responderJson(['sucesso' => false, 'mensagem' => 'Ação desconhecida.']);
