<?php
// Aluno com senha provisória troca antes de usar qualquer página do portal
// (inclusive as abertas, como o início).
if (alunoLogado() && !empty($_SESSION['portal_trocar_senha']) && !in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''), ['trocar_senha.php', 'sair.php'], true)) {
    redirect('/portal/trocar_senha.php');
}
// Topo do portal do aluno. Espera $tituloPagina e, opcionalmente,
// $telaPortal (chave da tela do sistema, para os blocos montados pela
// operadora antes/depois do conteúdo — ver portal_site.php). O menu vem de
// Portal › Site do portal › Menu e rodapé.
$pdo = getDB();
$configPortal = buscarConfiguracoes($pdo);
$nomePortal = $configPortal['portal_titulo'] ?: nomeSistema();
// Cores próprias do portal (Portal do aluno › Menu, rodapé e ajustes) ou,
// sem elas, as do sistema.
$corPortal = fn ($propria, $sistema, $padrao) => preg_match('/^#[0-9a-fA-F]{6}$/', (string) $propria) ? $propria
    : (preg_match('/^#[0-9a-fA-F]{6}$/', (string) $sistema) ? $sistema : $padrao);
$corPrimaria = $corPortal($configPortal['portal_cor_primaria'] ?? null, $configPortal['cor_primaria'], '#16a34a');
$corSecundaria = $corPortal($configPortal['portal_cor_secundaria'] ?? null, $configPortal['cor_secundaria'], '#15803d');
$tituloPagina = $tituloPagina ?? $nomePortal;
$telaPortal = $telaPortal ?? null;
$paginaTela = $telaPortal ? buscarPaginaPortal($pdo, 'chave', $telaPortal) : null;
$publicoPortal = publicoAtualPortal();

// Avisos da secretaria: para o aluno logado, os dele (sininho com os
// pendentes); para o visitante, os de "todo mundo". Só os marcados para
// esta página. Janela: uma por vez — "uma vez" até ser vista, "por visita"
// uma vez por sessão, persistente toda vez que a página abre.
$avisosPortal = emPreviaPortal() ? [] : (alunoLogado() ? avisosDoAluno($pdo, alunoAtualId()) : avisosVisitante($pdo));
$avisosPendentes = alunoLogado() ? count(array_filter($avisosPortal, 'avisoPendente')) : 0;
$paginaAviso = chavePaginaPortalAtual();
$avisosDaPagina = $paginaAviso === 'avisos' ? [] : array_filter($avisosPortal, fn ($a) => avisoAparece($a) && avisoNaPagina($a, $paginaAviso));
$faixasAviso = array_filter($avisosDaPagina, fn ($a) => $a['formato'] === 'faixa');
$avisoJanela = null;
foreach ($avisosDaPagina as $a) {
    if ($a['formato'] !== 'janela' || ($a['repeticao'] !== 'sempre' && in_array((int) $a['id'], $_SESSION['portal_avisos_janela'] ?? [], true))) {
        continue;
    }
    $avisoJanela = $a;
    $_SESSION['portal_avisos_janela'][] = (int) $a['id'];
    alunoLogado() ? marcarAvisoVisto($pdo, (int) $a['id'], alunoAtualId()) : marcarVistoVisitante((int) $a['id']);
    break;
}
$temArteAviso = (bool) array_filter([...$faixasAviso, ...($avisoJanela ? [$avisoJanela] : [])], fn ($a) => $a['montagem'] === 'arte');

// Libera as vagas de quem não pagou no prazo antes de mostrar qualquer coisa.
expirarInscricoesPortal($pdo);

// Item ativo: o que aponta para a página aberta agora.
$enderecoAtual = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) . (str_ends_with((string) ($_SERVER['SCRIPT_NAME'] ?? ''), '/pagina.php') ? '?p=' . rawurlencode((string) ($_GET['p'] ?? '')) : '');
$enderecoAtual = $enderecoAtual === '/portal/' ? '/portal/index.php' : $enderecoAtual;
?>
<!doctype html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(csrfToken()) ?>">
    <?php if ($iconeAba = urlIconeAba()): ?><link rel="icon" type="image/png" href="<?= e($iconeAba) ?>"><?php endif; ?>
    <title><?= e($tituloPagina) ?> · <?= e($nomePortal) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="<?= e(assetUrl('/assets/css/portal.css')) ?>" rel="stylesheet">
    <?php if ($temArteAviso || !empty($comArteAviso)): ?>
        <link href="<?= e(urlFontesArteAviso()) ?>" rel="stylesheet">
        <link href="<?= e(assetUrl('/assets/css/aviso-arte.css')) ?>" rel="stylesheet">
    <?php endif; ?>
    <style>:root { --accent: <?= e($corPrimaria) ?>; --accent-dark: <?= e($corSecundaria) ?>; --accent-soft: color-mix(in srgb, <?= e($corPrimaria) ?> 10%, white); }</style>
