# Site Cinematográfico · © 2026 Labrego IA · LBG-SC-2026-v1
# Escolhe o codificador de imagem disponível. Define EXT e a função extrair().
# extrair <entrada> <filtro-ffmpeg> <pasta-saida> <qualidade> [nome-unico]

if ffmpeg -hide_banner -encoders 2>/dev/null | grep -q libwebp; then
  MODO=ffmpeg-webp; EXT=webp
elif command -v cwebp >/dev/null; then
  MODO=cwebp; EXT=webp
else
  MODO=jpg; EXT=jpg
  echo "Aviso: sem WebP (instale com: brew install webp). Usando JPG." >&2
fi

extrair() {
  local entrada="$1" filtro="$2" pasta="$3" q="$4" nome="${5:-f_%04d}"
  case "$MODO" in
    ffmpeg-webp)
      ffmpeg -v error -y -i "$entrada" -vf "$filtro" -c:v libwebp -quality "$q" -compression_level 6 "$pasta/$nome.webp" ;;
    cwebp)
      local tmp; tmp=$(mktemp -d)
      ffmpeg -v error -y -i "$entrada" -vf "$filtro" "$tmp/$nome.png"
      find "$tmp" -name '*.png' -print0 | xargs -0 -P 8 -I{} sh -c \
        'cwebp -quiet -q '"$q"' -alpha_q 90 "$1" -o "'"$pasta"'/$(basename "${1%.png}").webp"' _ {}
      rm -rf "$tmp" ;;
    jpg)
      ffmpeg -v error -y -i "$entrada" -vf "$filtro" -q:v 3 "$pasta/$nome.jpg" ;;
  esac
}
