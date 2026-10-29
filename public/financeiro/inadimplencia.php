<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirInadimplencia();

$pdo = getDB();
$config = buscarConfiguracoes($pdo);

$turmaId = (int) ($_GET['turma_id'] ?? 0);
$cursoId = (int) ($_GET['curso_id'] ?? 0);

$inadimplentes = listarInadimplentes($pdo, ['turma_id' => $turmaId, 'curso_id' => $cursoId]);
$totalVencido = array_sum(array_column($inadimplentes, 'valor_vencido'));

$cursos = $pdo->query('SELECT id, nome FROM cursos ORDER BY nome')->fetchAll();
$turmas = $pdo->query(
    'SELECT t.id, t.nome, c.nome AS curso_nome FROM turmas t JOIN cursos c ON c.id = t.curso_id ORDER BY c.nome, t.nome'
)->fetchAll();

$ultimas = ultimasCobrancas($pdo, array_column($inadimplentes, 'aluno_id'));
$agenda = agendaCobrancas($pdo, array_column($inadimplentes, 'aluno_id'));

// Agenda: "Cobrar no dia" (hoje inclui as marcadas para dias que já
// passaram), "Agendados" (para depois) e "Sem agenda".
$hoje = date('Y-m-d');
$ver = in_array($_GET['ver'] ?? '', ['dia', 'agendados', 'sem'], true) ? $_GET['ver'] : 'todos';
$dia = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['dia'] ?? '')) ? $_GET['dia'] : $hoje;
$naAgendaDoDia = fn (array $i) => isset($agenda[(int) $i['aluno_id']]) && ($dia === $hoje ? $agenda[(int) $i['aluno_id']]['data'] <= $hoje : $agenda[(int) $i['aluno_id']]['data'] === $dia);
$contagem = [
    'todos' => count($inadimplentes),
    'dia' => count(array_filter($inadimplentes, $naAgendaDoDia)),
    'agendados' => count(array_filter($inadimplentes, fn ($i) => isset($agenda[(int) $i['aluno_id']]) && $agenda[(int) $i['aluno_id']]['data'] > $hoje)),
    'sem' => count(array_filter($inadimplentes, fn ($i) => !isset($agenda[(int) $i['aluno_id']]))),
];
$listados = match ($ver) {
    'dia' => array_filter($inadimplentes, $naAgendaDoDia),
    'agendados' => array_filter($inadimplentes, fn ($i) => isset($agenda[(int) $i['aluno_id']]) && $agenda[(int) $i['aluno_id']]['data'] > $hoje),
    'sem' => array_filter($inadimplentes, fn ($i) => !isset($agenda[(int) $i['aluno_id']])),
    default => $inadimplentes,
};
if ($ver === 'dia' || $ver === 'agendados') {
    usort($listados, fn ($a, $b) => strcmp($agenda[(int) $a['aluno_id']]['data'], $agenda[(int) $b['aluno_id']]['data']));
}
$linkVer = fn (array $mudar) => '?' . http_build_query(array_filter(array_merge(['curso_id' => $cursoId ?: null, 'turma_id' => $turmaId ?: null, 'ver' => $ver, 'dia' => $dia !== $hoje ? $dia : null], $mudar)));
$canais = canaisCobranca();
$situacoes = situacoesCobranca();
$whatsappPronto = whatsappConfigurado(whatsappConfig($pdo));
$minhaCaixa = caixaUsuario($pdo, (int) usuarioAtualId());
$modelos = modelosCobranca();
// "há 2 dias", "hoje"...
$haQuanto = function (string $quando): string {
    $dias = (int) floor((strtotime(date('Y-m-d')) - strtotime(substr($quando, 0, 10))) / 86400);
    return match (true) { $dias <= 0 => 'hoje, ' . date('H:i', strtotime($quando)), $dias === 1 => 'ontem', default => 'há ' . $dias . ' dias' };
};

$pageTitle = 'Inadimplência';
$pageBreadcrumb = 'Financeiro';
$activeMenu = 'inadimplencia';
require __DIR__ . '/../../includes/layout_header.php';
?>

<div class="filtros">
    <form method="get" class="d-flex flex-wrap gap-2 align-items-center">
        <select name="curso_id" class="form-select filtro-controle" style="width:220px" onchange="this.form.submit()">
            <option value="">Todos os cursos</option>
            <?php foreach ($cursos as $c): ?>
                <option value="<?= (int) $c['id'] ?>" <?= $cursoId === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['nome']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="turma_id" class="form-select filtro-controle" style="width:260px" onchange="this.form.submit()">
            <option value="">Todas as turmas</option>
            <?php foreach ($turmas as $t): ?>
                <option value="<?= (int) $t['id'] ?>" <?= $turmaId === (int) $t['id'] ? 'selected' : '' ?>><?= e($t['curso_nome']) ?> · <?= e($t['nome']) ?></option>
            <?php endforeach; ?>
        </select>
        <?php if ($turmaId || $cursoId): ?>
            <a href="/financeiro/inadimplencia.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x-lg"></i> Limpar</a>
        <?php endif; ?>
    </form>
    <span class="ms-auto filtro-chip">Total em atraso: <?= formatMoney($totalVencido) ?></span>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-md-4">
        <div class="card h-100"><div class="card-body">
            <div class="kpi-rotulo">Alunos em atraso</div>
            <div class="kpi-valor <?= $inadimplentes ? 'estoque-baixo' : '' ?>"><?= count($inadimplentes) ?></div>
        </div></div>
    </div>
    <div class="col-6 col-md-4">
        <div class="card h-100"><div class="card-body">
            <div class="kpi-rotulo">Valor em atraso</div>
            <div class="kpi-valor <?= $totalVencido > 0 ? 'estoque-baixo' : '' ?>"><?= formatMoney($totalVencido) ?></div>
        </div></div>
    </div>
    <div class="col-12 col-md-4">
        <div class="card h-100"><div class="card-body">
            <div class="kpi-rotulo">Parcelas em atraso</div>
            <div class="kpi-valor"><?= array_sum(array_column($inadimplentes, 'parcelas')) ?></div>
        </div></div>
    </div>
