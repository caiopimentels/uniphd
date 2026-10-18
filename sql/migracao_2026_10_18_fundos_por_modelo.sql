-- Fundos dos documentos passam a ser de cada modelo: no editor de um modelo
-- só aparecem (e só se excluem) os fundos dele.
ALTER TABLE certificado_fundos
    ADD COLUMN modelo_id INT DEFAULT NULL AFTER id,
    ADD KEY idx_fundo_modelo (modelo_id);

-- Cada fundo vai para o modelo que o usa (se mais de um usa, cada um ganha
-- a sua cópia do registro, apontando para o mesmo arquivo).
INSERT INTO certificado_fundos (modelo_id, nome, imagem, criado_em)
SELECT m.id, f.nome, f.imagem, f.criado_em
FROM certificado_fundos f
JOIN certificado_modelos m ON m.fundo = f.imagem OR LOCATE(f.imagem, m.layout) > 0 OR LOCATE(REPLACE(f.imagem, '/', '\\/'), m.layout) > 0
WHERE f.modelo_id IS NULL;
DELETE f FROM certificado_fundos f
WHERE f.modelo_id IS NULL AND EXISTS (SELECT 1 FROM (SELECT imagem FROM certificado_fundos WHERE modelo_id IS NOT NULL) x WHERE x.imagem = f.imagem);
-- Os que nenhum modelo usa ficam no modelo padrão.
UPDATE certificado_fundos SET modelo_id = (SELECT id FROM certificado_modelos WHERE padrao = 1 ORDER BY id LIMIT 1) WHERE modelo_id IS NULL;
DELETE FROM certificado_fundos WHERE modelo_id IS NULL;

ALTER TABLE certificado_fundos
    MODIFY modelo_id INT NOT NULL,
    ADD CONSTRAINT fk_fundo_modelo FOREIGN KEY (modelo_id) REFERENCES certificado_modelos(id) ON DELETE CASCADE;
