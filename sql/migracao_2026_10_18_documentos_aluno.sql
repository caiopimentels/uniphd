-- Documentos que o aluno precisa enviar em certos cursos (diploma, RG...):
-- o curso define quais (nome, tipos de arquivo aceitos, se trava o
-- certificado) e o aluno envia pelo portal. Os arquivos ficam em storage/
-- (fora do site), comprimidos.
-- Para quem já tem o sistema instalado; instalação nova: o schema.sql já cria.
-- Uso: mysql -u USUARIO -p NOME_DO_BANCO < sql/migracao_2026_10_18_documentos_aluno.sql

CREATE TABLE IF NOT EXISTS curso_documentos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    curso_id INT NOT NULL,
    nome VARCHAR(120) NOT NULL,
    instrucao VARCHAR(255) DEFAULT NULL,
    aceita_pdf TINYINT(1) NOT NULL DEFAULT 1,
    aceita_imagem TINYINT(1) NOT NULL DEFAULT 1,
    trava_certificado TINYINT(1) NOT NULL DEFAULT 1,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    ordem INT NOT NULL DEFAULT 0,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_curso_documentos (curso_id, ativo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS aluno_documentos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    aluno_id INT NOT NULL,
    curso_documento_id INT NOT NULL,
    status ENUM('enviado', 'aprovado', 'recusado', 'dispensado') NOT NULL DEFAULT 'enviado',
    arquivo VARCHAR(255) DEFAULT NULL,
    nome_original VARCHAR(200) DEFAULT NULL,
    mime VARCHAR(40) DEFAULT NULL,
    tamanho_original INT DEFAULT NULL,
    tamanho INT DEFAULT NULL,
    motivo VARCHAR(255) DEFAULT NULL,
    enviado_por VARCHAR(150) DEFAULT NULL,
    enviado_em DATETIME DEFAULT NULL,
    avaliado_por VARCHAR(150) DEFAULT NULL,
    avaliado_em DATETIME DEFAULT NULL,
    UNIQUE KEY uk_aluno_documento (aluno_id, curso_documento_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO permissoes (chave, descricao, grupo, ordem)
VALUES ('avaliar_documentos', 'Aprovar, recusar ou dispensar documentos dos alunos', 'Alunos', 15);
INSERT IGNORE INTO perfil_permissoes (perfil_id, permissao_id)
SELECT 1, id FROM permissoes WHERE chave = 'avaliar_documentos';

-- Anexos que a secretaria guarda na ficha do aluno (contrato, declaração...).
-- visivel_aluno = o aluno vê e baixa no portal ("Meus documentos").
CREATE TABLE IF NOT EXISTS aluno_anexos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    aluno_id INT NOT NULL,
    nome VARCHAR(150) NOT NULL,
    arquivo VARCHAR(255) NOT NULL,
    nome_original VARCHAR(200) DEFAULT NULL,
    mime VARCHAR(40) NOT NULL,
    tamanho_original INT DEFAULT NULL,
    tamanho INT DEFAULT NULL,
    visivel_aluno TINYINT(1) NOT NULL DEFAULT 0,
    enviado_por VARCHAR(150) DEFAULT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_aluno_anexos (aluno_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
