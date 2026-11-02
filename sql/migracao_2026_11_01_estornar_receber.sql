-- Estornar baixa vira uma permissão separada de "dar baixa": o funcionário
-- pode receber, mas desfazer um recebimento fica só com quem o perfil
-- liberar (administrador sempre pode). Nenhum perfil ganha esta permissão
-- automaticamente.
UPDATE permissoes SET descricao = 'Dar baixa em contas a receber (inclusive várias de uma vez)' WHERE chave = 'baixar_receber';
INSERT INTO permissoes (chave, descricao, grupo, ordem)
VALUES ('estornar_receber', 'Estornar baixa manual de contas a receber (a parcela volta a ficar em aberto)', 'Financeiro dos alunos', 54);
-- Perfis de administrador já podem tudo; marca para a tela de Perfis mostrar.
INSERT INTO perfil_permissoes (perfil_id, permissao_id)
SELECT pf.id, p.id FROM perfis pf JOIN permissoes p ON p.chave = 'estornar_receber' WHERE pf.administrador = 1;
