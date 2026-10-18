-- Documento avulso: qualquer modelo (Acadêmico › Documentos) pode ser
-- liberado para um aluno específico, mesmo que o curso não o emita. Fica
-- numa linha de curso_emissoes com avulso = 1 (uma por curso e modelo), que
-- não aparece para os outros alunos nem entra na liberação automática.
ALTER TABLE curso_emissoes ADD COLUMN avulso TINYINT(1) NOT NULL DEFAULT 0 AFTER modulo_id;
