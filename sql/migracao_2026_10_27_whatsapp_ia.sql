-- Atendimento por WhatsApp com IA. A mensagem chega pela Evolution API
-- (instalada no servidor, conectada ao número por QR Code), a IA (Groq ou
-- Gemini, planos gratuitos) responde sobre cursos e inscrição e, quando não
-- souber ou a pessoa pedir, passa para um atendente (Secretaria › WhatsApp).

-- Configuração (uma linha só). Chaves guardadas criptografadas.
CREATE TABLE whatsapp_config (
    id INT PRIMARY KEY DEFAULT 1,
    api_url VARCHAR(255) DEFAULT NULL,
    api_chave TEXT DEFAULT NULL,
    instancia VARCHAR(60) DEFAULT NULL,
    -- Token que a Evolution API manda na URL do webhook (confere quem chama).
    webhook_token CHAR(40) DEFAULT NULL,
    -- Endereço do sistema visto pela Evolution API (em branco = o do próprio
    -- sistema). No teste local com Docker: http://host.docker.internal:8000
    webhook_base VARCHAR(255) DEFAULT NULL,
    ia_ativa TINYINT(1) NOT NULL DEFAULT 0,
    -- Modo teste: a IA só responde aos números listados (um por linha).
    modo_teste TINYINT(1) NOT NULL DEFAULT 1,
    numeros_teste VARCHAR(500) DEFAULT NULL,
    groq_chave TEXT DEFAULT NULL,
    gemini_chave TEXT DEFAULT NULL,
    -- Lista de IAs em ordem de preferência (quantas quiser), JSON: [{"provedor":
    -- "groq"|"gemini", "modelo": "..."}]. Cada modelo tem o seu limite
    -- gratuito: estourou um, desce para o próximo.
    ia_modelos TEXT DEFAULT NULL,
    -- Treino da IA: o que ela sabe/responde (jeito de falar, perguntas
    -- frequentes) e o que ela não deve responder (passa para a equipe).
    ia_instrucoes TEXT DEFAULT NULL,
    ia_proibido TEXT DEFAULT NULL,
    mensagem_transferencia VARCHAR(255) NOT NULL DEFAULT 'Vou chamar alguém da nossa equipe para te ajudar. Em breve te respondemos por aqui!',
    -- Quando a IA não sabe responder: avisa estes números por WhatsApp (um
    -- por linha) e, se ia_continua_apos_aviso, segue respondendo as outras
    -- dúvidas enquanto ninguém da equipe assume.
    aviso_numeros VARCHAR(500) DEFAULT NULL,
    ia_continua_apos_aviso TINYINT(1) NOT NULL DEFAULT 0,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO whatsapp_config (id) VALUES (1);

-- Uma conversa por número. modo: ia (a IA responde) ou humano (só o
-- atendente). aguardando: a IA pediu um atendente e ninguém respondeu ainda.
CREATE TABLE whatsapp_conversas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    numero VARCHAR(30) NOT NULL UNIQUE,
    nome VARCHAR(150) DEFAULT NULL,
    aluno_id INT DEFAULT NULL,
    modo ENUM('ia', 'humano') NOT NULL DEFAULT 'ia',
    aguardando TINYINT(1) NOT NULL DEFAULT 0,
    nao_lidas INT NOT NULL DEFAULT 0,
    ultima_mensagem_em DATETIME DEFAULT NULL,
    -- Último aviso à equipe por WhatsApp (no máximo um a cada 30 minutos).
    ultimo_aviso_em DATETIME DEFAULT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_whatsapp_conversas_ultima (ultima_mensagem_em),
    CONSTRAINT fk_whatsapp_conversa_aluno FOREIGN KEY (aluno_id) REFERENCES alunos(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- autor: cliente, ia, atendente (pelo sistema ou pelo celular) ou sistema.
-- id_externo: id da mensagem no WhatsApp (não grava a mesma duas vezes).
CREATE TABLE whatsapp_mensagens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    conversa_id INT NOT NULL,
    autor ENUM('cliente', 'ia', 'atendente', 'sistema') NOT NULL,
    usuario_id INT DEFAULT NULL,
    autor_nome VARCHAR(150) DEFAULT NULL,
    texto TEXT DEFAULT NULL,
    tipo VARCHAR(20) NOT NULL DEFAULT 'texto',
    id_externo VARCHAR(100) DEFAULT NULL UNIQUE,
    erro VARCHAR(255) DEFAULT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_whatsapp_mensagens (conversa_id, id),
    CONSTRAINT fk_whatsapp_mensagem_conversa FOREIGN KEY (conversa_id) REFERENCES whatsapp_conversas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Consumo das IAs por dia e modelo (tokens e respostas), para acompanhar o
-- limite dos planos gratuitos.
CREATE TABLE whatsapp_uso_ia (
    data DATE NOT NULL,
    provedor VARCHAR(20) NOT NULL,
    modelo VARCHAR(80) NOT NULL,
    respostas INT NOT NULL DEFAULT 0,
    tokens INT NOT NULL DEFAULT 0,
    falhas INT NOT NULL DEFAULT 0,
    PRIMARY KEY (data, provedor, modelo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Modelo que bateu o limite fica pausado até "ate" (o próximo da lista
-- responde nesse meio-tempo, sem perder tempo tentando o pausado).
CREATE TABLE whatsapp_ia_pausa (
    provedor VARCHAR(20) NOT NULL,
    modelo VARCHAR(80) NOT NULL,
    ate DATETIME NOT NULL,
    motivo VARCHAR(255) DEFAULT NULL,
    PRIMARY KEY (provedor, modelo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Permissões: atender (quem já responde protocolos recebe) e configurar.
INSERT INTO permissoes (chave, descricao, grupo, ordem) VALUES
('atender_whatsapp', 'Atender as conversas do WhatsApp', 'Alunos', 19),
('configurar_whatsapp', 'Configurar o WhatsApp e a IA de atendimento', 'Alunos', 20);
INSERT INTO perfil_permissoes (perfil_id, permissao_id)
SELECT DISTINCT pp.perfil_id, (SELECT id FROM permissoes WHERE chave = 'atender_whatsapp')
FROM perfil_permissoes pp JOIN permissoes p ON p.id = pp.permissao_id
WHERE p.chave = 'ver_protocolos';
INSERT IGNORE INTO perfil_permissoes (perfil_id, permissao_id)
SELECT pf.id, p.id FROM perfis pf JOIN permissoes p ON p.chave IN ('atender_whatsapp', 'configurar_whatsapp') WHERE pf.administrador = 1;
