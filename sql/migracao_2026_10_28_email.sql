-- E-mail no sistema (menu E-mail): cada usuário usa a SUA caixa (e-mail e
-- senha no cadastro dele) para ler (IMAP) e enviar (SMTP), com anexos; e o
-- disparo em massa para alunos, com tags ({nome_aluno}, {turma}...).

-- Servidor das caixas (Configurações › E-mail). Vale para todos os
-- usuários: só o e-mail e a senha mudam de um para outro.
ALTER TABLE configuracoes
    ADD COLUMN email_imap_host VARCHAR(150) DEFAULT NULL,
    ADD COLUMN email_imap_porta INT NOT NULL DEFAULT 993,
    ADD COLUMN email_imap_seguranca VARCHAR(10) NOT NULL DEFAULT 'ssl',
    ADD COLUMN email_smtp_host VARCHAR(150) DEFAULT NULL,
    ADD COLUMN email_smtp_porta INT NOT NULL DEFAULT 465,
    ADD COLUMN email_smtp_seguranca VARCHAR(10) NOT NULL DEFAULT 'ssl',
    -- 0 = aceita certificado do servidor sem conferir (servidor com
    -- certificado de outro nome, comum em hospedagem compartilhada).
    ADD COLUMN email_verificar_certificado TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN email_anexo_max_mb INT NOT NULL DEFAULT 20,
    -- Disparo em massa: e-mails por hora (limite do provedor) e de qual caixa sai.
    ADD COLUMN email_disparo_por_hora INT NOT NULL DEFAULT 150,
    ADD COLUMN email_disparo_remetente ENUM('sistema', 'usuario') NOT NULL DEFAULT 'usuario';

-- Caixa de cada usuário. Sem email_caixa, usa o e-mail do login.
ALTER TABLE usuarios
    ADD COLUMN email_caixa VARCHAR(150) DEFAULT NULL,
    ADD COLUMN email_caixa_senha_cripto TEXT DEFAULT NULL,
    ADD COLUMN email_remetente_nome VARCHAR(150) DEFAULT NULL,
    ADD COLUMN email_assinatura MEDIUMTEXT DEFAULT NULL;

-- Disparo em massa. publico: turma | curso | andamento (alunos com turma em
-- andamento) | todos (todos os alunos ativos com e-mail) | alunos
-- (escolhidos). Os ids escolhidos ficam em filtro (JSON). anexos: JSON com
-- os arquivos guardados em storage/email_disparos/{id}/.
CREATE TABLE email_disparos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    assunto VARCHAR(250) NOT NULL,
    corpo_html MEDIUMTEXT NOT NULL,
    publico ENUM('turma', 'curso', 'andamento', 'todos', 'alunos') NOT NULL,
    filtro TEXT DEFAULT NULL,
    anexos TEXT DEFAULT NULL,
    remetente ENUM('sistema', 'usuario') NOT NULL DEFAULT 'usuario',
    usuario_id INT DEFAULT NULL,
    usuario_nome VARCHAR(150) DEFAULT NULL,
    status ENUM('enviando', 'pausado', 'concluido', 'cancelado') NOT NULL DEFAULT 'enviando',
    total INT NOT NULL DEFAULT 0,
    enviados INT NOT NULL DEFAULT 0,
    falhas INT NOT NULL DEFAULT 0,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    concluido_em DATETIME DEFAULT NULL,
    KEY idx_email_disparos_status (status),
    CONSTRAINT fk_email_disparo_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Um por aluno: as tags já ficam resolvidas na hora do envio. turma_id =
-- a turma que deu origem (para {turma}, {curso}...).
CREATE TABLE email_disparo_destinos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    disparo_id INT NOT NULL,
    aluno_id INT DEFAULT NULL,
    turma_id INT DEFAULT NULL,
    nome VARCHAR(150) NOT NULL,
    email VARCHAR(150) NOT NULL,
    status ENUM('pendente', 'enviado', 'erro') NOT NULL DEFAULT 'pendente',
    erro VARCHAR(255) DEFAULT NULL,
    enviado_em DATETIME DEFAULT NULL,
    KEY idx_email_destinos_fila (disparo_id, status),
    KEY idx_email_destinos_enviado (status, enviado_em),
    CONSTRAINT fk_email_destino_disparo FOREIGN KEY (disparo_id) REFERENCES email_disparos(id) ON DELETE CASCADE,
    CONSTRAINT fk_email_destino_aluno FOREIGN KEY (aluno_id) REFERENCES alunos(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissoes (chave, descricao, grupo, ordem) VALUES
('usar_email', 'Usar a própria caixa de e-mail no sistema (menu E-mail)', 'E-mail', 90),
('disparar_email', 'Disparar e-mails em massa para alunos (turma, curso, todos)', 'E-mail', 91);
-- Caixa própria: todos os perfis. Disparo: administradores e quem já envia avisos.
INSERT INTO perfil_permissoes (perfil_id, permissao_id)
SELECT pf.id, p.id FROM perfis pf JOIN permissoes p ON p.chave = 'usar_email';
INSERT INTO perfil_permissoes (perfil_id, permissao_id)
SELECT DISTINCT pp.perfil_id, (SELECT id FROM permissoes WHERE chave = 'disparar_email')
FROM perfil_permissoes pp JOIN permissoes p ON p.id = pp.permissao_id
WHERE p.chave = 'gerenciar_avisos';
INSERT IGNORE INTO perfil_permissoes (perfil_id, permissao_id)
SELECT pf.id, p.id FROM perfis pf JOIN permissoes p ON p.chave = 'disparar_email' WHERE pf.administrador = 1;
