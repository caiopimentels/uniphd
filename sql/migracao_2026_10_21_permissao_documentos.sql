-- Permissão própria para os modelos de documentos (certificados, declarações
-- e contratos), separada da edição do site do portal. Quem já editava o
-- portal (e, com ele, os certificados) continua podendo editar documentos.
UPDATE permissoes SET descricao = 'Editar o site do portal do aluno' WHERE chave = 'gerenciar_portal';
INSERT INTO permissoes (chave, descricao, grupo, ordem) VALUES
('editar_documentos', 'Editar os modelos de documentos (certificados, declarações e contratos)', 'Portal do aluno', 71);
INSERT INTO perfil_permissoes (perfil_id, permissao_id)
SELECT pp.perfil_id, (SELECT id FROM permissoes WHERE chave = 'editar_documentos')
FROM perfil_permissoes pp JOIN permissoes p ON p.id = pp.permissao_id
WHERE p.chave = 'gerenciar_portal';
