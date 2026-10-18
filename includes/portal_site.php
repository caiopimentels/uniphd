<?php

// Portal como site por módulos, montado pela operadora em Acadêmico ›
// Portal do aluno:
//   páginas -> Início, páginas criadas por ela (Sobre, Contato...) e as
//              telas do sistema (Cursos, Pagamentos...), que recebem blocos
//              antes e depois do próprio conteúdo
//   blocos  -> banner, vitrine de turmas, texto, imagem + texto, chamada
//              com botão, perguntas frequentes e as partes do aluno logado
//              (saudação, avisos, atalhos)
//   menu    -> itens do topo e links do rodapé, cada um para todo mundo,
//              só visitantes ou só alunos logados
// O site é o mesmo para quem entrou ou não; o que muda são os blocos e
// itens marcados "só alunos" / "só visitantes".

// Telas do sistema: chave => endereço.
function urlsTelasPortal(): array
{
    return [
        'inicio' => '/portal/index.php',
        'cursos' => '/portal/turmas.php',
        'turma' => null, // uma por curso: não entra no menu
        'meus_cursos' => '/portal/meus_cursos.php',
        'financeiro' => '/portal/financeiro.php',
        'calendario' => '/portal/calendario.php',
        'certificados' => '/portal/certificados.php',
    ];
}

function urlPaginaPortal(array $pagina): ?string
{
    if ($pagina['tipo'] === 'personalizada') {
        return '/portal/pagina.php?p=' . rawurlencode($pagina['slug']);
    }
    return urlsTelasPortal()[$pagina['chave']] ?? null;
}

// Telas do sistema escondidas pelo editor (portal_paginas.ativo = 0).
function telaPortalVisivel(string $chave): bool
{
    static $ocultas = null;
    if ($ocultas === null) {
        $ocultas = getDB()->query("SELECT chave FROM portal_paginas WHERE tipo = 'sistema' AND ativo = 0")->fetchAll(PDO::FETCH_COLUMN);
    }
    return !in_array($chave, $ocultas, true);
}

// Tela oculta: só abre na prévia do editor; os demais voltam para o início.
function exigirTelaPortal(string $chave): void
{
    if (!telaPortalVisivel($chave) && !emPreviaPortal()) {
        flash('warning', 'Esta página não está disponível.');
        redirect('/portal/index.php');
    }
}

function paginasPortal(PDO $pdo): array
{
    return $pdo->query("SELECT * FROM portal_paginas ORDER BY FIELD(tipo, 'inicio', 'personalizada', 'sistema'), ordem, titulo")->fetchAll();
}

function buscarPaginaPortal(PDO $pdo, string $campo, $valor): ?array
{
    $coluna = ['id' => 'id', 'chave' => 'chave', 'slug' => 'slug'][$campo];
    $stmt = $pdo->prepare("SELECT * FROM portal_paginas WHERE {$coluna} = ?");
    $stmt->execute([$valor]);
    return $stmt->fetch() ?: null;
}

// "Sobre nós!" -> "sobre-nos"
function slugPortal(string $texto): string
{
    // Tabela própria: o iconv//TRANSLIT muda de servidor para servidor
    // (no macOS, "ó" vira "'o").
    $texto = strtr(mb_strtolower(trim($texto), 'UTF-8'), [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n',
    ]);
    return mb_substr(trim(preg_replace('/[^a-z0-9]+/', '-', $texto), '-'), 0, 80) ?: 'pagina';
}

/**
 * Quem está vendo: 'alunos' (logado) ou 'visitantes'. A secretaria, no
 * editor, pode pedir a prévia de um ou de outro (?previa=alunos).
 */
function publicoAtualPortal(): string
{
    $previa = $_GET['previa'] ?? '';
    if (in_array($previa, ['alunos', 'visitantes'], true) && usuarioLogado() && usuarioPodeGerenciarPortal()) {
        return $previa;
    }
    return alunoLogado() ? 'alunos' : 'visitantes';
}

function emPreviaPortal(): bool
{
    return isset($_GET['previa']) && usuarioLogado() && usuarioPodeGerenciarPortal();
}

// ---------------------------------------------------------------------
// Tipos de bloco
// ---------------------------------------------------------------------

/**
 * [rótulo, ícone, explicação, só faz sentido para aluno logado?]
 */
