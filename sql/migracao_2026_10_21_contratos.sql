-- Modelos de contrato (texto editável, com variáveis como {aluno_nome}),
-- impressos na matrícula e mostrados ao aluno no portal. Cada curso escolhe
-- o seu (cursos.contrato_modelo_id); sem escolha, vale o padrão.
CREATE TABLE contrato_modelos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    conteudo MEDIUMTEXT DEFAULT NULL, -- HTML do editor
    padrao TINYINT(1) NOT NULL DEFAULT 0,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE cursos
    ADD COLUMN contrato_modelo_id INT DEFAULT NULL AFTER certificado_modelo_id,
    ADD CONSTRAINT fk_curso_contrato_modelo FOREIGN KEY (contrato_modelo_id) REFERENCES contrato_modelos(id) ON DELETE SET NULL;

-- Contrato da matrícula disponível para o aluno no portal (em Documentos).
ALTER TABLE turmas ADD COLUMN contrato_portal TINYINT(1) NOT NULL DEFAULT 0;
