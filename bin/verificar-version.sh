#!/usr/bin/env bash
# verificar-version.sh — una sola versión en todo el repo.
#
# Por qué existe: el desfase entre cabecera, Stable tag, CHANGELOG y la constante del plugin ya
# nos costó un PCP en rojo y assets servidos con la versión vieja (la constante clavada en un
# literal antiguo mientras la cabecera iba por delante).
#
# Reglas que comprueba:
#   1. Cabecera del plugin (o style.css del tema) == Stable tag de readme.txt.
#   2. Cabecera == primera versión de CHANGELOG.md.
#   3. Si la constante PROPIA del plugin lleva un literal, debe ser igual a la cabecera.
#      Si se deriva de la cabecera (`! empty( $header['Version'] ) ? ... : 'x'`) no se comprueba:
#      esa va siempre en sync y es el patrón bueno.
#   Las constantes *_DB_VERSION se ignoran a propósito (versionan esquema, no el plugin) y las de
#   otros plugins también (Features.php las usa para saber si el módulo está activo).
#
# Uso: verificar-version.sh [directorio]   (sin argumento: el actual)
# Sale 0 si todo coincide, 1 si hay desfase.
set -uo pipefail

REPO="${1:-.}"
cd "$REPO" || { echo "no existe el directorio $REPO" >&2; exit 2; }
nombre=$(basename "$PWD")
fallos=0

# --- 1. Cabecera -----------------------------------------------------------------
version=""; fuente=""; slug=""
for f in *.php; do
    [[ -f $f ]] || continue
    if head -40 "$f" | grep -q 'Plugin Name:'; then
        version=$(head -40 "$f" | grep -m1 -E '^[[:space:]]*\*?[[:space:]]*Version:' | sed -E 's/.*Version:[[:space:]]*//' | tr -d '\r' | xargs)
        fuente="$f"; slug="${f%.php}"; break
    fi
done
if [[ -z $version && -f style.css ]]; then
    version=$(grep -m1 -E '^[[:space:]]*Version:' style.css | sed -E 's/.*Version:[[:space:]]*//' | tr -d '\r' | xargs)
    fuente="style.css"; slug="theme"
fi
if [[ -z $version ]]; then
    echo "· $nombre: sin cabecera de versión, nada que comprobar"
    exit 0
fi

# --- 2. Stable tag ---------------------------------------------------------------
if [[ -f readme.txt ]]; then
    stable=$(grep -m1 -E '^[[:space:]]*Stable tag:' readme.txt | sed -E 's/.*Stable tag:[[:space:]]*//' | tr -d '\r' | xargs)
    if [[ -n $stable && $stable != "$version" ]]; then
        echo "✗ Stable tag ($stable) ≠ cabecera ($version)"; fallos=$((fallos+1))
    fi
fi

# --- 3. CHANGELOG ----------------------------------------------------------------
if [[ -f CHANGELOG.md ]]; then
    chlog=$(grep -m1 -oE '^## \[?[0-9]+\.[0-9]+\.[0-9]+' CHANGELOG.md | grep -oE '[0-9]+\.[0-9]+\.[0-9]+')
    if [[ -n $chlog && $chlog != "$version" ]]; then
        echo "✗ CHANGELOG ($chlog) ≠ cabecera ($version)"; fallos=$((fallos+1))
    fi
fi

# --- 4. Constante propia, solo si es un literal ----------------------------------
prefijo="CONVOCA_$(echo "${slug:-$nombre}" | tr 'a-z-' 'A-Z_')_VERSION"
propia=$(grep -rhE "define\([[:space:]]*'$prefijo'[[:space:]]*,[[:space:]]*'[0-9]+\.[0-9]+\.[0-9]+'" \
            --include='*.php' . 2>/dev/null | grep -v '/vendor/' | grep -v '/build/' | head -1)
if [[ -n $propia ]]; then
    valor=$(sed -E "s/.*,[[:space:]]*'([^']+)'.*/\1/" <<<"$propia")
    if [[ $valor != "$version" ]]; then
        echo "✗ $prefijo literal ($valor) ≠ cabecera ($version) — debe derivarse de la cabecera"; fallos=$((fallos+1))
    fi
fi

if (( fallos > 0 )); then
    echo "✗ $nombre: $fallos desfase(s) de versión"; exit 1
fi
echo "✓ $nombre: versión $version coherente ($fuente)"
exit 0
