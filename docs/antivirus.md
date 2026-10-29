# Antivírus (ClamAV) — instalação no servidor

O sistema nunca abre nem executa os arquivos enviados: anexos de e-mail
**só baixam** e os materiais ficam guardados fora da parte pública. Além
disso, bloqueia sozinho programas, scripts, documentos com macro, PDF com
código e compactados com programa dentro.

O ClamAV (gratuito) é uma camada a mais: quando instalado, todo anexo
baixado/enviado e todo material enviado passa por ele. O sistema encontra o
ClamAV sozinho — não precisa configurar nada no sistema.

## Instalar (Ubuntu)

```bash
sudo apt update
sudo apt install -y clamav clamav-daemon
sudo systemctl stop clamav-freshclam
sudo freshclam                     # baixa a lista de vírus (alguns minutos)
sudo systemctl enable --now clamav-freshclam clamav-daemon   # atualiza sozinho todo dia
```

Confira em **Configurações › E-mail** (ou **Arquivos**): deve aparecer
"Antivírus: Ativo".

Teste com o arquivo de teste oficial (inofensivo) EICAR:

```bash
printf 'X5O!P%%@AP[4\\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*' > /tmp/eicar.txt
clamdscan --fdpass /tmp/eicar.txt   # deve dizer: Eicar-Signature FOUND
rm /tmp/eicar.txt
```

## Memória

O serviço (`clamav-daemon`) usa cerca de 1 a 1,5 GB de RAM. Confira com
`free -h`. Se o servidor tiver pouca memória, instale só o `clamav` (sem o
daemon): o sistema usa o `clamscan`, que não fica ligado o tempo todo, mas
demora uns segundos a mais em cada verificação.
