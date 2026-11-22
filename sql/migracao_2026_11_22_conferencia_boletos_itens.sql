-- Conferência automática de boletos: guarda a lista do que aconteceu na
-- última conferência (pagos baixados, baixados no banco, erros), para a
-- tela Conferir boletos mostrar quais foram. JSON; vazio = nada a mostrar.
ALTER TABLE contas_bancarias
    ADD COLUMN conferencia_ultima_itens MEDIUMTEXT DEFAULT NULL AFTER conferencia_ultima_resumo;
