#!/bin/sh
set -eu
umask 077
cd "$(dirname "$0")/.."

if [ ! -f .env ]; then
    cp .env.example .env
fi

# Accept unquoted or quoted keys; never replace a populated APP_KEY.
if awk '
    /^[[:space:]]*APP_KEY[[:space:]]*=/ {
        value = $0
        sub(/^[^=]*=/, "", value)
        gsub(/^[[:space:]\047\042]+|[[:space:]\047\042]+$/, "", value)
        if (length(value) > 0) present = 1
    }
    END { exit !present }
' .env; then
    echo '.env is ready; the existing Laravel key was preserved.'
    exit 0
fi

command -v openssl >/dev/null 2>&1 || {
    echo 'OpenSSL is required to generate the local Laravel key.' >&2
    exit 1
}
app_key="base64:$(openssl rand -base64 32)"
env_tmp="$(mktemp .env.XXXXXX)"
trap 'rm -f "$env_tmp"' EXIT HUP INT TERM
awk -v key="$app_key" '
    /^[[:space:]]*APP_KEY[[:space:]]*=/ { print "APP_KEY=" key; found = 1; next }
    { print }
    END { if (!found) print "APP_KEY=" key }
' .env > "$env_tmp"
mv "$env_tmp" .env
trap - EXIT HUP INT TERM
echo '.env is ready; a local Laravel key was generated and saved.'
