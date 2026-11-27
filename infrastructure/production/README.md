# ViCarTech Production profile

This profile is an isolated production deployment. It uses the existing seven Laravel services, builds the existing frontend as a static Nginx image, and gives every Laravel service its own MariaDB 10.6 database and persistent Docker volume. It does not read the OFFICE Compose file, OFFICE secrets, OFFICE volumes, or OFFICE databases.

Only the public Nginx gateway publishes host ports 80 and 443. MariaDB containers and internal Laravel services have no published ports. The gateway and internal service calls use TLS; the gateway-to-auth, clients, and appointments paths use client certificate verification. Each application container receives only its own TLS identity directory. Application data is isolated on its service database network, and the databases are not attached to the public network.

## Compatibility and resource budget

The PHP/Apache, Node, Nginx, Debian, and MariaDB base images are multi-architecture images with ARM64 variants. The custom PHP images are built from this repository on GitHub Actions for `linux/arm64`; their dependencies are PHP extensions built from Debian packages and Composer packages in the existing lock files. The production build should remain an ARM64 CI build and must be checked with a deployment smoke run before loading any customer data. Full end-to-end readiness depends on the current Apache mTLS configuration and its certificate paths; the packaged TLS provisioning script creates both the existing service-specific paths and the production identity files required by this Compose profile.

The Compose memory caps total 8,448 MiB: 2,752 MiB for seven MariaDB containers, 4,608 MiB for seven Laravel services, and 1,088 MiB for Nginx/frontend/notification processes. This leaves roughly 3.5 GiB on a 12 GiB VM for Ubuntu, Docker, file cache, and short-lived backup/migration work. These are limits, not measured steady-state usage. The current workstation measurement had only AppointmentsService (44.76 MiB) and its MariaDB (66.6 MiB) running; there is no full-stack production load test yet. The caps should be revisited after representative load testing.

## GitHub repositories and workflows

The frontend workflow files live in `garagetech-frontend/.github/workflows/`; the backend files live in `garagetech-backend/.github/workflows/`:

- `ci.yml` runs frontend TypeScript/build/tests/lint or the backend Laravel test suites.
- `build-images.yml` publishes private, ARM64-only GHCR images after the matching CI workflow succeeds.
- Backend `deploy-production.yml` uploads the production deployment bundle over verified SSH, takes an encrypted off-server backup before migrations, applies migrations service by service, starts the stack, and checks the public HTTPS health endpoint.

Backend currently has local branch `master` and no `origin` remote configured; frontend has `main` and a configured SSH remote that could not be reached from this environment. The workflows recognize `main` and `master` on backend until branch names are aligned. No files have been pushed and GitHub Actions cannot run until the backend repository remote, GitHub Actions permissions, GHCR packages, and required repository/environment secrets are configured.

Configure these GitHub Actions secrets in the backend repository: `PRODUCTION_HOST`, `PRODUCTION_USER`, `PRODUCTION_SSH_PRIVATE_KEY`, and `PRODUCTION_SSH_KNOWN_HOSTS`. Add a `production` environment and protect it according to ViCarTech's release policy. Set the environment variable `PRODUCTION_FRONTEND_IMAGE_TAG` to a validated immutable frontend image tag; its fallback `main` tag is mutable. On the server, configure a read-only GHCR `read:packages` login for the dedicated deployment account.

The repositories are separate. Frontend image publication follows the frontend CI workflow, while backend deployment follows backend image publication and uses the validated frontend `main` image tag unless `PRODUCTION_FRONTEND_IMAGE_TAG` or a manual workflow input selects another tag. For reproducible releases, record and deploy immutable frontend and backend image tags together.

## Server setup

These scripts prepare Ubuntu; none has been run against a cloud server:

