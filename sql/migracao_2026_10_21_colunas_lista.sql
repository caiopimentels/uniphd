-- Colunas visíveis (e a ordem delas) nas listas — alunos, turmas, cursos,
-- contas a receber e a pagar —, escolhidas por cada usuário. A impressão
-- da lista sai com as mesmas colunas.
CREATE TABLE usuario_colunas_lista (
    usuario_id INT NOT NULL,
    tela VARCHAR(40) NOT NULL,
    colunas VARCHAR(500) NOT NULL, -- chaves separadas por vírgula, na ordem
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (usuario_id, tela),
    CONSTRAINT fk_colunas_lista_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
