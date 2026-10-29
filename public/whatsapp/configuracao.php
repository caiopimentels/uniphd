<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirPermissao('configurar_whatsapp', 'Seu perfil não pode configurar o WhatsApp.');

// Configuração do atendimento por WhatsApp: a Evolution API (endereço,
// chave, instância, QR Code para conectar o número), a IA (Groq e/ou
// Gemini, qual é a principal, chaves e modelos), o jeito de falar e um
// teste rápido da IA. Chaves ficam criptografadas; em branco = mantém.
$pdo = getDB();
$cfg = whatsappConfig($pdo);
if (empty($cfg['webhook_token'])) {
    $pdo->prepare('UPDATE whatsapp_config SET webhook_token = ? WHERE id = 1')->execute([bin2hex(random_bytes(20))]);
    $cfg = whatsappConfig($pdo);
}
$qrcode = null;
$teste = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = (string) ($_POST['acao'] ?? 'salvar');
    if ($acao === 'salvar') {
        $campos = [
            'api_url' => rtrim(trim(input('api_url')), '/') ?: null,
            'instancia' => preg_replace('/[^A-Za-z0-9_-]/', '', input('instancia')) ?: null,
            'ia_ativa' => isset($_POST['ia_ativa']) ? 1 : 0,
            'modo_teste' => isset($_POST['modo_teste']) ? 1 : 0,
            'numeros_teste' => mb_substr(trim((string) ($_POST['numeros_teste'] ?? '')), 0, 500) ?: null,
            'webhook_base' => rtrim(trim(input('webhook_base')), '/') ?: null,
            'ia_proibido' => trim((string) ($_POST['ia_proibido'] ?? '')) ?: null,
            // Lista de IAs na ordem (linhas sem modelo são ignoradas).
            'ia_modelos' => json_encode(array_values(array_filter(array_map(
                fn ($provedor, $modelo) => isset(provedoresIaWhatsapp()[$provedor]) && trim((string) $modelo) !== '' ? ['provedor' => $provedor, 'modelo' => mb_substr(trim((string) $modelo), 0, 80)] : null,
                (array) ($_POST['ia_provedor'] ?? []), (array) ($_POST['ia_modelo'] ?? [])
            ))), JSON_UNESCAPED_SLASHES),
            'ia_instrucoes' => trim((string) ($_POST['ia_instrucoes'] ?? '')) ?: null,
            'mensagem_transferencia' => mb_substr(trim(input('mensagem_transferencia')), 0, 255) ?: 'Vou chamar alguém da nossa equipe para te ajudar. Em breve te respondemos por aqui!',
            'aviso_numeros' => mb_substr(trim((string) ($_POST['aviso_numeros'] ?? '')), 0, 500) ?: null,
            'ia_continua_apos_aviso' => input('ia_continua_apos_aviso') === '1' ? 1 : 0,
        ];
        foreach (['api_chave', 'groq_chave', 'gemini_chave'] as $chave) {
            if (trim((string) ($_POST[$chave] ?? '')) !== '') {
                $campos[$chave] = criptografar(trim((string) $_POST[$chave]));
            } elseif (isset($_POST['apagar_' . $chave])) {
                $campos[$chave] = null;
            }
        }
        $sets = implode(', ', array_map(fn ($c) => "$c = ?", array_keys($campos)));
        $pdo->prepare("UPDATE whatsapp_config SET $sets WHERE id = 1")->execute(array_values($campos));
        registrarAtividade('configuracoes', 'edicao', 'Configuração do WhatsApp/IA alterada' . ($campos['ia_ativa'] ? ' (IA ligada)' : ' (IA desligada)'));
        flash('success', 'Configuração salva.');
        // Número de teste que também é de aviso: a IA não responde a ele.
        $novo = whatsappConfig($pdo);
        $repetidos = array_filter(preg_split('/[\r\n,;]+/', (string) $novo['numeros_teste']), fn ($n) => strlen(preg_replace('/\D/', '', $n)) >= 8 && ehNumeroAvisoWhatsapp($novo, preg_replace('/\D/', '', $n)));
        if ($novo['modo_teste'] && $repetidos) {
            flash('warning', 'Atenção: ' . implode(', ', array_map('trim', $repetidos)) . ' está no modo teste e também nos números de aviso. A IA nunca responde aos números de aviso — use outro número para testar.');
        }
        redirect('/whatsapp/configuracao.php');
    }
    if ($acao === 'conectar') {
        $r = conectarWhatsapp($cfg);
        flash($r['ok'] ? 'success' : 'danger', $r['mensagem']);
        $qrcode = $r['qrcode'];
    }
    if ($acao === 'testar_aviso') {
        $numeros = numerosAvisoWhatsapp($cfg);
        $ok = 0;
        foreach ($numeros as $numero) {
            $ok += enviarTextoWhatsapp($cfg, $numero, "🔔 Teste do aviso do atendimento pelo WhatsApp: quando a IA não souber responder um cliente, a mensagem chega aqui.")['ok'] ? 1 : 0;
        }
        flash($numeros && $ok === count($numeros) ? 'success' : 'danger', $numeros ? "Aviso de teste enviado para {$ok} de " . count($numeros) . ' número(s).' : 'Cadastre e salve pelo menos um número de aviso.');
        redirect('/whatsapp/configuracao.php');
    }
    if ($acao === 'testar_todas') {
        // Uma pergunta curta para cada modelo da lista (ignora as pausas;
        // o que responder sai da pausa).
        $resultadosModelos = [];
        foreach (modelosIaWhatsapp($cfg) as $item) {
            $chave = (string) ($cfg[$item['provedor'] . '_chave'] ?? '');
            $r = $chave === '' ? ['ok' => false, 'erro' => 'sem chave cadastrada', 'texto' => '']
                : chamarIaChat($item['provedor'], $chave, $item['modelo'], [['role' => 'user', 'content' => 'Responda só: ok']]);
            if ($r['ok']) {
                $pdo->prepare('DELETE FROM whatsapp_ia_pausa WHERE provedor = ? AND modelo = ?')->execute([$item['provedor'], $item['modelo']]);
            }
            $resultadosModelos[$item['provedor'] . '|' . $item['modelo']] = $r;
        }
    }
    if ($acao === 'testar') {
        $pergunta = trim((string) ($_POST['pergunta'] ?? ''));
        $teste = ['pergunta' => $pergunta, 'resposta' => $pergunta !== '' ? respostaIaWhatsapp($pdo, $cfg, 0, $pergunta) : null];
    }
}

