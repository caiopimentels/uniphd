-- Ajustes dos avisos (rodar depois de migracao_2026_10_28_avisos.sql).
-- repeticao: uma_vez (some depois de visto) | visita (uma vez por visita
-- ao portal) | sempre (toda vez que a página abre, até o fim do período).
-- quem_site: no público "todo mundo", quem vê — todos, só visitantes sem
-- login ou só alunos logados (qualquer aluno com cadastro, com ou sem
-- turma). paginas: chaves das páginas do portal onde aparece (vazio =
-- todas). largura: tamanho do aviso na tela, em px (vazio = automático).
ALTER TABLE avisos
    MODIFY repeticao ENUM('uma_vez', 'visita', 'sempre') NOT NULL DEFAULT 'uma_vez',
    ADD COLUMN quem_site ENUM('todos', 'visitantes', 'logados') NOT NULL DEFAULT 'todos' AFTER publico,
    ADD COLUMN paginas VARCHAR(1000) DEFAULT NULL AFTER repeticao,
    ADD COLUMN largura SMALLINT DEFAULT NULL AFTER paginas;
