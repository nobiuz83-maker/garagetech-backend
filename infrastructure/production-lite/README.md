# PRODUCTION-LITE (native PHP-FPM)

This is a separate deployment profile for the small AMD64 Ubuntu host. It does not replace or alter `infrastructure/production/` (Docker) or OFFICE. No installer here connects to, migrates, or changes a server automatically.

## Scope and limits

The profile assumes Ubuntu 24.04, PHP 8.3 FPM, Nginx, MariaDB 10.11, 952 MiB RAM, 2 GiB swap, and a React build copied to a static directory. Keep the seven Laravel services separate, each with its own database, `APP_KEY`, storage path, and PHP-FPM pool. They initially share the restricted MariaDB account `vicartech`; apply schema migrations manually one service at a time after a verified backup.

All seven FPM pools are `ondemand`, with `pm.max_children=1`; therefore no more than seven web PHP workers run at once. Run each pool as a distinct unprivileged Unix user (`vicartech-auth`, `vicartech-clients`, and so on), with access only to its own release, environment, TLS client identity, and HMAC key copies. Nginx gets access to FPM sockets through their `www-data` group. Queue and scheduler work use the Appointments account in separate low-concurrency systemd services. This is a concurrency ceiling, not a RAM guarantee. Measure with real traffic and `systemd-cgtop` before accepting customer data.

### Estimated memory budget (not measured)

| Component | Planning range |
| --- | ---: |
| Ubuntu, Nginx, SSH, system services | 180–280 MiB |
| MariaDB 10.11 (single local instance; proposed 128 MiB buffer pool) | 150–240 MiB |
| PHP-FPM master and shared OPcache | 70–110 MiB |
| Seven concurrent PHP request workers (upper bound; workload dependent) | 350–700 MiB |
| Scheduler / one notification worker | 50–120 MiB |

These planning ranges total roughly 800–1,450 MiB and can exceed physical RAM. The host is an extreme low-memory target: the seven pools impose a hard maximum of seven web workers, while the PHP-FPM systemd cgroup caps aggregate service memory; this can cause requests to fail under pressure and must be load-tested. Start with low traffic, observe, and move to a larger host if peak usage leaves less than 100 MiB available or causes sustained swap. The 2 GiB swap is emergency headroom, not RAM.

## Security model

- Public Nginx terminates HTTPS and serves the compiled React assets. Only the intended API paths are routed to service upstreams. Include `nginx/client-certificate-cn-map.conf` in the Nginx `http` context; it derives the verified peer's CN from Nginx's certificate subject variable for the route-specific authorization checks.
- Private service listeners bind to loopback and use TLS with required client certificates. Issue separate gateway and service client identities from an offline/private CA; keep private keys outside the repository and readable only by the service account. Do not disable `require_mtls`, HMAC signing, nonce/replay checks, or company-scope authorization.
- Service-to-service URL, CA, client certificate, and client key settings must be supplied in each private service environment. Map internal service names to `127.0.0.1` only if Nginx is configured with matching TLS virtual hosts and certificate verification. Nginx must pass verified client-certificate facts to PHP-FPM only after enforcing the certificate; direct PHP-FPM sockets are not public.
- The files here are templates. Verify every API route and internal caller against the checked-in service configs before enabling external traffic. In particular, the existing Apache rules include route-specific caller restrictions; porting them requires equivalent Nginx `map`/location rules and security review.

## Files and manual sequence

1. Review `nginx/`, `php-fpm/`, `php/`, `systemd/`, `mariadb/`, and `scripts/` against the exact Ubuntu package layout. Set the real domain and frontend build path in a private copy of `production-lite.env.example`.
2. Review `mariadb/low-memory.cnf.example` against the existing MariaDB workload. It proposes a local-only bind address and conservative memory values; install it only after reviewing the active configuration and measuring the database.
3. Create seven complete private environment files at `/etc/vicartech/services/{auth,clients,appointments,interventions,inventory,billing,accounting}.env`. The directory must be root-owned mode `0711`; each file must be owned by its matching service account and mode `0600`. Base each file on its matching service `.env.example`, set production debug off, a unique generated APP_KEY, secure cookies, HTTPS URLs, and the required internal mTLS/HMAC settings. Preserve any existing OFFICE/HOME environments.
4. Run `sudo infrastructure/production-lite/scripts/provision-shared-database.sh` on the target during deployment. It generates a 256-bit password once, stores it in `/etc/vicartech/secrets/database-password` mode 0600, creates the seven databases, provisions `vicartech`@`127.0.0.1`, and installs the same database credentials into each service environment without printing the password. It snapshots the prior private environment files before updating them. Reruns reuse the stored password and reapply the same grants.
5. Validate the account grants with `SHOW GRANTS FOR 'vicartech'@'127.0.0.1'` using the local MariaDB administrator account. Expected grants are `USAGE` plus database-level grants for the seven schemas only. The provisioning SQL resets prior privilege grants and refuses pre-existing role or proxy grants for this reserved account name.
6. Install one PHP-FPM pool per service and the example systemd resource drop-in, then validate with `php-fpm8.3 -t` and `nginx -t`. Check `php artisan about` and route health locally for each service.
7. Install app code under versioned directories and symlink each service's private `.env` and persistent storage. Build React outside this server and copy only the built assets.
8. Back up before any migration. Run each service's `php artisan migrate --force --no-interaction` explicitly and inspect its output. Then enable Nginx and systemd units manually.
9. Validate HTTPS, gateway routing, mTLS rejection for untrusted clients, signed internal requests, cross-company isolation, notifications, health, backup, and a restore into an isolated empty database.

