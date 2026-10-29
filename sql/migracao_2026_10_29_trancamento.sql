-- Trancamento de matrícula. As regras ficam em Configurações › Matrículas:
-- o que acontece com as mensalidades futuras (continuam, ficam suspensas
-- ou são canceladas), se o aluno trancado segura a vaga e para onde ele
-- volta ao destrancar (mesma turma ou escolher outra do mesmo curso).

ALTER TABLE configuracoes
    ADD COLUMN trancamento_parcelas ENUM('continuar', 'suspender', 'cancelar') NOT NULL DEFAULT 'continuar',
    ADD COLUMN trancamento_vaga ENUM('liberar', 'manter') NOT NULL DEFAULT 'liberar',
    ADD COLUMN trancamento_retorno ENUM('escolher', 'mesma') NOT NULL DEFAULT 'escolher',
    ADD COLUMN trancamento_portal TINYINT(1) NOT NULL DEFAULT 0;

-- trancamento_vaga / trancamento_parcelas guardam a regra usada no dia em
-- que trancou (mudar a configuração depois não muda quem já está trancado).
ALTER TABLE matriculas
    MODIFY status ENUM('pendente', 'ativa', 'cancelada', 'concluida', 'trancada') NOT NULL DEFAULT 'ativa',
    ADD COLUMN data_trancamento DATE DEFAULT NULL AFTER data_cancelamento,
    ADD COLUMN trancamento_motivo VARCHAR(255) DEFAULT NULL AFTER data_trancamento,
    ADD COLUMN trancamento_previsao DATE DEFAULT NULL AFTER trancamento_motivo,
    ADD COLUMN trancamento_vaga TINYINT(1) NOT NULL DEFAULT 0 AFTER trancamento_previsao,
    ADD COLUMN trancamento_parcelas VARCHAR(10) DEFAULT NULL AFTER trancamento_vaga;

-- Parcela suspensa pelo trancamento: fica 'cancelado' (sai da cobrança) e
-- marcada com a matrícula, para voltar com vencimentos novos ao destrancar.
ALTER TABLE contas_receber
    ADD COLUMN suspensa_trancamento TINYINT(1) NOT NULL DEFAULT 0 AFTER excluido_em;

INSERT INTO permissoes (chave, descricao, grupo, ordem)
SELECT 'trancar_matricula', 'Trancar e destrancar matrícula', 'Matrículas', 42
WHERE NOT EXISTS (SELECT 1 FROM permissoes WHERE chave = 'trancar_matricula');

-- Quem já podia cancelar matrícula passa a poder trancar.
INSERT INTO perfil_permissoes (perfil_id, permissao_id)
SELECT pp.perfil_id, (SELECT id FROM permissoes WHERE chave = 'trancar_matricula')
FROM perfil_permissoes pp JOIN permissoes p ON p.id = pp.permissao_id
WHERE p.chave = 'cancelar_matricula'
  AND NOT EXISTS (SELECT 1 FROM perfil_permissoes x WHERE x.perfil_id = pp.perfil_id AND x.permissao_id = (SELECT id FROM permissoes WHERE chave = 'trancar_matricula'));
