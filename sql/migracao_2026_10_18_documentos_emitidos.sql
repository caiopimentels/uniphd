-- Documentos emitidos para o aluno (certificado, declarações de módulo...):
-- o curso diz quais documentos emite, com qual modelo e quando ficam
-- disponíveis (ao concluir o curso ou ao concluir um módulo). Cada documento
-- liberado para uma matrícula é uma linha em "certificados" (com o código de
-- verificação). Os certificados que já existem continuam com o mesmo código.
-- Para quem já tem o sistema instalado; instalação nova: o schema.sql já cria.
-- Uso: mysql -u USUARIO -p NOME_DO_BANCO < sql/migracao_2026_10_18_documentos_emitidos.sql

ALTER TABLE certificado_modelos ADD COLUMN orientacao ENUM('horizontal', 'vertical') NOT NULL DEFAULT 'horizontal' AFTER nome;

ALTER TABLE cursos
    ADD COLUMN liberar_documentos_auto TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN bloquear_inadimplente TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN bloquear_pendencia_documental TINYINT(1) NOT NULL DEFAULT 1;

CREATE TABLE IF NOT EXISTS curso_emissoes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    curso_id INT NOT NULL,
    nome VARCHAR(120) NOT NULL,
    modelo_id INT DEFAULT NULL,
    modulo_id INT DEFAULT NULL,
    ordem INT NOT NULL DEFAULT 0,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_curso_emissoes (curso_id, ativo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS turma_modulos (
    turma_id INT NOT NULL,
    curso_modulo_id INT NOT NULL,
    concluido_em DATE NOT NULL,
    concluido_por VARCHAR(150) DEFAULT NULL,
    PRIMARY KEY (turma_id, curso_modulo_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE certificados
    ADD COLUMN curso_emissao_id INT DEFAULT NULL AFTER matricula_id,
    ADD COLUMN liberado TINYINT(1) NOT NULL DEFAULT 1 AFTER codigo,
    ADD COLUMN liberado_em DATETIME DEFAULT NULL AFTER liberado,
    ADD COLUMN liberado_por VARCHAR(150) DEFAULT NULL AFTER liberado_em,
    ADD UNIQUE KEY uk_certificado_documento (matricula_id, curso_emissao_id);
ALTER TABLE certificados DROP INDEX matricula_id;

-- Todo curso ganha o "Certificado de conclusão", com o modelo que já usava.
INSERT INTO curso_emissoes (curso_id, nome, modelo_id, modulo_id, ordem)
SELECT c.id, 'Certificado de conclusão', c.certificado_modelo_id, NULL, 10 FROM cursos c
WHERE NOT EXISTS (SELECT 1 FROM curso_emissoes e WHERE e.curso_id = c.id AND e.modulo_id IS NULL);

-- Certificados já emitidos passam a ser desse documento, com a liberação de hoje.
UPDATE certificados ce
JOIN matriculas m ON m.id = ce.matricula_id
JOIN turmas t ON t.id = m.turma_id
JOIN curso_emissoes e ON e.curso_id = t.curso_id AND e.modulo_id IS NULL
SET ce.curso_emissao_id = e.id, ce.liberado = m.certificado_liberado, ce.liberado_em = ce.emitido_em
WHERE ce.curso_emissao_id IS NULL;

-- Matrículas concluídas com o certificado liberado que ainda não tinham sido abertas.
INSERT INTO certificados (matricula_id, curso_emissao_id, codigo, liberado, liberado_em, liberado_por)
SELECT m.id, e.id, SUBSTRING(UPPER(SHA2(CONCAT(m.id, '-', e.id, '-', RAND(), '-', NOW(6)), 256)), 1, 12), 1, NOW(), 'Sistema'
FROM matriculas m JOIN turmas t ON t.id = m.turma_id JOIN curso_emissoes e ON e.curso_id = t.curso_id AND e.modulo_id IS NULL
WHERE m.status = 'concluida' AND m.certificado_liberado = 1
  AND NOT EXISTS (SELECT 1 FROM certificados ce WHERE ce.matricula_id = m.id AND ce.curso_emissao_id = e.id);

UPDATE permissoes SET descricao = 'Liberar e bloquear documentos dos alunos (certificados e declarações)' WHERE chave = 'liberar_certificado';
