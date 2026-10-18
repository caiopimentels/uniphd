-- RA (número do aluno), no formato RA0000000. Gerado no cadastro; os alunos
-- que já existem recebem o RA pelo número interno (id).
-- Para quem já tem o sistema instalado; instalação nova: o schema.sql já cria.
-- Uso: mysql -u USUARIO -p NOME_DO_BANCO < sql/migracao_2026_10_18_ra_aluno.sql

ALTER TABLE alunos ADD COLUMN ra VARCHAR(9) DEFAULT NULL AFTER id, ADD UNIQUE KEY uk_alunos_ra (ra);
UPDATE alunos SET ra = CONCAT('RA', LPAD(id, 7, '0')) WHERE ra IS NULL;