</div>

<div class="d-flex flex-wrap align-items-center gap-2 mb-2">
    <ul class="nav nav-pills agenda-abas">
        <li class="nav-item"><a class="nav-link <?= $ver === 'todos' ? 'active' : '' ?>" href="<?= e($linkVer(['ver' => null, 'dia' => null])) ?>">Todos <span class="badge text-bg-light"><?= $contagem['todos'] ?></span></a></li>
        <li class="nav-item"><a class="nav-link <?= $ver === 'dia' ? 'active' : '' ?>" href="<?= e($linkVer(['ver' => 'dia'])) ?>"><i class="bi bi-calendar-check"></i> <?= $dia === $hoje ? 'Cobrar hoje' : 'Cobrar em ' . formatDate($dia) ?> <span class="badge <?= $contagem['dia'] ? 'text-bg-danger' : 'text-bg-light' ?>"><?= $contagem['dia'] ?></span></a></li>
        <li class="nav-item"><a class="nav-link <?= $ver === 'agendados' ? 'active' : '' ?>" href="<?= e($linkVer(['ver' => 'agendados', 'dia' => null])) ?>"><i class="bi bi-calendar-event"></i> Agendados <span class="badge text-bg-light"><?= $contagem['agendados'] ?></span></a></li>
        <li class="nav-item"><a class="nav-link <?= $ver === 'sem' ? 'active' : '' ?>" href="<?= e($linkVer(['ver' => 'sem', 'dia' => null])) ?>"><i class="bi bi-calendar-x"></i> Sem agenda <span class="badge text-bg-light"><?= $contagem['sem'] ?></span></a></li>
    </ul>
    <?php if ($ver === 'dia'): ?>
        <form method="get" class="d-flex align-items-center gap-1 ms-auto">
            <?php foreach (['curso_id' => $cursoId, 'turma_id' => $turmaId] as $k => $v): if ($v): ?><input type="hidden" name="<?= $k ?>" value="<?= (int) $v ?>"><?php endif; endforeach; ?>
            <input type="hidden" name="ver" value="dia">
            <a class="btn btn-sm btn-outline-secondary" href="<?= e($linkVer(['dia' => date('Y-m-d', strtotime($dia . ' -1 day'))])) ?>" title="Dia anterior"><i class="bi bi-chevron-left"></i></a>
            <input type="date" name="dia" class="form-control form-control-sm" style="width: 150px" value="<?= e($dia) ?>" onchange="this.form.submit()">
            <a class="btn btn-sm btn-outline-secondary" href="<?= e($linkVer(['dia' => date('Y-m-d', strtotime($dia . ' +1 day'))])) ?>" title="Próximo dia"><i class="bi bi-chevron-right"></i></a>
            <?php if ($dia !== $hoje): ?><a class="btn btn-sm btn-link" href="<?= e($linkVer(['dia' => null])) ?>">Hoje</a><?php endif; ?>
        </form>
    <?php endif; ?>
</div>

