-- Formas de pagamento: ficam só Boleto e Boleto + Pix (além das manuais).
-- O Pix sozinho (sem boleto) não é emitido pelo sistema — quem estava como
-- "Pix" já gerava boleto com Pix; agora fica explícito.
UPDATE formas_pagamento SET instrumento = 'boleto_pix' WHERE instrumento = 'pix';
