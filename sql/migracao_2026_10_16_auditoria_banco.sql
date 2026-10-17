-- Auditoria do banco: guarda cada chamada feita à API do banco (token,
-- emissão de boleto/Pix, webhook) com o que foi enviado e a resposta.
-- Para quem já tem o sistema instalado. Instalação nova: o schema.sql já cria.
-- Pode rodar mais de uma vez sem problema.
-- Uso: mysql -u USUARIO -p NOME_DO_BANCO < sql/migracao_2026_10_16_auditoria_banco.sql

CREATE TABLE IF NOT EXISTS banco_chamadas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    conta_bancaria_id INT DEFAULT NULL,
    operacao VARCHAR(40) NOT NULL,
    conta_receber_id INT DEFAULT NULL,
    usuario_id INT DEFAULT NULL,
    aluno_id INT DEFAULT NULL,
    origem ENUM('sistema', 'portal', 'automatico') NOT NULL DEFAULT 'sistema',
    metodo VARCHAR(10) NOT NULL,
    url VARCHAR(255) NOT NULL,
    status_http SMALLINT DEFAULT NULL,
    sucesso TINYINT(1) NOT NULL DEFAULT 0,
    correlation_id VARCHAR(40) DEFAULT NULL,
    requisicao MEDIUMTEXT DEFAULT NULL,
    resposta MEDIUMTEXT DEFAULT NULL,
    mensagem VARCHAR(255) DEFAULT NULL,
    duracao_ms INT DEFAULT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_banco_chamadas_conta (conta_bancaria_id, criado_em),
    KEY idx_banco_chamadas_parcela (conta_receber_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
