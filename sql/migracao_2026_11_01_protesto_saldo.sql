-- Protesto automático do boleto (opcional, por conta) e saldo inicial do caixa.
--
-- Protesto: quando ligado, o boleto vai ao Itaú pedindo protesto depois de
-- N dias corridos do vencimento (1 a 99). Nenhum texto sobre protesto é
-- impresso nas instruções do boleto.
ALTER TABLE contas_bancarias
    ADD COLUMN protesto_ativo TINYINT(1) NOT NULL DEFAULT 0 AFTER multa_percentual,
    ADD COLUMN protesto_dias SMALLINT DEFAULT NULL AFTER protesto_ativo;

-- Saldo do caixa informado à mão: o Fluxo de caixa parte deste valor no fim
-- do dia informado e ignora tudo o que entrou/saiu antes (lançamentos antigos
-- sem o débito correspondente deixavam o saldo irreal).
ALTER TABLE configuracoes
    ADD COLUMN caixa_saldo_data DATE DEFAULT NULL,
    ADD COLUMN caixa_saldo_valor DECIMAL(12,2) DEFAULT NULL;
