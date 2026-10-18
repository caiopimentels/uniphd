-- Dados do aluno para certificação: nome da mãe e do pai, graduação
-- (escolhida de uma lista configurável) e o aceite do termo no cadastro.
-- Para quem já tem o sistema instalado; instalação nova: o schema.sql já cria.
-- Uso: mysql -u USUARIO -p NOME_DO_BANCO < sql/migracao_2026_10_18_graduacao_aluno.sql

CREATE TABLE IF NOT EXISTS tipos_graduacao (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(120) NOT NULL,
    no_site TINYINT(1) NOT NULL DEFAULT 1,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    ordem INT NOT NULL DEFAULT 0,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE alunos
    ADD COLUMN nome_mae VARCHAR(150) DEFAULT NULL AFTER rg,
    ADD COLUMN nome_pai VARCHAR(150) DEFAULT NULL AFTER nome_mae,
    ADD COLUMN possui_graduacao TINYINT(1) DEFAULT NULL AFTER nome_pai,
    ADD COLUMN graduacao_id INT DEFAULT NULL AFTER possui_graduacao,
    ADD COLUMN termo_certificacao_em DATETIME DEFAULT NULL AFTER graduacao_id;
