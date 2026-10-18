-- Turma: parceria (texto que sai no certificado como {parceria}) e
-- financeiro pelo valor total do curso, com forma de pagamento própria para
-- a matrícula (taxa) e para as mensalidades. Ex.: matrícula no Pix, curso
-- de R$ 1.000 em 3x no cartão.
--   turmas.portal_forma_pagamento_id passa a ser a forma da MATRÍCULA (taxa);
--   turmas.forma_mensalidade_id é a das mensalidades;
--   turmas.valor_total é o valor do curso (sem a matrícula); a mensalidade
--   (valor_mensalidade) é calculada: total ÷ parcelas.
-- Matrícula: guarda o mesmo (valor_total e forma_mensalidade_id), que a
-- secretaria pode mudar por aluno; forma_pagamento_id fica sendo a da taxa.
ALTER TABLE turmas
    ADD COLUMN parceria VARCHAR(255) DEFAULT NULL AFTER observacao,
    ADD COLUMN valor_total DECIMAL(12,2) DEFAULT NULL AFTER valor_mensalidade,
    ADD COLUMN forma_mensalidade_id INT DEFAULT NULL AFTER portal_forma_pagamento_id,
    ADD CONSTRAINT fk_turma_forma_mensalidade FOREIGN KEY (forma_mensalidade_id) REFERENCES formas_pagamento(id);

UPDATE turmas
SET valor_total = valor_mensalidade * COALESCE(portal_num_parcelas, IF(duracao_unidade = 'dia', 1, duracao_meses)),
    forma_mensalidade_id = portal_forma_pagamento_id;

ALTER TABLE matriculas
    ADD COLUMN valor_total DECIMAL(12,2) DEFAULT NULL AFTER valor_mensalidade,
    ADD COLUMN forma_mensalidade_id INT DEFAULT NULL AFTER forma_pagamento_id,
    ADD CONSTRAINT fk_matricula_forma_mensalidade FOREIGN KEY (forma_mensalidade_id) REFERENCES formas_pagamento(id);

UPDATE matriculas SET valor_total = valor_mensalidade * num_parcelas, forma_mensalidade_id = forma_pagamento_id;
