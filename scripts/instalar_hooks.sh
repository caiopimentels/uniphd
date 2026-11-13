#!/bin/sh
# Liga os gatilhos do Git deste projeto (só precisa rodar uma vez em cada
# computador que faz commit): hoje, o que atualiza a VERSAO do rodapé.
raiz=$(git rev-parse --show-toplevel) || exit 1
cp "$raiz/scripts/git-hooks/pre-commit" "$raiz/.git/hooks/pre-commit"
chmod +x "$raiz/.git/hooks/pre-commit"
echo "Gatilho instalado: a versão sobe sozinha a cada commit."
