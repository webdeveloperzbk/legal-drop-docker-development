#!/usr/bin/env bash
# Usage: bash deploy-development.sh <image-tag>
set -Eeuo pipefail
umask 077

[[ $# == 1 && "$1" =~ ^[a-zA-Z0-9_][a-zA-Z0-9_.-]{0,127}$ ]] || {
    echo 'Expected one valid image tag.' >&2
    exit 1
}
cd -- "$(dirname -- "${BASH_SOURCE[0]}")"
stack_dir="$PWD"
export APP_VERSION="$1"
release_image="ghcr.io/webdeveloperzbk/legal-drop-prerelease:$APP_VERSION"
release_volume="legal-drop-drive-$APP_VERSION"
runtime_image='ghcr.io/webdeveloperzbk/legal-drop-runtime:php8.5.10-nodejs24.21.0'
compose=(docker compose --env-file "$stack_dir/.env" -f "$stack_dir/docker-compose.yml")
runtime=(docker run --rm --pull never --init --user 2048:2048 --workdir /var/www/app
    --volume "$release_volume:/var/www/app" --entrypoint php)
next_env=''

cleanup() {
    status=$?
    trap - EXIT
    if [[ -n "$next_env" ]]; then rm -f -- "$next_env"; fi
    if (( status != 0 )); then
        echo 'Deployment failed; APP_VERSION was not saved. Check services and migrations before retrying.' >&2
    fi
    exit "$status"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

exec 9>.deploy.lock
flock -n 9 || { echo 'Another deployment is running.' >&2; exit 1; }
[[ -f .env && -f check-release-env.php && -f check-release-services.php && -f check-staged-uploads.php ]]
[[ -f nginx/ssl/ssl.crt && -f nginx/ssl/ssl.key && -d nginx/logs && -d nginx/acme ]]
"${compose[@]}" config --quiet

docker pull "$release_image"
docker pull "$runtime_image"
# Docker populates a new release volume from the image. No application processes start.
# Release tags are immutable: an existing volume is reused, never overwritten.
docker run --rm --pull never --network none --entrypoint /bin/true \
    --volume "$release_volume:/var/www/app" "$release_image"

"${runtime[@]}" --network none \
    --volume "$stack_dir/check-release-env.php:/tmp/check-release-env.php:ro" \
    "$runtime_image" /tmp/check-release-env.php development
"${compose[@]}" up -d --wait --wait-timeout 120 legal-drop-postgres legal-drop-redis
"${runtime[@]}" --network legal-drop \
    --volume "$stack_dir/check-release-services.php:/tmp/check-release-services.php:ro" \
    "$runtime_image" /tmp/check-release-services.php

check_staging() {
    "${runtime[@]}" --network legal-drop \
        --volume "$stack_dir/check-staged-uploads.php:/tmp/check-staged-uploads.php:ro" \
        "$runtime_image" /tmp/check-staged-uploads.php
}
check_staging

# Old workers must stop before changing the schema; new workers start after migrations.
"${compose[@]}" stop legal-drop-api legal-drop-reverb
# Recheck after stopping writers to close the race with a last upload request.
if ! check_staging; then
    "${compose[@]}" start legal-drop-api legal-drop-reverb
    exit 1
fi
"${runtime[@]}" --network legal-drop "$runtime_image" artisan migrate --force --no-interaction
"${compose[@]}" up -d --no-deps --no-build --pull never --wait --wait-timeout 180 legal-drop-api legal-drop-reverb
"${compose[@]}" up -d --no-deps --no-build --wait --wait-timeout 120 legal-drop-nginx
for domain in legal-drop.su admin.legal-drop.su; do
    curl --fail --silent --show-error --noproxy '*' --connect-timeout 5 --max-time 15 \
        --resolve "$domain:443:127.0.0.1" "https://$domain/up" >/dev/null
done

# Save the successfully deployed version without rewriting other settings.
next_env="$(mktemp "$stack_dir/.env.release.XXXXXXXX")"
awk -v version="$APP_VERSION" '
    /^APP_VERSION=/ { if (!written) print "APP_VERSION=" version; written=1; next }
    { print }
    END { if (!written) print "APP_VERSION=" version }
' .env > "$next_env"
chmod 600 "$next_env"
mv -- "$next_env" .env
next_env=''
printf 'Deployment complete: %s\n' "$APP_VERSION"
