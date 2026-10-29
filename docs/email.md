# E-mail no sistema — instalação no servidor

Cada usuário lê e envia a **própria caixa** (menu E-mail): o servidor de
e-mail (IMAP para receber, SMTP para enviar) é o mesmo para todos e fica em
**Configurações › E-mail**; o e-mail e a senha de cada um ficam no cadastro
dele (Usuários) ou em **E-mail › Minha caixa e assinatura**.

Não precisa instalar nada no PHP: o sistema fala IMAP sozinho.

## 1. Banco de dados

```bash
mysql -u USUARIO -p uniphd < /var/www/uniphd/sql/migracao_2026_10_28_email.sql
```

## 2. Tamanho dos anexos (PHP)

O Ubuntu vem com limite de 2 MB por arquivo e 8 MB por envio. Para anexos de
até 20 MB:

```bash
sudo sed -i 's/^upload_max_filesize.*/upload_max_filesize = 25M/; s/^post_max_size.*/post_max_size = 30M/' /etc/php/8.3/apache2/php.ini
sudo systemctl reload apache2
php -r 'echo ini_get("upload_max_filesize");'   # o do Apache confira em uma página de teste, se quiser
```

## 3. Agendador (disparo em massa)

Os disparos saem aos poucos (limite por hora em Configurações › E-mail). Com
a tela do disparo aberta ela mesma vai enviando; fechada, quem continua é o
cron, a cada 5 minutos:

```bash
sudo crontab -u www-data -e
# acrescente a linha:
*/5 * * * * php /var/www/uniphd/scripts/rotinas.php emails >> /var/www/uniphd/storage/rotinas.log 2>&1
```

## 4. Configurar

1. **Configurações › E-mail**: servidor de entrada `mail.uniphd.com.br`, porta
   993, SSL; saída `mail.uniphd.com.br`, porta 465, SSL. Salve.
2. **Usuários › (cada um) › Caixa de e-mail**: e-mail e senha da caixa,
   nome e assinatura → **Testar conexão**.
3. Se der erro de certificado, desligue "Conferir o certificado do servidor".

## Problemas comuns

- **"E-mail ou senha recusados"**: a senha é a da caixa no provedor (a do
  webmail), não a do sistema.
- **Anexo "não chegou"**: limite do PHP (passo 2).
- **Disparo pausou sozinho**: o servidor recusou a senha ou a conexão; confira
  e clique em Retomar na tela do disparo.
- **E-mails do disparo caindo no spam**: diminua o limite por hora e confira
  se o domínio tem SPF/DKIM configurados no provedor.
