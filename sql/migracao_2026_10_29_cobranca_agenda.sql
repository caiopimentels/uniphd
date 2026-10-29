-- Agenda de cobrança (Financeiro › Inadimplência): cada contato de cobrança
-- com o aluno (o que foi dito, a resposta, a situação) e a data marcada
-- para a próxima cobrança. As cobranças enviadas pelo sistema (WhatsApp /
-- e-mail) entram sozinhas, com automatico = 1.

CREATE TABLE cobranca_registros (
    id INT AUTO_INCREMENT PRIMARY KEY,
    aluno_id INT NOT NULL,
    canal ENUM('whatsapp', 'email', 'telefone', 'presencial', 'outro') NOT NULL,
    automatico TINYINT(1) NOT NULL DEFAULT 0,
    contato_em DATETIME NOT NULL,
    descricao TEXT NOT NULL,
    resposta TEXT DEFAULT NULL,
    -- sem_resposta | respondeu | prometeu (pagar até promessa_data) | recusou
    situacao ENUM('sem_resposta', 'respondeu', 'prometeu', 'recusou') NOT NULL DEFAULT 'sem_resposta',
    promessa_data DATE DEFAULT NULL,
    usuario_id INT DEFAULT NULL,
    usuario_nome VARCHAR(150) DEFAULT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_cobranca_registros_aluno (aluno_id, contato_em),
    CONSTRAINT fk_cobranca_registro_aluno FOREIGN KEY (aluno_id) REFERENCES alunos(id) ON DELETE CASCADE,
    CONSTRAINT fk_cobranca_registro_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Próxima cobrança marcada (uma por aluno).
CREATE TABLE cobranca_agenda (
    aluno_id INT PRIMARY KEY,
    data DATE NOT NULL,
    nota VARCHAR(255) DEFAULT NULL,
    usuario_id INT DEFAULT NULL,
    usuario_nome VARCHAR(150) DEFAULT NULL,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_cobranca_agenda_data (data),
    CONSTRAINT fk_cobranca_agenda_aluno FOREIGN KEY (aluno_id) REFERENCES alunos(id) ON DELETE CASCADE,
    CONSTRAINT fk_cobranca_agenda_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
