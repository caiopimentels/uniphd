-- Marca d'água (logo do sistema repetida em diagonal) por modelo de
-- documento: ligada/desligada, tamanho, espaço entre as logos e intensidade.
-- JSON {"ativa":1,"tamanho":8,"espaco":1.5,"opacidade":10}; vazio = padrão (ligada).
ALTER TABLE certificado_modelos ADD COLUMN marca_dagua VARCHAR(255) DEFAULT NULL AFTER padrao;
