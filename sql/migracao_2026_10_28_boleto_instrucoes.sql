-- Instruções do boleto (Contas bancárias › conta › Instruções do boleto):
-- até 3 linhas de texto livre impressas no campo "Instruções" do boleto
-- de todas as parcelas cobradas por esta conta.
ALTER TABLE contas_bancarias
    ADD COLUMN instrucao_boleto_1 VARCHAR(150) DEFAULT NULL AFTER itau_url_instrucoes,
    ADD COLUMN instrucao_boleto_2 VARCHAR(150) DEFAULT NULL AFTER instrucao_boleto_1,
    ADD COLUMN instrucao_boleto_3 VARCHAR(150) DEFAULT NULL AFTER instrucao_boleto_2;
