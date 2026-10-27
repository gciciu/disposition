#!/usr/bin/env bash
# Download the official Mercure hub binary into mercure/
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DIR="$ROOT/mercure"
VERSION="${MERCURE_VERSION:-v1.1.5}"
ARCH="$(uname -m)"
case "$ARCH" in
  x86_64|amd64) ASSET="mercure_Linux_x86_64.tar.gz" ;;
  aarch64|arm64) ASSET="mercure_Linux_arm64.tar.gz" ;;
  armv7l) ASSET="mercure_Linux_armv7.tar.gz" ;;
  *)
    echo "Unsupported arch: $ARCH" >&2
    exit 1
    ;;
esac

URL="https://github.com/dunglas/mercure/releases/download/${VERSION}/${ASSET}"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

echo "Downloading $URL"
curl -fsSL -o "$TMP/$ASSET" "$URL"
tar -xzf "$TMP/$ASSET" -C "$TMP"
BIN="$(find "$TMP" -type f -name mercure | head -n 1)"
if [[ -z "$BIN" ]]; then
  echo "mercure binary not found in archive" >&2
  exit 1
fi

install -m 755 "$BIN" "$DIR/mercure"
echo "Installed $DIR/mercure ($VERSION)"
