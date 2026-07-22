#!/bin/sh
set -eu

ROOT="$(CDPATH= cd -- "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

echo "==> Assembling dist/ (workspace vendor left untouched)"
rm -rf dist grandpasson-release.zip
mkdir dist

# Exact deployable set (MVP T12 / spec §18.3), plus lock for reproducible composer prune.
cp -a public_html app cron composer.json .env.example dist/
if [ -f composer.lock ]; then
  cp -a composer.lock dist/
fi
cp -a vendor dist/vendor

echo "==> Pruning to production Composer dependencies inside dist/"
(
  cd dist
  # Offline prune using the copied vendor (no Packagist/GitHub required).
  COMPOSER_DISABLE_NETWORK=1 \
    composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist
  rm -f composer.lock
)

echo "==> Creating grandpasson-release.zip"
(cd dist && zip -qr ../grandpasson-release.zip .)

echo "==> Verifying zip contents"
LIST="$(unzip -Z1 grandpasson-release.zip)"

for required in composer.json .env.example; do
  echo "$LIST" | grep -qx "$required" || {
    echo "missing required file: $required" >&2
    exit 1
  }
done

for prefix in public_html/ app/ vendor/ cron/; do
  echo "$LIST" | grep -q "^${prefix}" || {
    echo "missing required tree: $prefix" >&2
    exit 1
  }
done

echo "$LIST" | grep -E '^(tests/|docker/|docs/|\.git/)' && {
  echo "forbidden path present in zip" >&2
  exit 1
} || true

echo "$LIST" | grep -E '^vendor/phpunit/|^vendor/bin/phpunit' && {
  echo "phpunit must not ship in zip" >&2
  exit 1
} || true

count="$(echo "$LIST" | wc -l | tr -d ' ')"
echo "OK: ${count} entries in grandpasson-release.zip"

echo "==> Done: $ROOT/grandpasson-release.zip"
