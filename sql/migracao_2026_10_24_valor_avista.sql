-- Turma com dois preços: a prazo (valor_total, dividido nas mensalidades)
-- e à vista (valor_avista, uma parcela só; NULL = sem opção à vista).
-- Cada um pode aparecer ou não no portal. A matrícula guarda se foi à vista.
ALTER TABLE turmas
    ADD COLUMN valor_avista DECIMAL(12,2) DEFAULT NULL AFTER valor_total,
    ADD COLUMN portal_exibir_prazo TINYINT(1) NOT NULL DEFAULT 1 AFTER portal_publicar,
    ADD COLUMN portal_exibir_avista TINYINT(1) NOT NULL DEFAULT 1 AFTER portal_exibir_prazo;
ALTER TABLE matriculas
    ADD COLUMN pagamento_avista TINYINT(1) NOT NULL DEFAULT 0 AFTER valor_total;
