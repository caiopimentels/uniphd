-- Conferência automática de boletos: uma vez por dia, no horário escolhido
-- em Contas bancárias › Conferir boletos, a rotina pergunta ao banco a
-- situação dos boletos em aberto e baixa os que estiverem pagos (para os
-- casos em que o aviso do banco não chega). Horário vazio = desligada.
ALTER TABLE contas_bancarias
    ADD COLUMN conferencia_horario TIME DEFAULT NULL AFTER webhook_pix_cadastrado_em,
    ADD COLUMN conferencia_periodo ENUM('vencidos', 'todos') NOT NULL DEFAULT 'vencidos' AFTER conferencia_horario,
    ADD COLUMN conferencia_ultima_em DATETIME DEFAULT NULL AFTER conferencia_periodo,
    ADD COLUMN conferencia_ultima_resumo VARCHAR(255) DEFAULT NULL AFTER conferencia_ultima_em;