function tiposBlocoPortal(): array
{
    return [
        'banner' => ['Banner / promoção', 'bi-image', 'Uma imagem com link — ou várias, que passam sozinhas (carrossel).', false],
        'imagem_texto' => ['Imagem + texto', 'bi-layout-split', 'Foto de um lado; título, texto e botão do outro.', false],
        'chamada' => ['Chamada com botão', 'bi-megaphone', 'Faixa com uma frase de destaque e um botão ("Inscreva-se", "Fale no WhatsApp").', false],
        'turmas' => ['Vitrine de turmas', 'bi-grid-3x2-gap', 'Mostra sozinha as turmas com inscrição aberta.', false],
        'texto' => ['Texto / aviso', 'bi-card-text', 'Um título e um texto: apresentação, recado, aviso.', false],
        'faq' => ['Perguntas frequentes', 'bi-question-circle', 'Perguntas que abrem a resposta ao clicar.', false],
        'saudacao' => ['Saudação', 'bi-emoji-smile', 'Para o aluno: "Olá, Maria!". Para o visitante: o nome e a frase de boas-vindas do portal.', false],
        'avisos' => ['Avisos do aluno', 'bi-exclamation-triangle', 'Pagamento de inscrição pendente e parcelas vencidas do aluno.', true],
        'atalhos' => ['Atalhos do aluno', 'bi-grid', 'Botões para Meus cursos, Pagamentos, Calendário e Certificados.', true],
    ];
}

function largurasBlocoPortal(): array
{
    return ['inteira' => 'Largura inteira', 'metade' => 'Metade da largura', 'terco' => 'Um terço da largura'];
}

function alturasBlocoPortal(): array
{
    return ['baixa' => 'Baixa (faixa)', 'media' => 'Média', 'alta' => 'Alta'];
}

function publicosBlocoPortal(): array
{
    return ['todos' => 'Todo mundo', 'visitantes' => 'Só quem ainda não entrou', 'alunos' => 'Só alunos que entraram'];
}

function configBloco(array $bloco): array
{
    $config = json_decode((string) ($bloco['config'] ?? ''), true);
    return is_array($config) ? $config : [];
}

/**
 * Blocos de uma página (e região, nas telas do sistema). $paraExibir = só
 * o que aparece agora para quem está vendo (ligado, dentro das datas,
 * público certo); sem isso, todos (editor). Banners vêm com 'imagens'.
 */
function blocosPagina(PDO $pdo, int $paginaId, ?string $regiao = null, bool $paraExibir = true): array
{
    $sql = 'SELECT * FROM portal_blocos WHERE pagina_id = ?';
    $parametros = [$paginaId];
    if ($regiao) {
        $sql .= ' AND regiao = ?';
        $parametros[] = $regiao;
    }
    if ($paraExibir) {
        $sql .= " AND ativo = 1 AND (exibir_de IS NULL OR exibir_de <= CURDATE()) AND (exibir_ate IS NULL OR exibir_ate >= CURDATE())
                  AND publico IN ('todos', ?)";
        $parametros[] = publicoAtualPortal();
    }
    $stmt = $pdo->prepare($sql . ' ORDER BY regiao, ordem, id');
    $stmt->execute($parametros);
    $blocos = $stmt->fetchAll();

    if ($blocos) {
        $ids = array_column($blocos, 'id');
        $stmt = $pdo->prepare('SELECT * FROM portal_banner_imagens WHERE bloco_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY ordem, id');
        $stmt->execute($ids);
        $imagens = [];
        foreach ($stmt->fetchAll() as $img) {
            $imagens[$img['bloco_id']][] = $img;
        }
        foreach ($blocos as &$bloco) {
            $bloco['imagens'] = $imagens[$bloco['id']] ?? [];
            $bloco['config'] = configBloco($bloco);
        }
        unset($bloco);
    }

    // Banner sem imagem não tem o que mostrar.
    return $paraExibir ? array_values(array_filter($blocos, fn ($b) => $b['tipo'] !== 'banner' || $b['imagens'])) : $blocos;
}

