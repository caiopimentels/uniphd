<?php

$pdo = getDB();

$stmt = $pdo->prepare(
    'SELECT *
     FROM configuracoes
     WHERE id = 1'
);

$stmt->execute();
$configVisual = $stmt->fetch() ?: [];

$pageTitle = $pageTitle ?? nomeSistema();
$logoSistema = $configVisual['logo_sistema'] ?? null;
$corPrimaria = $configVisual['cor_primaria'] ?? '#16a34a';
$corSecundaria = $configVisual['cor_secundaria'] ?? '#15803d';
$activeMenu = $activeMenu ?? '';
$pageAction = $pageAction ?? '';
$pageBreadcrumb = $pageBreadcrumb ?? '';


if (!preg_match('/^#[0-9a-fA-F]{6}$/', $corPrimaria)) {
    $corPrimaria = '#16a34a';
}

if (!preg_match('/^#[0-9a-fA-F]{6}$/', $corSecundaria)) {
    $corSecundaria = '#15803d';
}

$nomeSistema = nomeSistema();

$logoSistema = $configVisual['logo_sistema'] ?? null;

$logoPosX = (int) ($configVisual['logo_pos_x'] ?? 50);
$logoPosY = (int) ($configVisual['logo_pos_y'] ?? 50);
$logoZoom = (float) ($configVisual['logo_zoom'] ?? 1);

$logoDeslocamentoX = ($logoPosX - 50) * 0.9;
$logoDeslocamentoY = ($logoPosY - 50) * 0.9;

// Espera que as páginas definam $pageTitle e $activeMenu antes de incluir este arquivo.
// Opcionalmente $pageAction (HTML do botão principal) e $pageBreadcrumb (texto).
// $acoesLaterais = true: os botões de $pageAction ficam numa coluna fixa à direita.

