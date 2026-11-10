# UniPHD Cursos — revisão 8

## Abrir localmente

```sh
cd /Users/caiomartinspimentelsantos/Downloads/uniphd-cursos/site
python3 -m http.server 4173
```

Acesse http://localhost:4173. Não houve publicação.

## Alterações desta revisão

- Luva rosa na abertura e no fundo do contato.
- Novo vídeo Veo 3.1 Fast, 8s, **1080p nativo**, sem áudio, com gesto mais contido e instrução de pressão única do êmbolo.
- Removida a ampliação digital de 2,2 vezes dos antigos quadros 720p e o desfoque dos painéis da abertura. Canvas limitado à resolução nativa da fonte.
- A seção seguinte, com imagem e texto, é renderizada em tamanho normal **dentro da gota**; a abertura vetorial da gota cresce até cobrir a tela. Assim o conteúdo revelado não é esticado. O vídeo congela no quadro final durante a expansão do portal. A borda é vetorial.
- 160 quadros WebP desktop 1920×1080 e mobile 720×1080. Qualidades 88 e 84; o aumento de peso preserva detalhes. Cache de 24 imagens, quatro carregamentos simultâneos, modo de movimento reduzido.
- Frase atualizada: “Especialize-se em Estética com Aulas 100% Presenciais, Próximo de Você”.
- Informação “Pós-graduação reconhecida pelo MEC” inserida somente na seção da pós. Origem: solicitação do usuário e texto já publicado em https://uniphd.com.br. Não houve consulta independente ao cadastro e-MEC.
- Portal https://portal.uniphd.com.br conectado à navegação, pós-graduação, cursos, contato e rodapé. Testado: HTTP 200, redireciona para /portal/index.php.

## Validação

Vídeo inspecionado diretamente em três quadros e numa folha com oito momentos. Verificador da skill passou. Testes DOM passaram para contatos e formulário, texto, escopo da informação MEC, links seguros do portal, rolagem de ida e volta, controle de frames, abertura do portal da gota e movimento reduzido. Nenhum contato real foi enviado.

A inspeção visual em navegador não foi concluída: Chromium não inicia dentro do sandbox do macOS (MachPortRendezvousServer: Permission denied). Não há prints reais de navegador. `scripts/conferir-navegador.cjs` permite capturar desktop e 390px num Terminal com permissão de iniciar Chromium. Requer servidor em 4173 e Playwright no caminho temporário indicado pelo script.

Os testes de DOM usam jsdom instalado apenas em `/private/tmp/uniphd-browser`. Essas ferramentas não são dependências do site.

## Custos

**Novo limite TOTAL autorizado: US$5**, incluindo versões anteriores. Acumulado confirmado: **US$3.699991**. Saldo do limite: US$1.300009. Detalhamento em `custos.json` e `fonte/**/*.usage.json`. Nenhuma chave dentro do site. Não solicitar novamente autorização para esse mesmo teto.

## Conteúdo e contatos

Imagens de campanha são ilustrativas; retrato da fundadora é real. Catálogo e grade vieram de https://uniphd.com.br; confirmar requisitos e turmas com a secretaria. Sem depoimentos ou resultados inventados.

FormSubmit: ativar secretaria@uniphd.com.br no e-mail recebido no primeiro envio e depois conferir um recebimento real. WhatsApp +55 77 98111-8760. A integração está configurada, mas a entrega real ainda não foi testada.

## Pendências

Inspeção visual em navegador desktop e celular; ativação e teste real do formulário; fotos e depoimentos reais de alunos quando fornecidos. Publicação e domínio somente quando solicitados.

## Revisão 4 — repetição e celular

A apresentação #sobre existe uma única vez. Em movimento normal, o próprio elemento é movido para dentro da revelação da gota, com um período de leitura antes da seção prática. Em movimento reduzido ou sem JavaScript, continua no fluxo normal. Os links de pular conteúdo e explorar levam à apresentação revelada e transferem o foco. A seção se torna acessível quando a abertura da gota está completa e volta a ficar oculta ao reverter a rolagem.

Removida a frase “Cada detalhe importa. Na estética. No ensino.”, mantendo a chamada de especialização com aulas 100% presenciais. Acrescentado destaque de 70% de aulas práticas na seção prática e na pós, conforme solicitado.

No celular, painéis são opacos, o texto principal é branco e o secundário claro; controles usam esquema escuro explícito e o rodapé mantém texto escuro sobre fundo claro. Razões de contraste dos pares principais: 18,2:1, 15,1:1, 14,2:1 e 11,9:1. Retiradas todas as setas decorativas; o botão flutuante usa ícone SVG de WhatsApp.

Testes DOM passaram para apresentação única (identidade do elemento e ausência de repetição no fluxo), avanço e retorno, acessibilidade da revelação, pular conteúdo, movimento reduzido, copy, 70%, contatos e formulário simulado. O verificador da skill passou. A inspeção visual em navegador continua limitada pela restrição do ambiente já descrita. Não houve geração nova nem gasto adicional: total US$3,699991 de US$5.

## Revisão 5 — depoimentos e marca do MEC

Seção 05 / Depoimentos inserida antes da instituição (agora 06), com oito prints originais identificados no HTML de https://uniphd.com.br. Não foram transcritas ou inventadas mensagens, identidades, estrelas ou resultados. Galeria horizontal com rolagem, ampliação em diálogo, fechamento por botão/Escape, retorno de foco e link para cada original.

