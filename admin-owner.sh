#!/usr/bin/env bash
# Usage: bash admin-owner.sh <email> [--name=...] [--last-name=...]
# Run after deployment. The generated password is printed only to this terminal.
set -Eeuo pipefail
umask 077

[[ $# -ge 1 ]] || { echo 'Usage: bash admin-owner.sh <email> [--name=...] [--last-name=...]' >&2; exit 1; }
cd -- "$(dirname -- "${BASH_SOURCE[0]}")"
exec 9>.deploy.lock
flock -n 9 || { echo 'Deployment/certificate task is running; retry afterwards.' >&2; exit 1; }

container="$(docker compose --env-file .env -f docker-compose.yml ps --all --quiet legal-drop-api)"
[[ -n "$container" ]] || { echo 'Deploy the application and migrations first.' >&2; exit 1; }
volume="$(docker inspect --format '{{range .Mounts}}{{if eq .Destination "/var/www/app"}}{{.Name}}{{end}}{{end}}' "$container")"
[[ "$volume" == legal-drop-drive-* ]] || { echo 'Application release volume not found.' >&2; exit 1; }

terminal=()
if [[ -t 0 && -t 1 ]]; then terminal=(--interactive --tty); fi
docker run --rm --pull never --init "${terminal[@]}" \
    --user 2048:2048 --network legal-drop --workdir /var/www/app \
    --volume "$volume:/var/www/app" --entrypoint php \
    ghcr.io/webdeveloperzbk/legal-drop-runtime:php8.5.10-nodejs24.21.0 \
    artisan admin:owner "$@"
