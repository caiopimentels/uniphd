-- Rodapé do portal: escolher item a item o que aparece (endereço, telefone,
-- e-mail, link do WhatsApp e o botão flutuante do WhatsApp).
-- JSON {"endereco":1,"telefone":1,"email":1,"whatsapp":0,"whatsapp_flutuante":1};
-- vazio = segue portal_rodape_contatos (como antes), sem o link duplicado do WhatsApp.
ALTER TABLE configuracoes ADD COLUMN portal_rodape_itens VARCHAR(255) DEFAULT NULL AFTER portal_rodape_contatos;
