-- Álbum de fotos da turma (Turma › Álbum): a equipe/professor envia as
-- fotos; os alunos da turma veem e baixam pelo portal (Meus cursos ›
-- Álbum). Qualquer foto pode virar a capa da turma (turmas.portal_imagem).
-- O arquivo original fica onde ficam os materiais (servidor ou FTP, conta
-- no mesmo limite de espaço); a miniatura fica sempre no servidor
-- (storage/album/miniaturas), para a grade abrir rápido.

CREATE TABLE turma_fotos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    turma_id INT NOT NULL,
    legenda VARCHAR(255) DEFAULT NULL,
    arquivo_nome VARCHAR(255) NOT NULL,
    arquivo_tipo VARCHAR(60) NOT NULL,
    tamanho BIGINT NOT NULL DEFAULT 0,
    largura INT DEFAULT NULL,
    altura INT DEFAULT NULL,
    armazenamento ENUM('local', 'ftp') NOT NULL DEFAULT 'local',
    caminho VARCHAR(500) NOT NULL,
    miniatura VARCHAR(255) DEFAULT NULL,
    -- id público (links do portal), como nos materiais.
    codigo CHAR(32) NOT NULL UNIQUE,
    -- 0 = escondida dos alunos (só a equipe vê).
    visivel TINYINT(1) NOT NULL DEFAULT 1,
    usuario_id INT DEFAULT NULL,
    usuario_nome VARCHAR(150) DEFAULT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_turma_fotos_turma (turma_id, criado_em),
    CONSTRAINT fk_turma_foto_turma FOREIGN KEY (turma_id) REFERENCES turmas(id) ON DELETE CASCADE,
    CONSTRAINT fk_turma_foto_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
