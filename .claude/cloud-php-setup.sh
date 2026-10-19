#!/usr/bin/env bash
# Make the repo runnable in a Claude Code cloud container whose PHP is too old.
# See "Cloud sessions" in CLAUDE.md. Idempotent: re-running skips finished steps.
#
# The container ships PHP 8.3; composer.lock needs >= 8.4. The PHP PPA,
# Debian mirrors and getcomposer.org are blocked by the egress proxy, but
# Docker Hub and archive.ubuntu.com aren't — so PHP 8.5 comes from an
# ubuntu:26.04 image, wrapped as `dphp`. Composer runs on the host
# (its 8.3 is fine for installing) with git sources, because GitHub
# zipball downloads are blocked too.
set -euo pipefail

REPO="$(cd "$(dirname "$0")/.." && pwd)"
IMAGE=offing-php
CACHE=/tmp/offing-cloud
mkdir -p "$CACHE/composer"

if php -r 'exit(PHP_VERSION_ID >= 80400 ? 0 : 1);' 2>/dev/null; then
    echo "Host PHP is new enough ($(php -r 'echo PHP_VERSION;')); use php/vendor/bin directly."
    exit 0
fi

echo "==> Docker daemon"
if ! docker info >/dev/null 2>&1; then
    (nohup dockerd >"$CACHE/dockerd.log" 2>&1 &)
    for _ in $(seq 1 30); do docker info >/dev/null 2>&1 && break; sleep 1; done
    docker info >/dev/null 2>&1 || { echo "dockerd didn't start; see $CACHE/dockerd.log" >&2; exit 1; }
fi

echo "==> PHP 8.5 image ($IMAGE)"
if ! docker image inspect "$IMAGE" >/dev/null 2>&1; then
    docker rm -f offing-php-build >/dev/null 2>&1 || true
    docker run --name offing-php-build --network host -e HTTPS_PROXY ubuntu:26.04 bash -c '
        set -e
        apt-get update -qq
        DEBIAN_FRONTEND=noninteractive apt-get install -y -qq \
            php-cli php-sqlite3 php-mbstring php-xml php-curl php-zip php-bcmath php-intl php-gmp \
            unzip git ca-certificates >/dev/null
        php -v | head -1'
    docker commit offing-php-build "$IMAGE" >/dev/null
    docker rm offing-php-build >/dev/null
fi

echo "==> dphp wrapper"
cat >/usr/local/bin/dphp <<EOF
#!/bin/sh
# Run a command under PHP 8.5 against the repo: dphp vendor/bin/pest, dphp php artisan …
exec docker run --rm -i --network host -e HTTPS_PROXY -e COMPOSER_HOME=/root/.composer \\
  -v /root/.ccr/ca-bundle.crt:/usr/local/share/ca-certificates/ccr.crt:ro \\
  -v $(command -v composer):/usr/local/bin/composer:ro \\
  -v "$REPO:$REPO" -v "$CACHE/composer:/root/.composer" \\
  -w "\$PWD" $IMAGE sh -c 'update-ca-certificates >/dev/null 2>&1; "\$@"' _ "\$@"
EOF
chmod +x /usr/local/bin/dphp

cd "$REPO"

echo "==> Composer dependencies"
if [ ! -x vendor/bin/pest ]; then
    # phpstan/phpstan has no git source in the lock, only a (blocked) GitHub
    # zipball. Fetch that commit over git, zip it, and point the lock at the
    # zip for this one install. The trap puts composer.lock back.
    ref=$(php -r '$l = json_decode(file_get_contents("composer.lock"), true);
        foreach (array_merge($l["packages"], $l["packages-dev"]) as $p)
            if ($p["name"] === "phpstan/phpstan") echo $p["dist"]["reference"];')
    if [ ! -f "$CACHE/phpstan-$ref.zip" ]; then
        rm -rf "$CACHE/phpstan-src"
        git init -q "$CACHE/phpstan-src"
        git -C "$CACHE/phpstan-src" fetch -q --depth 1 https://github.com/phpstan/phpstan.git "$ref"
        git -C "$CACHE/phpstan-src" checkout -q FETCH_HEAD
        rm -rf "$CACHE/phpstan-src/.git"
        (cd "$CACHE/phpstan-src" && zip -qr "$CACHE/phpstan-$ref.zip" .)
    fi
    cp composer.lock "$CACHE/composer.lock.bak"
    trap 'cp "$CACHE/composer.lock.bak" "$REPO/composer.lock"' EXIT
    sed -i "s#https://api.github.com/repos/phpstan/phpstan/zipball/$ref#file://$CACHE/phpstan-$ref.zip#" composer.lock
    COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --ignore-platform-reqs --no-scripts --prefer-source
    cp "$CACHE/composer.lock.bak" composer.lock
    trap - EXIT
fi

echo "==> .env and package discovery"
[ -f .env ] || cp .env.example .env
grep -q '^APP_KEY=base64' .env || dphp php artisan key:generate -q
dphp php artisan package:discover -q

echo "==> Frontend build (feature tests need the Vite manifest)"
[ -d node_modules ] || npm ci --no-audit --no-fund
npm run build >/dev/null

echo "Done. Run PHP through dphp, e.g.: dphp vendor/bin/pest"
