#!/usr/bin/env python3
"""Copia as mensagens públicas originais quando o servidor estiver disponível."""
from pathlib import Path
import json
import urllib.request

root = Path(__file__).resolve().parent.parent
manifest = root / 'fonte/depoimentos-originais.json'
data = json.loads(manifest.read_text())
html_path = root / 'site/index.html'
html = html_path.read_text()
completed = 0
for i, item in enumerate(data['depoimentos'], 1):
    relative = f'assets/depoimentos/depoimento-{i:02}.webp'
    destination = root / 'site' / relative
    try:
        request = urllib.request.Request(item['origem'], headers={'Referer': 'https://uniphd.com.br/'})
        with urllib.request.urlopen(request, timeout=25) as response:
            content = response.read()
        if not content.startswith(b'RIFF') or content[8:12] != b'WEBP':
            raise ValueError('Resposta não é a imagem WebP original')
        destination.parent.mkdir(parents=True, exist_ok=True)
        destination.write_bytes(content)
        # Keep the public attribution/source links; localize only image sources.
        html = html.replace('src="' + item['origem'] + '"', 'src="' + relative + '"')
        item['arquivo'] = relative
        completed += 1
        print(f'OK: depoimento {i:02}')
    except Exception as exc:
        print(f'Não foi possível copiar depoimento {i:02}: {exc}')
html_path.write_text(html)
manifest.write_text(json.dumps(data, ensure_ascii=False, indent=2) + '\n')
print(f'{completed}/{len(data["depoimentos"])} mensagens copiadas. Fontes originais preservadas.')
