#!/usr/bin/env bash
# Site Cinematográfico · © 2026 Labrego IA · LBG-SC-2026-v1
# Converte as imagens geradas para WebP leve (2400px máx.), mantendo transparência.
# Uso: otimizar_imagens.sh <pasta-origem> <pasta-destino>
set -euo pipefail

ORIGEM="${1:?informe a pasta de origem}"
DESTINO="${2:?informe a pasta de destino}"
mkdir -p "$DESTINO"
source "$(dirname "$0")/_codificador.sh"

shopt -s nullglob nocaseglob
for f in "$ORIGEM"/*.{png,jpg,jpeg,webp}; do
  nome=$(basename "${f%.*}")
  case "$nome" in quadro-chave|check_*) continue ;; esac
  extrair "$f" "scale='min(2400,iw)':-2:flags=lanczos" "$DESTINO" 82 "$nome"
  echo "$DESTINO/$nome.$EXT  $(du -h "$DESTINO/$nome.$EXT" | cut -f1)"
done
