#!/usr/bin/env bash
# Runs ON THE HOST over SSH (appleboy/ssh-action `script_file`), from both
# deploy workflows. Prints the path of the newest PHP CLI >= 8.4 on stdout —
# the workflows capture it and use it for every `artisan` call.
#
# Why: on 20i the PHP version picked in the control panel only applies to
# web requests. Over SSH, plain `php` is an old system default (8.0.30 on
# staging as of the first deploy), which Composer's platform check rejects.
#
# Everything except the final path goes to stderr, so it shows in the
# Actions log without polluting the captured output.

best=
best_id=0

for bin in \
    "$(command -v php)" \
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
