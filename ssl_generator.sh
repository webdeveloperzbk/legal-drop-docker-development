#!/usr/bin/env bash
# First issuance: standalone Certbot on free TCP/80. Renewal: Nginx webroot, no downtime.
# Run on the dev server: sudo bash ssl_generator.sh
# Read-only certificate check: bash ssl_generator.sh --check
set -Eeuo pipefail
umask 077

stack_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
ssl_dir="$stack_dir/nginx/ssl"
webroot="$stack_dir/nginx/acme"
certbot_dir="$stack_dir/certbot"
cert_name='legal-drop-development'
certbot_image='certbot/certbot:v5.8.0'
CERTBOT_EMAIL="${CERTBOT_EMAIL:-admin@legal-drop.space}"
nginx_container='legal-drop-nginx-development'
domains=(legal-drop.su admin.legal-drop.su)
# Same threshold as the Cloudset reference: request renewal at <= 3 days remaining.
renew_before_seconds=259200
check_only=false
stage=''
probe=''
installed=false
had_pair=false

log() { printf '[%s] %s\n' "$(date -Is)" "$*"; }
fail() { log "ERROR: $*" >&2; exit 1; }

case "${1:-}" in
    '') [[ $# == 0 ]] || fail 'Unexpected arguments.' ;;
    --check) [[ $# == 1 ]] || fail 'Unexpected arguments.'; check_only=true ;;
    *) fail 'Usage: ssl_generator.sh [--check]' ;;
esac

for command in openssl date; do command -v "$command" >/dev/null || fail "Missing command: $command"; done

valid_pair() {
    local cert="$1" key="$2" seconds="$3" domain cert_public key_public
    [[ -s "$cert" && -s "$key" ]] || return 1
    openssl x509 -in "$cert" -noout -checkend "$seconds" >/dev/null 2>&1 || return 1
    for domain in "${domains[@]}"; do
        openssl x509 -in "$cert" -noout -checkhost "$domain" >/dev/null 2>&1 || return 1
    done
    cert_public="$(openssl x509 -in "$cert" -noout -pubkey 2>/dev/null)" || return 1
    key_public="$(openssl pkey -in "$key" -passin pass: -pubout 2>/dev/null)" || return 1
    [[ "$cert_public" == "$key_public" ]]
}

if [[ "$check_only" == true ]]; then
    valid_pair "$ssl_dir/ssl.crt" "$ssl_dir/ssl.key" "$renew_before_seconds" \
        || fail 'Certificate/key missing, invalid, mismatched, missing a domain or expiring within 3 days.'
    log "Certificate covers both domains; $(openssl x509 -in "$ssl_dir/ssl.crt" -noout -enddate)"
    exit 0
fi

[[ "$EUID" == 0 ]] || fail 'Run issuance/renewal with sudo.'
for command in docker flock curl ss install getent; do command -v "$command" >/dev/null || fail "Missing command: $command"; done
[[ "$(id -u legaldrop)" == 2048 && "$(getent group legaldrop | cut -d: -f3)" == 2048 ]] \
    || fail 'Expected legaldrop UID/GID 2048.'

# Share the release lock: do not replace certificates while a deploy recreates Nginx.
touch "$stack_dir/.deploy.lock"
chown legaldrop:legaldrop "$stack_dir/.deploy.lock"
chmod 600 "$stack_dir/.deploy.lock"
exec 9>"$stack_dir/.deploy.lock"
flock -n 9 || fail 'Another deployment/certificate job is running.'

install -d -o legaldrop -g legaldrop -m 0750 "$ssl_dir" "$stack_dir/nginx/logs"
install -d -m 0755 "$webroot" "$webroot/.well-known" "$webroot/.well-known/acme-challenge"
install -d -m 0700 "$certbot_dir"

if valid_pair "$ssl_dir/ssl.crt" "$ssl_dir/ssl.key" "$renew_before_seconds"; then
    log "Certificate is current; $(openssl x509 -in "$ssl_dir/ssl.crt" -noout -enddate)"
    exit 0
fi

: "${CERTBOT_EMAIL:?Set CERTBOT_EMAIL to the ACME account email}"
[[ "$CERTBOT_EMAIL" == *@*.* && "$CERTBOT_EMAIL" != *[[:space:]]* ]] || fail 'Invalid CERTBOT_EMAIL.'
docker info --format '{{.ServerVersion}}' >/dev/null
nginx_running="$(docker inspect --format '{{.State.Running}}' "$nginx_container" 2>/dev/null || true)"

cleanup() {
    status=$?
    trap - EXIT
    if (( status != 0 )) && [[ "$installed" == true && "$had_pair" == true ]]; then
        install -o legaldrop -g legaldrop -m 0640 "$stage/previous.key" "$ssl_dir/ssl.key"
        install -o legaldrop -g legaldrop -m 0644 "$stage/previous.crt" "$ssl_dir/ssl.crt"
        log 'Previous certificate files restored; Nginx was not restarted.' >&2
    fi
    if [[ -n "$probe" ]]; then rm -f -- "$webroot/.well-known/acme-challenge/$probe"; fi
    if [[ -n "$stage" && "$stage" == "$ssl_dir"/.certificate.* ]]; then rm -rf -- "$stage"; fi
    exit "$status"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

docker_args=(run --rm --init --volume "$certbot_dir:/etc/letsencrypt")
if [[ "$nginx_running" == true ]]; then
    # Fail early if Nginx still has the old unconditional HTTP redirect or no ACME mount.
    docker exec "$nginx_container" nginx -t
    probe="legal-drop-check-$(openssl rand -hex 16)"
    printf '%s' "$probe" > "$webroot/.well-known/acme-challenge/$probe"
    chmod 0644 "$webroot/.well-known/acme-challenge/$probe"
    for domain in "${domains[@]}"; do
        response="$(curl --noproxy '*' --fail --silent --show-error --max-time 10 \
            --header "Host: $domain" "http://127.0.0.1/.well-known/acme-challenge/$probe")" \
            || fail "Nginx does not serve the ACME challenge for $domain."
        [[ "$response" == "$probe" ]] || fail 'ACME response mismatch; rebuild Nginx with the updated config and check its webroot mount.'
    done
    docker_args+=(--volume "$webroot:/var/www/acme")
    authenticator=(--webroot --webroot-path /var/www/acme)
else
    [[ -z "$(ss -H -ltn 'sport = :80')" ]] || fail 'TCP/80 is occupied; no services will be stopped automatically.'
    docker_args+=(--publish 80:80)
    authenticator=(--standalone)
fi

domain_args=()
for domain in "${domains[@]}"; do domain_args+=(-d "$domain"); done
log 'Requesting/reusing a certificate for legal-drop.su and admin.legal-drop.su.'
docker "${docker_args[@]}" "$certbot_image" certonly \
    "${authenticator[@]}" --preferred-challenges http --key-type rsa \
    --cert-name "$cert_name" "${domain_args[@]}" \
    --email "$CERTBOT_EMAIL" --agree-tos --non-interactive \
    --keep-until-expiring --renew-with-new-domains

# Copy real files, not Certbot live/ symlinks whose archive targets are outside the SSL mount.
stage="$(mktemp -d "$ssl_dir/.certificate.XXXXXXXX")"
install -m 0644 "$certbot_dir/live/$cert_name/fullchain.pem" "$stage/ssl.crt"
install -m 0600 "$certbot_dir/live/$cert_name/privkey.pem" "$stage/ssl.key"
valid_pair "$stage/ssl.crt" "$stage/ssl.key" "$renew_before_seconds" \
    || fail 'Certbot output failed certificate/key/domain/expiry verification; existing files were not replaced.'

if [[ -f "$ssl_dir/ssl.crt" && -f "$ssl_dir/ssl.key" ]]; then
    cp -- "$ssl_dir/ssl.crt" "$stage/previous.crt"
    cp -- "$ssl_dir/ssl.key" "$stage/previous.key"
    had_pair=true
fi
installed=true
install -o legaldrop -g legaldrop -m 0640 "$stage/ssl.key" "$ssl_dir/ssl.key"
install -o legaldrop -g legaldrop -m 0644 "$stage/ssl.crt" "$ssl_dir/ssl.crt"

if [[ "$nginx_running" == true ]]; then
    docker exec "$nginx_container" nginx -t
    docker exec "$nginx_container" nginx -s reload
fi
installed=false
log "Certificate installed; $(openssl x509 -in "$ssl_dir/ssl.crt" -noout -enddate)"