**Limitação dos depoimentos:** o servidor da UniPHD começou a recusar as conexões para download durante esta sessão. As imagens permanecem vinculadas aos oito URLs públicos originais; não foi possível inspecionar seu conteúdo nem confirmar seu carregamento neste ambiente. Se o carregamento falhar, o componente informa a falha e mantém o link ao original. O manifesto está em `fonte/depoimentos-originais.json`. O script `python3 scripts/baixar-depoimentos.py` pode copiar os originais e trocar somente os src das imagens quando o servidor estiver acessível, preservando os links de origem.

Na seção 03, foi incluída a marca original do Ministério da Educação/Governo Federal, sem edição, obtida de https://www.gov.br/transferegov/pt-br/noticias/noticias/arquivos-e-imagens/mec.png. O arquivo PNG local foi validado e inspecionado. Não é um selo de aprovação emitido à UniPHD; a afirmação textual de reconhecimento continua sendo a informação institucional solicitada pelo usuário.

Validação: oito referências originais, ordem da seção, marca na seção 03, abertura/fechamento do diálogo, retorno de foco e fallback de imagem testados em DOM. Verificador da skill e testes funcionais passaram. A conferência visual no navegador segue pendente pelas limitações já descritas. Nenhum gasto novo; acumulado US$3,699991 de US$5.

## Revisão 6 — mosaico e selo original

Depoimentos reorganizados em mosaico CSS com 3 colunas no desktop, 2 no tablet e 1 no celular. Imagens inteiras, sem cortes, diálogo, botões, links externos ou instruções de ampliação. Título: “Veja o que nossos alunos falam da gente.” Mantidos os oito URLs originais; nova tentativa de copiar os arquivos falhou por Connection reset by peer.

A marca do governo adicionada na revisão anterior foi retirada. O usuário pediu o selo específico Reconhecido pelo MEC do site UniPHD. Não foi possível identificar/baixar o arquivo, pois imagens, CSS e API de mídia recusam conexões embora a página inicial responda. A afirmação textual na seção 03 foi preservada. O selo visual exato permanece pendente do arquivo, print ou endereço direto identificável; não foi criado um selo substituto.

Verificador da skill e testes DOM passaram: frase, oito imagens, ordem, ausência de controles/links, remoção da marca anterior, manutenção do texto MEC, fallback de imagem e contatos. Nenhum novo gasto: US$3,699991 de US$5.

## Revisão 7 — carrossel da experiência UniPHD

Inserida a seção “Por que escolher a UniPHD para sua especialização?” após a pós-graduação e antes dos cursos, com as 14 imagens na ordem exata do carrossel identificado no HTML original. Manifesto em `fonte/carrossel-original.json`. As seções posteriores foram renumeradas, preservando a pós na seção 03.

Três imagens visíveis no desktop e uma no celular. Rolagem nativa por gesto e teclado; avanço contínuo de aproximadamente uma imagem a cada nove segundos; loop com cópia visual escondida das tecnologias assistivas; botão de pausa sem setas decorativas. Interação manual pausa o avanço. Fora da tela e em aba oculta, a animação para. Movimento reduzido inicia pausado e permite reprodução por escolha explícita do visitante.

A página inicial original respondeu HTTP 200, mas a primeira imagem recusou o download. As 14 imagens permanecem vinculadas aos URLs originais; não foi possível inspecionar os arquivos ou garantir seu carregamento dentro deste ambiente. Há tratamento de falha sem imagens inventadas.

Verificador da skill e testes de interações passaram. Testes específicos do carrossel cobrem quantidade e ordem das imagens, autoplay, pausa, gesto, loop, aba oculta, posição fora da tela, movimento reduzido e falha de carregamento. A inspeção visual no navegador continua pendente pela restrição do ambiente. Sem geração nova nem custos adicionais; acumulado US$3,699991 de US$5. O selo MEC específico solicitado continua pendente do arquivo original, conforme revisão anterior.

## Revisão 8 — conteúdo nativo dos diferenciais

O usuário transcreveu os 14 diferenciais do carrossel (o número 11 apareceu duas vezes). Todos foram preservados como texto HTML, com correções de acentos, ortografia e pontuação. A seção foi redesenhada com introdução editorial e quatro temas: Aprendizado, Pessoas, Perto de você, Formação e condições. Navegação por toque, clique, setas do teclado, Home e End. Sem JavaScript, todos os conteúdos aparecem em sequência.

A seção não usa mais imagens do carrossel, placeholders, mensagens de falha, cópias para loop ou reprodução automática. Os manifestos originais ficam somente como histórico. O texto pode ser selecionado e lido por tecnologias assistivas, sem perda de resolução ao ampliar. Os destaques da pós e da prática agora dizem “mais de 70% da carga horária prática”, de acordo com a nova informação do usuário.

Preservados: material incluso, suporte, mais de 70% de prática, vagas limitadas, conselhos COREN/CREFITO/CRF/CRB, aulas presenciais e pacientes-modelo, proximidade, até 14x no boleto e desconto em dia, mulheres na estética, Guanambi e região, didática acolhedora, acompanhamento, mestres/doutores, tecnologias atuais e certificação nacional da pós com a informação MEC. Não foram adicionados juros, taxas, datas, elegibilidades ou requisitos não fornecidos. A sigla CRB foi mantida como fornecida. As alegações regulatórias seguem o conteúdo institucional do usuário, sem verificação independente.

Conteúdo e grupos em `fonte/diferenciais-conteudo.json`. Testes de interações, verificador da skill e teste específico dos diferenciais passaram: 14 itens presentes sem duplicação, quatro temas, clique/teclado, foco, alternativa sem JS, texto sem imagens externas e atualização do percentual. Inspeção visual em navegador segue pendente pelas limitações já registradas. Sem novo gasto; acumulado US$3,699991 de US$5.
