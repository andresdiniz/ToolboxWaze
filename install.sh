#!/usr/bin/env bash
# Instala o módulo Saúde (CNES) no projeto ToolboxWaze.
# Uso: ./install.sh /caminho/do/ToolboxWaze [--dry-run]
set -euo pipefail

AQUI="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DESTINO="${1:-}"
DRY="${2:-}"

if [[ -z "$DESTINO" || ! -f "$DESTINO/composer.json" || ! -f "$DESTINO/src/Entity/User.php" ]]; then
  echo "Uso: $0 /caminho/do/ToolboxWaze [--dry-run]" >&2
  echo "A pasta informada precisa ser a raiz do projeto (com composer.json e src/Entity/User.php)." >&2
  exit 1
fi

BACKUP="$DESTINO/.saude-backup/$(date +%Y%m%d-%H%M%S)"
novos=0; substituidos=0

while IFS= read -r -d '' arq; do
  rel="${arq#"$AQUI"/}"
  case "$rel" in install.sh|INSTALL.md|docs/API_SAUDE.md) [[ "$rel" == docs/API_SAUDE.md ]] || continue ;; esac

  alvo="$DESTINO/$rel"
  if [[ -f "$alvo" ]]; then
    if cmp -s "$arq" "$alvo"; then continue; fi
    echo "SUBSTITUI  $rel"
    substituidos=$((substituidos+1))
    if [[ "$DRY" != "--dry-run" ]]; then
      mkdir -p "$BACKUP/$(dirname "$rel")"
      cp "$alvo" "$BACKUP/$rel"
    fi
  else
    echo "NOVO       $rel"
    novos=$((novos+1))
  fi

  if [[ "$DRY" != "--dry-run" ]]; then
    mkdir -p "$(dirname "$alvo")"
    cp "$arq" "$alvo"
  fi
done < <(find "$AQUI" -type f -not -path "$AQUI/.git/*" -print0 | sort -z)

echo
echo "$novos novo(s), $substituidos substituído(s)."
if [[ "$DRY" == "--dry-run" ]]; then
  echo "(dry-run: nada foi copiado)"
else
  [[ $substituidos -gt 0 ]] && echo "Backup dos originais em: $BACKUP"
  cat <<'MSG'

Próximos passos (na raiz do projeto):
  php bin/console doctrine:migrations:migrate
  php bin/console cache:clear
  # Admin > Usuários: marcar a permissão "Saúde" para quem vai usar
  php bin/console app:import-saude --file=/caminho/cnes.csv
MSG
fi
