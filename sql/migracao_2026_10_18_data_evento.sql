-- Data do evento do documento liberado (ex.: o dia da aula numa declaração de
-- presença avulsa) — marcador {data_evento} no modelo, por extenso.
ALTER TABLE certificados ADD COLUMN data_evento DATE DEFAULT NULL AFTER curso_emissao_id;
