#!/usr/bin/env bash
# Site Cinematográfico · © 2026 Labrego IA · LBG-SC-2026-v1
# Fatia o vídeo em frames para a rolagem cinematográfica.
# Usa WebP (libwebp do ffmpeg ou cwebp); sem nenhum dos dois, cai para JPG.
# Uso: fatiar_frames.sh <video.mp4> <pasta-saida> [quantidade=160]
set -euo pipefail

VIDEO="${1:?informe o vídeo}"
SAIDA="${2:?informe a pasta de saída}"
QTD="${3:-160}"

command -v ffmpeg >/dev/null || { echo "ffmpeg não encontrado (brew install ffmpeg)"; exit 1; }
source "$(dirname "$0")/_codificador.sh"

DUR=$(ffprobe -v error -show_entries format=duration -of csv=p=0 "$VIDEO")
FPS=$(python3 -c "print(round($QTD / float('$DUR'), 4))")

rm -rf "$SAIDA/desktop" "$SAIDA/mobile"
mkdir -p "$SAIDA/desktop" "$SAIDA/mobile"

# Desktop: 1920 de largura. Mobile: recorte 720×1080 centrado no gesto da seringa.
extrair "$VIDEO" "fps=$FPS,scale=1920:-2:flags=lanczos" "$SAIDA/desktop" 88
extrair "$VIDEO" "fps=$FPS,crop=ih*2/3:ih:iw*0.56-ih/3:0,scale=720:1080:flags=lanczos" "$SAIDA/mobile" 84

N=$(ls "$SAIDA/desktop" | wc -l | tr -d ' ')
PRIMEIRO="$SAIDA/desktop/f_0001.$EXT"
W=$(ffprobe -v error -select_streams v:0 -show_entries stream=width -of csv=p=0 "$PRIMEIRO")
H=$(ffprobe -v error -select_streams v:0 -show_entries stream=height -of csv=p=0 "$PRIMEIRO")

cat > "$SAIDA/frames.json" <<JSON
{ "total": $N, "largura": $W, "altura": $H,
  "desktop": "assets/frames/desktop/f_{n}.$EXT",
  "mobile": "assets/frames/mobile/f_{n}.$EXT" }
JSON

PESO_D=$(du -sh "$SAIDA/desktop" | cut -f1)
PESO_M=$(du -sh "$SAIDA/mobile" | cut -f1)
echo "OK: $N frames .$EXT (${W}x${H}) · desktop $PESO_D · mobile $PESO_M"
echo "Em config.js → frames: { total: $N, desktop: \"assets/frames/desktop/f_{n}.$EXT\", mobile: \"assets/frames/mobile/f_{n}.$EXT\" }"
