-- Materiais dos cursos e turmas (apostilas, slides, artigos, links...):
-- do curso (todas as turmas dele) ou extra de uma turma, em "locais"
-- (pastas) configuráveis. O aluno com matrícula ativa, pendente ou
-- concluída vê no portal. Professor ligado ao usuário só mexe nas turmas
-- dele. Arquivos no próprio servidor ou num FTP (TurboCloud).

-- Onde ficam os arquivos (Configurações › Arquivos).
ALTER TABLE configuracoes
    ADD COLUMN arquivos_destino ENUM('local', 'ftp') NOT NULL DEFAULT 'local',
    ADD COLUMN ftp_host VARCHAR(150) DEFAULT NULL,
    ADD COLUMN ftp_porta INT NOT NULL DEFAULT 21,
    -- ftps = FTP com criptografia (TLS explícito); ftp = sem (não recomendado).
    ADD COLUMN ftp_seguranca ENUM('ftps', 'ftp') NOT NULL DEFAULT 'ftps',
    ADD COLUMN ftp_verificar_certificado TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN ftp_usuario VARCHAR(150) DEFAULT NULL,
    ADD COLUMN ftp_senha_cripto TEXT DEFAULT NULL,
    ADD COLUMN ftp_pasta VARCHAR(255) NOT NULL DEFAULT '/materiais',
    ADD COLUMN material_max_mb INT NOT NULL DEFAULT 10,
    -- Espaço total para os arquivos dos materiais (servidor + FTP), em GB.
    ADD COLUMN material_limite_gb INT NOT NULL DEFAULT 15;

-- Usuário que é professor: só vê/mexe nos materiais das turmas dele.
ALTER TABLE usuarios
    ADD COLUMN professor_id INT DEFAULT NULL,
    ADD CONSTRAINT fk_usuario_professor FOREIGN KEY (professor_id) REFERENCES professores(id) ON DELETE SET NULL;

-- Outros professores da turma (além do principal, turmas.professor_id):
-- ex.: um por módulo da pós. Dá acesso aos materiais da turma.
CREATE TABLE turma_professores (
    turma_id INT NOT NULL,
    professor_id INT NOT NULL,
    PRIMARY KEY (turma_id, professor_id),
    CONSTRAINT fk_turma_prof_turma FOREIGN KEY (turma_id) REFERENCES turmas(id) ON DELETE CASCADE,
    CONSTRAINT fk_turma_prof_professor FOREIGN KEY (professor_id) REFERENCES professores(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- "Locais" (pastas) dos materiais.
CREATE TABLE material_locais (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(80) NOT NULL,
    icone VARCHAR(40) NOT NULL DEFAULT 'bi-folder',
    ordem INT NOT NULL DEFAULT 0,
    ativo TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO material_locais (nome, icone, ordem) VALUES
('Apostilas', 'bi-book', 1),
('Slides das aulas', 'bi-easel', 2),
('Artigos e leituras', 'bi-journal-text', 3),
('Exercícios e atividades', 'bi-pencil-square', 4),
('Vídeos e links', 'bi-play-btn', 5),
('Outros', 'bi-folder', 6);

-- Um material: do curso (curso_id) ou extra da turma (turma_id). Arquivo
-- (no servidor ou no FTP, em "caminho") ou link. codigo = id público
-- (links do portal). visivel = 0: escondido; liberar_em: aparece a partir de.
CREATE TABLE materiais (
    id INT AUTO_INCREMENT PRIMARY KEY,
    curso_id INT DEFAULT NULL,
    turma_id INT DEFAULT NULL,
    local_id INT NOT NULL,
    curso_modulo_id INT DEFAULT NULL,
    titulo VARCHAR(200) NOT NULL,
    descricao TEXT DEFAULT NULL,
    tipo ENUM('arquivo', 'link') NOT NULL DEFAULT 'arquivo',
    link VARCHAR(500) DEFAULT NULL,
    arquivo_nome VARCHAR(255) DEFAULT NULL,
    arquivo_tipo VARCHAR(120) DEFAULT NULL,
    tamanho BIGINT NOT NULL DEFAULT 0,
    armazenamento ENUM('local', 'ftp') DEFAULT NULL,
    caminho VARCHAR(500) DEFAULT NULL,
    codigo CHAR(32) NOT NULL UNIQUE,
    visivel TINYINT(1) NOT NULL DEFAULT 1,
    liberar_em DATETIME DEFAULT NULL,
    usuario_id INT DEFAULT NULL,
    usuario_nome VARCHAR(150) DEFAULT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_materiais_curso (curso_id),
    KEY idx_materiais_turma (turma_id),
    CONSTRAINT fk_material_curso FOREIGN KEY (curso_id) REFERENCES cursos(id) ON DELETE CASCADE,
    CONSTRAINT fk_material_turma FOREIGN KEY (turma_id) REFERENCES turmas(id) ON DELETE CASCADE,
    CONSTRAINT fk_material_local FOREIGN KEY (local_id) REFERENCES material_locais(id),
    CONSTRAINT fk_material_modulo FOREIGN KEY (curso_modulo_id) REFERENCES curso_modulos(id) ON DELETE SET NULL,
    CONSTRAINT fk_material_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Quem abriu/baixou cada material (e quantas vezes).
CREATE TABLE material_acessos (
    material_id INT NOT NULL,
    aluno_id INT NOT NULL,
    primeiro_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ultimo_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    vezes INT NOT NULL DEFAULT 1,
    PRIMARY KEY (material_id, aluno_id),
    KEY idx_material_acessos_aluno (aluno_id),
    CONSTRAINT fk_material_acesso_material FOREIGN KEY (material_id) REFERENCES materiais(id) ON DELETE CASCADE,
    CONSTRAINT fk_material_acesso_aluno FOREIGN KEY (aluno_id) REFERENCES alunos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissoes (chave, descricao, grupo, ordem) VALUES
('gerenciar_materiais', 'Enviar materiais (apostilas, slides...) para cursos e turmas — usuário professor: só as turmas dele', 'Cursos, turmas e professores', 33);
-- Administradores e quem já edita turmas ou vê alunos (secretaria).
INSERT INTO perfil_permissoes (perfil_id, permissao_id)
SELECT DISTINCT pp.perfil_id, (SELECT id FROM permissoes WHERE chave = 'gerenciar_materiais')
FROM perfil_permissoes pp JOIN permissoes p ON p.id = pp.permissao_id
WHERE p.chave IN ('editar_turma', 'ver_alunos');
INSERT IGNORE INTO perfil_permissoes (perfil_id, permissao_id)
SELECT pf.id, p.id FROM perfis pf JOIN permissoes p ON p.chave = 'gerenciar_materiais' WHERE pf.administrador = 1;

-- Perfil pronto para professores: só os materiais (das turmas dele).
INSERT INTO perfis (nome, administrador) SELECT 'Professor', 0 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM perfis WHERE nome = 'Professor');
INSERT IGNORE INTO perfil_permissoes (perfil_id, permissao_id)
SELECT pf.id, p.id FROM perfis pf JOIN permissoes p ON p.chave = 'gerenciar_materiais' WHERE pf.nome = 'Professor';