// Abre espaço na ordem para inserir um bloco na posição pedida da região.
function ordemParaInserirBloco(PDO $pdo, int $paginaId, string $regiao, ?int $posicao): int
{
    $stmt = $pdo->prepare('SELECT id FROM portal_blocos WHERE pagina_id = ? AND regiao = ? ORDER BY ordem, id');
    $stmt->execute([$paginaId, $regiao]);
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $posicao = $posicao === null ? count($ids) : max(0, min(count($ids), $posicao));
    $atualiza = $pdo->prepare('UPDATE portal_blocos SET ordem = ? WHERE id = ?');
    foreach ($ids as $i => $id) {
        $atualiza->execute([($i < $posicao ? $i + 1 : $i + 2) * 10, $id]);
    }
    return ($posicao + 1) * 10;
}

// ---------------------------------------------------------------------
// Menu e rodapé
// ---------------------------------------------------------------------

/**
 * Itens do menu de um local. $paraExibir = só os visíveis para quem está
 * vendo, já com 'href' resolvido.
 */
function itensMenuPortal(PDO $pdo, string $local, bool $paraExibir = true): array
{
    $stmt = $pdo->prepare(
        'SELECT m.*, p.tipo AS pagina_tipo, p.chave AS pagina_chave, p.slug AS pagina_slug, p.titulo AS pagina_titulo, p.ativo AS pagina_ativa, p.publico AS pagina_publico
         FROM portal_menu m LEFT JOIN portal_paginas p ON p.id = m.pagina_id WHERE m.local = ? ORDER BY m.ordem, m.id'
    );
    $stmt->execute([$local]);
    $itens = [];
    $publico = publicoAtualPortal();
    foreach ($stmt->fetchAll() as $item) {
        $item['href'] = $item['pagina_id']
            ? urlPaginaPortal(['tipo' => $item['pagina_tipo'], 'chave' => $item['pagina_chave'], 'slug' => $item['pagina_slug']])
            : $item['url'];
        if ($paraExibir) {
            if (!$item['ativo'] || !$item['href'] || ($item['pagina_id'] && !$item['pagina_ativa'])) {
                continue;
            }
            if ($item['publico'] !== 'todos' && $item['publico'] !== $publico) {
                continue;
            }
        }
        $itens[] = $item;
    }
    return $itens;
}

function redesSociaisPortal(): array
{
    return [
        'instagram' => ['Instagram', 'bi-instagram'],
        'facebook' => ['Facebook', 'bi-facebook'],
        'youtube' => ['YouTube', 'bi-youtube'],
        'tiktok' => ['TikTok', 'bi-tiktok'],
        'linkedin' => ['LinkedIn', 'bi-linkedin'],
    ];
}

function redesConfiguradasPortal(array $config): array
{
    $redes = json_decode((string) ($config['portal_redes'] ?? ''), true);
    return array_filter(is_array($redes) ? $redes : [], fn ($url, $rede) => isset(redesSociaisPortal()[$rede]) && preg_match('#^https?://#i', (string) $url), ARRAY_FILTER_USE_BOTH);
}

/**
 * O que aparece no rodapé do portal, item a item. Sem escolha salva: segue a
 * chave antiga (contatos ligados/desligados), com o WhatsApp só no botão
 * flutuante (o link no rodapé repetia o mesmo contato).
 */
function itensRodapePortal(array $config): array
{
    $contatos = !empty($config['portal_rodape_contatos']);
    $padrao = ['endereco' => $contatos, 'telefone' => $contatos, 'email' => $contatos, 'whatsapp' => false, 'whatsapp_flutuante' => true];
    $salvo = json_decode((string) ($config['portal_rodape_itens'] ?? ''), true);
    if (!is_array($salvo)) {
        return $padrao;
    }
    foreach ($padrao as $chave => $valor) {
        $padrao[$chave] = (bool) ($salvo[$chave] ?? $valor);
    }
    return $padrao;
}

function opcoesRodapePortal(): array
{
    return [
        'endereco' => ['Endereço', 'bi-geo-alt'],
        'telefone' => ['Telefone', 'bi-telephone'],
        'email' => ['E-mail', 'bi-envelope'],
        'whatsapp' => ['Link do WhatsApp no rodapé', 'bi-whatsapp'],
        'whatsapp_flutuante' => ['Botão flutuante do WhatsApp (canto da tela)', 'bi-chat-dots'],
    ];
}