<div class="card">
    <div class="d-flex flex-wrap align-items-center gap-2 px-3 py-2 border-bottom" id="barra-lote" style="display: none !important;">
        <strong id="qtd-marcados"></strong>
        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="abrirLote('email')"><i class="bi bi-envelope"></i> Cobrar por e-mail</button>
        <button type="button" class="btn btn-sm btn-outline-success" onclick="abrirLote('whatsapp')" <?= $whatsappPronto ? '' : 'disabled title="WhatsApp do sistema não configurado"' ?>><i class="bi bi-whatsapp"></i> Cobrar por WhatsApp</button>
        <button type="button" class="btn btn-sm btn-link text-secondary ms-auto" onclick="marcarTodos(false)">Desmarcar</button>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead><tr>
                <th style="width: 34px"><?php if ($listados): ?><input type="checkbox" class="form-check-input" title="Marcar todos" onchange="marcarTodos(this.checked)"><?php endif; ?></th>
                <th>Aluno</th><th>Turma(s)</th><th class="text-center">Parcelas</th><th class="text-end">Dias</th><th class="text-end">Valor</th><th>Último contato</th><th>Próxima cobrança</th><th class="text-end">Cobrar</th>
            </tr></thead>
            <tbody>
            <?php if (!$listados): ?>
                <tr><td colspan="9" class="text-center text-muted py-4"><?= !$inadimplentes ? '<i class="bi bi-emoji-smile"></i> Nenhum aluno em atraso.' : ($ver === 'dia' ? '<i class="bi bi-calendar-check"></i> Ninguém para cobrar ' . ($dia === $hoje ? 'hoje' : 'neste dia') . '.' : 'Nenhum aluno aqui.') ?></td></tr>
            <?php endif; ?>
            <?php foreach ($listados as $i): $ultima = $ultimas[(int) $i['aluno_id']] ?? null; $ag = $agenda[(int) $i['aluno_id']] ?? null; ?>
                <tr class="cursor-pointer" onclick="abrirFicha(<?= (int) $i['aluno_id'] ?>)" title="Abrir a ficha de cobrança">
                    <td onclick="event.stopPropagation();"><input type="checkbox" class="form-check-input marca-aluno" value="<?= (int) $i['aluno_id'] ?>" onchange="atualizarLote()"></td>
                    <td><?= e($i['nome']) ?><div class="small text-muted"><?= e(telefoneFormatado($i['telefone']) ?: 'sem telefone') ?><?= $i['email'] ? ' · ' . e($i['email']) : '' ?></div></td>
                    <td class="small"><?= e($i['turmas'] ?: 'Lançamento avulso') ?></td>
                    <td class="text-center"><?= (int) $i['parcelas'] ?></td>
                    <td class="text-end <?= (int) $i['dias_atraso'] > 30 ? 'estoque-baixo' : '' ?>" title="Desde <?= formatDate($i['vencimento_mais_antigo']) ?>"><?= (int) $i['dias_atraso'] ?></td>
                    <td class="text-end fw-semibold"><?= formatMoney($i['valor_vencido']) ?></td>
                    <td class="small" id="ultima-<?= (int) $i['aluno_id'] ?>"><?php if ($ultima): ?><span title="<?= e($ultima['descricao']) ?>"><i class="bi <?= $canais[$ultima['canal']][1] ?? 'bi-chat-dots' ?> text-<?= $canais[$ultima['canal']][2] ?? 'secondary' ?>"></i> <?= e($haQuanto($ultima['quando'])) ?></span>
                        <?php if ($ultima['situacao'] && $ultima['situacao'] !== 'sem_resposta'): ?><div><span class="badge text-bg-<?= $situacoes[$ultima['situacao']][1] ?>"><?= e($situacoes[$ultima['situacao']][0]) ?></span></div><?php endif; ?>
                        <?php else: ?><span class="text-muted">nunca</span><?php endif; ?></td>
                    <td class="small"><?php if ($ag): ?><span class="<?= $ag['data'] < $hoje ? 'text-danger fw-semibold' : ($ag['data'] === $hoje ? 'fw-semibold' : '') ?>"><i class="bi bi-calendar-event"></i> <?= $ag['data'] === $hoje ? 'Hoje' : formatDate($ag['data']) ?></span><?= $ag['data'] < $hoje ? ' <span class="badge text-bg-danger">atrasada</span>' : '' ?><?php if ($ag['nota']): ?><div class="text-muted text-truncate" style="max-width: 180px" title="<?= e($ag['nota']) ?>"><?= e($ag['nota']) ?></div><?php endif; ?>
                        <?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
                    <td class="text-end text-nowrap" onclick="event.stopPropagation();">
                        <button type="button" class="btn btn-sm btn-outline-success" onclick="cobrar(<?= (int) $i['aluno_id'] ?>, 'whatsapp')" title="Cobrar por WhatsApp" <?= $i['telefone'] ? '' : 'disabled' ?>><i class="bi bi-whatsapp"></i></button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="cobrar(<?= (int) $i['aluno_id'] ?>, 'email')" title="Cobrar por e-mail" <?= $i['email'] ? '' : 'disabled' ?>><i class="bi bi-envelope"></i></button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <?php if ($listados): ?>
            <tfoot><tr><th colspan="5" class="text-end"><?= $ver === 'todos' ? 'Total em atraso' : 'Total desta lista' ?></th><th class="text-end"><?= formatMoney(array_sum(array_column($listados, 'valor_vencido'))) ?></th><th colspan="3"></th></tr></tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<!-- Cobrar um aluno -->
<div class="modal fade" id="modal-cobrar" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header"><div><h5 class="modal-title mb-0" id="cobrar-titulo"></h5><div class="small text-muted" id="cobrar-destino"></div></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div id="cobrar-whats">
                    <div class="alert alert-warning small py-2 d-none" id="whats-aviso"></div>
                    <label class="form-label">Mensagem</label>
                    <textarea class="form-control" id="whats-texto" rows="9"></textarea>
                    <div class="form-text">Sai pelo número do sistema e fica na conversa dele em Secretaria › WhatsApp.</div>
                </div>
                <div id="cobrar-email">
                    <div class="mb-2"><label class="form-label">Assunto</label><input type="text" class="form-control" id="email-assunto" maxlength="250"></div>
                    <label class="form-label">Mensagem</label>
                    <div id="email-editor" style="min-height: 220px; background: #fff;"></div>
                    <div class="mt-2 small">Sai de:
                        <label class="form-check form-check-inline ms-2"><input class="form-check-input" type="radio" name="remetente" value="usuario" id="rem-usuario"> <span class="form-check-label" id="rem-usuario-rotulo">minha caixa</span></label>
                        <label class="form-check form-check-inline"><input class="form-check-input" type="radio" name="remetente" value="sistema" id="rem-sistema"> <span class="form-check-label">e-mail do sistema</span></label>
                    </div>
                </div>
                <div class="alert mt-3 mb-0 d-none" id="cobrar-resultado"></div>
            </div>
            <div class="modal-footer">
                <a class="btn btn-link text-secondary me-auto d-none" id="whats-web" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i> Abrir no WhatsApp Web</a>
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Fechar</button>
                <button type="button" class="btn btn-brand" id="cobrar-enviar" onclick="enviarCobranca()"><i class="bi bi-send"></i> Enviar</button>
            </div>
        </div>
    </div>
</div>

