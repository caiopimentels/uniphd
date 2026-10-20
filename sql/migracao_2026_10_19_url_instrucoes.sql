-- Endereço usado nas instruções de boleto (baixa, valor, vencimento) da
-- conta, escolhido numa lista na tela da conta bancária. Vazio = o de antes
-- (a URL base da API da conta).
ALTER TABLE contas_bancarias ADD COLUMN itau_url_instrucoes VARCHAR(255) DEFAULT NULL AFTER api_url_base;