`deploy-manual.sh` performs no SSH or remote action; it validates a local release path and prints the manual steps. `deploy-github-actions.yml.example` is deliberately outside `.github/workflows` and has no trigger. It is a later reference only; do not enable it until a protected environment and explicit approval are configured.

## Backups and monitoring

The optional `appointments-notifications.env.example` keeps email/SMS disabled until a private provider configuration is supplied. Notifications are queued in the Appointments database and handled by one worker; the scheduler dispatches due notifications each minute.

Use `scripts/backup.sh` with a root-only MariaDB client configuration, an age recipient file stored outside Git, and persistent private storage at `/srv/vicartech/shared/private-storage/{appointments,billing}`. Keep encrypted backups off-host. `scripts/restore.sh` refuses populated databases or document directories and requires the explicit `--confirm-empty-target` argument. Test backups regularly by restoring to isolated schemas and empty storage directories on a separate host.

`systemd/vicartech-monitor.service` records free memory, swap, disk, and load to the journal. Review with `journalctl -u vicartech-monitor` and `systemd-cgtop`. Configure log rotation and alerting outside this starter profile.

### Per-service credentials and calls

`services.env.example` is a checklist, not a complete env file. Copy the matching service's existing `.env.example`, then add only the values needed by that service:

All seven service environments use `DB_CONNECTION=mysql`, `DB_HOST=127.0.0.1`, `DB_PORT=3306`, `DB_USERNAME=vicartech`, the same generated `DB_PASSWORD`, `DB_CHARSET=utf8mb4`, and `DB_COLLATION=utf8mb4_unicode_ci`. Only `DB_DATABASE` differs:

| Service | Database |
| --- | --- |
| Auth | `vicartech_auth` |
| Clients | `vicartech_clients` |
| Appointments | `vicartech_appointments` |
| Interventions | `vicartech_interventions` |
| Inventory | `vicartech_inventory` |
| Billing | `vicartech_billing` |
| Accounting | `vicartech_accounting` |

The shared account has full database-level privileges on those seven schemas only, which permits Laravel migrations but does not isolate one service from another at the SQL layer. A later per-service-user model can be introduced by changing each environment's `DB_USERNAME`/`DB_PASSWORD` and the corresponding database grants; application business code does not need to change.

| Service | Internal credentials / endpoints to configure |
| --- | --- |
| Auth | `INTERNAL_AUTH_CLIENTS_KEY_FILE`, `INTERNAL_AUTH_APPOINTMENTS_KEY_FILE`, and `INTERNAL_AUTH_V2_{CLIENTS,APPOINTMENTS,INTERVENTIONS,INVENTORY,BILLING,ACCOUNTING}_KEY_FILE`; `COMPANY_SECURITY_ENABLED=true` |
| Clients | `AUTH_SERVICE_URL`, `AUTH_SERVICE_V2_URL`, both CA/client certificate/key sets, `AUTH_SIGNING_KEY_FILE`, `AUTH_V2_SIGNING_KEY_FILE`, `APPOINTMENTS_RELATIONSHIP_KEY_FILE`, `COMPANY_SECURITY_ENABLED=true` |
| Appointments | Same Auth v1/v2 settings as Clients, plus `CLIENTS_SERVICE_URL` and `CLIENTS_RELATIONSHIP_KEY_FILE`; set `QUEUE_CONNECTION=database` |
| Interventions | Auth v2 settings; `INTERVENTION_CLIENTS_URL`, `INTERVENTION_APPOINTMENTS_URL`, `INTERVENTION_CLIENTS_CA_FILE`, `INTERVENTION_CLIENTS_CERT_FILE`, `INTERVENTION_CLIENTS_KEY_FILE`, and `COMPANY_SECURITY_ENABLED=true` |
| Inventory | Auth v2 settings; `INVENTORY_INTERVENTIONS_URL`, matching CA/client cert/key files, `INVENTORY_BILLING_URL`, `INVENTORY_TO_BILLING_SIGNING_KEY_FILE`, `INVENTORY_BILLING_KEY_FILE`, and `COMPANY_SECURITY_ENABLED=true` |
| Billing | Auth v2 settings; `BILLING_INTERVENTIONS_URL`, matching CA/client cert/key files, `BILLING_INVENTORY_URL`, `BILLING_TO_INVENTORY_SIGNING_KEY_FILE`, `BILLING_INVENTORY_KEY_FILE`, and `COMPANY_SECURITY_ENABLED=true` |
| Accounting | Auth v2 URL, CA/client certificate/key, `AUTH_V2_SIGNING_KEY_FILE`, and `COMPANY_SECURITY_ENABLED=true` |

Generate each HMAC key independently; configure the same key on both sides of its specific caller relationship. Do not reuse APP_KEYs, TLS identities, or HMAC keys between services. The MariaDB password is intentionally shared across these seven service environments only. The Nginx internal virtual hosts must carry forward the current Apache trust rules: Auth accepts CA-issued service clients; Clients accepts the gateway generally and Appointments only on the two existing internal reference routes; Appointments accepts the gateway. The generic internal template is gateway-only. Review the actual PHP route middleware and request-signature configuration before writing caller exceptions for any other service.

## PHP compatibility

All seven checked-in `composer.lock` files passed `composer check-platform-reqs --lock --no-dev` under PHP 8.3.36 in the development environment. This checks locked production platform requirements, not OS extension availability, runtime behavior, or Composer `require-dev` packages. Repeat the command on the target host after installing the required extensions; do not use `--ignore-platform-reqs`.
