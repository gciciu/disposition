#!/usr/bin/env bash
# Download the Mailpit binary into mailpit/
# Works from: scripts/ inside the repo, project root, or the client folder
# that contains a cloned project subfolder (e.g. ./delivery/).
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

resolve_project_root() {
  local base="$1" preferred="${DELIVERY_DIR_NAME:-delivery}" d
  if [[ -f "$base/backend/composer.json" ]]; then
    printf '%s\n' "$base"
    return 0
  fi
  if [[ -f "$base/../backend/composer.json" ]]; then
    (cd "$base/.." && pwd)
    return 0
  fi
  if [[ -f "$base/$preferred/backend/composer.json" ]]; then
    printf '%s\n' "$base/$preferred"
    return 0
  fi
  for d in "$base"/*/ ; do
    [[ -d "$d" ]] || continue
    if [[ -f "${d}backend/composer.json" ]]; then
      (cd "$d" && pwd)
      return 0
    fi
  done
  return 1
}

ROOT="$(resolve_project_root "$SCRIPT_DIR")" || {
  echo "ERROR: cannot find project root (backend/composer.json)." >&2
  echo "Run from the project folder, scripts/, or the client folder that contains it." >&2
  exit 1
}

DIR="$ROOT/mailpit"
VERSION="${MAILPIT_VERSION:-v1.33.0}"
ARCH="$(uname -m)"
case "$ARCH" in
  x86_64|amd64) ASSET="mailpit-linux-amd64.tar.gz" ;;
  aarch64|arm64) ASSET="mailpit-linux-arm64.tar.gz" ;;
  *)
    echo "Unsupported arch: $ARCH" >&2
    exit 1
    ;;
esac

mkdir -p "$DIR"
URL="https://github.com/axllent/mailpit/releases/download/${VERSION}/${ASSET}"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

echo "Downloading $URL"
curl -fsSL -o "$TMP/$ASSET" "$URL"
tar -xzf "$TMP/$ASSET" -C "$TMP"
BIN="$(find "$TMP" -type f -name mailpit | head -n 1)"
if [[ -z "$BIN" ]]; then
  echo "mailpit binary not found in archive" >&2
  exit 1
fi

install -m 755 "$BIN" "$DIR/mailpit"
echo "Installed $DIR/mailpit ($VERSION)"