// Menus do topo: chave => [rótulo, itens[]]. Item: [texto, link, pode ver,
// chave do $activeMenu, nova aba?, divisória antes?, número em destaque].
$protocolosParaResponder = usuarioPodeVerProtocolos() ? protocolosAguardandoSecretaria(getDB()) : 0;
$menus = [
    'academico' => [
        'rotulo' => 'Acadêmico',
        'itens' => [
            ['Alunos', '/alunos/listar.php', usuarioPodeVerAlunos(), 'alunos'],
            ['Turmas', '/turmas/listar.php', usuarioPodeVerCursos(), 'turmas'],
            ['Cursos', '/cursos/listar.php', usuarioPodeVerCursos(), 'cursos'],
            ['Professores', '/professores/listar.php', usuarioPodeVerCursos(), 'professores'],
            ['Materiais', '/materiais/index.php', usuarioPodeGerenciarMateriais(), 'materiais'],
            ['Videoaulas', '/videoaulas/index.php', usuarioPodeGerenciarMateriais(), 'videoaulas'],
        ],
    ],
    // Atendimento ao aluno e o que ele vê no portal.
    'secretaria' => [
        'rotulo' => 'Secretaria',
        'itens' => [
            ['Protocolos', '/protocolos/listar.php', usuarioPodeVerProtocolos(), 'protocolos', false, false, $protocolosParaResponder],
            ['WhatsApp', '/whatsapp/conversas.php', usuarioTemPermissao('atender_whatsapp'), 'whatsapp', false, false, usuarioTemPermissao('atender_whatsapp') ? conversasAguardandoWhatsapp(getDB()) : 0],
            ['Certificados emitidos', '/documentos/emitidos.php', usuarioPodeLiberarCertificado() || usuarioPodeVerAlunos(), 'certificados-emitidos'],
        ],
    ],
    // O que o aluno vê no portal: o site, os avisos e os modelos de documentos.
    'portal' => [
        'rotulo' => 'Portal',
        'itens' => [
            ['Site do portal', '/configuracoes/portal.php', usuarioPodeGerenciarPortal(), 'portal'],
            ['Avisos aos alunos', '/avisos/listar.php', usuarioTemPermissao('gerenciar_avisos'), 'avisos'],
            ['Documentos (modelos)', '/configuracoes/certificado.php', usuarioPodeEditarDocumentos(), 'certificado'],
        ],
    ],
    'financeiro' => [
        'rotulo' => 'Financeiro',
        'itens' => [
            ['Contas a receber', '/financeiro/contas_receber/listar.php', usuarioPodeVerFinanceiro(), 'contas-receber'],
            ['Contas a pagar', '/financeiro/contas_pagar/listar.php', usuarioPodeVerFinanceiro(), 'contas-pagar'],
            ['Inadimplência', '/financeiro/inadimplencia.php', usuarioPodeVerInadimplencia(), 'inadimplencia'],
            ['Fornecedores', '/fornecedores/listar.php', usuarioPodeVerFornecedores(), 'fornecedores'],
            ['Categorias financeiras', '/financeiro/categorias/listar.php', usuarioPodeGerenciarCategorias(), 'categorias-financeiras'],
            ['Formas de pagamento', '/formas_pagamento/listar.php', usuarioPodeVerFormasPagamento(), 'formas-pagamento'],
            ['Cupons de desconto', '/cupons/listar.php', usuarioPodeGerenciarCupons(), 'cupons'],
        ],
    ],
    'relatorios' => [
        'rotulo' => 'Relatórios',
        'itens' => [
            ['DRE', '/financeiro/dre.php', usuarioPodeVerDRE(), 'dre'],
            ['Fluxo de caixa', '/financeiro/fluxo_caixa.php', usuarioPodeVerDRE(), 'fluxo-caixa'],
            ['Recebimentos', '/financeiro/recebimentos.php', usuarioPodeVerFinanceiro(), 'recebimentos'],
            ['Resultado por turma', '/financeiro/turmas_resultado.php', usuarioPodeVerFinanceiro(), 'resultado-turmas'],
        ],
    ],
];
?>
<!doctype html>
<html lang="pt-br" data-bs-theme="<?= usuarioLogado() && usuarioAtualTema() === 'escuro' ? 'dark' : 'light' ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(csrfToken()) ?>">
    <title><?= e($pageTitle) ?> - <?= e($nomeSistema) ?></title>
    <?php if ($iconeAba = urlIconeAba()): ?><link rel="icon" type="image/png" href="<?= e($iconeAba) ?>"><?php endif; ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;450;500;600&display=swap" rel="stylesheet">
    <link href="<?= e(assetUrl('/assets/css/app.css')) ?>" rel="stylesheet">
    <style>
    :root {
        --accent: <?= e($corPrimaria) ?>;
        --text-muted: <?= e($corPrimaria) ?>;
        --accent-dark: <?= e($corSecundaria) ?>;
        --accent-soft: color-mix(
            in srgb,
            <?= e($corPrimaria) ?> 10%,
            white
        );
    }
</style>
</head>
<body>

