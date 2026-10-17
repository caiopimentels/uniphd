-- Histórico do aluno: acessos ao portal, cadastro, inscrições, matrículas
-- (quem matriculou), cancelamentos, senha do portal...
-- Para quem já tem o sistema instalado; instalação nova: o schema.sql já cria.
-- Uso: mysql -u USUARIO -p NOME_DO_BANCO < sql/migracao_2026_10_17_historico_aluno.sql

CREATE TABLE IF NOT EXISTS aluno_historico (
    id INT AUTO_INCREMENT PRIMARY KEY,
    aluno_id INT NOT NULL,
    matricula_id INT DEFAULT NULL,
    usuario_id INT DEFAULT NULL,
    quem VARCHAR(150) NOT NULL,
    tipo VARCHAR(30) NOT NULL,
    descricao VARCHAR(255) NOT NULL,
    ip VARCHAR(45) DEFAULT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_aluno_historico (aluno_id, criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
