#!/usr/bin/env python3
# Site Cinematográfico · © 2026 Labrego IA · LBG-SC-2026-v1
"""Gera uma imagem no OpenRouter (POST /api/v1/images) e salva em disco.

Exemplos:
  or_image.py --listar
  or_image.py --prompt "..." --aspect 16:9 --resolucao 2K --saida fonte/servicos.png
"""
import argparse
import base64
import sys
import json
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent))
from or_comum import custo, requisicao, data_url  # noqa: E402

PADRAO = "google/gemini-3-pro-image"


def listar():
    dados = requisicao("GET", "/images/models", com_chave=False)
    for m in dados.get("data", []):
        linha = f"{m.get('id')}"
        if m.get("pricing"):
            linha += f"  preço={m['pricing']}"
        print(linha)


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--listar", action="store_true", help="lista modelos de imagem")
    ap.add_argument("--prompt")
    ap.add_argument("--modelo", default=PADRAO)
    ap.add_argument("--aspect", default="16:9")
    ap.add_argument("--resolucao", default="2K")
    ap.add_argument("--referencia", action="append", default=[])
    ap.add_argument("--quality", default="high")
    ap.add_argument("--saida", default="imagem.png")
    a = ap.parse_args()

    if a.listar:
        return listar()
    if not a.prompt:
        ap.error("--prompt é obrigatório")

    corpo = {"model": a.modelo, "prompt": a.prompt, "aspect_ratio": a.aspect, "resolution": a.resolucao}
    if a.modelo.startswith("openai/gpt-image"):
        corpo.pop("resolution")
        corpo["quality"] = a.quality
    if a.referencia:
        corpo["input_references"] = [{"type": "image_url", "image_url": {"url": data_url(r)}} for r in a.referencia]
    r = requisicao("POST", "/images", corpo, timeout=300)
    itens = r.get("data") or []
    if not itens or not itens[0].get("b64_json"):
        sys.exit(f"Resposta sem imagem: {str(r)[:500]}")

    saida = Path(a.saida)
    tipo = itens[0].get("media_type", "image/png")
    ext = {"image/jpeg": ".jpg", "image/webp": ".webp"}.get(tipo, ".png")
    if saida.suffix.lower() != ext:
        saida = saida.with_suffix(ext)
    saida.parent.mkdir(parents=True, exist_ok=True)
    saida.write_bytes(base64.b64decode(itens[0]["b64_json"]))
    saida.with_suffix(".usage.json").write_text(json.dumps({"model": a.modelo, "usage": r.get("usage")}, indent=2))
    print(f"OK: {saida} {custo(r.get('usage'))}")


if __name__ == "__main__":
    main()
