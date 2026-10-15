#!/usr/bin/env bash
# Runs ON THE HOST, from the deploy workflows' SSH steps — it ships with the
# app (deploy/ is not in rsync-exclude.txt), so each step can call it as
# `PHP="$(bash deploy/find-php.sh)"`. Prints the path of the newest PHP CLI
# >= 8.4 on stdout, used for every `artisan` call.
#
# Why: on 20i the PHP version picked in the control panel only applies to
# web requests. Over SSH, plain `php` is an old system default (8.0.30 on
# staging as of the first deploy), which Composer's platform check rejects.
# 20i keeps the other versions at /usr/phpNN/usr/bin/php (listed in the SSH
# login banner).
#
# Everything except the final path goes to stderr, so it shows in the
# Actions log without polluting the command substitution.

best=
best_id=0

for bin in \
    "$(command -v php)" \
    /usr/php*/usr/bin/php \
    /usr/bin/php* \
    /usr/local/bin/php* \
    /usr/local/php*/bin/php \
    /opt/alt/php*/usr/bin/php \
    /opt/remi/php*/root/usr/bin/php \
    /opt/cpanel/ea-php*/root/usr/bin/php; do
    case "${bin##*/}" in
        '' | *config* | *ize* | *fpm* | *cgi* | *dbg*) continue ;;
    esac
    [ -x "$bin" ] || continue

    id=$("$bin" -r 'echo PHP_VERSION_ID;' 2>/dev/null)
    case "$id" in
        '' | *[!0-9]*) continue ;;
    esac

    echo "found PHP CLI: $bin ($id)" >&2
    if [ "$id" -gt "$best_id" ]; then
        best=$bin
        best_id=$id
    fi
done

if [ "$best_id" -lt 80400 ]; then
    echo "No PHP CLI >= 8.4 found on this host (see 'found PHP CLI' lines above)." >&2
    exit 1
fi

echo "using PHP CLI: $best ($best_id)" >&2
echo "$best"
