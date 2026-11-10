-- Rematrícula: o aluno que cancelou pode ser matriculado de novo na mesma
-- turma. A matrícula cancelada continua guardada (histórico: entrou,
-- cancelou, voltou) e a nova é outra linha, com cobranças e contrato
-- próprios. Antes, a chave única (turma, aluno) impedia isso.
-- Quem impede duas matrículas valendo ao mesmo tempo é o sistema (só
-- aceita uma nova se as outras dessa turma estiverem canceladas).

-- O índice novo entra antes de tirar o antigo: a chave estrangeira da
-- turma precisa de um índice começando por turma_id.
ALTER TABLE matriculas ADD KEY idx_matriculas_turma_aluno (turma_id, aluno_id);
ALTER TABLE matriculas DROP INDEX uk_matricula_turma_aluno;
