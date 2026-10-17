-- Aviso de Pix na hora (webhook Pix do Itaú).
-- Guarda o txid do QR Code de cada boleto (é por ele que o aviso de Pix
-- identifica a cobrança) e quando o aviso foi cadastrado no Itaú.
-- Para quem já tem o sistema instalado; instalação nova: o schema.sql já cria.
-- Uso: mysql -u USUARIO -p NOME_DO_BANCO < sql/migracao_2026_10_17_aviso_pix.sql

ALTER TABLE cobrancas_bancarias ADD COLUMN txid VARCHAR(35) DEFAULT NULL AFTER pix_copia_cola, ADD KEY idx_cobranca_txid (txid);
ALTER TABLE contas_bancarias ADD COLUMN webhook_pix_cadastrado_em DATETIME DEFAULT NULL AFTER webhook_cadastrado_em;

-- Boletos já registrados: o txid está na resposta que o banco devolveu.
UPDATE cobrancas_bancarias
   SET txid = COALESCE(JSON_UNQUOTE(JSON_EXTRACT(resposta, '$.data.dados_qrcode.txid')), JSON_UNQUOTE(JSON_EXTRACT(resposta, '$.dados_qrcode.txid')))
 WHERE txid IS NULL AND resposta IS NOT NULL;
