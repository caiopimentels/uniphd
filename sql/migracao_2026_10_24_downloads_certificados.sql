-- Controle de quando o aluno abre/baixa o documento pelo portal: a primeira
-- vez, a última e quantas vezes (tela Acadêmico › Certificados emitidos).
ALTER TABLE certificados
    ADD COLUMN aluno_baixou_em DATETIME DEFAULT NULL AFTER emitido_em,
    ADD COLUMN aluno_ultimo_download DATETIME DEFAULT NULL AFTER aluno_baixou_em,
    ADD COLUMN aluno_downloads INT NOT NULL DEFAULT 0 AFTER aluno_ultimo_download;