1. Provision the Always Free ARM VM manually in the OCI tenancy's home region. Do not select paid resources. Configure the OCI network security list to allow only SSH from the operator's trusted IP and public TCP ports 80/443.
2. Run `sudo infrastructure/production/install-docker.sh` to install Docker CE and Compose. It does not add users to the Docker group.
3. Set up and test SSH public-key login, run `sudo infrastructure/production/harden-ssh.sh`, and leave the current SSH session open until a second key-authenticated login succeeds.
4. Run `sudo infrastructure/production/create-deployment-user.sh` only after installing a dedicated CI public key in `/home/vicartech/.ssh/authorized_keys`. Docker-group membership is root-equivalent host access; protect the CI key as a production administrator credential.
5. As `vicartech`, copy `infrastructure/production/` to `/opt/vicartech/deployment/infrastructure/production/` and run `install-production.sh`. Edit `/opt/vicartech/.env.production` with the domain, GHCR owner, image tags, and persistent backup destination.
6. Set the Gmail app password manually in `/opt/vicartech/shared/notification-provider.env` (`MAIL_PASSWORD`). The template uses Gmail on port 587 with `MAIL_SCHEME=smtp` (STARTTLS); Twilio values are empty. Notification settings default to disabled and this deployment setup does not enable them.
7. Configure an existing off-server rclone destination in `/opt/vicartech/shared/rclone.conf`, and set `OFFSITE_BACKUP_REMOTE`. Send the public age recipient file to the independent backup operator/destination. Keep `backup-age-identity.txt` only on the server and in a separately protected recovery copy.
8. Configure a read-only GHCR login with `configure-ghcr-login.sh`. Then install daily backup and certificate timers with `sudo infrastructure/production/install-systemd-timers.sh` after the deployment account has Docker access.
9. Verify domain DNS and ports 80/443, then run `obtain-first-certificate.sh`. The gateway cannot start until the initial certificate exists.
10. Deploy the tested image tags with `deploy.sh BACKEND_SHA FRONTEND_SHA`, or allow the configured GitHub workflow to deploy after CI and image publication.

Run `sudo infrastructure/production/configure-firewall.sh` after Docker is installed. It allows SSH, HTTP, and HTTPS, denies other inbound traffic, and adds a persistent `DOCKER-USER` rule that drops forwarded inbound traffic except ports 80/443. Also enforce the same allowed ports in the OCI security list; host firewall rules do not replace OCI network rules.

## Data, migrations, backups, and rollback

Production creates new databases and storage volumes. No OFFICE, HOME, company A/B, browser, localStorage, or IndexedDB data is copied. Existing browser data remains in the browser and is not available to this server. Before any future customer-data migration, prepare a separate verified export/import plan with backups, stable identifiers, relationships, and an isolated restore rehearsal.

`deploy.sh` pulls immutable image tags, waits for all seven database health checks, writes an encrypted snapshot of all seven databases and persistent document/logo volumes, requires successful upload to the configured off-server rclone destination, and only then runs `php artisan migrate --force` service by service. It never runs `migrate:fresh`, drops databases, truncates tables, or removes volumes. Keep migrations backward-compatible using expand/migrate/contract releases.

`backup.sh` uses MariaDB logical dumps, encrypts the archive with age, stores the encrypted copy locally, uploads it off-server, and expires old encrypted local copies. Backups fail when the off-server remote or encryption recipient is not configured. A successful daily timer is not considered verified until an operator checks the off-server object and completes a restore rehearsal.

`restore.sh BACKUP.age --confirm-empty-target` restores only into empty database tables and empty document volumes; it refuses a non-empty target. Never point it at a populated production volume. `rollback.sh` restores previous application image tags without rolling back database schema or destroying volumes. Run it only when the prior app release is compatible with the migrated schema. If a migration is not backward-compatible, use a reviewed forward-fix or restore into a separate recovery environment.

## Initial certificate and test commands

```bash
cd /opt/vicartech/deployment/infrastructure/production
./obtain-first-certificate.sh
./deploy.sh <immutable-backend-image-tag> <immutable-frontend-image-tag>
./healthcheck.sh
./monitor.sh
```

No production server, cloud instance, GitHub package, or customer dataset was created or modified while preparing these files.