<header class="topnav">
    <div class="topnav-inner">
        <span class="topnav-logo">
            <?php if (!empty($logoSistema)): ?>
                <img
                    src="<?= e($logoSistema) ?>"
                    alt="<?= e($nomeSistema) ?>"
                    class="topnav-logo-img"
                    style="
                        left: <?= $logoDeslocamentoX ?>%;
                        top: <?= $logoDeslocamentoY ?>%;
                        transform: scale(<?= $logoZoom ?>);
                    "
                >
            <?php else: ?>
                <span class="topnav-logo-letra">
                    <?= e(mb_strtoupper(mb_substr($nomeSistema, 0, 1))) ?>
                </span>
            <?php endif; ?>
        </span>


        <a class="topnav-marca" href="/index.php"><?= e($nomeSistema) ?></a>

        <button class="topnav-toggle" type="button" onclick="document.getElementById('menu-principal').classList.toggle('aberto')">
            <i class="bi bi-list"></i>
        </button>

        <nav class="topnav-menu" id="menu-principal">

            <?php foreach ($menus as $chave => $menu): ?>
                <?php $itensVisiveis = array_values(array_filter($menu['itens'], fn ($i) => $i[2])); ?>
                <?php if (!$itensVisiveis) continue; ?>
                <?php $grupoAtivo = $activeMenu !== '' && in_array($activeMenu, array_column($itensVisiveis, 3), true); ?>
                <div class="dropdown topnav-grupo">
                    <button class="topnav-item <?= $grupoAtivo ? 'ativo' : '' ?>" data-bs-toggle="dropdown" type="button">
                        <?= e($menu['rotulo']) ?> <i class="bi bi-chevron-down topnav-seta"></i>
                    </button>
                    <div class="dropdown-menu topnav-dropdown">
                        <?php foreach ($itensVisiveis as $i => $item): ?>
                            <?php if ($i > 0 && ($item[5] ?? false)): ?><div class="dropdown-divider"></div><?php endif; ?>
                            <a class="dropdown-item d-flex align-items-center gap-2" href="<?= e($item[1]) ?>" <?= ($item[4] ?? false) ? 'target="_blank" rel="noopener"' : '' ?>><?= e($item[0]) ?>
                                <?php if (!empty($item[6])): ?><span class="badge rounded-pill text-bg-warning ms-auto" title="Para responder"><?= (int) $item[6] ?></span><?php endif; ?></a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </nav>

        <div class="topnav-direita">
            <button type="button" class="topnav-icone" id="botao-tema" title="Trocar para tema <?= usuarioAtualTema() === 'escuro' ? 'claro' : 'escuro' ?>" onclick="alternarTema()">
                <i class="bi <?= usuarioAtualTema() === 'escuro' ? 'bi-sun' : 'bi-moon-stars' ?>"></i>
            </button>

            <span class="topnav-sessao" id="topnav-sessao-timer" title="Tempo até a sessão expirar por inatividade">
                <i class="bi bi-clock-history"></i> <span id="sessao-timer-valor">--:--</span>
            </span>

            <?php if (usuarioPodeUsarEmail()): ?>
                <?php $emailNaoLidasCache = $_SESSION['email_nao_lidas_' . usuarioAtualId()]['n'] ?? null; ?>
                <a class="topnav-icone topnav-sino" href="/email/index.php" title="E-mail">
                    <i class="bi bi-envelope"></i>
                    <span class="sino-badge" id="email-badge" <?= $emailNaoLidasCache ? '' : 'style="display:none"' ?>><?= $emailNaoLidasCache > 99 ? '99+' : (int) $emailNaoLidasCache ?></span>
                </a>
                <script>
                // Não lidas do e-mail sem atrasar a página (o servidor de e-mail responde depois).
                window.addEventListener('load', () => fetch('/email/nao_lidas.php').then((r) => r.json()).then((r) => {
                    const b = document.getElementById('email-badge');
                    if (!b || r.nao_lidas === null || r.nao_lidas === undefined) return;
                    b.textContent = r.nao_lidas > 99 ? '99+' : r.nao_lidas;
                    b.style.display = r.nao_lidas > 0 ? '' : 'none';
                }).catch(() => {}));
                </script>
            <?php endif; ?>

            <?php
                $notificacoes = buscarNotificacoes($pdo, (int) usuarioAtualId());
                $naoLidas = contarNotificacoesNaoLidas($pdo, (int) usuarioAtualId());
            ?>
            <div class="dropdown">
                <button class="topnav-icone topnav-sino" data-bs-toggle="dropdown" data-bs-auto-close="outside" type="button" title="Alertas">
                    <i class="bi bi-bell"></i>
                    <?php if ($naoLidas > 0): ?>
                        <span class="sino-badge" id="sino-badge"><?= $naoLidas > 99 ? '99+' : $naoLidas ?></span>
                    <?php endif; ?>
                </button>
                <div class="dropdown-menu dropdown-menu-end topnav-dropdown painel-alertas">
                    <div class="alertas-cabecalho">
                        <span>Alertas</span>
                        <?php if ($naoLidas > 0): ?>
                            <button type="button" class="btn btn-link btn-sm p-0" onclick="marcarTodosAlertas()">Marcar todos como lidos</button>
                        <?php endif; ?>
                    </div>

                    <?php if (!$notificacoes): ?>
                        <div class="alertas-vazio">
                            <i class="bi bi-bell-slash"></i>
                            <div>Nenhum alerta por aqui.</div>
                        </div>
                    <?php endif; ?>

                    <?php foreach ($notificacoes as $n): ?>
                        <a class="alerta-item <?= $n['lida'] ? '' : 'nao-lida' ?>"
                           href="<?= e($n['link'] ?: '#') ?>"
                           data-id="<?= (int) $n['id'] ?>"
                           onclick="marcarAlertaLido(<?= (int) $n['id'] ?>)">
                            <span class="alerta-icone alerta-<?= e($n['tipo']) ?>">
                                <i class="bi bi-bell"></i>
                            </span>
                            <span class="alerta-corpo">
                                <span class="alerta-titulo"><?= e($n['titulo']) ?></span>
                                <?php if ($n['descricao']): ?>
                                    <span class="alerta-descricao"><?= e($n['descricao']) ?></span>
                                <?php endif; ?>
                                <span class="alerta-tempo"><?= e(tempoRelativo($n['criado_em'])) ?></span>
                            </span>
                            <?php if (!$n['lida']): ?><span class="alerta-ponto"></span><?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php if (usuarioPodeAcessarUsuarios()): ?>
            <div class="dropdown">
                <button class="topnav-icone" data-bs-toggle="dropdown" type="button" title="Administração">
                    <i class="bi bi-gear"></i>
                </button>
                <div class="dropdown-menu dropdown-menu-end topnav-dropdown">
                    <a class="dropdown-item" href="/usuarios/listar.php">Usuários</a>
                    <?php if (usuarioEhAdmin()): ?>
                    <a class="dropdown-item" href="/matriculas/liberacoes.php">Liberações de matrícula</a>
                    <a class="dropdown-item" href="/perfis/listar.php">Perfis de acesso</a>
                    <a class="dropdown-item" href="/configuracoes/form.php">Configurações</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="dropdown">
                <button class="topnav-usuario" data-bs-toggle="dropdown" type="button">
                    <span class="topnav-avatar"><?= e(mb_strtoupper(mb_substr(usuarioAtualNome(), 0, 1))) ?></span>
                    <span class="d-none d-md-inline"><?= e(usuarioAtualNome()) ?></span>
                </button>
                <div class="dropdown-menu dropdown-menu-end topnav-dropdown">
                    <a class="dropdown-item" href="/minha_conta.php">Minha conta</a>
                    <div class="dropdown-divider"></div>
                    <a class="dropdown-item" href="/logout.php">Sair</a>
                </div>
            </div>
        </div>
    </div>
