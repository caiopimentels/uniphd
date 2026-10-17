<?php
// Topo do portal do aluno. Espera $tituloPagina e, opcionalmente,
// $telaPortal (chave da tela do sistema, para os blocos montados pela
// operadora antes/depois do conteúdo — ver portal_site.php). O menu vem de
// Acadêmico › Portal do aluno › Menu e rodapé.
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
                <div class="dropdown">
                    <a class="item dropdown-toggle <?= $enderecoAtual === '/portal/conta.php' ? 'ativo' : '' ?>" href="#" data-bs-toggle="dropdown">
                        <i class="bi bi-person-circle"></i> <?= e(explode(' ', trim(alunoLogado() ? alunoAtualNome() : 'Maria'))[0]) ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="/portal/conta.php"><i class="bi bi-person"></i> Meus dados</a></li>
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