</head>
<body class="portal">

<header class="portal-topo">
    <div class="portal-topo-inner">
        <a class="portal-marca" href="/portal/index.php">
            <?php if (!empty($configPortal['logo_sistema'])): ?>
                <img src="<?= e($configPortal['logo_sistema']) ?>" alt="">
            <?php else: ?>
                <span class="letra"><?= e(mb_strtoupper(mb_substr($nomePortal, 0, 1))) ?></span>
            <?php endif; ?>
            <span><?= e($nomePortal) ?></span>
        </a>
        <button class="portal-menu-btn" type="button" aria-label="Menu" onclick="document.getElementById('portal-nav').classList.toggle('aberto')"><i class="bi bi-list"></i></button>
        <nav class="portal-nav" id="portal-nav">
            <?php foreach (itensMenuPortal($pdo, 'topo') as $item): ?>
                <a class="item <?= $item['href'] === $enderecoAtual ? 'ativo' : '' ?>" href="<?= e($item['href']) ?>"<?= $item['nova_aba'] ? ' target="_blank" rel="noopener"' : '' ?>><?= e($item['rotulo']) ?></a>
            <?php endforeach; ?>
            <?php if ($publicoPortal === 'alunos'): ?>
                <a class="item <?= $enderecoAtual === '/portal/avisos.php' ? 'ativo' : '' ?>" href="/portal/avisos.php" title="Meus avisos">
                    <i class="bi bi-bell"></i><span class="d-md-none"> Meus avisos</span><?php if ($avisosPendentes): ?> <span class="badge rounded-pill text-bg-danger"><?= $avisosPendentes ?></span><?php endif; ?>
                </a>
                <div class="dropdown">
                    <a class="item dropdown-toggle <?= $enderecoAtual === '/portal/conta.php' ? 'ativo' : '' ?>" href="#" data-bs-toggle="dropdown">
                        <i class="bi bi-person-circle"></i> <?= e(explode(' ', trim(alunoLogado() ? alunoAtualNome() : 'Maria'))[0]) ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="/portal/conta.php"><i class="bi bi-person"></i> Meus dados</a></li>
                        <li><a class="dropdown-item" href="/portal/documentos.php"><i class="bi bi-folder2-open"></i> Meus documentos</a></li>
                        <li><a class="dropdown-item" href="/portal/protocolos.php"><i class="bi bi-chat-left-text"></i> Meus protocolos</a></li>
                        <li><a class="dropdown-item" href="/portal/avisos.php"><i class="bi bi-bell"></i> Meus avisos<?php if ($avisosPendentes): ?> <span class="badge rounded-pill text-bg-danger"><?= $avisosPendentes ?></span><?php endif; ?></a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="/portal/sair.php"><i class="bi bi-box-arrow-right"></i> Sair</a></li>
                    </ul>
                </div>
            <?php else: ?>
                <a class="item <?= $enderecoAtual === '/portal/entrar.php' ? 'ativo' : '' ?>" href="/portal/entrar.php"><i class="bi bi-box-arrow-in-right"></i> Entrar</a>
                <?php if ($configPortal['portal_cadastro_aberto']): ?>
                    <a class="btn btn-brand btn-sm ms-md-1" href="/portal/cadastro.php">Criar conta</a>
                <?php endif; ?>
            <?php endif; ?>
        </nav>
    </div>
</header>
<?php foreach ($faixasAviso as $faixa): [, $corFaixa] = coresAviso()[$faixa['cor']]; ?>
    <div class="portal-faixa-aviso" id="faixa-aviso-<?= (int) $faixa['id'] ?>" style="background: <?= $corFaixa ?>">
        <div class="portal-faixa-aviso-inner">
            <i class="bi bi-megaphone"></i>
            <strong><?= e($faixa['titulo']) ?></strong>
            <?php if (avisoTemConteudo($faixa) || ($faixa['exige_confirmacao'] && alunoLogado())): ?>
                <a href="#" onclick="abrirAvisoFaixa(<?= (int) $faixa['id'] ?>); return false;">ver aviso</a>
            <?php endif; ?>
            <button type="button" title="Fechar" onclick="fecharFaixaAviso(<?= (int) $faixa['id'] ?>, <?= $faixa['repeticao'] === 'sempre' ? 'false' : 'true' ?>)"><i class="bi bi-x-lg"></i></button>
        </div>
    </div>
<?php endforeach; ?>

<main class="portal-main">
<?php foreach (getFlashes() as $flash): ?>
    <div class="alert alert-<?= e($flash['tipo']) ?> alert-dismissible fade show" role="alert">
        <?= e($flash['mensagem']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endforeach; ?>
<?php
if ($paginaTela) {
    $blocosRender = blocosPagina($pdo, (int) $paginaTela['id'], 'topo');
    require __DIR__ . '/portal_blocos_render.php';
}
?>
