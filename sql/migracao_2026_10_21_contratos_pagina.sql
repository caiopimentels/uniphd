-- Configuração de página do contrato: margens, cabeçalho e rodapé (JSON).
-- {"margens":{"superior":2,"inferior":2,"esquerda":2,"direita":2} (cm),
--  "cabecalho":"instituicao"|"nenhum"|"personalizado", "cabecalho_html":"...",
--  "rodape_html":"...", "numero_pagina":1}. Vazio = padrão (margens de 2 cm,
-- cabeçalho com os dados da instituição, número da página no rodapé).
ALTER TABLE contrato_modelos ADD COLUMN pagina TEXT DEFAULT NULL AFTER conteudo;
