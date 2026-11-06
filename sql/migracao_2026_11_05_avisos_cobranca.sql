-- Avisos automáticos de cobrança (Financeiro › Avisos automáticos): regras
-- que mandam e-mail e/ou WhatsApp sozinhas para o aluno. Quantas regras
-- quiser, cada uma com o momento (gatilho + dias), o meio e o texto.
-- Quem envia é a rotina "emails" do cron (scripts/rotinas.php), a cada 5 min.

CREATE TABLE cobranca_avisos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(120) NOT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    -- emissao = boleto/Pix registrado no banco pela secretaria;
    -- vencimento = lembretes em relação ao vencimento, nos dias de "dias";
    -- pagamento = pagamento confirmado (baixa pelo banco ou manual).
    gatilho ENUM('emissao', 'vencimento', 'pagamento') NOT NULL,
    -- Lembretes de vencimento: lista "-5,-2,0,3" (5 e 2 dias antes, no dia, 3 depois).
    dias VARCHAR(100) NOT NULL DEFAULT '',
    -- Lembretes de vencimento: a partir de que hora do dia sai.
    horario TIME NOT NULL DEFAULT '08:00:00',
    -- Lembretes de vencimento: só parcelas com boleto/Pix registrado no banco.
    so_com_boleto TINYINT(1) NOT NULL DEFAULT 1,
    enviar_email TINYINT(1) NOT NULL DEFAULT 1,
    enviar_whatsapp TINYINT(1) NOT NULL DEFAULT 0,
    email_assunto VARCHAR(250) DEFAULT NULL,
    email_html MEDIUMTEXT DEFAULT NULL,
    anexar_boleto TINYINT(1) NOT NULL DEFAULT 1,
    whatsapp_texto TEXT DEFAULT NULL,
    -- Quando foi ligada: emissão e pagamento só contam o que veio depois
    -- (ligar uma regra não manda aviso de coisa antiga).
    ativado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    usuario_nome VARCHAR(150) DEFAULT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Um por regra + parcela + meio + referência (o vencimento avisado, o
-- boleto emitido "cb:ID" ou o pagamento "pago:DATA"): é o que impede mandar
-- o mesmo aviso duas vezes. Erro tenta de novo até 3 vezes.
CREATE TABLE cobranca_avisos_envios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    aviso_id INT NOT NULL,
    conta_receber_id INT NOT NULL,
    aluno_id INT DEFAULT NULL,
    canal ENUM('email', 'whatsapp') NOT NULL,
    referencia VARCHAR(30) NOT NULL,
    destino VARCHAR(150) DEFAULT NULL,
    status ENUM('enviado', 'erro', 'sem_contato') NOT NULL,
    erro VARCHAR(255) DEFAULT NULL,
    tentativas INT NOT NULL DEFAULT 1,
    enviado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_aviso_envio (aviso_id, conta_receber_id, canal, referencia),
    KEY idx_aviso_envio_data (enviado_em),
    KEY idx_aviso_envio_canal (canal, status, enviado_em),
    CONSTRAINT fk_aviso_envio_aviso FOREIGN KEY (aviso_id) REFERENCES cobranca_avisos(id) ON DELETE CASCADE,
    CONSTRAINT fk_aviso_envio_parcela FOREIGN KEY (conta_receber_id) REFERENCES contas_receber(id) ON DELETE CASCADE,
    CONSTRAINT fk_aviso_envio_aluno FOREIGN KEY (aluno_id) REFERENCES alunos(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Configurar é só para quem o perfil liberar (administrador sempre pode).
INSERT INTO permissoes (chave, descricao, grupo, ordem)
VALUES ('configurar_avisos_cobranca', 'Configurar os avisos automáticos de cobrança (e-mail e WhatsApp para o aluno)', 'Financeiro dos alunos', 56);
INSERT INTO perfil_permissoes (perfil_id, permissao_id)
SELECT pf.id, p.id FROM perfis pf JOIN permissoes p ON p.chave = 'configurar_avisos_cobranca' WHERE pf.administrador = 1;
