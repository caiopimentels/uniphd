# WhatsApp com IA — instalação no servidor

O atendimento usa três peças, todas gratuitas:

1. **Evolution API**: programa que conecta o número de WhatsApp (por QR Code) e
   avisa o sistema quando chega mensagem. Roda no próprio servidor, via Docker.
2. **Groq** e **Google Gemini**: as IAs que escrevem as respostas (planos gratuitos).
3. **O sistema**: recebe as mensagens em `/webhook/whatsapp.php`, chama a IA e
   mostra as conversas em **Secretaria › WhatsApp**.

> A Evolution API não é a API oficial da Meta. Mesmo só respondendo quem chama,
> existe um risco pequeno de o WhatsApp bloquear o número. Não use para disparo
> em massa.

> **Versão:** use a **v2.3.7**. A 2.4.0 passou a exigir registro/licença no site
> da Evolution. A versão do WhatsApp Web a Evolution busca sozinha.

---

## 1. Banco de dados do sistema

Rode a migração no banco `uniphd`:

```bash
mysql -u USUARIO -p uniphd < /var/www/uniphd/sql/migracao_2026_10_27_whatsapp_ia.sql
```

## 2. Instalar o Docker (se ainda não tiver)

```bash
docker --version || curl -fsSL https://get.docker.com | sh
```

## 3. Subir a Evolution API

```bash
mkdir -p /opt/evolution-uniphd && cd /opt/evolution-uniphd
umask 077
printf "EVOLUTION_API_KEY=%s\nPOSTGRES_PASSWORD=%s\n" "$(openssl rand -hex 24)" "$(openssl rand -hex 16)" > .env
nano docker-compose.yml
```

Conteúdo do `docker-compose.yml`:

```yaml
services:
  evolution:
    image: evoapicloud/evolution-api:v2.3.7
    container_name: evolution_uniphd
    restart: unless-stopped
    ports:
      - "127.0.0.1:8081:8080"   # só o próprio servidor acessa
    environment:
      SERVER_URL: http://127.0.0.1:8081
      AUTHENTICATION_API_KEY: ${EVOLUTION_API_KEY}
      DATABASE_ENABLED: "true"
      DATABASE_PROVIDER: postgresql
      DATABASE_CONNECTION_URI: postgresql://evolution:${POSTGRES_PASSWORD}@postgres:5432/evolution
      DATABASE_CONNECTION_CLIENT_NAME: evolution_uniphd
      DATABASE_SAVE_DATA_INSTANCE: "true"
      CACHE_REDIS_ENABLED: "false"
      CACHE_LOCAL_ENABLED: "true"
      CONFIG_SESSION_PHONE_CLIENT: UniPHD
      CONFIG_SESSION_PHONE_NAME: Chrome
      TZ: America/Sao_Paulo
    depends_on:
      - postgres
  postgres:
    image: postgres:16
    container_name: evolution_uniphd_postgres
    restart: unless-stopped
    environment:
      POSTGRES_USER: evolution
      POSTGRES_PASSWORD: ${POSTGRES_PASSWORD}
      POSTGRES_DB: evolution
    volumes:
      - ./postgres:/var/lib/postgresql/data
```

Suba e confira:

```bash
docker compose up -d
curl -s http://127.0.0.1:8081    # deve responder "Welcome to the Evolution API" com version 2.3.7
grep EVOLUTION_API_KEY .env      # é a chave que vai na configuração do sistema
```

A porta fica presa ao `127.0.0.1`: ninguém de fora acessa a Evolution API; só o
sistema, que está no mesmo servidor.

## 4. Chaves das IAs (grátis)

- **Groq**: https://console.groq.com/keys → *Create API Key*.
- **Gemini**: https://aistudio.google.com/apikey → *Criar chave de API*.

## 5. Configurar no sistema

Em **Secretaria › WhatsApp › Configurar**:

1. **Endereço da Evolution API**: `http://127.0.0.1:8081`
2. **Chave da API**: o `EVOLUTION_API_KEY` do `.env`.
3. **Nome da instância**: `uniphd`.
4. **Endereço do sistema para a Evolution API**: em branco (só se usa no teste no computador).
5. Chaves da Groq e do Gemini, a lista de IAs, as orientações, o que não
   responder, os números de aviso. Deixe o **modo teste** ligado com o seu número. Salve.
6. **Testar todas** (IAs) e **Testar aviso**.
7. **Conectar / mostrar QR Code** → no celular: WhatsApp › *Aparelhos conectados*
   › *Conectar um aparelho* → leia o QR Code.
8. Teste mandando mensagem do seu número; quando estiver bom, ligue
   **IA respondendo**, desligue o **modo teste** e salve.

## Problemas comuns

- **"Não é possível conectar novos dispositivos no momento"** no celular: muitas
  tentativas seguidas (espere algumas horas), 4 aparelhos já conectados (o máximo)
  ou Evolution API muito antiga.
- **Mensagens não aparecem no sistema**: `docker logs --tail 50 evolution_uniphd`
  mostra se o webhook falhou ao chamar o sistema.
- **IA "Falhou" em Testar todas**: o modelo foi aposentado pela empresa; troque o
  nome na lista (os do Google terminados em `-latest` não quebram).
