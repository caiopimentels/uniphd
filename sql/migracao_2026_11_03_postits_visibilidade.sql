-- Post-its (rodar depois de migracao_2026_11_03_postits.sql): separa quem
-- pode VER o post-it de quem é AVISADO, e o período (de/até) passa a valer
-- para o post-it em si — com ou sem aviso.
--   visibilidade: todos (quem abre o aluno) | eu (só quem escreveu) |
--                 escolhidos (quem escreveu + as pessoas de aluno_postit_destinos)
--   avisar: 1 = abre um aviso quando as pessoas escolhidas (ou, sem
--           ninguém escolhido, quem escreveu) entram no sistema.
ALTER TABLE aluno_postits
    CHANGE aviso_inicio inicio DATETIME DEFAULT NULL,
    CHANGE aviso_fim fim DATE DEFAULT NULL;
ALTER TABLE aluno_postits
    ADD visibilidade ENUM('todos', 'eu', 'escolhidos') NOT NULL DEFAULT 'todos' AFTER cor,
    ADD avisar TINYINT(1) NOT NULL DEFAULT 0 AFTER fim;
-- Os que já tinham pessoas eram avisos.
UPDATE aluno_postits p SET p.avisar = 1 WHERE EXISTS (SELECT 1 FROM aluno_postit_destinos d WHERE d.postit_id = p.id);
