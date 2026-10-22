-- Cupons de desconto para a matrícula (portal e secretaria).
--   tipo 'valor'          → R$ a menos no valor do curso (mensalidades)
--   tipo 'percentual'     → % a menos no valor do curso
--   tipo 'matricula_zero' → taxa de matrícula zerada
-- quantidade NULL = ilimitado (conta as matrículas não canceladas que usaram);
-- validade_inicio/fim NULL = sem limite naquele lado; sem cursos marcados = todos.
CREATE TABLE cupons (
    id INT AUTO_INCREMENT PRIMARY KEY,
    codigo VARCHAR(40) NOT NULL UNIQUE,
    descricao VARCHAR(150) DEFAULT NULL,
    tipo ENUM('valor', 'percentual', 'matricula_zero') NOT NULL,
    valor DECIMAL(12,2) NOT NULL DEFAULT 0,
    quantidade INT DEFAULT NULL,
    validade_inicio DATE DEFAULT NULL,
    validade_fim DATE DEFAULT NULL,
    uma_vez_por_aluno TINYINT(1) NOT NULL DEFAULT 1,
    -- Acumula com a bolsa e o desconto por pontualidade da matrícula?
    acumula TINYINT(1) NOT NULL DEFAULT 1,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    usuario_id INT DEFAULT NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_cupom_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cupom_cursos (
    cupom_id INT NOT NULL,
    curso_id INT NOT NULL,
    PRIMARY KEY (cupom_id, curso_id),
    CONSTRAINT fk_cupom_curso_cupom FOREIGN KEY (cupom_id) REFERENCES cupons(id) ON DELETE CASCADE,
    CONSTRAINT fk_cupom_curso_curso FOREIGN KEY (curso_id) REFERENCES cursos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cupom usado na matrícula e quanto ele descontou (do curso, ou a taxa zerada).
ALTER TABLE matriculas
    ADD COLUMN cupom_id INT DEFAULT NULL AFTER desconto_pontualidade_dias,
    ADD COLUMN cupom_desconto DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER cupom_id,
    ADD CONSTRAINT fk_matricula_cupom FOREIGN KEY (cupom_id) REFERENCES cupons(id) ON DELETE SET NULL;

INSERT INTO permissoes (chave, descricao, grupo, ordem) VALUES
('gerenciar_cupons', 'Cadastrar cupons de desconto', 'Financeiro dos alunos', 55);
INSERT INTO perfil_permissoes (perfil_id, permissao_id)
SELECT 1, id FROM permissoes WHERE chave = 'gerenciar_cupons';
