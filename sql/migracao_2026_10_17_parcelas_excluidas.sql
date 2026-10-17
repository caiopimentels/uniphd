-- Parcelas excluídas que já tinham passado pelo banco (boleto gerado): não
-- são apagadas (o histórico do boleto fica), só somem das telas. Aparecem
-- no filtro "Excluídas" do Contas a receber.
-- Para quem já tem o sistema instalado; instalação nova: o schema.sql já cria.
-- Uso: mysql -u USUARIO -p NOME_DO_BANCO < sql/migracao_2026_10_17_parcelas_excluidas.sql

ALTER TABLE contas_receber ADD COLUMN excluido_em DATETIME DEFAULT NULL AFTER status;
