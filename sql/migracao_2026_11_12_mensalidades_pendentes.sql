-- Turma com início a definir: na matrícula (secretaria ou portal) só a taxa
-- de matrícula é lançada. As mensalidades ficam pendentes até a secretaria
-- liberar em Turma › Financeiro, escolhendo o 1º vencimento (as demais no
-- mesmo dia dos meses seguintes).
ALTER TABLE matriculas
    ADD COLUMN mensalidades_pendentes TINYINT(1) NOT NULL DEFAULT 0 AFTER primeiro_vencimento;
