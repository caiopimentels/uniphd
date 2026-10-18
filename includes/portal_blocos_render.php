<?php
// Desenha uma lista de blocos do portal. Espera $blocosRender (de
// blocosPagina()). Os blocos do aluno usam o aluno logado; na prévia do
// editor "como aluno", usam um aluno de exemplo.
if (!$blocosRender) {
    return;
}
$pdo = getDB();
$configSite = buscarConfiguracoes($pdo);
$publicoSite = publicoAtualPortal();
$alunoExemplo = $publicoSite === 'alunos' && !alunoLogado();
$destacar = emPreviaPortal() ? (int) ($_GET['destacar'] ?? 0) : 0;
$abrirLink = fn (array $c, string $chaveLink = 'botao_link') => !empty($c[$chaveLink])
    ? 'href="' . e($c[$chaveLink]) . '"' . (!empty($c['nova_aba']) ? ' target="_blank" rel="noopener"' : '') : '';
?>
<div class="portal-blocos mb-4">
<?php foreach ($blocosRender as $bloco): $c = $bloco['config']; ob_start(); ?>
        <?php if ($bloco['titulo'] && !in_array($bloco['tipo'], ['imagem_texto', 'chamada', 'saudacao'], true)): ?>
            <h2 class="bloco-titulo"><?= e($bloco['titulo']) ?></h2>
        <?php endif; ?>

        <?php if ($bloco['tipo'] === 'banner'): ?>
            <div class="banner banner-<?= e($bloco['altura']) ?>" data-slides="<?= count($bloco['imagens']) ?>">
                <?php foreach ($bloco['imagens'] as $i => $img): $tag = $img['link'] ? 'a' : 'div'; ?>
                    <<?= $tag ?> class="banner-slide <?= $i === 0 ? 'ativo' : '' ?>"
                        <?php if ($img['link']): ?>href="<?= e($img['link']) ?>"<?= $img['nova_aba'] ? ' target="_blank" rel="noopener"' : '' ?><?php endif; ?>>
                        <img src="<?= e($img['imagem']) ?>" alt="<?= e($img['texto_alternativo'] ?: $bloco['titulo'] ?: '') ?>" <?= $i ? 'loading="lazy"' : '' ?>>
                    </<?= $tag ?>>
                <?php endforeach; ?>
                <?php if (count($bloco['imagens']) > 1): ?>
                    <div class="banner-pontos"><?php foreach ($bloco['imagens'] as $i => $img): ?><button type="button" class="<?= $i === 0 ? 'ativo' : '' ?>" aria-label="Imagem <?= $i + 1 ?>"></button><?php endforeach; ?></div>
                <?php endif; ?>
            </div>

        <?php elseif ($bloco['tipo'] === 'turmas'): ?>
            <?php
            $turmasCartao = turmasAbertasPortal($pdo);
            if (!empty($c['curso_id'])) {
                $turmasCartao = array_values(array_filter($turmasCartao, fn ($t) => (int) $t['curso_id'] === (int) $c['curso_id']));
            }
            if (!empty($c['limite'])) {
                $turmasCartao = array_slice($turmasCartao, 0, (int) $c['limite']);
            }
            require __DIR__ . '/portal_bloco_turmas.php';
            ?>
            <?php if (!empty($c['limite'])): ?><div class="text-end mt-2"><a href="/portal/turmas.php">Ver todos os cursos <i class="bi bi-arrow-right"></i></a></div><?php endif; ?>

        <?php elseif ($bloco['tipo'] === 'texto'): ?>
            <div class="bloco-texto"><?= e($bloco['texto']) ?></div>

        <?php elseif ($bloco['tipo'] === 'imagem_texto'): ?>
            <div class="bloco-imagem-texto <?= ($c['lado'] ?? 'esquerda') === 'direita' ? 'imagem-direita' : '' ?>">
                <?php if (!empty($c['imagem'])): ?><div class="lado-imagem"><img src="<?= e($c['imagem']) ?>" alt="" loading="lazy"></div><?php endif; ?>
                <div class="lado-texto">
                    <?php if ($bloco['titulo']): ?><h2><?= e($bloco['titulo']) ?></h2><?php endif; ?>
                    <?php if (!empty($c['texto'])): ?><p><?= nl2br(e($c['texto'])) ?></p><?php endif; ?>
                    <?php if (!empty($c['botao_texto']) && !empty($c['botao_link'])): ?><a class="btn btn-brand" <?= $abrirLink($c) ?>><?= e($c['botao_texto']) ?></a><?php endif; ?>
                </div>
            </div>

        <?php elseif ($bloco['tipo'] === 'chamada'): ?>
            <div class="bloco-chamada" style="<?= !empty($c['cor_fundo']) ? 'background:' . e($c['cor_fundo']) . ';' : '' ?><?= !empty($c['cor_texto']) ? 'color:' . e($c['cor_texto']) . ';' : '' ?>">
                <div>
                    <?php if ($bloco['titulo']): ?><div class="chamada-titulo"><?= e($bloco['titulo']) ?></div><?php endif; ?>
                    <?php if (!empty($c['texto'])): ?><div class="chamada-texto"><?= nl2br(e($c['texto'])) ?></div><?php endif; ?>
                </div>
                <?php if (!empty($c['botao_texto']) && !empty($c['botao_link'])): ?>
                    <a class="btn btn-lg chamada-botao" <?= $abrirLink($c) ?> style="<?= !empty($c['cor_texto']) ? 'color:' . e($c['cor_fundo'] ?? '#fff') . ';background:' . e($c['cor_texto']) . ';' : '' ?>"><?= e($c['botao_texto']) ?></a>
                <?php endif; ?>
            </div>

        <?php elseif ($bloco['tipo'] === 'faq'): ?>
            <div class="bloco-faq">
                <?php foreach ((array) ($c['itens'] ?? []) as $item): ?>
                    <details><summary><?= e($item['pergunta'] ?? '') ?></summary><div><?= nl2br(e($item['resposta'] ?? '')) ?></div></details>
                <?php endforeach; ?>
            </div>

        <?php elseif ($bloco['tipo'] === 'saudacao'): ?>
            <?php if ($publicoSite === 'alunos'): ?>
                <h1 class="portal-titulo"><?= e($bloco['titulo'] ?: 'Olá') ?>, <?= e(explode(' ', trim($alunoExemplo ? 'Maria' : alunoAtualNome()))[0]) ?>!</h1>
                <p class="portal-sub mb-0"><?= e($configSite['portal_subtitulo'] ?: 'Que bom ter você por aqui.') ?></p>
            <?php elseif ($configSite['portal_titulo'] || $configSite['portal_subtitulo']): ?>
                <h1 class="portal-titulo"><?= e($configSite['portal_titulo'] ?: $configSite['nome_sistema']) ?></h1>
                <?php if ($configSite['portal_subtitulo']): ?><p class="portal-sub mb-0"><?= e($configSite['portal_subtitulo']) ?></p><?php endif; ?>
            <?php elseif (emPreviaPortal()): ?>
                <div class="text-muted small border rounded p-2">Para o visitante, a saudação mostra o nome e a frase de boas-vindas do portal — preencha em "Menu, rodapé e ajustes".</div>
            <?php endif; ?>

        <?php elseif ($bloco['tipo'] === 'avisos'): ?>
            <?php
            $pendencias = [];
            $vencidas = 0;
            if ($alunoExemplo) {
                $pendencias = [['lista_espera' => 0, 'curso_nome' => 'Curso de exemplo', 'pagamento_ate' => date('Y-m-d 23:59:59', strtotime('+2 days'))]];
            } elseif (alunoLogado()) {
                $stmt = $pdo->prepare("SELECT m.pagamento_ate, m.lista_espera, c.nome AS curso_nome FROM matriculas m JOIN turmas t ON t.id = m.turma_id JOIN cursos c ON c.id = t.curso_id WHERE m.aluno_id = ? AND m.status = 'pendente'");
                $stmt->execute([alunoAtualId()]);
                $pendencias = $stmt->fetchAll();
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM contas_receber WHERE aluno_id = ? AND status = 'pendente' AND data_vencimento < CURDATE()");
                $stmt->execute([alunoAtualId()]);
                $vencidas = (int) $stmt->fetchColumn();
                $faltamDocumentos = documentosParaEnviar(documentosDoAluno($pdo, alunoAtualId()));
            }
            ?>
            <?php foreach ($pendencias as $p): ?>
                <div class="alert alert-warning d-flex flex-wrap align-items-center gap-2 mb-2">
                    <i class="bi bi-hourglass-split"></i>
                    <div class="flex-grow-1">
                        <?php if ($p['lista_espera']): ?>
                            Recebemos seu pagamento de <strong><?= e($p['curso_nome']) ?></strong>, mas a turma lotou antes. A secretaria vai entrar em contato.
                        <?php else: ?>
                            Sua inscrição em <strong><?= e($p['curso_nome']) ?></strong> espera o pagamento até <strong><?= formatDateTime($p['pagamento_ate']) ?></strong>.
                        <?php endif; ?>
                    </div>
                    <?php if (!$p['lista_espera'] && telaPortalVisivel('meus_cursos')): ?><a href="/portal/meus_cursos.php" class="btn btn-sm btn-warning">Pagar agora</a><?php endif; ?>
                </div>
            <?php endforeach; ?>
            <?php if ($vencidas): ?>
                <div class="alert alert-danger d-flex flex-wrap align-items-center gap-2 mb-2">
                    <i class="bi bi-exclamation-circle"></i><div class="flex-grow-1">Você tem <?= $vencidas ?> parcela(s) vencida(s).</div>
                    <?php if (telaPortalVisivel('financeiro')): ?><a href="/portal/financeiro.php" class="btn btn-sm btn-danger">Ver pagamentos</a><?php endif; ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($faltamDocumentos)): ?>
                <div class="alert alert-warning d-flex flex-wrap align-items-center gap-2 mb-2">
                    <i class="bi bi-file-earmark-arrow-up"></i><div class="flex-grow-1">Você tem <?= $faltamDocumentos ?> documento(s) para enviar.</div>
                    <a href="/portal/documentos.php" class="btn btn-sm btn-warning">Enviar documentos</a>
                </div>
            <?php endif; ?>
            <?php if (!$pendencias && !$vencidas && empty($faltamDocumentos) && emPreviaPortal()): ?>
                <div class="text-muted small border rounded p-2">Avisos do aluno aparecem aqui quando houver (pagamento pendente, parcela vencida).</div>
            <?php endif; ?>

        <?php elseif ($bloco['tipo'] === 'atalhos'): ?>
            <div class="atalhos">
                <?php foreach (['meus_cursos' => ['/portal/meus_cursos.php', 'bi-journal-bookmark', 'Meus cursos'], 'financeiro' => ['/portal/financeiro.php', 'bi-wallet2', 'Pagamentos'],
                    'calendario' => ['/portal/calendario.php', 'bi-calendar-week', 'Calendário'], 'certificados' => ['/portal/certificados.php', 'bi-award', 'Certificados'],
                    'documentos' => ['/portal/documentos.php', 'bi-folder2-open', 'Documentos']] as $chaveTela => [$hrefTela, $iconeTela, $rotuloTela]):
                    if (!telaPortalVisivel($chaveTela)) continue; ?>
                    <a class="atalho" href="<?= $hrefTela ?>"><i class="bi <?= $iconeTela ?>"></i><?= $rotuloTela ?></a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
<?php
    // Bloco sem nada para mostrar agora (ex.: avisos de um aluno em dia) não
    // ocupa espaço na página.
    $conteudoBloco = ob_get_clean();
    if (trim($conteudoBloco) !== ''): ?>
    <section class="bloco bloco-<?= e($bloco['largura']) ?> bloco-tipo-<?= e($bloco['tipo']) ?><?= $destacar === (int) $bloco['id'] ? ' bloco-destacado' : '' ?>" id="bloco-<?= (int) $bloco['id'] ?>"><?= $conteudoBloco ?></section>
    <?php endif; ?>
<?php endforeach; ?>
</div>
<?php if ($destacar): ?>
<script>document.getElementById('bloco-<?= $destacar ?>')?.scrollIntoView({ block: 'center' });</script>
<?php endif; ?>
