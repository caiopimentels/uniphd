-- CPF e telefones dos alunos guardados só com números (a máscara fica na
-- tela). Converte o que já foi gravado com pontos, traços, parênteses e
-- espaços. A estrutura das tabelas não muda.

UPDATE alunos SET cpf = REGEXP_REPLACE(cpf, '[^0-9]', '') WHERE cpf REGEXP '[^0-9]';
UPDATE alunos SET cpf = NULL WHERE cpf = '';
UPDATE alunos SET telefone = REGEXP_REPLACE(telefone, '[^0-9]', '') WHERE telefone REGEXP '[^0-9]';
UPDATE alunos SET telefone = NULL WHERE telefone = '';
UPDATE aluno_telefones SET telefone = REGEXP_REPLACE(telefone, '[^0-9]', '') WHERE telefone REGEXP '[^0-9]';
DELETE FROM aluno_telefones WHERE telefone = '';
UPDATE turma_reservas SET telefone = REGEXP_REPLACE(telefone, '[^0-9]', '') WHERE telefone REGEXP '[^0-9]';
UPDATE turma_reservas SET telefone = NULL WHERE telefone = '';
