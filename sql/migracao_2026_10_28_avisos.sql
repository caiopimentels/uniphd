-- Avisos (Secretaria › Avisos aos alunos). O conteúdo é uma arte livre,
-- tipo Canva (imagens, textos, botões e formas soltos sobre um fundo, em
-- avisos.arte, JSON), ou um texto com imagens e links (avisos.conteudo).
-- Aparece no portal como mural, janela ao entrar ou faixa no topo.

-- formato: mural (só em Meus avisos), janela (abre ao entrar no portal) ou
-- faixa (barra no topo das páginas). publico: site (todo mundo que entrar
-- no portal, com ou sem login) | todos (alunos com turma em andamento) |
-- curso | turma | aluno (os escolhidos ficam em aviso_destinos).
-- repeticao: uma_vez (some depois de visto) ou sempre (aparece em toda
-- visita até o fim do período). exige_confirmacao: o aluno precisa clicar
-- em "Li e entendi" (só alunos logados).
CREATE TABLE avisos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    titulo VARCHAR(150) NOT NULL,
    montagem ENUM('arte', 'texto') NOT NULL DEFAULT 'arte',
    arte MEDIUMTEXT DEFAULT NULL,
    conteudo MEDIUMTEXT DEFAULT NULL,
    formato ENUM('mural', 'janela', 'faixa') NOT NULL DEFAULT 'mural',
    cor ENUM('azul', 'verde', 'amarelo', 'vermelho') NOT NULL DEFAULT 'azul',
    publico ENUM('site', 'todos', 'curso', 'turma', 'aluno') NOT NULL DEFAULT 'todos',
    repeticao ENUM('uma_vez', 'sempre') NOT NULL DEFAULT 'uma_vez',
    inicio DATETIME NOT NULL,
    fim DATETIME DEFAULT NULL,
    exige_confirmacao TINYINT(1) NOT NULL DEFAULT 0,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    criado_por VARCHAR(150) DEFAULT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_avisos_periodo (ativo, inicio, fim)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cursos, turmas ou alunos escolhidos (conforme avisos.publico).
CREATE TABLE aviso_destinos (
    aviso_id INT NOT NULL,
    ref_id INT NOT NULL,
    PRIMARY KEY (aviso_id, ref_id),
    CONSTRAINT fk_aviso_destino FOREIGN KEY (aviso_id) REFERENCES avisos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Quem viu (abriu o aviso) e quem confirmou ("Li e entendi").
CREATE TABLE aviso_leituras (
    aviso_id INT NOT NULL,
    aluno_id INT NOT NULL,
    visto_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    confirmado_em DATETIME DEFAULT NULL,
    PRIMARY KEY (aviso_id, aluno_id),
    KEY idx_aviso_leituras_aluno (aluno_id),
    CONSTRAINT fk_aviso_leitura FOREIGN KEY (aviso_id) REFERENCES avisos(id) ON DELETE CASCADE,
    CONSTRAINT fk_aviso_leitura_aluno FOREIGN KEY (aluno_id) REFERENCES alunos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Permissão: quem já vê os alunos recebe.
INSERT INTO permissoes (chave, descricao, grupo, ordem) VALUES
('gerenciar_avisos', 'Enviar avisos aos alunos (portal) e ver quem leu', 'Alunos', 21);
INSERT INTO perfil_permissoes (perfil_id, permissao_id)
SELECT DISTINCT pp.perfil_id, (SELECT id FROM permissoes WHERE chave = 'gerenciar_avisos')
FROM perfil_permissoes pp JOIN permissoes p ON p.id = pp.permissao_id
WHERE p.chave = 'ver_alunos';
INSERT IGNORE INTO perfil_permissoes (perfil_id, permissao_id)
SELECT pf.id, p.id FROM perfis pf JOIN permissoes p ON p.chave = 'gerenciar_avisos' WHERE pf.administrador = 1;
