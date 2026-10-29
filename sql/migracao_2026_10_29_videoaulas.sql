-- Videoaulas (YouTube) por curso ou turma, organizadas em seções, com o
-- progresso de cada aluno (curso online / vídeos complementares). Quem
-- gerencia os materiais gerencia os vídeos (mesma permissão e regras).

-- Um vídeo: do curso (todas as turmas) ou só da turma. youtube_id = o código
-- de 11 letras do vídeo. secao = agrupamento livre (ex.: "Módulo 1",
-- "Vídeos complementares"). duracao_seg vem do player na 1ª vez que tocar.
CREATE TABLE videoaulas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    curso_id INT DEFAULT NULL,
    turma_id INT DEFAULT NULL,
    secao VARCHAR(120) NOT NULL DEFAULT 'Aulas',
    titulo VARCHAR(200) NOT NULL,
    descricao TEXT DEFAULT NULL,
    youtube_id VARCHAR(20) NOT NULL,
    duracao_seg INT DEFAULT NULL,
    ordem INT NOT NULL DEFAULT 0,
    codigo CHAR(32) NOT NULL UNIQUE,
    visivel TINYINT(1) NOT NULL DEFAULT 1,
    liberar_em DATETIME DEFAULT NULL,
    usuario_id INT DEFAULT NULL,
    usuario_nome VARCHAR(150) DEFAULT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_videoaulas_curso (curso_id, ordem),
    KEY idx_videoaulas_turma (turma_id, ordem),
    CONSTRAINT fk_videoaula_curso FOREIGN KEY (curso_id) REFERENCES cursos(id) ON DELETE CASCADE,
    CONSTRAINT fk_videoaula_turma FOREIGN KEY (turma_id) REFERENCES turmas(id) ON DELETE CASCADE,
    CONSTRAINT fk_videoaula_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Quanto cada aluno assistiu: posição onde parou (para continuar), o maior
-- ponto alcançado e quando concluiu (90% do vídeo).
CREATE TABLE videoaula_progresso (
    videoaula_id INT NOT NULL,
    aluno_id INT NOT NULL,
    posicao_seg INT NOT NULL DEFAULT 0,
    maximo_seg INT NOT NULL DEFAULT 0,
    concluido_em DATETIME DEFAULT NULL,
    primeiro_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (videoaula_id, aluno_id),
    KEY idx_videoaula_progresso_aluno (aluno_id),
    CONSTRAINT fk_video_prog_video FOREIGN KEY (videoaula_id) REFERENCES videoaulas(id) ON DELETE CASCADE,
    CONSTRAINT fk_video_prog_aluno FOREIGN KEY (aluno_id) REFERENCES alunos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE permissoes SET descricao = 'Enviar materiais e videoaulas para cursos e turmas — usuário professor: só as turmas dele' WHERE chave = 'gerenciar_materiais';
