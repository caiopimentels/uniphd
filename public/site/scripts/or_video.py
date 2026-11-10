#!/usr/bin/env python3
# Site Cinematográfico · © 2026 Labrego IA · LBG-SC-2026-v1
"""Gera o vídeo de abertura no OpenRouter (POST /api/v1/videos), espera e baixa o MP4.

Exemplos:
  or_video.py --listar
  or_video.py --prompt "..." --primeiro-quadro quadro.png --duracao 8 --saida abertura.mp4
"""
import argparse
import sys
import time
import json
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent))
from or_comum import custo, data_url, requisicao  # noqa: E402

PADRAO = "google/veo-3.1"


def listar():
    dados = requisicao("GET", "/videos/models", com_chave=False)
    for m in dados.get("data", []):
        print(
            f"{m.get('id'):<40} dur={m.get('supported_durations')} "
            f"res={m.get('supported_resolutions')} aspect={m.get('supported_aspect_ratios')}"
        )
        if m.get("pricing"):
            print(f"{'':<40} preço={m['pricing']}")


def validar(modelo, duracao, resolucao, aspect):
    dados = requisicao("GET", "/videos/models", com_chave=False)
    info = next((m for m in dados.get("data", []) if m.get("id") == modelo), None)
    if not info:
        sys.exit(f"Modelo {modelo} não está na lista de vídeo do OpenRouter. Rode --listar.")
    problemas = []
    if info.get("supported_durations") and duracao not in info["supported_durations"]:
        problemas.append(f"duração {duracao} (aceita {info['supported_durations']})")
    if info.get("supported_resolutions") and resolucao not in info["supported_resolutions"]:
        problemas.append(f"resolução {resolucao} (aceita {info['supported_resolutions']})")
    if info.get("supported_aspect_ratios") and aspect not in info["supported_aspect_ratios"]:
        problemas.append(f"proporção {aspect} (aceita {info['supported_aspect_ratios']})")
    if problemas:
        sys.exit(f"{modelo} não aceita: " + "; ".join(problemas))


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--listar", action="store_true", help="lista modelos de vídeo e parâmetros")
    ap.add_argument("--prompt")
    ap.add_argument("--modelo", default=PADRAO)
    ap.add_argument("--duracao", type=int, default=8)
    ap.add_argument("--resolucao", default="1080p")
    ap.add_argument("--aspect", default="16:9")
    ap.add_argument("--primeiro-quadro", help="imagem que vira o 1º frame (imagem para vídeo)")
    ap.add_argument("--ultimo-quadro", help="imagem que vira o último frame (opcional)")
    ap.add_argument("--referencia", action="append", default=[], help="imagem de referência de estilo (repetível)")
    ap.add_argument("--com-audio", action="store_true", help="por padrão o vídeo sai sem áudio")
    ap.add_argument("--seed", type=int)
    ap.add_argument("--saida", default="abertura.mp4")
    ap.add_argument("--intervalo", type=int, default=15, help="segundos entre consultas de status")
    ap.add_argument("--limite", type=int, default=1200, help="tempo máximo de espera em segundos")
    a = ap.parse_args()

    if a.listar:
        return listar()
    if not a.prompt:
        ap.error("--prompt é obrigatório")

    validar(a.modelo, a.duracao, a.resolucao, a.aspect)

    corpo = {
        "model": a.modelo,
        "prompt": a.prompt,
        "duration": a.duracao,
        "resolution": a.resolucao,
        "aspect_ratio": a.aspect,
        "generate_audio": bool(a.com_audio),
    }
    if a.seed is not None:
        corpo["seed"] = a.seed
    quadros = []
    if a.primeiro_quadro:
        quadros.append({"type": "image_url", "image_url": {"url": data_url(a.primeiro_quadro)}, "frame_type": "first_frame"})
    if a.ultimo_quadro:
        quadros.append({"type": "image_url", "image_url": {"url": data_url(a.ultimo_quadro)}, "frame_type": "last_frame"})
    if quadros:
        corpo["frame_images"] = quadros
    elif a.referencia:
        corpo["input_references"] = [{"type": "image_url", "image_url": {"url": data_url(r)}} for r in a.referencia]

    job = requisicao("POST", "/videos", corpo)
    registro = Path(a.saida).with_suffix(".job.json")
    registro.parent.mkdir(parents=True, exist_ok=True)
    registro.write_text(json.dumps(job, indent=2))
    jid = job.get("id")
    url_status = job.get("polling_url") or f"/videos/{jid}"
    print(f"Job {jid} enviado ({a.modelo}). Aguardando…", flush=True)

    inicio = time.time()
    while True:
        st = requisicao("GET", url_status)
        estado = st.get("status")
        if estado == "completed":
            break
        if estado == "failed":
            sys.exit(f"Geração falhou: {st.get('error')}")
        if time.time() - inicio > a.limite:
            sys.exit(f"Tempo esgotado. Consulte depois: GET /api/v1/videos/{jid}")
        print(f"  {estado}… {int(time.time() - inicio)}s", flush=True)
        time.sleep(a.intervalo)

    urls = st.get("unsigned_urls") or [f"/videos/{jid}/content?index=0"]
    video = requisicao("GET", urls[0], bruto=True, timeout=600)
    saida = Path(a.saida)
    saida.parent.mkdir(parents=True, exist_ok=True)
    saida.write_bytes(video)
    saida.with_suffix(".usage.json").write_text(json.dumps({"model": a.modelo, "usage": st.get("usage")}, indent=2))
    print(f"OK: {saida} ({len(video) / 1e6:.1f} MB) {custo(st.get('usage'))}")


if __name__ == "__main__":
    main()
