-- Histórico de cada parcela (lançamento, edições, baixas, estornos,
-- cancelamentos) e tarifas do banco (quanto o banco cobra por boleto
-- emitido, pago, baixado ou alterado — vira despesa depois).
-- Para quem já tem o sistema instalado; instalação nova: o schema.sql já cria.
-- Uso: mysql -u USUARIO -p NOME_DO_BANCO < sql/migracao_2026_10_17_historico_tarifas.sql

CREATE TABLE IF NOT EXISTS parcela_historico (
    id INT AUTO_INCREMENT PRIMARY KEY,
    conta_receber_id INT NOT NULL,
    usuario_id INT DEFAULT NULL,
    usuario_nome VARCHAR(150) DEFAULT NULL,
    tipo VARCHAR(30) NOT NULL,
    descricao VARCHAR(255) NOT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_parcela_historico (conta_receber_id, criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE contas_bancarias
    ADD COLUMN tarifa_emissao DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER multa_percentual,
    ADD COLUMN tarifa_liquidacao DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER tarifa_emissao,
    ADD COLUMN tarifa_baixa DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER tarifa_liquidacao,
    ADD COLUMN tarifa_alteracao DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER tarifa_baixa;

CREATE TABLE IF NOT EXISTS banco_tarifas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    conta_bancaria_id INT NOT NULL,
    conta_receber_id INT DEFAULT NULL,
    cobranca_id INT DEFAULT NULL,
    tipo ENUM('emissao', 'liquidacao', 'baixa', 'alteracao') NOT NULL,
    valor DECIMAL(10,2) NOT NULL,
    data DATE NOT NULL,
    conta_pagar_id INT DEFAULT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_banco_tarifas_conta (conta_bancaria_id, data),
    KEY idx_banco_tarifas_parcela (conta_receber_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
