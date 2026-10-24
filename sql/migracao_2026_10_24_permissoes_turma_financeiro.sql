-- Permissões próprias para ver, na tela da turma, a receita mensal prevista
-- e as despesas da turma. Quem já via continua vendo: a receita, quem via
-- o financeiro dos alunos ou o da instituição; as despesas, quem via o
-- financeiro da instituição.
INSERT INTO permissoes (chave, descricao, grupo, ordem) VALUES
('ver_receita_turma', 'Ver a receita mensal prevista na tela da turma', 'Cursos, turmas e professores', 31),
('ver_despesas_turma', 'Ver as despesas da turma na tela da turma', 'Cursos, turmas e professores', 32);
INSERT INTO perfil_permissoes (perfil_id, permissao_id)
SELECT DISTINCT pp.perfil_id, (SELECT id FROM permissoes WHERE chave = 'ver_receita_turma')
FROM perfil_permissoes pp JOIN permissoes p ON p.id = pp.permissao_id
WHERE p.chave IN ('ver_inadimplencia', 'ver_financeiro', 'acesso_financeiro');
INSERT INTO perfil_permissoes (perfil_id, permissao_id)
SELECT DISTINCT pp.perfil_id, (SELECT id FROM permissoes WHERE chave = 'ver_despesas_turma')
FROM perfil_permissoes pp JOIN permissoes p ON p.id = pp.permissao_id
WHERE p.chave IN ('ver_financeiro', 'acesso_financeiro');
