-- Renovação do certificado do Itaú: quando o sistema tentou renovar sozinho,
-- o último erro e o último aviso de vencimento mandado (30/15/7/1/0 dias).
-- Para quem já tem o sistema instalado; instalação nova: o schema.sql já cria.
-- Uso: mysql -u USUARIO -p NOME_DO_BANCO < sql/migracao_2026_10_17_renovacao_certificado.sql

ALTER TABLE contas_bancarias
    ADD COLUMN api_certificado_renovacao_em DATETIME DEFAULT NULL AFTER api_certificado_validade,
    ADD COLUMN api_certificado_renovacao_erro VARCHAR(255) DEFAULT NULL AFTER api_certificado_renovacao_em,
    ADD COLUMN api_certificado_aviso SMALLINT DEFAULT NULL AFTER api_certificado_renovacao_erro;
