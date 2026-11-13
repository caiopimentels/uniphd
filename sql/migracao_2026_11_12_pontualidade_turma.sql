-- Desconto por pontualidade padrão da turma (opcional): toda matrícula nova
-- da turma (secretaria ou portal) já nasce com ele. A secretaria pode mudar
-- na hora de matricular; depois, cada matrícula tem o seu.
ALTER TABLE turmas
    ADD COLUMN desconto_pontualidade_tipo ENUM('nenhum', 'valor', 'percentual') NOT NULL DEFAULT 'nenhum' AFTER portal_dia_vencimento,
    ADD COLUMN desconto_pontualidade_valor DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER desconto_pontualidade_tipo,
    ADD COLUMN desconto_pontualidade_dias INT NOT NULL DEFAULT 0 AFTER desconto_pontualidade_valor;