<!-- Cobrar vários -->
<div class="modal fade" id="modal-lote" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title" id="lote-titulo"></h5><button type="button" class="btn-close" data-bs-dismiss="modal" id="lote-fechar"></button></div>
            <div class="modal-body">
                <div class="small mb-2">Cada aluno recebe com os dados dele. Tags: <?php foreach (['{primeiro_nome}', '{parcelas_em_atraso}', '{valor_em_atraso}', '{vencimento_mais_antigo}', '{link_pagamento}', '{empresa}'] as $tag): ?><code class="me-1"><?= $tag ?></code><?php endforeach; ?></div>
                <div id="lote-email">
                    <div class="mb-2"><label class="form-label">Assunto</label><input type="text" class="form-control" id="lote-assunto" value="<?= e($modelos['assunto']) ?>"></div>
                    <label class="form-label">Mensagem</label>
                    <div id="lote-editor" style="min-height: 200px; background: #fff;"><?= $modelos['email'] ?></div>
                    <div class="mt-2 small">Sai de:
                        <label class="form-check form-check-inline ms-2"><input class="form-check-input" type="radio" name="lote-remetente" value="usuario" <?= $minhaCaixa['configurada'] ? 'checked' : 'disabled' ?>> <span class="form-check-label"><?= $minhaCaixa['configurada'] ? e($minhaCaixa['email']) : 'minha caixa (não configurada)' ?></span></label>
                        <label class="form-check form-check-inline"><input class="form-check-input" type="radio" name="lote-remetente" value="sistema" <?= $minhaCaixa['configurada'] ? '' : 'checked' ?>> <span class="form-check-label">e-mail do sistema</span></label>
                    </div>
                    <div class="form-text">Vão para a fila do disparo em massa e saem aos poucos (limite por hora). Dá para acompanhar em E-mail › Disparo em massa.</div>
                </div>
                <div id="lote-whats">
                    <div class="alert alert-warning small py-2"><i class="bi bi-exclamation-triangle"></i> WhatsApp não oficial: muitas mensagens seguidas podem bloquear o número. O sistema manda <strong>uma a cada 20 segundos</strong> e no máximo 30 por vez — deixe esta janela aberta até terminar.</div>
                    <label class="form-label">Mensagem</label>
                    <textarea class="form-control" id="lote-texto" rows="8"><?= e($modelos['whatsapp']) ?></textarea>
                    <div class="mt-3 d-none" id="lote-progresso"><div class="progress mb-2" style="height: 8px"><div class="progress-bar bg-success" id="lote-barra"></div></div><div class="small" id="lote-log" style="max-height: 180px; overflow-y: auto"></div></div>
                </div>
                <div class="alert mt-3 mb-0 d-none" id="lote-resultado"></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" id="lote-cancelar">Fechar</button><button type="button" class="btn btn-brand" id="lote-enviar" onclick="enviarLote()"><i class="bi bi-send"></i> Enviar</button></div>
        </div>
    </div>
</div>

<!-- Ficha de cobrança do aluno -->
<div class="offcanvas offcanvas-end ficha-cobranca" tabindex="-1" id="ficha">
    <div class="offcanvas-header border-bottom align-items-start">
        <div class="flex-grow-1 me-2">
            <h5 class="offcanvas-title mb-0" id="ficha-nome">Carregando...</h5>
            <div class="small text-muted" id="ficha-contato"></div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
    </div>
    <div class="offcanvas-body p-0">
        <div id="ficha-msg" class="d-none"></div>
        <div class="p-3 border-bottom">
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <div class="me-auto"><div class="kpi-rotulo">Em atraso</div><div class="fw-bold fs-5 text-danger" id="ficha-total"></div></div>
                <button type="button" class="btn btn-sm btn-outline-success" id="ficha-whats"><i class="bi bi-whatsapp"></i> WhatsApp</button>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="ficha-email"><i class="bi bi-envelope"></i> E-mail</button>
                <a class="btn btn-sm btn-outline-secondary" id="ficha-cadastro" target="_blank" title="Abrir o cadastro do aluno"><i class="bi bi-person"></i></a>
            </div>
            <details class="mt-2 small"><summary class="text-muted" style="cursor: pointer" id="ficha-qtd-parcelas"></summary><div id="ficha-parcelas" class="mt-1"></div></details>
        </div>

        <div class="p-3 border-bottom agenda-caixa">
            <div class="kpi-rotulo mb-1"><i class="bi bi-calendar-event"></i> Próxima cobrança</div>
            <div id="ficha-agenda-ver"></div>
            <div class="d-flex flex-wrap gap-2 mt-2">
                <input type="date" class="form-control form-control-sm" style="width: 150px" id="agenda-data">
                <input type="text" class="form-control form-control-sm flex-grow-1" style="min-width: 150px" id="agenda-nota" maxlength="255" placeholder="Anotação (ex.: ligar depois das 18h)">
            </div>
            <div class="d-flex flex-wrap gap-1 mt-2 align-items-center">
                <?php foreach ([1 => 'Amanhã', 3 => '+3 dias', 7 => '+1 semana', 15 => '+15 dias'] as $d => $rotulo): ?>
                    <button type="button" class="btn btn-sm btn-light border" onclick="somarDias('agenda-data', <?= $d ?>)"><?= $rotulo ?></button>
                <?php endforeach; ?>
                <button type="button" class="btn btn-sm btn-link text-danger ms-auto" id="agenda-limpar" onclick="salvarAgenda(true)">Desmarcar</button>
                <button type="button" class="btn btn-sm btn-brand" onclick="salvarAgenda(false)">Salvar data</button>
            </div>
        </div>

        <div class="p-3 border-bottom">
            <button type="button" class="btn btn-outline-brand w-100" id="btn-novo-contato" onclick="mostrarFormContato(true)"><i class="bi bi-plus-lg"></i> Registrar contato de cobrança</button>
            <form id="form-contato" class="d-none" onsubmit="registrarContato(event)">
                <div class="fw-semibold mb-2">Novo contato</div>
                <div class="btn-group btn-group-sm w-100 mb-2 flex-wrap" role="group">
                    <?php foreach ($canais as $chave => [$rotulo, $icone]): ?>
                        <input type="radio" class="btn-check" name="canal" id="canal-<?= $chave ?>" value="<?= $chave ?>" <?= $chave === 'telefone' ? 'checked' : '' ?>>
                        <label class="btn btn-outline-secondary" for="canal-<?= $chave ?>"><i class="bi <?= $icone ?>"></i> <?= e($rotulo) ?></label>
                    <?php endforeach; ?>
                </div>
                <label class="form-label small mb-1">Quando</label>
                <input type="datetime-local" class="form-control form-control-sm mb-2" name="contato_em" id="contato-em">
                <label class="form-label small mb-1">O que foi dito / feito</label>
                <textarea class="form-control form-control-sm mb-2" name="descricao" rows="3" required placeholder="Ex.: Liguei e lembrei das 2 parcelas em atraso."></textarea>
                <label class="form-label small mb-1">Resposta do aluno <span class="text-muted">(se houve)</span></label>
                <textarea class="form-control form-control-sm mb-2" name="resposta" rows="2" placeholder="Ex.: Disse que paga na sexta, quando receber o salário."></textarea>
                <div class="row g-2 mb-2">
                    <div class="col-7"><label class="form-label small mb-1">Situação</label>
                        <select class="form-select form-select-sm" name="situacao" onchange="this.form.querySelector('.promessa').classList.toggle('d-none', this.value !== 'prometeu')">
                            <?php foreach ($situacoes as $chave => [$rotulo]): ?><option value="<?= $chave ?>"><?= e($rotulo) ?></option><?php endforeach; ?>
                        </select></div>
                    <div class="col-5 promessa d-none"><label class="form-label small mb-1">Pagar até</label><input type="date" class="form-control form-control-sm" name="promessa_data" onchange="sugerirAposPromessa(this)"></div>
                </div>
                <label class="form-label small mb-1">Próxima cobrança <span class="text-muted">(opcional)</span></label>
                <div class="d-flex gap-1 flex-wrap mb-3">
                    <input type="date" class="form-control form-control-sm" style="width: 150px" name="proxima_data" id="contato-proxima">
                    <?php foreach ([1 => '+1', 3 => '+3', 7 => '+7'] as $d => $rotulo): ?><button type="button" class="btn btn-sm btn-light border" onclick="somarDias('contato-proxima', <?= $d ?>)"><?= $rotulo ?> dias</button><?php endforeach; ?>
                </div>
                <div class="d-flex gap-2"><button type="button" class="btn btn-sm btn-outline-secondary" onclick="mostrarFormContato(false)">Cancelar</button><button class="btn btn-sm btn-brand flex-grow-1"><i class="bi bi-check-lg"></i> Registrar</button></div>
            </form>
        </div>

        <div class="p-3">
            <div class="kpi-rotulo mb-2"><i class="bi bi-clock-history"></i> Histórico de cobrança</div>
            <div id="ficha-linha-tempo"></div>
        </div>
    </div>
