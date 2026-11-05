-- Post-its no aluno: um bilhete colado na ficha do aluno (e na matrícula,
-- quando feito nela). Opcionalmente avisa usuários e/ou perfis quando eles
-- abrem o sistema: a partir de agora ou de uma data e hora (agendamento),
-- até uma data ou só num dia, e quantas vezes (toda vez que abrir, uma vez
-- só ou N vezes seguidas). "Abrir o sistema" = cada login (sessão).

CREATE TABLE aluno_postits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    aluno_id INT NOT NULL,
    -- Feito dentro de uma matrícula (aparece nela também); vazio = só no aluno.
    matricula_id INT DEFAULT NULL,
    texto TEXT NOT NULL,
    cor ENUM('amarelo', 'rosa', 'azul', 'verde') NOT NULL DEFAULT 'amarelo',
    -- Aviso (opcional). Sem destinatário em aluno_postit_destinos, não avisa ninguém.
    aviso_inicio DATETIME DEFAULT NULL,  -- vazio = desde que foi criado
    aviso_fim DATE DEFAULT NULL,         -- vazio = até resolver o post-it
    -- sempre = toda vez que abrir o sistema | uma_vez | vezes = repeticao_vezes logins seguidos
    repeticao ENUM('sempre', 'uma_vez', 'vezes') NOT NULL DEFAULT 'uma_vez',
    repeticao_vezes TINYINT UNSIGNED NOT NULL DEFAULT 3,
    resolvido_em DATETIME DEFAULT NULL,
    resolvido_por VARCHAR(150) DEFAULT NULL,
    usuario_id INT DEFAULT NULL,
    usuario_nome VARCHAR(150) NOT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME DEFAULT NULL,
    KEY idx_postits_aluno (aluno_id, resolvido_em),
    CONSTRAINT fk_postit_aluno FOREIGN KEY (aluno_id) REFERENCES alunos(id) ON DELETE CASCADE,
    CONSTRAINT fk_postit_matricula FOREIGN KEY (matricula_id) REFERENCES matriculas(id) ON DELETE SET NULL,
    CONSTRAINT fk_postit_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Para quem o post-it avisa: um usuário OU um perfil (todos os usuários dele).
CREATE TABLE aluno_postit_destinos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    postit_id INT NOT NULL,
    usuario_id INT DEFAULT NULL,
    perfil_id INT DEFAULT NULL,
    KEY idx_postit_destinos (postit_id),
    CONSTRAINT fk_postit_destino_postit FOREIGN KEY (postit_id) REFERENCES aluno_postits(id) ON DELETE CASCADE,
    CONSTRAINT fk_postit_destino_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    CONSTRAINT fk_postit_destino_perfil FOREIGN KEY (perfil_id) REFERENCES perfis(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Quantas vezes cada usuário já viu o aviso (uma por login) e se pediu para
-- não ver mais.
CREATE TABLE aluno_postit_vistos (
    postit_id INT NOT NULL,
    usuario_id INT NOT NULL,
    vezes INT NOT NULL DEFAULT 0,
    ultima_sessao CHAR(64) DEFAULT NULL,
    ultima_vez DATETIME DEFAULT NULL,
    dispensado_em DATETIME DEFAULT NULL,
    PRIMARY KEY (postit_id, usuario_id),
    CONSTRAINT fk_postit_visto_postit FOREIGN KEY (postit_id) REFERENCES aluno_postits(id) ON DELETE CASCADE,
    CONSTRAINT fk_postit_visto_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ver os post-its vem junto com ver o aluno; criar e avisar é esta
-- permissão. Começa liberada para quem já vê alunos ou cursos.
INSERT INTO permissoes (chave, descricao, grupo, ordem)
VALUES ('usar_postits', 'Criar post-its no aluno e na matrícula (e avisar usuários e perfis)', 'Alunos', 14);
INSERT INTO perfil_permissoes (perfil_id, permissao_id)
SELECT DISTINCT pp.perfil_id, p.id FROM perfil_permissoes pp
  JOIN permissoes v ON v.id = pp.permissao_id AND v.chave IN ('ver_alunos', 'ver_cursos')
  JOIN permissoes p ON p.chave = 'usar_postits';
INSERT IGNORE INTO perfil_permissoes (perfil_id, permissao_id)
SELECT pf.id, p.id FROM perfis pf JOIN permissoes p ON p.chave = 'usar_postits' WHERE pf.administrador = 1;