$estado = estadoConexaoWhatsapp($cfg);
$resultadosModelos = $resultadosModelos ?? [];
$pausas = pausasIaWhatsapp($pdo);
$modelos = modelosIaWhatsapp($cfg);
$usoHoje = [];
foreach ($pdo->query('SELECT provedor, modelo, respostas, tokens, falhas FROM whatsapp_uso_ia WHERE data = CURDATE()') as $u) {
    $usoHoje[$u['provedor'] . '|' . $u['modelo']] = $u;
}
// Consumo das IAs: hoje e os últimos 7 dias.
$uso = $pdo->query('SELECT data, provedor, modelo, respostas, tokens, falhas FROM whatsapp_uso_ia WHERE data >= CURDATE() - INTERVAL 6 DAY ORDER BY data DESC, provedor, modelo')->fetchAll();
$rotulosEstado = [
    'open' => ['Conectado', 'success'], 'connecting' => ['Aguardando leitura do QR Code', 'warning'], 'close' => ['Desconectado', 'danger'],
    'sem_config' => ['Não configurado', 'secondary'], 'sem_instancia' => ['Instância ainda não criada — clique em Conectar', 'warning'], 'erro' => ['Erro ao consultar', 'danger'],
];
[$rotuloEstado, $corEstado] = $rotulosEstado[$estado['estado']] ?? [$estado['estado'], 'secondary'];
$provedores = provedoresIaWhatsapp();
$campoChave = function (string $nome, string $rotulo, ?string $atual, string $ajuda = '') {
    ?>
    <label class="form-label"><?= e($rotulo) ?></label>
    <input type="password" name="<?= e($nome) ?>" class="form-control" autocomplete="new-password" placeholder="<?= $atual ? '•••••••• guardada — deixe em branco para manter' : 'Cole a chave aqui' ?>">
    <?php if ($atual): ?><div class="form-check mt-1"><input type="checkbox" class="form-check-input" name="apagar_<?= e($nome) ?>" id="apagar_<?= e($nome) ?>"><label class="form-check-label small" for="apagar_<?= e($nome) ?>">Apagar a chave guardada</label></div><?php endif; ?>
    <?php if ($ajuda): ?><div class="form-text"><?= $ajuda ?></div><?php endif;
};

