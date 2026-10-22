-- Sistema DY - Gestão acadêmica e financeira (cursos, turmas, matrículas)
--
-- Arquivo ÚNICO de inicialização: cria todas as tabelas já na estrutura
-- final, com os dados iniciais.
--
-- O nome do banco muda por instalação (vem de DB_NAME em config/config.php),
-- por isso este arquivo NÃO cria nem apaga banco — rode contra um banco vazio:
--
--   mysql -u root -e "CREATE DATABASE nome_do_banco CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
--   mysql -u root nome_do_banco < sql/schema.sql
--
-- Num banco que já tem as tabelas, o primeiro CREATE TABLE falha e nada é
-- sobrescrito.

SET NAMES utf8mb4;

-- ======================================================================
-- ACESSO: perfis, permissões e usuários
-- ======================================================================

-- ----------------------------------------------------------------------
-- Perfis de usuário. desconto_max é a alçada: o maior desconto/bolsa (%)
-- que quem tem este perfil pode conceder numa matrícula.
-- ----------------------------------------------------------------------
CREATE TABLE perfis (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    administrador TINYINT(1) NOT NULL DEFAULT 0,
    desconto_max DECIMAL(5,2) NOT NULL DEFAULT 0,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------
-- Permissões granulares: cada item de menu/ação exige permissão explícita
-- do perfil (administrador tem bypass total no código).
-- ----------------------------------------------------------------------
CREATE TABLE permissoes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    chave VARCHAR(50) NOT NULL UNIQUE,
    descricao VARCHAR(150) NOT NULL,
    -- Área e posição na tela de Perfis.
    grupo VARCHAR(40) NOT NULL DEFAULT 'Geral',
    ordem INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE perfil_permissoes (
    perfil_id INT NOT NULL,
    permissao_id INT NOT NULL,
    PRIMARY KEY (perfil_id, permissao_id),
    CONSTRAINT fk_pp_perfil FOREIGN KEY (perfil_id) REFERENCES perfis(id) ON DELETE CASCADE,
    CONSTRAINT fk_pp_permissao FOREIGN KEY (permissao_id) REFERENCES permissoes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Quais perfis cada perfil pode dar a usuários que ele cadastra/edita (ex.:
-- Gerente pode criar Secretaria e Financeiro; Secretaria só Secretaria).
-- Administrador pode todos; perfil de administrador só um administrador dá.
CREATE TABLE perfil_perfis_gerenciaveis (
    perfil_id INT NOT NULL,
    perfil_alvo_id INT NOT NULL,
    PRIMARY KEY (perfil_id, perfil_alvo_id),
    CONSTRAINT fk_ppg_perfil FOREIGN KEY (perfil_id) REFERENCES perfis(id) ON DELETE CASCADE,
    CONSTRAINT fk_ppg_alvo FOREIGN KEY (perfil_alvo_id) REFERENCES perfis(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(150) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    senha VARCHAR(255) NOT NULL,
    perfil_id INT NOT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    -- Impede login simultâneo: um novo login gera token novo e "derruba" a
    -- sessão anterior na próxima ação dela.
    token_sessao VARCHAR(64) DEFAULT NULL,
    tema ENUM('claro', 'escuro') NOT NULL DEFAULT 'claro',
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_usuario_perfil FOREIGN KEY (perfil_id) REFERENCES perfis(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Colunas visíveis (e a ordem) nas listas, por usuário; a impressão segue.
CREATE TABLE usuario_colunas_lista (
    usuario_id INT NOT NULL,
    tela VARCHAR(40) NOT NULL,
    colunas VARCHAR(500) NOT NULL, -- chaves separadas por vírgula, na ordem
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (usuario_id, tela),
    CONSTRAINT fk_colunas_lista_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE usuarios_reset_senha (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expira_em DATETIME NOT NULL,
    usado_em DATETIME DEFAULT NULL,
    INDEX idx_reset_senha_usuario (usuario_id),
    CONSTRAINT fk_reset_senha_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------
-- Limite de tentativas de login e de recuperação de senha (força bruta /
-- spam de e-mail de recuperação).
-- ----------------------------------------------------------------------
CREATE TABLE tentativas_acesso (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tipo VARCHAR(30) NOT NULL,
    identificador VARCHAR(190) NOT NULL,
    ip VARCHAR(45) NOT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tentativas_tipo_id (tipo, identificador, criado_em),
    INDEX idx_tentativas_tipo_ip (tipo, ip, criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------
-- Auditoria: quem fez o quê e quando, em todos os módulos. Sem FK em
-- usuario_id e com usuario_nome copiado de propósito: o registro precisa
-- sobreviver à exclusão/renomeação do usuário.
-- ----------------------------------------------------------------------
CREATE TABLE log_atividades (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT DEFAULT NULL,
    usuario_nome VARCHAR(150) NOT NULL,
    modulo VARCHAR(40) NOT NULL,
    operacao VARCHAR(40) NOT NULL,
    descricao VARCHAR(255) NOT NULL,
    ip VARCHAR(45) DEFAULT NULL,
    user_agent VARCHAR(255) DEFAULT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_log_modulo (modulo, criado_em),
    INDEX idx_log_usuario (usuario_id, criado_em),
    INDEX idx_log_criado (criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ======================================================================
-- CONFIGURAÇÕES E NOTIFICAÇÕES
-- ======================================================================

-- ----------------------------------------------------------------------
-- Configurações gerais (linha única, id sempre 1 — config/config.php lê
-- esta linha no boot, então ela PRECISA existir).
-- ----------------------------------------------------------------------
CREATE TABLE configuracoes (
    id INT PRIMARY KEY DEFAULT 1,
    nome_sistema VARCHAR(100) NOT NULL DEFAULT 'Sistema DY',

    -- Identidade visual do painel
    logo_sistema VARCHAR(255) DEFAULT NULL,
    cor_primaria VARCHAR(7) NOT NULL DEFAULT '#16a34a',
    cor_secundaria VARCHAR(7) NOT NULL DEFAULT '#15803d',
    logo_pos_x TINYINT UNSIGNED NOT NULL DEFAULT 50,
    logo_pos_y TINYINT UNSIGNED NOT NULL DEFAULT 50,
    logo_zoom DECIMAL(4,2) NOT NULL DEFAULT 1.00,

    -- Dados da empresa (beneficiário nas cobranças bancárias)
    empresa_razao_social VARCHAR(150) DEFAULT NULL,
    empresa_nome_fantasia VARCHAR(150) DEFAULT NULL,
    empresa_cnpj VARCHAR(18) DEFAULT NULL,
    empresa_inscricao_municipal VARCHAR(30) DEFAULT NULL,
    empresa_email VARCHAR(150) DEFAULT NULL,
    empresa_telefone VARCHAR(20) DEFAULT NULL,
    empresa_cep VARCHAR(10) DEFAULT NULL,
    empresa_logradouro VARCHAR(150) DEFAULT NULL,
    empresa_numero VARCHAR(20) DEFAULT NULL,
    empresa_complemento VARCHAR(100) DEFAULT NULL,
    empresa_bairro VARCHAR(100) DEFAULT NULL,
    empresa_cidade VARCHAR(100) DEFAULT NULL,
    empresa_uf CHAR(2) DEFAULT NULL,

    -- Matrícula: dia do mês sugerido para o vencimento das mensalidades.
    dia_vencimento_padrao TINYINT UNSIGNED NOT NULL DEFAULT 10,
    -- Aluno com parcela vencida: permitir, bloquear ou só com autorização
    -- de administrador (liberacoes_inadimplencia). No portal ele não se inscreve.
    matricula_inadimplente ENUM('permitir', 'bloquear', 'autorizar') NOT NULL DEFAULT 'permitir',

    -- Sessão e contato
    sessao_timeout_minutos INT NOT NULL DEFAULT 30,
    whatsapp_numero VARCHAR(20) DEFAULT NULL,

    -- E-mail (SMTP). Em branco, email_notificacao_erros usa o remetente.
    smtp_host VARCHAR(150) DEFAULT NULL,
    smtp_porta INT NOT NULL DEFAULT 587,
    smtp_seguranca VARCHAR(10) NOT NULL DEFAULT 'tls',
    smtp_usuario VARCHAR(150) DEFAULT NULL,
    smtp_senha VARCHAR(255) DEFAULT NULL,
    smtp_remetente_nome VARCHAR(150) DEFAULT NULL,
    smtp_remetente_email VARCHAR(150) DEFAULT NULL,
    email_notificacao_erros VARCHAR(150) DEFAULT NULL,

    -- Portal do aluno e verificação de certificado (os modelos ficam em
    -- certificado_modelos).
    portal_titulo VARCHAR(150) DEFAULT NULL,
    portal_subtitulo VARCHAR(255) DEFAULT NULL,
    -- Endereço próprio do portal (links para o aluno; raiz do domínio vai ao portal).
    portal_url VARCHAR(255) DEFAULT NULL,
    -- Cores do portal; em branco usa as do sistema (cor_primaria/secundaria).
    portal_cor_primaria VARCHAR(7) DEFAULT NULL,
    portal_cor_secundaria VARCHAR(7) DEFAULT NULL,
    portal_cadastro_aberto TINYINT(1) NOT NULL DEFAULT 1,
    portal_rodape_texto TEXT DEFAULT NULL,
    portal_rodape_contatos TINYINT(1) NOT NULL DEFAULT 1,
    -- O que aparece no rodapé, item a item (ver itensRodapePortal()).
    portal_rodape_itens VARCHAR(255) DEFAULT NULL,
    portal_redes TEXT DEFAULT NULL,
    -- Em branco: <endereço do sistema>/portal/verificar.php
    certificado_url_verificacao VARCHAR(255) DEFAULT NULL,

    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------
-- Notificações. A leitura é por usuário — a mesma notificação pode estar
-- lida para um e não para outro — por isso a tabela separada em vez de um
-- campo "lida".
-- ----------------------------------------------------------------------
CREATE TABLE notificacoes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tipo VARCHAR(30) NOT NULL,
    titulo VARCHAR(150) NOT NULL,
    descricao VARCHAR(255) DEFAULT NULL,
    link VARCHAR(255) DEFAULT NULL,
    referencia_id INT DEFAULT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_notificacoes_criado (criado_em),
    INDEX idx_notificacoes_tipo (tipo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notificacoes_lidas (
    notificacao_id INT NOT NULL,
    usuario_id INT NOT NULL,
    lida_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (notificacao_id, usuario_id),
    CONSTRAINT fk_notlida_notificacao FOREIGN KEY (notificacao_id) REFERENCES notificacoes(id) ON DELETE CASCADE,
    CONSTRAINT fk_notlida_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ======================================================================
-- CADASTROS
-- ======================================================================

CREATE TABLE tipos_graduacao (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(120) NOT NULL,
    no_site TINYINT(1) NOT NULL DEFAULT 1,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    ordem INT NOT NULL DEFAULT 0,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE alunos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ra VARCHAR(9) DEFAULT NULL UNIQUE,
    nome VARCHAR(150) NOT NULL,
    data_nascimento DATE DEFAULT NULL,
    cpf VARCHAR(14) DEFAULT NULL, -- só números (a máscara é da tela)
    rg VARCHAR(20) DEFAULT NULL,
    nome_mae VARCHAR(150) DEFAULT NULL,
    nome_pai VARCHAR(150) DEFAULT NULL,
    possui_graduacao TINYINT(1) DEFAULT NULL,
    graduacao_id INT DEFAULT NULL,
    termo_certificacao_em DATETIME DEFAULT NULL,
    telefone VARCHAR(20) DEFAULT NULL, -- só números
    email VARCHAR(150) DEFAULT NULL,
    -- Portal do aluno: entra com CPF + esta senha (password_hash).
    senha_hash VARCHAR(255) DEFAULT NULL,
    -- Senha criada pela secretaria: o portal pede para trocar no 1º acesso.
    senha_provisoria TINYINT(1) NOT NULL DEFAULT 0,
    portal_ultimo_acesso DATETIME DEFAULT NULL,
    observacao VARCHAR(255) DEFAULT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_alunos_nome (nome),
    KEY idx_alunos_cpf (cpf)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- alunos.telefone é o principal; aqui ficam os extras.
CREATE TABLE aluno_telefones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    aluno_id INT NOT NULL,
    telefone VARCHAR(20) NOT NULL, -- só números
    CONSTRAINT fk_aluno_telefone_aluno FOREIGN KEY (aluno_id) REFERENCES alunos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE aluno_enderecos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    aluno_id INT NOT NULL,
    apelido VARCHAR(50) DEFAULT NULL,
    cep VARCHAR(10) DEFAULT NULL,
    logradouro VARCHAR(150) DEFAULT NULL,
    numero VARCHAR(20) DEFAULT NULL,
    complemento VARCHAR(100) DEFAULT NULL,
    bairro VARCHAR(100) DEFAULT NULL,
    cidade VARCHAR(100) DEFAULT NULL,
    uf CHAR(2) DEFAULT NULL,
    principal TINYINT(1) NOT NULL DEFAULT 0,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_aluno_endereco_aluno FOREIGN KEY (aluno_id) REFERENCES alunos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE professores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(150) NOT NULL,
    cpf VARCHAR(14) DEFAULT NULL,
    formacao VARCHAR(150) DEFAULT NULL,
    telefone VARCHAR(20) DEFAULT NULL,
    email VARCHAR(150) DEFAULT NULL,
    observacao VARCHAR(255) DEFAULT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fornecedores = quem a instituição paga (aluguel, material, serviços...).
CREATE TABLE fornecedores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(150) NOT NULL,
    documento VARCHAR(20) DEFAULT NULL,
    telefone VARCHAR(20) DEFAULT NULL,
    email VARCHAR(150) DEFAULT NULL,
    endereco VARCHAR(255) DEFAULT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------
-- Contas bancárias da instituição. Os campos itau_* e de juros/multa só
-- valem quando integracao = 'itau' (registro de boleto pela API de
-- Cobrança do Itaú). proximo_nosso_numero é a sequência de "nosso número"
-- desta conta: o Itaú recusa número repetido na mesma carteira, então ele
-- é reservado com trava de linha no momento da geração da cobrança.
-- ----------------------------------------------------------------------
CREATE TABLE contas_bancarias (
    id INT AUTO_INCREMENT PRIMARY KEY,
    apelido VARCHAR(60) NOT NULL,
    banco_codigo CHAR(3) NOT NULL,
    banco_nome VARCHAR(60) NOT NULL,
    agencia VARCHAR(10) NOT NULL,
    conta VARCHAR(20) NOT NULL,
    conta_digito VARCHAR(2) DEFAULT NULL,
    chave_pix VARCHAR(77) DEFAULT NULL,
    integracao ENUM('nenhuma', 'itau') NOT NULL DEFAULT 'nenhuma',
    itau_carteira VARCHAR(3) NOT NULL DEFAULT '109',
    itau_especie CHAR(2) NOT NULL DEFAULT '04',
    proximo_nosso_numero INT UNSIGNED NOT NULL DEFAULT 1,
    juros_tipo CHAR(2) NOT NULL DEFAULT '05',
    juros_percentual DECIMAL(7,4) NOT NULL DEFAULT 0,
    multa_tipo CHAR(2) NOT NULL DEFAULT '03',
    multa_percentual DECIMAL(7,4) NOT NULL DEFAULT 0,
    tarifa_emissao DECIMAL(10,2) NOT NULL DEFAULT 0,
    tarifa_liquidacao DECIMAL(10,2) NOT NULL DEFAULT 0,
    tarifa_baixa DECIMAL(10,2) NOT NULL DEFAULT 0,
    tarifa_alteracao DECIMAL(10,2) NOT NULL DEFAULT 0,
    -- API do banco (OAuth 2.0 client credentials + certificado mTLS).
    -- Os campos *_cripto são cifrados pela aplicação (includes/cripto.php);
    -- os arquivos do certificado ficam em storage/, fora do public/.
    api_ambiente ENUM('sandbox', 'producao') NOT NULL DEFAULT 'sandbox',
    api_client_id VARCHAR(100) DEFAULT NULL,
    api_client_secret_cripto TEXT DEFAULT NULL,
    api_url_token VARCHAR(255) DEFAULT NULL,
    api_url_base VARCHAR(255) DEFAULT NULL,
    -- Endereço das instruções de boleto (baixa, valor, vencimento); vazio = a URL base.
    itau_url_instrucoes VARCHAR(255) DEFAULT NULL,
    api_certificado_arquivo VARCHAR(255) DEFAULT NULL,
    api_chave_arquivo VARCHAR(255) DEFAULT NULL,
    api_chave_senha_cripto TEXT DEFAULT NULL,
    api_certificado_validade DATE DEFAULT NULL,
    api_certificado_renovacao_em DATETIME DEFAULT NULL,
    api_certificado_renovacao_erro VARCHAR(255) DEFAULT NULL,
    api_certificado_aviso SMALLINT DEFAULT NULL,
    api_token_cripto TEXT DEFAULT NULL,
    api_token_expira_em DATETIME DEFAULT NULL,
    api_ultimo_teste_em DATETIME DEFAULT NULL,
    api_ultimo_teste_ok TINYINT(1) DEFAULT NULL,
    api_ultimo_teste_mensagem VARCHAR(255) DEFAULT NULL,
    -- Webhook de aviso de pagamento: o Itaú pede um token em
    -- /webhook/itau/token.php com este client_id/secret (gerados aqui e
    -- informados a ele no cadastro) e depois chama /webhook/itau/boletos.php.
    webhook_client_id VARCHAR(64) DEFAULT NULL,
    webhook_client_secret_cripto TEXT DEFAULT NULL,
    webhook_cadastrado_em DATETIME DEFAULT NULL,
    webhook_pix_cadastrado_em DATETIME DEFAULT NULL,
    padrao TINYINT(1) NOT NULL DEFAULT 0,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------
-- Formas de pagamento. instrumento diz como a cobrança é gerada: 'manual'
-- (dinheiro, cartão... só registra a baixa), 'boleto', 'boleto_pix'
-- (bolecode: boleto com QR Code Pix) ou 'pix' — e conta_bancaria_id em
-- qual conta ela cai.
-- ----------------------------------------------------------------------
CREATE TABLE formas_pagamento (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(60) NOT NULL,
    instrumento ENUM('manual', 'boleto', 'boleto_pix', 'pix') NOT NULL DEFAULT 'manual',
    conta_bancaria_id INT DEFAULT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_forma_conta_bancaria FOREIGN KEY (conta_bancaria_id) REFERENCES contas_bancarias(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------
-- Categorias financeiras (base do DRE). "chave" marca as categorias que o
-- próprio sistema usa ao gerar lançamentos (mensalidade, taxa de
-- matrícula) — o código as encontra por ela, não pelo nome, que o usuário
-- pode renomear à vontade.
-- ----------------------------------------------------------------------
CREATE TABLE categorias_financeiras (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    tipo ENUM('receita', 'despesa') NOT NULL,
    chave VARCHAR(30) DEFAULT NULL UNIQUE,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ======================================================================
-- ACADÊMICO: cursos, turmas e matrículas
-- ======================================================================

CREATE TABLE cursos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(150) NOT NULL,
    carga_horaria INT DEFAULT NULL,
    descricao TEXT DEFAULT NULL,
    -- Modelo de certificado do curso (NULL = o modelo padrão). A chave
    -- estrangeira é criada no fim, depois de certificado_modelos.
    certificado_modelo_id INT DEFAULT NULL,
    contrato_modelo_id INT DEFAULT NULL,
    liberar_documentos_auto TINYINT(1) NOT NULL DEFAULT 1,
    bloquear_inadimplente TINYINT(1) NOT NULL DEFAULT 0,
    bloquear_pendencia_documental TINYINT(1) NOT NULL DEFAULT 1,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Módulos do curso (conteúdo programático), na ordem de "ordem".
CREATE TABLE curso_modulos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    curso_id INT NOT NULL,
    nome VARCHAR(150) NOT NULL,
    carga_horaria INT DEFAULT NULL,
    ordem INT NOT NULL DEFAULT 0,
    KEY idx_curso_modulos (curso_id, ordem),
    CONSTRAINT fk_curso_modulo_curso FOREIGN KEY (curso_id) REFERENCES cursos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------
-- Situações de turma (cadastráveis: nome, cor, ordem). O nome é livre, mas
-- cada uma aponta para um "comportamento" fixo, que é o que o código usa
-- para as regras — renomear "Em andamento" para "Cursando" não pode fazer
-- a turma parar de aceitar matrícula.
--   aberta       -> turma ainda não começou; aceita matrícula
--   em_andamento -> turma em curso; aceita matrícula
--   concluida    -> ao entrar nela, as matrículas ativas viram concluídas
--   cancelada    -> só pode entrar nela sem matrículas ativas
-- ----------------------------------------------------------------------
CREATE TABLE situacoes_turma (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(60) NOT NULL,
    cor VARCHAR(7) NOT NULL DEFAULT '#6c757d',
    comportamento ENUM('aberta', 'em_andamento', 'concluida', 'cancelada') NOT NULL,
    padrao TINYINT(1) NOT NULL DEFAULT 0,
    ordem INT NOT NULL DEFAULT 0,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------
-- Turmas. valor_mensalidade é cobrado por duracao_meses; a data de término
-- é derivada (coluna gerada) para nunca divergir do início + duração.
-- ----------------------------------------------------------------------
CREATE TABLE turmas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    curso_id INT NOT NULL,
    professor_id INT DEFAULT NULL,
    nome VARCHAR(100) NOT NULL,
    vagas INT NOT NULL,
    -- Turma sem data (data_inicio em branco = "a definir") começa quando
    -- tiver minimo_alunos alunos ativos.
    minimo_alunos INT DEFAULT NULL,
    data_inicio DATE DEFAULT NULL,
    -- Duração: quantidade (duracao_meses) na unidade duracao_unidade
    -- (meses, ou dias para curso de fim de semana / de 7 dias).
    duracao_meses INT NOT NULL,
    duracao_unidade ENUM('mes', 'dia') NOT NULL DEFAULT 'mes',
    data_termino DATE GENERATED ALWAYS AS (
        IF(duracao_unidade = 'dia', data_inicio + INTERVAL duracao_meses DAY, data_inicio + INTERVAL duracao_meses MONTH)
    ) STORED,
    -- Informada ao concluir a turma; é a data de término no certificado.
    data_conclusao DATE DEFAULT NULL,
    valor_mensalidade DECIMAL(12,2) NOT NULL DEFAULT 0,
    -- Valor do curso sem a matrícula; valor_mensalidade = total ÷ parcelas.
    valor_total DECIMAL(12,2) DEFAULT NULL,
    horario VARCHAR(100) DEFAULT NULL,
    -- Agenda: dias da semana (1 = segunda ... 7 = domingo, separados por
    -- vírgula) e horário — de onde o calendário de aulas é gerado.
    dias_semana VARCHAR(20) DEFAULT NULL,
    hora_inicio TIME DEFAULT NULL,
    hora_fim TIME DEFAULT NULL,
    -- Horário por dia: [{"dia":1,"inicio":"19:00","fim":"22:00"}, ...].
    -- dias_semana/hora_inicio/hora_fim acima ficam como resumo.
    agenda JSON DEFAULT NULL,
    local VARCHAR(100) DEFAULT NULL,
    situacao_id INT NOT NULL,
    observacao VARCHAR(255) DEFAULT NULL,
    -- Ex.: "em parceria com a Faculdade X" (marcador {parceria} no certificado).
    parceria VARCHAR(255) DEFAULT NULL,
    -- Portal do aluno: divulgação e inscrição online. portal_modo_vaga:
    --   reserva        -> a inscrição segura a vaga até o prazo de pagamento
    --   apos_pagamento -> só conta vaga quem pagou (os outros esperam)
    --   inscricao      -> a vaga é ocupada na inscrição, sem pagar antes
    portal_publicar TINYINT(1) NOT NULL DEFAULT 0,
    -- Contrato da matrícula disponível para o aluno no portal (em Documentos).
    contrato_portal TINYINT(1) NOT NULL DEFAULT 0,
    portal_descricao TEXT DEFAULT NULL,
    portal_imagem VARCHAR(255) DEFAULT NULL,
    portal_inscricoes_ate DATE DEFAULT NULL,
    portal_modo_vaga ENUM('reserva', 'apos_pagamento', 'inscricao') NOT NULL DEFAULT 'reserva',
    portal_prazo_pagamento_dias TINYINT UNSIGNED NOT NULL DEFAULT 3,
    portal_taxa_matricula DECIMAL(12,2) NOT NULL DEFAULT 0,
    portal_num_parcelas INT DEFAULT NULL,
    portal_dia_vencimento TINYINT UNSIGNED NOT NULL DEFAULT 10,
    -- Forma da matrícula (taxa) e das mensalidades (pode ser cartão).
    portal_forma_pagamento_id INT DEFAULT NULL,
    forma_mensalidade_id INT DEFAULT NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_turmas_situacao (situacao_id, data_inicio),
    CONSTRAINT fk_turma_portal_forma FOREIGN KEY (portal_forma_pagamento_id) REFERENCES formas_pagamento(id),
    CONSTRAINT fk_turma_forma_mensalidade FOREIGN KEY (forma_mensalidade_id) REFERENCES formas_pagamento(id),
    CONSTRAINT fk_turma_situacao FOREIGN KEY (situacao_id) REFERENCES situacoes_turma(id),
    CONSTRAINT fk_turma_curso FOREIGN KEY (curso_id) REFERENCES cursos(id),
    CONSTRAINT fk_turma_professor FOREIGN KEY (professor_id) REFERENCES professores(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------
-- Matrícula = aluno numa turma. Os valores são COPIADOS da turma no ato
-- (valor_mensalidade) porque mudar o preço da turma depois não pode mexer
-- no que já foi contratado com quem está matriculado. As mensalidades em
-- si vivem em contas_receber (matricula_id).
-- ----------------------------------------------------------------------
-- Cupons de desconto para a matrícula (portal e secretaria).
--   tipo 'valor'          → R$ a menos no valor do curso (mensalidades)
--   tipo 'percentual'     → % a menos no valor do curso
--   tipo 'matricula_zero' → taxa de matrícula zerada
-- quantidade NULL = ilimitado (conta as matrículas não canceladas que usaram);
-- validade_inicio/fim NULL = sem limite naquele lado; sem cursos marcados = todos.
CREATE TABLE cupons (
    id INT AUTO_INCREMENT PRIMARY KEY,
    codigo VARCHAR(40) NOT NULL UNIQUE,
    descricao VARCHAR(150) DEFAULT NULL,
    tipo ENUM('valor', 'percentual', 'matricula_zero') NOT NULL,
    valor DECIMAL(12,2) NOT NULL DEFAULT 0,
    quantidade INT DEFAULT NULL,
    validade_inicio DATE DEFAULT NULL,
    validade_fim DATE DEFAULT NULL,
    uma_vez_por_aluno TINYINT(1) NOT NULL DEFAULT 1,
    -- Acumula com a bolsa e o desconto por pontualidade da matrícula?
    acumula TINYINT(1) NOT NULL DEFAULT 1,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    usuario_id INT DEFAULT NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_cupom_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cupom_cursos (
    cupom_id INT NOT NULL,
    curso_id INT NOT NULL,
    PRIMARY KEY (cupom_id, curso_id),
    CONSTRAINT fk_cupom_curso_cupom FOREIGN KEY (cupom_id) REFERENCES cupons(id) ON DELETE CASCADE,
    CONSTRAINT fk_cupom_curso_curso FOREIGN KEY (curso_id) REFERENCES cursos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE matriculas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    turma_id INT NOT NULL,
    aluno_id INT NOT NULL,
    data_matricula DATE NOT NULL,
    -- 'pendente' = inscrição feita pelo portal esperando o pagamento que
    -- confirma (conta_receber_confirmacao_id) até pagamento_ate. Só ocupa
    -- vaga enquanto segura_vaga = 1 e o prazo não passou. lista_espera =
    -- pagou, mas a turma lotou antes (modo "só depois de pagar").
    status ENUM('pendente', 'ativa', 'cancelada', 'concluida') NOT NULL DEFAULT 'ativa',
    origem ENUM('secretaria', 'portal') NOT NULL DEFAULT 'secretaria',
    pagamento_ate DATETIME DEFAULT NULL,
    segura_vaga TINYINT(1) NOT NULL DEFAULT 0,
    lista_espera TINYINT(1) NOT NULL DEFAULT 0,
    conta_receber_confirmacao_id INT DEFAULT NULL,
    -- O aluno só vê o certificado com a matrícula concluída E liberado.
    certificado_liberado TINYINT(1) NOT NULL DEFAULT 1,
    valor_mensalidade DECIMAL(12,2) NOT NULL,
    valor_total DECIMAL(12,2) DEFAULT NULL,
    -- Desconto fixo (bolsa): reduz toda mensalidade, pague quando pagar.
    desconto_percentual DECIMAL(5,2) NOT NULL DEFAULT 0,
    -- Desconto condicional por pontualidade: só vale pagando até N dias
    -- antes do vencimento (0 = até o próprio vencimento). Em 'valor' é R$
    -- por mensalidade; em 'percentual', % sobre a mensalidade já com bolsa.
    -- Não se aplica à taxa de matrícula.
    desconto_pontualidade_tipo ENUM('nenhum', 'valor', 'percentual') NOT NULL DEFAULT 'nenhum',
    desconto_pontualidade_valor DECIMAL(12,2) NOT NULL DEFAULT 0,
    desconto_pontualidade_dias INT NOT NULL DEFAULT 0,
    -- Cupom usado e quanto ele descontou (do curso, ou a taxa zerada).
    cupom_id INT DEFAULT NULL,
    cupom_desconto DECIMAL(12,2) NOT NULL DEFAULT 0,
    num_parcelas INT NOT NULL,
    primeiro_vencimento DATE NOT NULL,
    taxa_matricula DECIMAL(12,2) NOT NULL DEFAULT 0,
    -- forma_pagamento_id = da taxa de matrícula; forma_mensalidade_id = das mensalidades.
    forma_pagamento_id INT DEFAULT NULL,
    forma_mensalidade_id INT DEFAULT NULL,
    data_cancelamento DATE DEFAULT NULL,
    observacao VARCHAR(255) DEFAULT NULL,
    usuario_id INT DEFAULT NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_matricula_turma_aluno (turma_id, aluno_id),
    KEY idx_matriculas_aluno (aluno_id),
    CONSTRAINT fk_matricula_turma FOREIGN KEY (turma_id) REFERENCES turmas(id),
    CONSTRAINT fk_matricula_aluno FOREIGN KEY (aluno_id) REFERENCES alunos(id),
    CONSTRAINT fk_matricula_forma_pagamento FOREIGN KEY (forma_pagamento_id) REFERENCES formas_pagamento(id),
    CONSTRAINT fk_matricula_forma_mensalidade FOREIGN KEY (forma_mensalidade_id) REFERENCES formas_pagamento(id),
    CONSTRAINT fk_matricula_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
    CONSTRAINT fk_matricula_cupom FOREIGN KEY (cupom_id) REFERENCES cupons(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ======================================================================
-- FINANCEIRO
-- ======================================================================

-- ----------------------------------------------------------------------
-- Contas a pagar (despesas da instituição)
-- ----------------------------------------------------------------------
CREATE TABLE contas_pagar (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fornecedor_id INT DEFAULT NULL,
    -- Turma a que a despesa pertence (professor, palestrante, material...).
    turma_id INT DEFAULT NULL,
    categoria_id INT DEFAULT NULL,
    descricao VARCHAR(255) NOT NULL,
    valor DECIMAL(12,2) NOT NULL,
    data_emissao DATE NOT NULL,
    data_vencimento DATE NOT NULL,
    data_pagamento DATE DEFAULT NULL,
    forma_pagamento_id INT DEFAULT NULL,
    status ENUM('pendente', 'pago', 'cancelado') NOT NULL DEFAULT 'pendente',
    observacao VARCHAR(255) DEFAULT NULL,
    usuario_id INT DEFAULT NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_cp_status_vencimento (status, data_vencimento),
    KEY idx_cp_turma (turma_id),
    CONSTRAINT fk_cp_fornecedor FOREIGN KEY (fornecedor_id) REFERENCES fornecedores(id),
    CONSTRAINT fk_cp_turma FOREIGN KEY (turma_id) REFERENCES turmas(id) ON DELETE SET NULL,
    CONSTRAINT fk_cp_categoria FOREIGN KEY (categoria_id) REFERENCES categorias_financeiras(id),
    CONSTRAINT fk_cp_forma_pagamento FOREIGN KEY (forma_pagamento_id) REFERENCES formas_pagamento(id),
    CONSTRAINT fk_cp_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------
-- Contas a receber: mensalidades/taxas geradas pela matrícula
-- (matricula_id preenchido) ou lançamentos avulsos.
-- ----------------------------------------------------------------------
CREATE TABLE contas_receber (
    id INT AUTO_INCREMENT PRIMARY KEY,
    aluno_id INT DEFAULT NULL,
    matricula_id INT DEFAULT NULL,
    categoria_id INT DEFAULT NULL,
    descricao VARCHAR(255) NOT NULL,
    valor DECIMAL(12,2) NOT NULL,
    -- Desconto próprio da parcela: NULL = regra de pontualidade da matrícula;
    -- 0 = sem desconto; > 0 = R$ de desconto até desconto_ate (vazio = vencimento).
    desconto_valor DECIMAL(12,2) DEFAULT NULL,
    desconto_ate DATE DEFAULT NULL,
    data_emissao DATE NOT NULL,
    data_vencimento DATE NOT NULL,
    data_recebimento DATE DEFAULT NULL,
    -- Quanto entrou de fato na baixa (pode ser menor que "valor" quando o
    -- aluno pagou com desconto de pontualidade). Caixa, DRE e "já pago"
    -- usam este valor quando existe.
    valor_recebido DECIMAL(12,2) DEFAULT NULL,
    forma_pagamento_id INT DEFAULT NULL,
    -- Quem deu a baixa: 'manual' (tela; pode estornar) ou 'banco' (boleto/Pix pelo Itaú; não pode).
    baixa_origem ENUM('manual', 'banco') DEFAULT NULL,
    status ENUM('pendente', 'pago', 'cancelado') NOT NULL DEFAULT 'pendente',
    excluido_em DATETIME DEFAULT NULL,
    observacao VARCHAR(255) DEFAULT NULL,
    usuario_id INT DEFAULT NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_cr_status_vencimento (status, data_vencimento),
    CONSTRAINT fk_cr_aluno FOREIGN KEY (aluno_id) REFERENCES alunos(id),
    CONSTRAINT fk_cr_matricula FOREIGN KEY (matricula_id) REFERENCES matriculas(id),
    CONSTRAINT fk_cr_categoria FOREIGN KEY (categoria_id) REFERENCES categorias_financeiras(id),
    CONSTRAINT fk_cr_forma_pagamento FOREIGN KEY (forma_pagamento_id) REFERENCES formas_pagamento(id),
    CONSTRAINT fk_cr_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------
-- Cobranças registradas no banco (boleto / boleto+Pix / Pix) para uma
-- parcela de contas a receber. payload e resposta guardam exatamente o
-- que foi enviado e recebido — é a primeira coisa a olhar quando o banco
-- recusar ou divergir.
-- ----------------------------------------------------------------------
CREATE TABLE cobrancas_bancarias (
    id INT AUTO_INCREMENT PRIMARY KEY,
    conta_receber_id INT NOT NULL,
    conta_bancaria_id INT NOT NULL,
    instrumento ENUM('boleto', 'boleto_pix', 'pix') NOT NULL,
    nosso_numero INT UNSIGNED NOT NULL,
    valor DECIMAL(12,2) NOT NULL,
    data_vencimento DATE NOT NULL,
    status ENUM('pendente_envio', 'registrada', 'paga', 'baixada', 'erro') NOT NULL DEFAULT 'pendente_envio',
    linha_digitavel VARCHAR(60) DEFAULT NULL,
    codigo_barras VARCHAR(60) DEFAULT NULL,
    pix_copia_cola TEXT DEFAULT NULL,
    txid VARCHAR(35) DEFAULT NULL,
    payload JSON DEFAULT NULL,
    resposta JSON DEFAULT NULL,
    mensagem_erro VARCHAR(255) DEFAULT NULL,
    usuario_id INT DEFAULT NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_cobranca_nosso_numero (conta_bancaria_id, nosso_numero),
    KEY idx_cobranca_conta_receber (conta_receber_id),
    KEY idx_cobranca_txid (txid),
    CONSTRAINT fk_cobranca_conta_receber FOREIGN KEY (conta_receber_id) REFERENCES contas_receber(id),
    CONSTRAINT fk_cobranca_conta_bancaria FOREIGN KEY (conta_bancaria_id) REFERENCES contas_bancarias(id),
    CONSTRAINT fk_cobranca_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------
-- Tokens que o sistema emite para o Itaú chamar o webhook. Só o hash fica
-- guardado; o token em si vai uma vez na resposta e não é recuperável.
-- ----------------------------------------------------------------------
CREATE TABLE webhook_tokens (
    token_hash CHAR(64) PRIMARY KEY,
    conta_bancaria_id INT NOT NULL,
    expira_em DATETIME NOT NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_webhook_token_expira (expira_em),
    CONSTRAINT fk_webhook_token_conta FOREIGN KEY (conta_bancaria_id) REFERENCES contas_bancarias(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------
-- Toda notificação que chega do banco, crua (payload) e o que o sistema
-- fez com ela. Grava antes de processar: se algo der errado, o aviso de
-- pagamento não se perde e dá para conferir/reprocessar à mão.
-- ----------------------------------------------------------------------
CREATE TABLE webhook_eventos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    conta_bancaria_id INT DEFAULT NULL,
    requisicao_id INT DEFAULT NULL,
    tipo_notificacao VARCHAR(40) DEFAULT NULL,
    nosso_numero INT UNSIGNED DEFAULT NULL,
    cobranca_id INT DEFAULT NULL,
    conta_receber_id INT DEFAULT NULL,
    valor_pago DECIMAL(12,2) DEFAULT NULL,
    data_pagamento DATE DEFAULT NULL,
    status ENUM('recebido', 'processado', 'ignorado', 'nao_encontrado', 'erro') NOT NULL DEFAULT 'recebido',
    mensagem VARCHAR(255) DEFAULT NULL,
    payload MEDIUMTEXT NOT NULL,
    ip VARCHAR(45) DEFAULT NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_webhook_evento_conta (conta_bancaria_id, criado_em),
    KEY idx_webhook_evento_nosso_numero (nosso_numero)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------
-- Auditoria das chamadas do sistema ao banco (emissão/validação de
-- boleto, cadastro e consulta do webhook, pedido de token): o que foi
-- enviado e o que o banco respondeu, quem pediu (usuário do sistema ou
-- aluno no portal) e para qual parcela. Segredos vão mascarados.
-- ----------------------------------------------------------------------
CREATE TABLE aluno_historico (
    id INT AUTO_INCREMENT PRIMARY KEY,
    aluno_id INT NOT NULL,
    matricula_id INT DEFAULT NULL,
    usuario_id INT DEFAULT NULL,
    quem VARCHAR(150) NOT NULL,
    tipo VARCHAR(30) NOT NULL,
    descricao VARCHAR(255) NOT NULL,
    ip VARCHAR(45) DEFAULT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_aluno_historico (aluno_id, criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE curso_documentos (
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

CREATE TABLE aluno_documentos (
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

CREATE TABLE aluno_anexos (
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

CREATE TABLE curso_emissoes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    curso_id INT NOT NULL,
    nome VARCHAR(120) NOT NULL,
    modelo_id INT DEFAULT NULL,
    modulo_id INT DEFAULT NULL,
    -- 1 = documento avulso (liberado só para alguns alunos; ver documentoAvulsoCurso).
    avulso TINYINT(1) NOT NULL DEFAULT 0,
    ordem INT NOT NULL DEFAULT 0,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_curso_emissoes (curso_id, ativo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE turma_modulos (
    turma_id INT NOT NULL,
    curso_modulo_id INT NOT NULL,
    concluido_em DATE NOT NULL,
    concluido_por VARCHAR(150) DEFAULT NULL,
    PRIMARY KEY (turma_id, curso_modulo_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE parcela_historico (
    id INT AUTO_INCREMENT PRIMARY KEY,
    conta_receber_id INT NOT NULL,
    usuario_id INT DEFAULT NULL,
    usuario_nome VARCHAR(150) DEFAULT NULL,
    tipo VARCHAR(30) NOT NULL,
    descricao VARCHAR(255) NOT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_parcela_historico (conta_receber_id, criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE banco_tarifas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    conta_bancaria_id INT NOT NULL,
    conta_receber_id INT DEFAULT NULL,
    cobranca_id INT DEFAULT NULL,
    tipo ENUM('emissao', 'liquidacao', 'baixa', 'alteracao') NOT NULL,
    valor DECIMAL(10,2) NOT NULL,
    data DATE NOT NULL,
    conta_pagar_id INT DEFAULT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_banco_tarifas_conta (conta_bancaria_id, data),
    KEY idx_banco_tarifas_parcela (conta_receber_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE banco_chamadas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    conta_bancaria_id INT DEFAULT NULL,
    operacao VARCHAR(40) NOT NULL,
    conta_receber_id INT DEFAULT NULL,
    usuario_id INT DEFAULT NULL,
    aluno_id INT DEFAULT NULL,
    origem ENUM('sistema', 'portal', 'automatico') NOT NULL DEFAULT 'sistema',
    metodo VARCHAR(10) NOT NULL,
    url VARCHAR(255) NOT NULL,
    status_http SMALLINT DEFAULT NULL,
    sucesso TINYINT(1) NOT NULL DEFAULT 0,
    correlation_id VARCHAR(40) DEFAULT NULL,
    requisicao MEDIUMTEXT DEFAULT NULL,
    resposta MEDIUMTEXT DEFAULT NULL,
    mensagem VARCHAR(255) DEFAULT NULL,
    duracao_ms INT DEFAULT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_banco_chamadas_conta (conta_bancaria_id, criado_em),
    KEY idx_banco_chamadas_parcela (conta_receber_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------
-- Registro bruto de TODA chamada recebida nas URLs de webhook (inclusive
-- as recusadas e os pedidos de token): cabeçalhos, corpo exatamente como
-- chegou e o que o sistema respondeu. É a auditoria do que o banco mandou —
-- não se apaga. Segredos (client_secret, tokens) ficam mascarados. Uma cópia
-- vai também para storage/logs/webhook-AAAA-MM.log, que sobrevive a uma
-- queda do banco de dados.
-- ----------------------------------------------------------------------
CREATE TABLE webhook_requisicoes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    endpoint VARCHAR(40) NOT NULL,
    metodo VARCHAR(10) NOT NULL,
    conta_bancaria_id INT DEFAULT NULL,
    ip VARCHAR(45) DEFAULT NULL,
    cabecalhos TEXT DEFAULT NULL,
    corpo MEDIUMTEXT DEFAULT NULL,
    status_http SMALLINT DEFAULT NULL,
    resposta TEXT DEFAULT NULL,
    duracao_ms INT DEFAULT NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_webhook_req_criado (criado_em),
    KEY idx_webhook_req_endpoint (endpoint, criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ======================================================================
-- PORTAL DO ALUNO
-- ======================================================================

-- Links de "criar/redefinir senha" mandados por e-mail (convite da
-- secretaria ou "esqueci a senha"). Só o hash do token fica guardado.
CREATE TABLE aluno_tokens_senha (
    id INT AUTO_INCREMENT PRIMARY KEY,
    aluno_id INT NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    tipo ENUM('convite', 'redefinir') NOT NULL,
    expira_em DATETIME NOT NULL,
    usado_em DATETIME DEFAULT NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_aluno_token_aluno (aluno_id),
    CONSTRAINT fk_aluno_token_aluno FOREIGN KEY (aluno_id) REFERENCES alunos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- Calendário de aulas
-- Vaga guardada pela secretaria para alguém (aluno cadastrado ou só o nome).
-- Ocupa vaga como uma matrícula: o site mostra só o que sobra.
-- Pedido de liberação para matricular aluno inadimplente (configuração
-- "autorizar"): a secretaria pede, um administrador aprova ou nega; a
-- aprovação vale para uma matrícula, por 2 dias.
CREATE TABLE liberacoes_inadimplencia (
    id INT AUTO_INCREMENT PRIMARY KEY,
    aluno_id INT NOT NULL,
    turma_id INT DEFAULT NULL,
    valor_atraso DECIMAL(12,2) NOT NULL DEFAULT 0,
    motivo VARCHAR(255) DEFAULT NULL,
    status ENUM('pendente', 'aprovada', 'negada', 'usada') NOT NULL DEFAULT 'pendente',
    solicitado_por INT DEFAULT NULL,
    decidido_por INT DEFAULT NULL,
    decidido_em DATETIME DEFAULT NULL,
    usado_em DATETIME DEFAULT NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_liberacoes_aluno (aluno_id, status),
    CONSTRAINT fk_liberacao_aluno FOREIGN KEY (aluno_id) REFERENCES alunos(id) ON DELETE CASCADE,
    CONSTRAINT fk_liberacao_turma FOREIGN KEY (turma_id) REFERENCES turmas(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE turma_reservas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    turma_id INT NOT NULL,
    aluno_id INT DEFAULT NULL,
    nome VARCHAR(150) NOT NULL,
    telefone VARCHAR(30) DEFAULT NULL,
    observacao VARCHAR(255) DEFAULT NULL,
    usuario_id INT DEFAULT NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_turma_reservas_turma (turma_id),
    CONSTRAINT fk_turma_reserva_turma FOREIGN KEY (turma_id) REFERENCES turmas(id) ON DELETE CASCADE,
    CONSTRAINT fk_turma_reserva_aluno FOREIGN KEY (aluno_id) REFERENCES alunos(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE turma_aulas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    turma_id INT NOT NULL,
    data DATE NOT NULL,
    hora_inicio TIME DEFAULT NULL,
    hora_fim TIME DEFAULT NULL,
    status ENUM('prevista', 'cancelada') NOT NULL DEFAULT 'prevista',
    avulsa TINYINT(1) NOT NULL DEFAULT 0,
    tema VARCHAR(150) DEFAULT NULL,
    observacao VARCHAR(255) DEFAULT NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_turma_aulas_data (turma_id, data),
    CONSTRAINT fk_turma_aula_turma FOREIGN KEY (turma_id) REFERENCES turmas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Modelos de certificado: arte de fundo + elementos (layout, JSON com
-- posição/tamanho de cada texto, imagem e QR Code). Cada curso escolhe o
-- seu (cursos.certificado_modelo_id); sem escolha, vale o padrão.
CREATE TABLE certificado_modelos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    orientacao ENUM('horizontal', 'vertical') NOT NULL DEFAULT 'horizontal',
    fundo VARCHAR(255) DEFAULT NULL,
    layout TEXT DEFAULT NULL,
    padrao TINYINT(1) NOT NULL DEFAULT 0,
    -- Marca d'água: {"ativa":1,"tamanho":8,"espaco":1.5,"opacidade":10} (vazio = padrão, ligada).
    marca_dagua VARCHAR(255) DEFAULT NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Galeria de artes de fundo, compartilhada entre os modelos.
CREATE TABLE certificado_fundos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    modelo_id INT NOT NULL,
    nome VARCHAR(100) NOT NULL,
    imagem VARCHAR(255) NOT NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_fundo_modelo (modelo_id),
    CONSTRAINT fk_fundo_modelo FOREIGN KEY (modelo_id) REFERENCES certificado_modelos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE certificados (
    id INT AUTO_INCREMENT PRIMARY KEY,
    matricula_id INT NOT NULL,
    curso_emissao_id INT DEFAULT NULL,
    -- Data informada ao liberar (ex.: dia da aula numa declaração): {data_evento}.
    data_evento DATE DEFAULT NULL,
    codigo CHAR(12) NOT NULL UNIQUE,
    liberado TINYINT(1) NOT NULL DEFAULT 1,
    liberado_em DATETIME DEFAULT NULL,
    liberado_por VARCHAR(150) DEFAULT NULL,
    emitido_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_certificado_documento (matricula_id, curso_emissao_id),
    CONSTRAINT fk_certificado_matricula FOREIGN KEY (matricula_id) REFERENCES matriculas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- Página inicial do portal (montada pela operadora)
-- tipo: inicio (a página inicial), personalizada (criada pela operadora,
-- endereço /portal/pagina.php?p=<slug>) e sistema (telas do portal, como
-- Cursos e Pagamentos, que recebem blocos antes e depois do conteúdo).
CREATE TABLE portal_paginas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tipo ENUM('inicio', 'personalizada', 'sistema') NOT NULL,
    chave VARCHAR(40) DEFAULT NULL UNIQUE,
    titulo VARCHAR(150) NOT NULL,
    slug VARCHAR(80) DEFAULT NULL UNIQUE,
    publico ENUM('todos', 'alunos') NOT NULL DEFAULT 'todos',
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    ordem INT NOT NULL DEFAULT 0,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Blocos em qualquer página. regiao só vale nas telas do sistema (antes ou
-- depois do conteúdo). config guarda os campos de cada tipo (JSON).
CREATE TABLE portal_blocos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pagina_id INT NOT NULL,
    regiao ENUM('topo', 'fim') NOT NULL DEFAULT 'topo',
    tipo VARCHAR(30) NOT NULL,
    titulo VARCHAR(150) DEFAULT NULL,
    texto TEXT DEFAULT NULL,
    config TEXT DEFAULT NULL,
    largura ENUM('inteira', 'metade', 'terco') NOT NULL DEFAULT 'inteira',
    altura ENUM('baixa', 'media', 'alta') NOT NULL DEFAULT 'media',
    publico ENUM('todos', 'visitantes', 'alunos') NOT NULL DEFAULT 'todos',
    exibir_de DATE DEFAULT NULL,
    exibir_ate DATE DEFAULT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    ordem INT NOT NULL DEFAULT 0,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_portal_blocos_pagina (pagina_id, regiao, ordem),
    CONSTRAINT fk_portal_bloco_pagina FOREIGN KEY (pagina_id) REFERENCES portal_paginas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Menu do topo e links do rodapé. destino: uma página do portal ou um link.
CREATE TABLE portal_menu (
    id INT AUTO_INCREMENT PRIMARY KEY,
    local ENUM('topo', 'rodape') NOT NULL DEFAULT 'topo',
    rotulo VARCHAR(60) NOT NULL,
    pagina_id INT DEFAULT NULL,
    url VARCHAR(255) DEFAULT NULL,
    publico ENUM('todos', 'visitantes', 'alunos') NOT NULL DEFAULT 'todos',
    nova_aba TINYINT(1) NOT NULL DEFAULT 0,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    ordem INT NOT NULL DEFAULT 0,
    KEY idx_portal_menu (local, ordem),
    CONSTRAINT fk_portal_menu_pagina FOREIGN KEY (pagina_id) REFERENCES portal_paginas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Imagens de um bloco banner (mais de uma = carrossel).
CREATE TABLE portal_banner_imagens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bloco_id INT NOT NULL,
    imagem VARCHAR(255) NOT NULL,
    link VARCHAR(255) DEFAULT NULL,
    nova_aba TINYINT(1) NOT NULL DEFAULT 0,
    texto_alternativo VARCHAR(150) DEFAULT NULL,
    ordem INT NOT NULL DEFAULT 0,
    CONSTRAINT fk_banner_imagem_bloco FOREIGN KEY (bloco_id) REFERENCES portal_blocos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ======================================================================
-- DADOS INICIAIS
-- ======================================================================

INSERT INTO configuracoes (id) VALUES (1);

INSERT INTO portal_paginas (tipo, chave, titulo, ordem) VALUES
('inicio', 'inicio', 'Início', 0),
('sistema', 'cursos', 'Cursos (lista de turmas abertas)', 10),
('sistema', 'turma', 'Página de cada curso', 20),
('sistema', 'meus_cursos', 'Meus cursos', 30),
('sistema', 'financeiro', 'Pagamentos', 40),
('sistema', 'calendario', 'Calendário', 50),
('sistema', 'certificados', 'Certificados', 60);

INSERT INTO portal_blocos (pagina_id, tipo, titulo, publico, ordem) VALUES
(1, 'saudacao', NULL, 'todos', 1),
(1, 'avisos', NULL, 'alunos', 2),
(1, 'atalhos', NULL, 'alunos', 3),
(1, 'turmas', 'Turmas com inscrições abertas', 'todos', 10);

INSERT INTO portal_menu (local, rotulo, pagina_id, publico, ordem)
SELECT 'topo', 'Início', id, 'todos', 10 FROM portal_paginas WHERE chave = 'inicio'
UNION ALL SELECT 'topo', 'Cursos', id, 'todos', 20 FROM portal_paginas WHERE chave = 'cursos'
UNION ALL SELECT 'topo', 'Meus cursos', id, 'alunos', 30 FROM portal_paginas WHERE chave = 'meus_cursos'
UNION ALL SELECT 'topo', 'Financeiro', id, 'alunos', 40 FROM portal_paginas WHERE chave = 'financeiro'
UNION ALL SELECT 'topo', 'Calendário', id, 'alunos', 50 FROM portal_paginas WHERE chave = 'calendario'
UNION ALL SELECT 'topo', 'Certificados', id, 'alunos', 60 FROM portal_paginas WHERE chave = 'certificados';
INSERT INTO portal_menu (local, rotulo, url, ordem) VALUES ('rodape', 'Conferir um certificado', '/portal/verificar.php', 10);

INSERT INTO certificado_modelos (nome, padrao) VALUES ('Modelo padrão', 1);

ALTER TABLE cursos ADD CONSTRAINT fk_curso_certificado_modelo FOREIGN KEY (certificado_modelo_id) REFERENCES certificado_modelos(id) ON DELETE SET NULL;

-- Modelos de contrato (texto editável, com variáveis como {aluno_nome}),
-- impressos na matrícula e mostrados ao aluno no portal. Cada curso escolhe
-- o seu (cursos.contrato_modelo_id); sem escolha, vale o padrão.
CREATE TABLE contrato_modelos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    conteudo MEDIUMTEXT DEFAULT NULL, -- HTML do editor
    -- Página: margens (cm), cabeçalho e rodapé, em JSON (vazio = padrão).
    pagina TEXT DEFAULT NULL,
    padrao TINYINT(1) NOT NULL DEFAULT 0,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE cursos ADD CONSTRAINT fk_curso_contrato_modelo FOREIGN KEY (contrato_modelo_id) REFERENCES contrato_modelos(id) ON DELETE SET NULL;

INSERT INTO perfis (id, nome, administrador, desconto_max) VALUES
(1, 'Administrador', 1, 100.00),
(2, 'Secretaria', 0, 10.00);

INSERT INTO permissoes (chave, descricao, grupo, ordem) VALUES
('ver_dashboard', 'Ver o Dashboard (página inicial com resumo)', 'Painel', 1),
('ver_alunos', 'Ver alunos (lista e ficha)', 'Alunos', 10),
('cadastrar_aluno', 'Cadastrar alunos', 'Alunos', 11),
('editar_aluno', 'Editar alunos (e criar/enviar o acesso ao portal)', 'Alunos', 12),
('ativar_aluno', 'Ativar e desativar alunos', 'Alunos', 13),
('excluir_aluno', 'Excluir alunos (só quem não tem matrícula nem lançamento financeiro)', 'Alunos', 14),
('avaliar_documentos', 'Aprovar, recusar ou dispensar documentos dos alunos', 'Alunos', 15),
('ver_cursos', 'Ver cursos, turmas, professores e calendário de aulas', 'Cursos, turmas e professores', 20),
('criar_curso', 'Cadastrar cursos', 'Cursos, turmas e professores', 21),
('editar_curso', 'Editar cursos (e ativar/desativar)', 'Cursos, turmas e professores', 22),
('excluir_curso', 'Excluir cursos (só sem turmas)', 'Cursos, turmas e professores', 23),
('criar_turma', 'Cadastrar turmas', 'Cursos, turmas e professores', 24),
('editar_turma', 'Editar turmas, mudar a situação e ajustar o calendário de aulas', 'Cursos, turmas e professores', 25),
('excluir_turma', 'Excluir turmas', 'Cursos, turmas e professores', 26),
('concluir_turma', 'Concluir e reabrir turmas', 'Cursos, turmas e professores', 27),
('gerenciar_professores', 'Cadastrar professores', 'Cursos, turmas e professores', 28),
('editar_professor', 'Editar professores (e ativar/desativar)', 'Cursos, turmas e professores', 29),
('excluir_professor', 'Excluir professores (só sem turmas)', 'Cursos, turmas e professores', 30),
('gerenciar_matriculas', 'Matricular, reservar vaga, confirmar inscrição e concluir/reativar matrícula', 'Matrículas', 40),
('cancelar_matricula', 'Cancelar matrícula, excluir matrícula e tirar aluno da turma', 'Matrículas', 41),
('liberar_certificado', 'Liberar e bloquear documentos dos alunos (certificados e declarações)', 'Matrículas', 42),
('ver_inadimplencia', 'Ver a situação financeira dos alunos (aba Financeiro, Inadimplência, boleto e Pix)', 'Financeiro dos alunos', 50),
('lancar_receber', 'Lançar cobranças (contas a receber)', 'Financeiro dos alunos', 51),
('editar_financeiro_aluno', 'Alterar parcelas (valor, vencimento, desconto) e o desconto por pontualidade', 'Financeiro dos alunos', 52),
('excluir_receber', 'Excluir lançamentos a receber', 'Financeiro dos alunos', 53),
('gerenciar_cupons', 'Cadastrar cupons de desconto', 'Financeiro dos alunos', 55),
('baixar_receber', 'Dar baixa em contas a receber (inclusive várias de uma vez) e estornar baixa manual', 'Financeiro dos alunos', 54),
('ver_financeiro', 'Ver o Financeiro (contas a receber e a pagar, recebimentos, resultado por turma)', 'Financeiro da instituição', 60),
('acesso_financeiro', 'Lançar contas a pagar (inclusive despesas da turma)', 'Financeiro da instituição', 61),
('pagar_conta', 'Dar baixa (pagar) contas a pagar', 'Financeiro da instituição', 62),
('excluir_pagar', 'Excluir contas a pagar', 'Financeiro da instituição', 63),
('gerenciar_categorias', 'Cadastrar e editar categorias financeiras', 'Financeiro da instituição', 64),
('ver_dre', 'Ver o DRE e o Fluxo de caixa', 'Financeiro da instituição', 65),
('ver_fornecedores', 'Cadastrar fornecedores (menu Fornecedores)', 'Financeiro da instituição', 66),
('ver_formas_pagamento', 'Cadastrar formas de pagamento', 'Financeiro da instituição', 67),
('gerenciar_portal', 'Editar o site do portal do aluno', 'Portal do aluno', 70),
('editar_documentos', 'Editar os modelos de documentos (certificados, declarações e contratos)', 'Portal do aluno', 71),
('cadastrar_usuario', 'Cadastrar usuários (só com os perfis liberados abaixo)', 'Usuários do sistema', 80),
('editar_usuario', 'Editar usuários e trocar a senha deles (só dos perfis liberados abaixo)', 'Usuários do sistema', 81),
('desativar_usuario', 'Ativar e desativar usuários (só dos perfis liberados abaixo)', 'Usuários do sistema', 82);

-- Administrador tem bypass no código, mas recebe tudo para a tela de
-- Perfis refletir isso. Secretaria começa com o atendimento ao aluno.
INSERT INTO perfil_permissoes (perfil_id, permissao_id)
SELECT 1, id FROM permissoes;

INSERT INTO perfil_permissoes (perfil_id, permissao_id)
SELECT 2, id FROM permissoes
WHERE chave IN ('ver_dashboard', 'ver_alunos', 'cadastrar_aluno', 'editar_aluno', 'ativar_aluno', 'ver_cursos', 'gerenciar_matriculas',
                'liberar_certificado', 'avaliar_documentos', 'ver_inadimplencia', 'baixar_receber');

-- Usuário padrão: email admin@sistema.com / senha admin123
-- (troque a senha após o primeiro acesso)
INSERT INTO usuarios (nome, email, senha, perfil_id) VALUES
('Administrador', 'admin@sistema.com', '$2y$12$twgc2kyXYm0blO64cqFsqeQXmU1z6LVy5.V8hcUxTPqfxs3O/5fZa', 1);

INSERT INTO categorias_financeiras (nome, tipo, chave) VALUES
('Mensalidades', 'receita', 'mensalidade'),
('Taxa de matrícula', 'receita', 'taxa_matricula'),
('Outras receitas', 'receita', NULL),
('Salários e encargos', 'despesa', NULL),
('Pagamento de professores', 'despesa', NULL),
('Aluguel', 'despesa', NULL),
('Água/Luz/Internet', 'despesa', NULL),
('Material didático', 'despesa', NULL),
('Palestrantes e convidados', 'despesa', NULL),
('Marketing', 'despesa', NULL),
('Impostos', 'despesa', NULL),
('Outras despesas', 'despesa', NULL);

INSERT INTO situacoes_turma (nome, cor, comportamento, padrao, ordem) VALUES
('Planejada',    '#0dcaf0', 'aberta',       1, 1),
('Em andamento', '#198754', 'em_andamento', 0, 2),
('Concluída',    '#6c757d', 'concluida',    0, 3),
('Cancelada',    '#dc3545', 'cancelada',    0, 4);

-- Pix e Boleto nascem sem conta vinculada: viram cobrança integrada quando
-- uma conta bancária for cadastrada e ligada a elas.
INSERT INTO formas_pagamento (nome, instrumento) VALUES
('Dinheiro', 'manual'),
('Pix', 'pix'),
('Boleto', 'boleto_pix'),
('Cartão de débito', 'manual'),
('Cartão de crédito', 'manual'),
('Transferência', 'manual');