</header>

<main class="pagina">
    <div class="pagina-cabecalho">
        <div class="pagina-titulo-area">
            <?php if ($pageBreadcrumb): ?><div class="pagina-breadcrumb"><?= e($pageBreadcrumb) ?></div><?php endif; ?>
            <h1 class="pagina-titulo"><?= e($pageTitle) ?></h1>
        </div>
        <?php if ($pageAction): ?>
            <div class="pagina-acoes <?= !empty($acoesLaterais) ? 'acoes-no-topo' : '' ?>"><?= $pageAction ?></div>
        <?php endif; ?>
    </div>
    <?php if (!empty($acoesLaterais) && $pageAction): ?>
    <?php // Ações numa coluna fixa à direita (fecha em layout_footer.php); em tela estreita voltam ao topo. ?>
    <div class="pagina-grade-lateral"><div class="pagina-conteudo-principal">
    <?php endif; ?>

    <?php if (!empty($_SESSION['recibo_disponivel'])): $reciboId = (int) $_SESSION['recibo_disponivel']; unset($_SESSION['recibo_disponivel']); ?>
        <div class="alert alert-light border d-flex align-items-center gap-2 py-2">
            <i class="bi bi-receipt"></i> Baixa registrada.
            <a href="/financeiro/contas_receber/recibo.php?id=<?= $reciboId ?>" target="_blank" class="btn btn-sm btn-brand ms-auto"><i class="bi bi-printer"></i> Imprimir recibo</a>
        </div>
    <?php endif; ?>
    <?php foreach (getFlashes() as $flash): ?>
        <div class="alert alert-<?= e($flash['tipo']) ?> alert-dismissible fade show" role="alert">
            <?= e($flash['mensagem']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endforeach; ?>
