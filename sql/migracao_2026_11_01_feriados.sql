-- Feriados da cidade (Configurações › Cadastros auxiliares › Feriados): uma
-- parcela que vence em fim de semana ou feriado só fica "em atraso" depois
-- do próximo dia útil (o banco aceita sem juros até lá). Os feriados
-- nacionais o sistema já calcula sozinho; aqui vão só os da cidade.
CREATE TABLE feriados (
    id INT AUTO_INCREMENT PRIMARY KEY,
    data DATE NOT NULL,
    nome VARCHAR(100) NOT NULL,
    todo_ano TINYINT(1) NOT NULL DEFAULT 1,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_feriados_data (data)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
