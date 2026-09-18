# Legal Drop — development infrastructure

Server checkout: `/var/www/legal_drop`. Run deployment as `legaldrop` (UID/GID 2048).

## First deployment

1. Clone this repository into the server checkout. Preserve any existing certificates and Certbot state before preparing the checkout; do not overwrite them.
2. Place `.env` manually with `POSTGRES_PASSWORD` matching the application environment and `APP_VERSION` set to the intended release tag. This file is excluded from Git.
3. Authenticate to GHCR as `legaldrop`: `docker login ghcr.io --username webdeveloperzbk`. Use a GitHub classic PAT with `read:packages` and access to the private runtime, application and Nginx packages. Never commit the token or pass it as a command-line password. Login as root does not authenticate the `legaldrop` user.
4. Run `sudo bash ssl_generator.sh` to check/issue certificates and prepare SSL, logs and ACME directories. Existing valid certificates are preserved.
5. Configure application CI secrets in the GitHub environment `development_server`: `ENV_DECRYPT_KEY`, `SSH_KEY`, `SSH_KNOWN_HOSTS`, `SSH_SERVER`, `SSH_USER`, `DEPLOY_SCRIPT`; optional `SSH_PORT` defaults to 22. Set `DEPLOY_SCRIPT=/var/www/legal_drop/deploy-development.sh`.
6. Publish an application pre-release with a new, explicit tag. CI builds/publishes the application image and calls `bash deploy-development.sh <tag>` over SSH.
7. Create the owner using `admin-owner.sh`, then import countries, cities, currencies, transport types and block reasons through the administrative reference loader. Deployment runs migrations only: it does not run seeders or import reference data. Development fixtures are separate, optional commands requiring reference data (and, for some scenarios, an active owner).

## Release switching

The pre-release tag is passed unchanged to:

- Image `ghcr.io/webdeveloperzbk/legal-drop-prerelease:<tag>`.
- Docker volume `legal-drop-drive-<tag>`.
- The exported `APP_VERSION` used by Compose during deployment.

After migration, service startup and both HTTPS health checks succeed, the script atomically writes `APP_VERSION=<tag>` into this checkout's `.env`. No manual tag edit is needed for subsequent releases. On failure, the saved value is unchanged; this does not automatically roll back containers or database migrations.

Use a new tag for every code/environment change: an existing release volume is not repopulated. The script does not pull infrastructure Git changes; update this checkout separately when its configuration changes.

## Maintenance

### Owner account

After deployment and migrations, run as `legaldrop` from `/var/www/legal_drop`:

```bash
bash admin-owner.sh admin@legal-drop.space
```

For a new account, the command asks for first and last names; alternatively pass `--name="..." --last-name="..." --no-interaction`. For an existing owner, it generates a new password and invalidates previous sessions without changing the profile, role or active status. An email belonging to another staff role is rejected.

The generated password appears in the terminal after the transaction commits; save it securely. Do not run this command in CI or redirect its output to logs. Passwords are never included in the administrative audit. This command is intentionally not part of automatic deployment, and runs only through the ready runtime against the API container's mounted release volume.

Schedule `sudo bash /var/www/legal_drop/ssl_generator.sh` regularly for certificate renewal. The script itself does not install a schedule.

Install `nginx/logrotate.conf` in the server's logrotate configuration to rotate Nginx files. Docker logging limits do not rotate these bind-mounted files.

Nginx is a separately prepared image with embedded configuration. After publishing an updated Nginx image, run `docker compose pull legal-drop-nginx`, then `docker compose up -d --no-deps --force-recreate legal-drop-nginx`.

User uploads and imports live in S3; application code comes from the release image/volume. PostgreSQL and Redis retain data in `./postgres` and `./redis`. SSL keys, database files, logs and `.env` must stay outside Git.
