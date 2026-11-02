-- CPF do aluno mascarado na tela (800.3**.***-**) para todo mundo. Quem tem
-- esta permissão vê um olhinho que mostra o CPF completo — e cada vez fica
-- registrada nas atividades. Só os perfis de administrador começam com ela.
INSERT INTO permissoes (chave, descricao, grupo, ordem)
VALUES ('ver_cpf_completo', 'Ver o CPF completo dos alunos (pelo olhinho; fica registrado)', 'Alunos', 14);
INSERT INTO perfil_permissoes (perfil_id, permissao_id)
SELECT pf.id, p.id FROM perfis pf JOIN permissoes p ON p.chave = 'ver_cpf_completo' WHERE pf.administrador = 1;
