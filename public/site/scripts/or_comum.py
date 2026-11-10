# Site Cinematográfico · © 2026 Labrego IA · LBG-SC-2026-v1
# Funções comuns de acesso ao OpenRouter (só biblioteca padrão do Python).
import base64
import json
import mimetypes
import os
import sys
import urllib.error
import urllib.request
from pathlib import Path

API = "https://openrouter.ai/api/v1"
ASSINATURA = "LBG-SC-2026-v1"


def chave():
    k = os.environ.get("OPENROUTER_API_KEY", "").strip()
    if k:
        return k
    env = Path.cwd() / ".env"
    if env.exists():
        for linha in env.read_text().splitlines():
            if linha.startswith("OPENROUTER_API_KEY="):
                return linha.split("=", 1)[1].strip().strip('"').strip("'")
    arq = Path.home() / ".config" / "labrego" / "openrouter.key"
    if arq.exists():
        return arq.read_text().strip()
    sys.exit(
        "Chave do OpenRouter não encontrada. Defina OPENROUTER_API_KEY, um .env no projeto "
        "ou ~/.config/labrego/openrouter.key"
    )


def _cabecalhos(com_chave=True):
    h = {
        "Content-Type": "application/json",
        "HTTP-Referer": "https://labregoia.com.br",
        "X-Title": "Site Cinematografico Labrego IA",
    }
    if com_chave:
        h["Authorization"] = f"Bearer {chave()}"
    return h


def requisicao(metodo, url, corpo=None, com_chave=True, bruto=False, timeout=180):
    if not url.startswith("http"):
        url = API + url
    dados = json.dumps(corpo).encode() if corpo is not None else None
    req = urllib.request.Request(url, data=dados, method=metodo, headers=_cabecalhos(com_chave))
    try:
        with urllib.request.urlopen(req, timeout=timeout) as r:
            conteudo = r.read()
    except urllib.error.HTTPError as e:
        msg = e.read().decode(errors="replace")[:800]
        sys.exit(f"OpenRouter respondeu {e.code} em {url}:\n{msg}")
    except urllib.error.URLError as e:
        sys.exit(f"Falha de rede ao chamar {url}: {e.reason}")
    return conteudo if bruto else json.loads(conteudo)


def data_url(caminho):
    p = Path(caminho)
    if not p.exists():
        sys.exit(f"Arquivo não encontrado: {p}")
    tipo = mimetypes.guess_type(p.name)[0] or "image/png"
    return f"data:{tipo};base64," + base64.b64encode(p.read_bytes()).decode()


def custo(usage):
    if not usage:
        return ""
    c = usage.get("cost")
    return f"custo: US$ {c:.4f}" if isinstance(c, (int, float)) else ""
