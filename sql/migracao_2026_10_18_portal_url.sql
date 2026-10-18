-- Endereço próprio do portal do aluno (ex.: https://portal.uniphd.com.br):
-- usado nos links que vão para o aluno (e-mails, QR Code e conferência do
-- certificado) e para mandar a raiz desse domínio direto ao portal.
ALTER TABLE configuracoes ADD COLUMN portal_url VARCHAR(255) DEFAULT NULL AFTER portal_subtitulo;