</div>
<style>
    .ficha-cobranca { --bs-offcanvas-width: min(520px, 100vw); }
    .linha-tempo-item { position: relative; padding: 0 0 14px 34px; }
    .linha-tempo-item::before { content: ''; position: absolute; left: 13px; top: 28px; bottom: 0; width: 2px; background: var(--border, #e5e7eb); }
    .linha-tempo-item:last-child::before { display: none; }
    .linha-tempo-item .bolinha { position: absolute; left: 0; top: 0; width: 28px; height: 28px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 14px; background: var(--surface, #fff); border: 2px solid currentColor; }
    .linha-tempo-item .texto { white-space: pre-wrap; word-break: break-word; }
    .linha-tempo-item .resposta { border-left: 3px solid var(--accent); background: var(--accent-soft); padding: 6px 10px; border-radius: 0 8px 8px 0; white-space: pre-wrap; }
    .linha-tempo-item.whats-cliente .balao { background: #e7f8ee; border-radius: 10px; padding: 6px 10px; white-space: pre-wrap; display: inline-block; }
    .agenda-caixa { background: var(--accent-soft); }
</style>

<link href="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.snow.css" rel="stylesheet">
<style>#email-editor.ql-container, #lote-editor.ql-container { height: auto; } #email-editor .ql-editor, #lote-editor .ql-editor { min-height: 200px; }</style>
<script src="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.js"></script>
<script>
['attributors/style/align', 'attributors/style/color', 'attributors/style/size'].forEach((a) => Quill.register(Quill.import(a), true));
const barraEmail = [['bold', 'italic', 'underline'], [{ color: [] }], [{ list: 'ordered' }, { list: 'bullet' }], ['link', 'clean']];
const editorEmail = new Quill('#email-editor', { theme: 'snow', modules: { toolbar: barraEmail } });
const editorLote = new Quill('#lote-editor', { theme: 'snow', modules: { toolbar: barraEmail } });
let alunoAtual = null;
let canalAtual = null;

function mostrarResultado(id, ok, texto) {
    const el = document.getElementById(id);
    el.className = 'alert mt-3 mb-0 ' + (ok ? 'alert-success' : 'alert-danger');
    el.textContent = texto;
}
async function cobrar(alunoId, canal) {
    alunoAtual = alunoId;
    canalAtual = canal;
    document.getElementById('cobrar-resultado').className = 'alert mt-3 mb-0 d-none';
    document.getElementById('cobrar-whats').style.display = canal === 'whatsapp' ? '' : 'none';
    document.getElementById('cobrar-email').style.display = canal === 'email' ? '' : 'none';
    document.getElementById('cobrar-titulo').textContent = 'Carregando...';
    document.getElementById('cobrar-enviar').disabled = true;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-cobrar')).show();
    const r = await (await fetch('/financeiro/cobrar.php?aluno=' + alunoId)).json();
    if (!r.sucesso) { mostrarResultado('cobrar-resultado', false, r.mensagem); return; }
    document.getElementById('cobrar-titulo').textContent = (canal === 'whatsapp' ? 'WhatsApp para ' : 'E-mail para ') + r.nome;
    document.getElementById('cobrar-destino').textContent = canal === 'whatsapp' ? r.telefone : r.email;
    const web = document.getElementById('whats-web');
    web.classList.toggle('d-none', canal !== 'whatsapp' || !r.link_wa);
    if (r.link_wa) web.href = r.link_wa;
    if (canal === 'whatsapp') {
        document.getElementById('whats-texto').value = r.texto;
        const aviso = document.getElementById('whats-aviso');
        aviso.classList.toggle('d-none', r.whatsapp_ok);
        aviso.textContent = r.whatsapp_motivo + ' Use "Abrir no WhatsApp Web".';
        document.getElementById('cobrar-enviar').disabled = !r.whatsapp_ok;
    } else {
        document.getElementById('email-assunto').value = r.assunto;
        editorEmail.setContents([]);
        editorEmail.clipboard.dangerouslyPasteHTML(0, r.html);
        document.getElementById('rem-usuario').disabled = !r.minha_caixa;
        document.getElementById('rem-usuario-rotulo').textContent = r.minha_caixa || 'minha caixa (não configurada)';
        document.getElementById(r.minha_caixa ? 'rem-usuario' : 'rem-sistema').checked = true;
        document.getElementById('cobrar-enviar').disabled = false;
    }
}
async function enviarCobranca() {
    const botao = document.getElementById('cobrar-enviar');
    botao.disabled = true;
    const dados = new FormData();
    dados.append('acao', canalAtual);
    dados.append('aluno', alunoAtual);
    if (canalAtual === 'whatsapp') {
        dados.append('texto', document.getElementById('whats-texto').value);
    } else {
        dados.append('assunto', document.getElementById('email-assunto').value);
        dados.append('html', editorEmail.getSemanticHTML());
        dados.append('remetente', document.querySelector('[name=remetente]:checked').value);
    }
    try {
        const r = await (await fetch('/financeiro/cobrar.php', { method: 'POST', body: dados })).json();
        mostrarResultado('cobrar-resultado', r.sucesso, r.mensagem);
        if (r.sucesso) {
            marcarCobrado(alunoAtual, canalAtual);
            if (fichaAluno === alunoAtual && document.getElementById('ficha').classList.contains('show')) { precisaRecarregar = true; carregarFicha(); }
        } else botao.disabled = false;
    } catch (e) {
        mostrarResultado('cobrar-resultado', false, 'Não foi possível enviar agora.');
        botao.disabled = false;
    }
}
// ---- Ficha de cobrança (agenda) ----
let fichaAluno = null;
const hojeIso = () => new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);
function somarDias(campo, dias) {
    const d = new Date(hojeIso() + 'T12:00:00');
    d.setDate(d.getDate() + dias);
    document.getElementById(campo).value = d.toISOString().slice(0, 10);
}
function sugerirAposPromessa(campo) {
    if (!campo.value) return;
    const d = new Date(campo.value + 'T12:00:00');
    d.setDate(d.getDate() + 1);
    document.getElementById('contato-proxima').value = d.toISOString().slice(0, 10);
}
function mostrarFormContato(mostrar) {
    const form = document.getElementById('form-contato');
    form.reset();
    form.querySelector('.promessa').classList.add('d-none');
    document.getElementById('contato-em').value = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 16);
    form.classList.toggle('d-none', !mostrar);
    document.getElementById('btn-novo-contato').classList.toggle('d-none', mostrar);
    if (mostrar) form.querySelector('textarea').focus();
}
async function abrirFicha(alunoId) {
    fichaAluno = alunoId;
    mostrarFormContato(false);
    bootstrap.Offcanvas.getOrCreateInstance(document.getElementById('ficha')).show();
    await carregarFicha();
}
async function carregarFicha() {
    const r = await (await fetch('/financeiro/cobranca_agenda.php?aluno=' + fichaAluno)).json();
    if (!r.sucesso) { document.getElementById('ficha-nome').textContent = r.mensagem; return; }
    const a = r.aluno;
    document.getElementById('ficha-nome').textContent = a.nome;
    document.getElementById('ficha-contato').innerHTML = [a.telefone && (a.link_wa ? `<a href="${escapeHtml(a.link_wa)}" target="_blank" rel="noopener">${escapeHtml(a.telefone)}</a>` : escapeHtml(a.telefone)), a.email && escapeHtml(a.email)].filter(Boolean).join(' · ') || 'sem telefone e e-mail';
    document.getElementById('ficha-total').textContent = r.total;
    document.getElementById('ficha-whats').disabled = !a.telefone;
    document.getElementById('ficha-whats').onclick = () => cobrar(a.id, 'whatsapp');
    document.getElementById('ficha-email').disabled = !a.email;
    document.getElementById('ficha-email').onclick = () => cobrar(a.id, 'email');
    document.getElementById('ficha-cadastro').href = '/alunos/form.php?id=' + a.id + '&tab=financeiro';
    document.getElementById('ficha-qtd-parcelas').textContent = r.parcelas.length + ' parcela(s) vencida(s) — ver';
    document.getElementById('ficha-parcelas').innerHTML = r.parcelas.map((p) => `<div class="d-flex justify-content-between border-bottom py-1"><span>${escapeHtml(p.descricao)}<br><span class="text-muted">venceu ${escapeHtml(p.vencimento)} · ${p.dias} dia(s)</span></span><strong class="text-nowrap ms-2">${escapeHtml(p.valor)}</strong></div>`).join('');
    pintarAgenda(r.agenda);
    const linha = document.getElementById('ficha-linha-tempo');
    linha.innerHTML = r.itens.length ? r.itens.map(itemLinhaTempo).join('') : '<div class="text-muted small">Nenhuma cobrança registrada ainda.</div>';
}
function pintarAgenda(ag) {
    const ver = document.getElementById('ficha-agenda-ver');
    document.getElementById('agenda-data').value = ag ? ag.data : '';
    document.getElementById('agenda-nota').value = ag ? ag.nota : '';
    document.getElementById('agenda-limpar').classList.toggle('d-none', !ag);
    ver.innerHTML = ag
        ? `<span class="fw-semibold ${ag.situacao === 'atrasada' ? 'text-danger' : ''}">${ag.situacao === 'hoje' ? 'Hoje' : escapeHtml(ag.data_br)}</span>${ag.situacao === 'atrasada' ? ' <span class="badge text-bg-danger">atrasada</span>' : ''}${ag.nota ? ' — ' + escapeHtml(ag.nota) : ''}${ag.quem ? `<span class="small text-muted"> · marcada por ${escapeHtml(ag.quem)}</span>` : ''}`
        : '<span class="text-muted">Nenhuma data marcada.</span>';
}
function itemLinhaTempo(i) {
    if (i.tipo === 'whatsapp') {
        return `<div class="linha-tempo-item whats-cliente"><span class="bolinha text-success"><i class="bi bi-whatsapp"></i></span>
            <div class="small text-muted mb-1">${escapeHtml(i.quando)} · <strong class="text-success">aluno respondeu no WhatsApp</strong></div><div class="balao small">${escapeHtml(i.descricao)}</div></div>`;
    }
    return `<div class="linha-tempo-item"><span class="bolinha text-${i.cor}"><i class="bi ${i.icone}"></i></span>
        <div class="small text-muted mb-1">${escapeHtml(i.quando)} · ${escapeHtml(i.canal_nome)}${i.automatico ? ' (enviado pelo sistema)' : ''}${i.quem ? ' · ' + escapeHtml(i.quem) : ''}
            <span class="badge text-bg-${i.situacao_cor} ms-1">${escapeHtml(i.situacao_nome)}${i.promessa ? ' até ' + escapeHtml(i.promessa) : ''}</span></div>
        <div class="texto small">${escapeHtml(i.descricao.length > 600 ? i.descricao.slice(0, 600) + '…' : i.descricao)}</div>
        ${i.resposta ? `<div class="resposta small mt-1"><i class="bi bi-reply"></i> ${escapeHtml(i.resposta)}</div>` : ''}
        <div class="mt-1 d-flex gap-2">
            <button type="button" class="btn btn-sm btn-link p-0" onclick="abrirResposta(${i.id}, this)">${i.resposta ? 'Editar resposta' : 'Anotar resposta'}</button>
            ${i.pode_excluir ? `<button type="button" class="btn btn-sm btn-link p-0 text-danger" onclick="excluirContato(${i.id})">Apagar</button>` : ''}
        </div>
        <form class="d-none mt-2 form-resposta" onsubmit="salvarResposta(event, ${i.id})">
            <textarea class="form-control form-control-sm mb-1" name="resposta" rows="2" placeholder="O que o aluno respondeu">${escapeHtml(i.resposta)}</textarea>
            <div class="d-flex gap-1">
                <select class="form-select form-select-sm" name="situacao" onchange="this.form.querySelector('[name=promessa_data]').classList.toggle('d-none', this.value !== 'prometeu')">
                    ${Object.entries(<?= json_encode(array_map(fn ($x) => $x[0], $situacoes)) ?>).map(([k, v]) => `<option value="${k}" ${k === (i.situacao === 'sem_resposta' ? 'respondeu' : i.situacao) ? 'selected' : ''}>${v}</option>`).join('')}
                </select>
                <input type="date" class="form-control form-control-sm ${i.situacao === 'prometeu' ? '' : 'd-none'}" name="promessa_data" title="Pagar até">
                <button class="btn btn-sm btn-brand">Salvar</button>
            </div>
        </form></div>`;
}
function abrirResposta(id, botao) {
    botao.closest('.linha-tempo-item').querySelector('.form-resposta').classList.toggle('d-none');
}
async function postarAgenda(dados) {
    try { return await (await fetch('/financeiro/cobranca_agenda.php', { method: 'POST', body: dados })).json(); }
    catch (e) { return { sucesso: false, mensagem: 'Não foi possível salvar agora.' }; }
}
let tempoAvisoFicha = null;
function avisoFicha(r) {
    const el = document.getElementById('ficha-msg');
    el.className = 'alert py-2 px-3 small mb-0 rounded-0 ' + (r.sucesso ? 'alert-success' : 'alert-danger');
    el.textContent = r.mensagem;
    clearTimeout(tempoAvisoFicha);
    tempoAvisoFicha = setTimeout(() => el.classList.add('d-none'), r.sucesso ? 3500 : 8000);
}
async function salvarAgenda(limpar) {
    const dados = new FormData();
    dados.append('acao', 'agendar');
    dados.append('aluno', fichaAluno);
    dados.append('data', limpar ? '' : document.getElementById('agenda-data').value);
    dados.append('nota', limpar ? '' : document.getElementById('agenda-nota').value);
    const r = await postarAgenda(dados);
    avisoFicha(r);
    if (r.sucesso) { pintarAgenda(r.agenda); precisaRecarregar = true; }
}
async function registrarContato(ev) {
    ev.preventDefault();
    const dados = new FormData(ev.target);
    dados.append('acao', 'registrar');
    dados.append('aluno', fichaAluno);
    const r = await postarAgenda(dados);
    avisoFicha(r);
    if (r.sucesso) { mostrarFormContato(false); precisaRecarregar = true; await carregarFicha(); }
}
async function salvarResposta(ev, id) {
    ev.preventDefault();
    const dados = new FormData(ev.target);
    dados.append('acao', 'resposta');
    dados.append('id', id);
    const r = await postarAgenda(dados);
    avisoFicha(r);
    if (r.sucesso) { precisaRecarregar = true; await carregarFicha(); }
}
async function excluirContato(id) {
    if (!(await confirmarNoModal({ titulo: 'Apagar registro', html: 'Este contato sai do histórico de cobrança.', botao: 'Apagar', classeBotao: 'btn-danger' }))) return;
    const dados = new FormData();
    dados.append('acao', 'excluir');
    dados.append('id', id);
    const r = await postarAgenda(dados);
    avisoFicha(r);
    if (r.sucesso) { precisaRecarregar = true; await carregarFicha(); }
}
// Fechou a ficha depois de mudar algo: atualiza a lista (agenda e último contato).
let precisaRecarregar = false;
document.getElementById('ficha').addEventListener('hidden.bs.offcanvas', () => { if (precisaRecarregar) location.reload(); });
function marcarCobrado(alunoId, canal) {
    const el = document.getElementById('ultima-' + alunoId);
    if (el) el.innerHTML = `<i class="bi ${canal === 'whatsapp' ? 'bi-whatsapp text-success' : 'bi-envelope'}"></i> agora`;
}

// ---- Vários ----
const marcados = () => [...document.querySelectorAll('.marca-aluno:checked')].map((c) => c.value);
function atualizarLote() {
    const n = marcados().length;
    const barra = document.getElementById('barra-lote');
    barra.style.setProperty('display', n ? 'flex' : 'none', 'important');
    document.getElementById('qtd-marcados').textContent = n + ' marcado(s)';
}
function marcarTodos(ligar) { document.querySelectorAll('.marca-aluno').forEach((c) => { c.checked = ligar; }); atualizarLote(); }
let canalLote = null;
let parar = false;
function abrirLote(canal) {
    canalLote = canal;
    parar = false;
    const n = marcados().length;
    document.getElementById('lote-titulo').textContent = (canal === 'email' ? 'Cobrar por e-mail' : 'Cobrar por WhatsApp') + ' · ' + n + ' aluno(s)';
    document.getElementById('lote-email').style.display = canal === 'email' ? '' : 'none';
    document.getElementById('lote-whats').style.display = canal === 'whatsapp' ? '' : 'none';
    document.getElementById('lote-progresso').classList.add('d-none');
    document.getElementById('lote-resultado').className = 'alert mt-3 mb-0 d-none';
    document.getElementById('lote-enviar').disabled = canal === 'whatsapp' && n > 30;
    if (canal === 'whatsapp' && n > 30) mostrarResultado('lote-resultado', false, 'Marque no máximo 30 alunos para o WhatsApp.');
    bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-lote')).show();
}
document.getElementById('modal-lote').addEventListener('hide.bs.modal', () => { parar = true; });
const espera = (ms) => new Promise((ok) => setTimeout(ok, ms));
async function enviarLote() {
    const ids = marcados();
    const botao = document.getElementById('lote-enviar');
    botao.disabled = true;
    if (canalLote === 'email') {
        const dados = new FormData();
        dados.append('acao', 'lote_email');
        ids.forEach((i) => dados.append('ids[]', i));
        dados.append('assunto', document.getElementById('lote-assunto').value);
        dados.append('html', editorLote.getSemanticHTML());
        dados.append('remetente', document.querySelector('[name=lote-remetente]:checked').value);
        const r = await (await fetch('/financeiro/cobrar.php', { method: 'POST', body: dados })).json();
        mostrarResultado('lote-resultado', r.sucesso, r.mensagem);
        if (r.sucesso) {
            ids.forEach((i) => marcarCobrado(i, 'email'));
            document.getElementById('lote-resultado').insertAdjacentHTML('beforeend', ` <a href="/email/disparo.php?id=${r.disparo}" target="_blank">Acompanhar o envio</a>`);
        } else botao.disabled = false;
        return;
    }
    // WhatsApp: um por vez, com intervalo.
    const log = document.getElementById('lote-log');
    document.getElementById('lote-progresso').classList.remove('d-none');
    log.innerHTML = '';
    let feitos = 0;
    for (const id of ids) {
        if (parar) { log.insertAdjacentHTML('beforeend', '<div class="text-muted">Parado.</div>'); break; }
        const nome = document.querySelector(`.marca-aluno[value="${id}"]`).closest('tr').children[1].childNodes[0].textContent.trim();
        const dados = new FormData();
        dados.append('acao', 'whatsapp');
        dados.append('aluno', id);
        dados.append('modelo', document.getElementById('lote-texto').value);
        let r;
        try { r = await (await fetch('/financeiro/cobrar.php', { method: 'POST', body: dados })).json(); } catch (e) { r = { sucesso: false, mensagem: 'falha de conexão' }; }
        feitos++;
        log.insertAdjacentHTML('beforeend', `<div class="${r.sucesso ? 'text-success' : 'text-danger'}"><i class="bi ${r.sucesso ? 'bi-check2' : 'bi-x'}"></i> ${escapeHtml(nome)}${r.sucesso ? '' : ' — ' + escapeHtml(r.mensagem)}</div>`);
        log.scrollTop = log.scrollHeight;
        document.getElementById('lote-barra').style.width = (feitos / ids.length * 100) + '%';
        if (r.sucesso) marcarCobrado(id, 'whatsapp');
        if (feitos < ids.length) await espera(20000);
    }
    mostrarResultado('lote-resultado', true, 'Concluído: ' + feitos + ' de ' + ids.length + ' processado(s).');
}
</script>

<?php require __DIR__ . '/../../includes/modal_confirmar.php'; ?>
<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