$pageTitle = 'Configurar WhatsApp';
$pageBreadcrumb = 'Secretaria · WhatsApp';
$activeMenu = 'whatsapp';
$pageAction = '<a href="/whatsapp/conversas.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Conversas</a>';
require __DIR__ . '/../../includes/layout_header.php';
?>
<form method="post" style="max-width: 900px;">
    <input type="hidden" name="acao" value="salvar">

    <div class="secao">
        <div class="secao-titulo"><i class="bi bi-whatsapp"></i> Conexão com o WhatsApp (Evolution API)</div>
        <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
            <span class="badge text-bg-<?= $corEstado ?>"><?= e($rotuloEstado) ?></span>
            <?php if ($estado['erro']): ?><span class="small text-danger"><?= e($estado['erro']) ?></span><?php endif; ?>
        </div>
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Endereço da Evolution API</label>
                <input type="url" name="api_url" class="form-control" placeholder="https://whatsapp.seudominio.com.br" value="<?= e((string) $cfg['api_url']) ?>">
            </div>
            <div class="col-md-6"><?php $campoChave('api_chave', 'Chave da API (AUTHENTICATION_API_KEY)', $cfg['api_chave']); ?></div>
            <div class="col-md-6">
                <label class="form-label">Nome da instância</label>
                <input type="text" name="instancia" class="form-control" placeholder="uniphd" value="<?= e((string) $cfg['instancia']) ?>">
                <div class="form-text">Um nome qualquer, sem espaço (ex.: uniphd).</div>
            </div>
            <div class="col-md-6">
                <label class="form-label">Endereço do webhook (preenchido sozinho ao conectar)</label>
                <input type="text" class="form-control form-control-sm font-monospace" readonly value="<?= e(urlWebhookWhatsapp($cfg)) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">Endereço do sistema para a Evolution API <span class="text-muted small">(avançado)</span></label>
                <input type="text" name="webhook_base" class="form-control" placeholder="Em branco: <?= e(rtrim(urlAbsoluta('/'), '/')) ?>" value="<?= e((string) $cfg['webhook_base']) ?>">
                <div class="form-text">Só para teste no computador com Docker: <code>http://host.docker.internal:8000</code>. No servidor, deixe em branco.</div>
            </div>
        </div>
    </div>

    <div class="secao">
        <div class="secao-titulo"><i class="bi bi-robot"></i> Inteligência artificial</div>
        <div class="form-check form-switch mb-3">
            <input type="checkbox" class="form-check-input" role="switch" name="ia_ativa" id="ia_ativa" <?= $cfg['ia_ativa'] ? 'checked' : '' ?>>
            <label class="form-check-label" for="ia_ativa"><strong>IA respondendo</strong> — desligada, as mensagens chegam e só a equipe responde.</label>
        </div>
        <div class="border rounded p-3 mb-3" style="background: #fffbea;">
            <div class="form-check form-switch">
                <input type="checkbox" class="form-check-input" role="switch" name="modo_teste" id="modo_teste" <?= $cfg['modo_teste'] ? 'checked' : '' ?>>
                <label class="form-check-label" for="modo_teste"><strong>Modo teste</strong> — a IA só responde aos números abaixo. Os outros contatos continuam chegando na tela de conversas, sem resposta automática.</label>
            </div>
            <textarea name="numeros_teste" class="form-control mt-2" rows="2" placeholder="Um número por linha, com DDD. Ex.: (77) 98111-8760"><?= e((string) $cfg['numeros_teste']) ?></textarea>
        </div>
        <div class="row g-3">
            <div class="col-md-6"><?php $campoChave('groq_chave', 'Chave da Groq', $cfg['groq_chave'], 'Grátis em <a href="https://console.groq.com/keys" target="_blank" rel="noopener">console.groq.com/keys</a>. Também transcreve os áudios.'); ?></div>
            <div class="col-md-6"><?php $campoChave('gemini_chave', 'Chave do Google Gemini', $cfg['gemini_chave'], 'Grátis em <a href="https://aistudio.google.com/apikey" target="_blank" rel="noopener">aistudio.google.com/apikey</a>.'); ?></div>
            <div class="col-12">
                <label class="form-label">IAs, na ordem em que são usadas</label>
                <div class="form-text mt-0 mb-2">Cada modelo tem o seu próprio limite grátis. Responde o 1º; quando ele bate o limite, o sistema pula para o próximo (até amanhã, se for o limite do dia; por 1 minuto, se for o do minuto). Coloque quantas quiser e use as setas para mudar a ordem.</div>
                <div class="card"><div class="table-responsive">
                    <table class="table table-sm mb-0 align-middle">
                        <thead><tr><th style="width: 40px;">#</th><th style="width: 160px;">Empresa</th><th style="min-width: 250px;">Modelo</th><th>Hoje</th><th>Situação</th><th style="width: 110px;"></th></tr></thead>
                        <tbody id="lista-ias">
                        <?php foreach ($modelos ?: [['provedor' => 'groq', 'modelo' => '']] as $m): $k = $m['provedor'] . '|' . $m['modelo']; ?>
                            <tr class="linha-ia">
                                <td class="text-muted numero-ia"></td>
                                <td><select name="ia_provedor[]" class="form-select form-select-sm">
                                    <?php foreach ($provedores as $chave => $p): ?><option value="<?= $chave ?>" <?= $m['provedor'] === $chave ? 'selected' : '' ?>><?= e($p['nome']) ?></option><?php endforeach; ?>
                                </select></td>
                                <td><input type="text" name="ia_modelo[]" class="form-control form-control-sm font-monospace" value="<?= e($m['modelo']) ?>" placeholder="nome do modelo"></td>
                                <td class="small text-nowrap"><?= $m['modelo'] !== '' && isset($usoHoje[$k]) ? (int) $usoHoje[$k]['respostas'] . ' resp. · ' . number_format((int) $usoHoje[$k]['tokens'], 0, ',', '.') . ' tokens' : '<span class="text-muted">—</span>' ?></td>
                                <td class="small">
                                    <?php if ($m['modelo'] === ''): ?>
                                    <?php elseif (isset($resultadosModelos[$k])): ?>
                                        <?= $resultadosModelos[$k]['ok'] ? '<span class="badge text-bg-success">Respondeu</span>' : '<span class="badge text-bg-danger">Falhou</span> <span class="text-danger">' . e(mb_strimwidth($resultadosModelos[$k]['erro'], 0, 120, '…')) . '</span>' ?>
                                    <?php elseif (isset($pausas[$k])): ?>
                                        <span class="badge text-bg-warning" title="<?= e((string) $pausas[$k]['motivo']) ?>">Pausado até <?= date('d/m H:i', strtotime($pausas[$k]['ate'])) ?></span>
                                    <?php elseif (empty($cfg[$m['provedor'] . '_chave'])): ?>
                                        <span class="badge text-bg-secondary">Sem chave</span>
                                    <?php else: ?>
                                        <span class="badge text-bg-light border">Liberado</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end text-nowrap">
                                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="moverIa(this, -1)" title="Subir"><i class="bi bi-arrow-up"></i></button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="moverIa(this, 1)" title="Descer"><i class="bi bi-arrow-down"></i></button>
                                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="removerIa(this)" title="Remover"><i class="bi bi-x-lg"></i></button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div></div>
                <button type="button" class="btn btn-sm btn-outline-secondary mt-2" onclick="adicionarIa()"><i class="bi bi-plus-lg"></i> Adicionar IA</button>
                <script>
                // Lista de IAs: numera, adiciona (copiando uma linha vazia), remove e muda a ordem.
                function numerarIas() {
                    document.querySelectorAll('#lista-ias .numero-ia').forEach((td, i) => { td.textContent = (i + 1) + 'º'; });
                }
                function adicionarIa() {
                    const corpo = document.getElementById('lista-ias');
                    const nova = corpo.querySelector('.linha-ia').cloneNode(true);
                    nova.querySelector('input').value = '';
                    nova.querySelectorAll('td')[3].innerHTML = '<span class="text-muted">—</span>';
                    nova.querySelectorAll('td')[4].innerHTML = '<span class="small text-muted">nova — salve e use "Testar todas"</span>';
                    corpo.appendChild(nova);
                    numerarIas();
                    nova.querySelector('input').focus();
                }
                function removerIa(botao) {
                    const corpo = document.getElementById('lista-ias');
                    if (corpo.querySelectorAll('.linha-ia').length === 1) {
                        botao.closest('tr').querySelector('input').value = '';
                    } else {
                        botao.closest('tr').remove();
                    }
                    numerarIas();
                }
                function moverIa(botao, direcao) {
                    const linha = botao.closest('tr');
                    const vizinha = direcao < 0 ? linha.previousElementSibling : linha.nextElementSibling;
                    if (vizinha) {
                        direcao < 0 ? vizinha.before(linha) : vizinha.after(linha);
                        numerarIas();
                    }
                }
                numerarIas();
                </script>
                <div class="form-text">Sugestão de modelos grátis: Groq — <code>openai/gpt-oss-120b</code>, <code>qwen/qwen3.8-27b</code>, <code>openai/gpt-oss-20b</code>; Google — <code>gemini-flash-latest</code> e <code>gemini-flash-lite-latest</code> (sempre o mais novo). As empresas aposentam modelos com o tempo; use "Testar todas" depois de salvar.</div>
            </div>
            <div class="col-12">
                <label class="form-label">O que a IA sabe e pode responder <span class="text-muted small">(jeito de falar, perguntas frequentes, informações que não estão no sistema)</span></label>
                <textarea name="ia_instrucoes" class="form-control" rows="7" placeholder="Ex.: Nosso nome é UniPHD. Chame a pessoa pelo primeiro nome. Aceitamos Pix, boleto e cartão em até 12x na secretaria. Atendimento presencial de segunda a sexta, 8h às 18h. As aulas práticas usam modelos reais..."><?= e((string) $cfg['ia_instrucoes']) ?></textarea>
                <div class="form-text">Os cursos, turmas abertas, valores, vagas, conteúdo e a descrição de cada turma a IA já pega sozinha do sistema (veja em "O que a IA sabe hoje", abaixo). Aqui vai o resto: jeito de falar, horários, formas de pagamento, perguntas que sempre aparecem.</div>
            </div>
            <div class="col-12">
                <label class="form-label">O que a IA NÃO deve responder <span class="text-muted small">(ela passa para a equipe)</span></label>
                <textarea name="ia_proibido" class="form-control" rows="4" placeholder="Ex.: descontos e negociação de valores; se o curso tem MEC ou validade de pós-graduação; datas de turmas que ainda não estão no sistema; perguntas sobre professores"><?= e((string) $cfg['ia_proibido']) ?></textarea>
                <div class="form-text">Pagamento, boleto, certificado, documentos, faltas e assuntos da matrícula ela já passa sempre para a equipe.</div>
            </div>
        </div>
    </div>

    <div class="secao">
        <div class="secao-titulo"><i class="bi bi-bell"></i> Quando a IA não souber responder</div>
        <p class="small text-muted">Acontece quando a pessoa pede um atendente, pergunta algo que a IA não deve responder (pagamento, matrícula, os assuntos da lista acima) ou que não está nas informações dela.</p>
        <div class="row g-3">
            <div class="col-12">
                <label class="form-label">Mensagem que a IA manda para a pessoa</label>
                <input type="text" name="mensagem_transferencia" class="form-control" maxlength="255" value="<?= e($cfg['mensagem_transferencia']) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">Avisar a equipe por WhatsApp nestes números</label>
                <textarea name="aviso_numeros" class="form-control" rows="3" placeholder="Um por linha, com DDD. Ex.: (77) 98100-4911"><?= e((string) $cfg['aviso_numeros']) ?></textarea>
                <div class="form-text">O WhatsApp da escola manda: "🔔 Cliente Fulano (77) 98153-5395 precisa de ajuda da equipe", com a última mensagem dele. No máximo um aviso por cliente a cada 30 minutos. Use números da equipe, diferentes do da escola. A IA não conversa com esses números.</div>
            </div>
            <div class="col-md-6">
                <label class="form-label">Depois de chamar a equipe, a IA…</label>
                <div class="form-check">
                    <input type="radio" class="form-check-input" name="ia_continua_apos_aviso" id="continua_0" value="0" <?= empty($cfg['ia_continua_apos_aviso']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="continua_0"><strong>para de responder</strong> naquela conversa até alguém da equipe devolver para ela</label>
                </div>
                <div class="form-check mt-2">
                    <input type="radio" class="form-check-input" name="ia_continua_apos_aviso" id="continua_1" value="1" <?= !empty($cfg['ia_continua_apos_aviso']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="continua_1"><strong>continua respondendo</strong> as outras dúvidas da pessoa, até alguém da equipe responder (aí ela sai sozinha)</label>
                </div>
                <div class="form-text">"Continua" é melhor quando ninguém fica olhando o sistema o tempo todo: a pessoa não fica sem resposta nas dúvidas simples.</div>
            </div>
        </div>
    </div>
    <button type="submit" class="btn btn-brand"><i class="bi bi-check-lg"></i> Salvar</button>
</form>

<div class="secao mt-4" style="max-width: 900px;">
    <div class="secao-titulo"><i class="bi bi-bar-chart"></i> Consumo das IAs (últimos 7 dias)</div>
    <?php if (!$uso): ?>
        <p class="text-muted small mb-0">Nenhuma resposta da IA ainda.</p>
    <?php else: ?>
        <div class="card"><div class="table-responsive">
            <table class="table table-sm mb-0 align-middle">
                <thead><tr><th>Dia</th><th>IA</th><th>Modelo</th><th class="text-end">Respostas</th><th class="text-end">Tokens</th><th class="text-end">Média por resposta</th><th class="text-end">Falhas</th></tr></thead>
                <tbody>
                <?php foreach ($uso as $u): ?>
                    <tr class="<?= $u['data'] === date('Y-m-d') ? 'fw-semibold' : '' ?>">
                        <td><?= $u['data'] === date('Y-m-d') ? 'Hoje' : formatDate($u['data']) ?></td>
                        <td><?= e($provedores[$u['provedor']]['nome'] ?? $u['provedor']) ?></td>
                        <td class="font-monospace small"><?= e($u['modelo']) ?></td>
                        <td class="text-end"><?= (int) $u['respostas'] ?></td>
                        <td class="text-end"><?= number_format((int) $u['tokens'], 0, ',', '.') ?></td>
                        <td class="text-end"><?= $u['respostas'] ? number_format((int) $u['tokens'] / (int) $u['respostas'], 0, ',', '.') : '-' ?></td>
                        <td class="text-end <?= $u['falhas'] ? 'text-danger' : '' ?>"><?= (int) $u['falhas'] ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div></div>
    <?php endif; ?>
    <div class="form-text">Falhas costumam ser o limite do plano gratuito: nessa hora a outra IA responde. Os limites da sua conta: Groq em console.groq.com › Settings › Limits; Gemini em aistudio.google.com.</div>
</div>

<div class="row g-4 mt-1" style="max-width: 900px;">
    <div class="col-md-6">
        <div class="secao">
            <div class="secao-titulo"><i class="bi bi-qr-code"></i> Conectar o número</div>
            <p class="small text-muted">Salve o endereço, a chave e a instância antes. O botão cria a instância (se precisar), liga o webhook e mostra o QR Code.</p>
            <form method="post"><input type="hidden" name="acao" value="conectar">
                <button class="btn btn-outline-secondary" <?= whatsappConfigurado($cfg) ? '' : 'disabled' ?>><i class="bi bi-plug"></i> Conectar / mostrar QR Code</button>
            </form>
            <?php if ($qrcode): ?><img src="<?= e($qrcode) ?>" alt="QR Code" class="mt-3 border rounded" style="width: 260px; max-width: 100%;"><div class="small text-muted mt-1">O QR Code vale por pouco tempo; se expirar, clique de novo.</div><?php endif; ?>
        </div>
    </div>
    <div class="col-md-6">
        <div class="secao">
            <div class="secao-titulo"><i class="bi bi-bell"></i> Testar o aviso à equipe</div>
            <p class="small text-muted">Manda uma mensagem de teste para os números de aviso (salve antes).</p>
            <form method="post"><input type="hidden" name="acao" value="testar_aviso"><button class="btn btn-outline-secondary" <?= numerosAvisoWhatsapp($cfg) && whatsappConfigurado($cfg) ? '' : 'disabled' ?>><i class="bi bi-send"></i> Testar aviso</button></form>
        </div>
        <div class="secao">
            <div class="secao-titulo"><i class="bi bi-check2-all"></i> Testar todas as IAs da lista</div>
            <p class="small text-muted">Manda uma pergunta curtinha para cada modelo e mostra, na tabela acima, quem respondeu.</p>
            <form method="post"><input type="hidden" name="acao" value="testar_todas"><button class="btn btn-outline-secondary"><i class="bi bi-check2-all"></i> Testar todas</button></form>
        </div>
        <div class="secao">
            <div class="secao-titulo"><i class="bi bi-chat-dots"></i> Testar a IA</div>
            <form method="post"><input type="hidden" name="acao" value="testar">
                <div class="input-group">
                    <input type="text" name="pergunta" class="form-control" placeholder="Ex.: quanto custa o curso de toxina?" value="<?= e($teste['pergunta'] ?? '') ?>" required>
                    <button class="btn btn-outline-secondary">Perguntar</button>
                </div>
            </form>
            <?php if ($teste && $teste['resposta']): $r = $teste['resposta']; ?>
                <div class="mt-3 p-3 rounded" style="background: #eef2ff; white-space: pre-line;"><?php if ($r['ok']): ?><?= e($r['texto']) ?><?php if ($r['transferir']): ?>
<span class="badge text-bg-warning">passaria para a equipe</span><?php endif; ?>
<span class="small text-muted d-block mt-1">respondido por <?= e($provedores[$r['provedor']]['nome'] . ' · ' . $r['modelo']) ?></span><?php else: ?><span class="text-danger"><?= e($r['erro']) ?></span><?php endif; ?></div>
            <?php endif; ?>
        </div>
    </div>
</div>
<div class="secao mt-4" style="max-width: 900px;">
    <div class="secao-titulo"><i class="bi bi-eye"></i> O que a IA sabe hoje</div>
    <p class="small text-muted">É exatamente este texto que a IA lê antes de cada resposta: as regras fixas, os cursos e turmas abertas no portal (com a descrição de cada turma, ou a do curso quando a turma não tem) e o que você escreveu acima. Para mudar o que ela sabe de um curso, edite a turma (descrição do portal) ou o curso.</p>
    <details>
        <summary class="btn btn-sm btn-outline-secondary">Mostrar o texto</summary>
        <pre class="mt-2 p-3 border rounded small" style="white-space: pre-wrap; max-height: 500px; overflow: auto; background: rgba(0,0,0,.02);"><?= e(promptSistemaWhatsapp($pdo, $cfg)) ?></pre>
    </details>
</div>
<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
