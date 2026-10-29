# Materiais dos cursos e turmas — instalação no servidor

Apostilas, slides, artigos e links que a secretaria e os professores enviam
(Acadêmico › Materiais) e os alunos baixam em **Meus cursos**, no portal.

## 1. Banco de dados

```bash
mysql -u USUARIO -p uniphd < /var/www/uniphd/sql/migracao_2026_10_28_materiais.sql
```

Cria também o perfil **Professor** (só materiais).

## 2. Tamanho dos arquivos (PHP)

Cada arquivo vai até 10 MB (Configurações › Arquivos). O PHP do servidor
precisa aceitar isso — se já fez o passo do e-mail (25M / 30M), está pronto:

```bash
sudo sed -i 's/^upload_max_filesize.*/upload_max_filesize = 25M/; s/^post_max_size.*/post_max_size = 30M/' /etc/php/8.3/apache2/php.ini
sudo systemctl reload apache2
```

## 3. Onde guardar (Configurações › Arquivos)

- **No servidor**: não precisa fazer nada (fica em `storage/materiais`, fora
  da parte pública do site).
- **No FTP do TurboCloud**: no painel do TurboCloud, crie uma conta FTP e
  preencha endereço, usuário, senha e uma pasta **fora** do `public_html`
  (ex.: `/materiais`). Clique em **Testar FTP**. Se o TurboCloud não aceitar,
  deixe "No próprio servidor".

O limite total (padrão 15 GB) fica na mesma tela.

## 4. Professores

1. Acadêmico › Professores: o cadastro do professor.
2. Usuários › Novo usuário: perfil **Professor** e, em "É professor?",
   escolha o cadastro dele.
3. Turma: ele vê as turmas em que é o professor principal; para outras (ex.:
   um módulo da pós), Materiais › turma › **Professores com acesso** › Liberar.

## 5. Segurança

Os arquivos são conferidos pelo conteúdo (não só pelo nome): programas,
scripts, documentos com macro e PDF com código são recusados. Com o
antivírus instalado (docs/antivirus.md), tudo passa por ele também.
