-- Protocolos: o aluno abre pelo portal (justificativa de ausência, pedido
-- de reposição...), com anexo (ex.: atestado); a secretaria responde e muda
-- o status. Temas, assuntos e status são configuráveis (Protocolos › Configurar).

-- Temas: agrupam os assuntos (Acadêmico, Financeiro...).
CREATE TABLE protocolo_temas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(60) NOT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    ordem INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Assuntos (cada um num tema). pede_turma: o aluno escolhe a turma (e o módulo); pede_data:
-- informa a data (ex.: da falta); anexo: não pede, opcional ou obrigatório.
CREATE TABLE protocolo_assuntos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tema_id INT DEFAULT NULL,
    nome VARCHAR(100) NOT NULL,
    instrucao VARCHAR(255) DEFAULT NULL,
    pede_turma TINYINT(1) NOT NULL DEFAULT 0,
    pede_data TINYINT(1) NOT NULL DEFAULT 0,
    anexo ENUM('nao', 'opcional', 'obrigatorio') NOT NULL DEFAULT 'opcional',
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    ordem INT NOT NULL DEFAULT 0,
    CONSTRAINT fk_protocolo_assunto_tema FOREIGN KEY (tema_id) REFERENCES protocolo_temas(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Status. inicial: o do protocolo recém-aberto; encerra: protocolo
-- finalizado (o aluno não responde mais nele).
CREATE TABLE protocolo_status (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(60) NOT NULL,
    cor VARCHAR(7) NOT NULL DEFAULT '#6c757d',
    inicial TINYINT(1) NOT NULL DEFAULT 0,
    encerra TINYINT(1) NOT NULL DEFAULT 0,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    ordem INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- numero: o que aparece para todos — data de abertura + sequência
-- (ex.: 2026102400001). codigo: aleatório, vai no link do aluno.
-- aguardando: quem precisa agir agora (secretaria responder / aluno).
CREATE TABLE protocolos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    numero VARCHAR(20) DEFAULT NULL UNIQUE,
    codigo CHAR(12) NOT NULL UNIQUE,
    aluno_id INT NOT NULL,
    assunto_id INT NOT NULL,
    status_id INT NOT NULL,
    matricula_id INT DEFAULT NULL,
    curso_modulo_id INT DEFAULT NULL,
    data_referencia DATE DEFAULT NULL,
    descricao TEXT NOT NULL,
    origem ENUM('portal', 'secretaria') NOT NULL DEFAULT 'portal',
    aguardando ENUM('secretaria', 'aluno', 'ninguem') NOT NULL DEFAULT 'secretaria',
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    encerrado_em DATETIME DEFAULT NULL,
    KEY idx_protocolos_aluno (aluno_id, criado_em),
    KEY idx_protocolos_status (status_id, atualizado_em),
    CONSTRAINT fk_protocolo_aluno FOREIGN KEY (aluno_id) REFERENCES alunos(id),
    CONSTRAINT fk_protocolo_assunto FOREIGN KEY (assunto_id) REFERENCES protocolo_assuntos(id),
    CONSTRAINT fk_protocolo_status FOREIGN KEY (status_id) REFERENCES protocolo_status(id),
    CONSTRAINT fk_protocolo_matricula FOREIGN KEY (matricula_id) REFERENCES matriculas(id) ON DELETE SET NULL,
    CONSTRAINT fk_protocolo_modulo FOREIGN KEY (curso_modulo_id) REFERENCES curso_modulos(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Conversa do protocolo. autor: aluno, secretaria ou sistema (mudança de
-- status). interna = nota da secretaria que o aluno não vê. Anexo guardado
-- em storage/documentos/aluno_N/ (como os documentos do aluno).
CREATE TABLE protocolo_mensagens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    protocolo_id INT NOT NULL,
    autor ENUM('aluno', 'secretaria', 'sistema') NOT NULL,
    usuario_id INT DEFAULT NULL,
    autor_nome VARCHAR(150) DEFAULT NULL,
    texto TEXT DEFAULT NULL,
    interna TINYINT(1) NOT NULL DEFAULT 0,
    anexo VARCHAR(255) DEFAULT NULL,
    anexo_nome VARCHAR(200) DEFAULT NULL,
    anexo_mime VARCHAR(50) DEFAULT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_protocolo_mensagens (protocolo_id, id),
    CONSTRAINT fk_protocolo_mensagem FOREIGN KEY (protocolo_id) REFERENCES protocolos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO protocolo_temas (id, nome, ordem) VALUES
(1, 'Acadêmico', 1),
(2, 'Financeiro', 2),
(3, 'Documentos', 3),
(4, 'Outros', 4);

INSERT INTO protocolo_assuntos (tema_id, nome, instrucao, pede_turma, pede_data, anexo, ordem) VALUES
(1, 'Justificativa de ausência', 'Conte o motivo da falta. Se tiver atestado ou declaração, anexe aqui.', 1, 1, 'opcional', 1),
(1, 'Solicitação de reposição', 'Diga qual aula ou módulo você perdeu e a sua disponibilidade para repor.', 1, 1, 'opcional', 2),
(2, 'Dúvida sobre pagamento', 'Se for sobre uma parcela ou comprovante, anexe aqui.', 0, 0, 'opcional', 3),
(3, 'Solicitação de documento', 'Diga qual documento você precisa (declaração, histórico...).', 1, 0, 'nao', 4),
(4, 'Outros assuntos', NULL, 0, 0, 'opcional', 5);

INSERT INTO protocolo_status (nome, cor, inicial, encerra, ordem) VALUES
('Aberto', '#0d6efd', 1, 0, 1),
('Em análise', '#fd7e14', 0, 0, 2),
('Aguardando o aluno', '#6f42c1', 0, 0, 3),
('Deferido', '#198754', 0, 1, 4),
('Indeferido', '#dc3545', 0, 1, 5),
('Concluído', '#6c757d', 0, 1, 6);

-- Permissões: ver e responder, abrir em nome do aluno (quem já vê os
-- alunos recebe as duas) e configurar.
INSERT INTO permissoes (chave, descricao, grupo, ordem) VALUES
('ver_protocolos', 'Ver e responder os protocolos dos alunos', 'Alunos', 16),
('abrir_protocolo', 'Abrir protocolo em nome do aluno', 'Alunos', 17),
('configurar_protocolos', 'Configurar os protocolos (temas, assuntos e status)', 'Alunos', 18);
INSERT INTO perfil_permissoes (perfil_id, permissao_id)
SELECT DISTINCT pp.perfil_id, np.id
FROM perfil_permissoes pp JOIN permissoes p ON p.id = pp.permissao_id
JOIN permissoes np ON np.chave IN ('ver_protocolos', 'abrir_protocolo')
WHERE p.chave = 'ver_alunos';
-- Administrador já pode tudo; marca para a tela de Perfis mostrar.
INSERT IGNORE INTO perfil_permissoes (perfil_id, permissao_id)
SELECT pf.id, p.id FROM perfis pf JOIN permissoes p ON p.chave IN ('ver_protocolos', 'abrir_protocolo', 'configurar_protocolos') WHERE pf.administrador = 1;
