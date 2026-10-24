-- Presença dos alunos em cada módulo da turma: presente (com a data) ou
-- falta. Sem linha = ainda não marcado.
CREATE TABLE turma_presencas (
    turma_id INT NOT NULL,
    curso_modulo_id INT NOT NULL,
    aluno_id INT NOT NULL,
    presente TINYINT(1) NOT NULL,
    data DATE DEFAULT NULL,
    registrado_por VARCHAR(150) DEFAULT NULL,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (turma_id, curso_modulo_id, aluno_id),
    CONSTRAINT fk_presenca_turma FOREIGN KEY (turma_id) REFERENCES turmas(id) ON DELETE CASCADE,
    CONSTRAINT fk_presenca_modulo FOREIGN KEY (curso_modulo_id) REFERENCES curso_modulos(id) ON DELETE CASCADE,
    CONSTRAINT fk_presenca_aluno FOREIGN KEY (aluno_id) REFERENCES alunos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
