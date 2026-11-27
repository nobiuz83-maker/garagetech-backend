# ViCarTech Backend

ViCarTech is a free garage management application. This repository contains its PHP backend: independent APIs for identity, customers and vehicles, appointments, repair work, inventory, billing, and accounting.

## Architecture

An Nginx API Gateway routes client requests to seven separate Laravel services. Each service has its own Composer manifest, database migrations, tests, storage, and database schema. Services use signed HMAC requests and mutual TLS for the internal calls that require them, with company-level authorization enforced by the service APIs.

| Service | Responsibility |
| --- | --- |
| Auth | User identity, sessions, and company membership |
| Clients | Customer and vehicle records |
| Appointments | Scheduling and appointment notifications |
| Interventions | Repair and service work |
| Inventory | Parts and inventory operations |
| Billing | Estimates, invoices, and payments |
| Accounting | Accounting records and reports |

The React frontend is maintained separately. The backend exposes the APIs consumed by that frontend.

## Technology

- PHP 8.3 and Laravel 13
- MariaDB 10.11 with a separate schema for each service
- Nginx API Gateway
- Docker Compose for the container deployment
- Native Nginx and PHP-FPM for the small-server PRODUCTION-LITE profile
- Composer and PHPUnit

The PRODUCTION-LITE profile uses one local MariaDB instance and seven schemas. Its initial shared database account is restricted to those schemas. See [the PRODUCTION-LITE guide](infrastructure/production-lite/README.md). The Docker production configuration remains in [infrastructure/production](infrastructure/production/).

For more detail, see the [architecture decisions](docs/lot-0-architecture.md) and [service boundaries](docs/lot-0-service-boundaries.md).

## Requirements

- PHP 8.3 and Composer 2
- The PHP extensions declared by the Composer manifests
- MariaDB 10.11 for a native development or PRODUCTION-LITE installation
- Docker Engine and the Docker Compose plugin for the container profile

The frontend and its build toolchain are not included in this repository.

## Development setup

1. Clone the repository and install Composer dependencies in the root project and each service:

   ```bash
   composer install
   for service in services/*; do
       composer install --working-dir="$service"
   done
   ```

2. Copy `.env.example` to `.env` in the root project and in each service directory. Configure a separate database schema and connection for every service. Keep real credentials, generated keys, and certificates in local ignored files or a secret manager.

3. Generate a distinct Laravel application key for the root application, if used, and for each service:

   ```bash
   php artisan key:generate
   for service in services/*; do
       (cd "$service" && php artisan key:generate)
   done
   ```

4. Create the service databases, review the target connection, then run that service's migrations from its directory:

   ```bash
   cd services/auth-service
   php artisan migrate
   ```

   Repeat for the other services. Never point a local setup at a database containing data you need to preserve.

5. Configure the HMAC keys and TLS identities required by the internal service calls. Keep each key and identity private; do not reuse keys between services.

The root `compose.yaml` defines profiles for the Gateway and individual services. Configure the required private environment and secret files before starting the profiles. Production deployment procedures are documented in [infrastructure/production](infrastructure/production/) and [infrastructure/production-lite](infrastructure/production-lite/).

## Tests

Run Laravel's test suite from the root project and from each service directory:

```bash
php artisan test
```

The `ci.yml` workflow runs the same command across the root application and all seven services.

## Security

Use the checked-in `.env.example` files as templates only. Never commit real `.env` files, credentials, private keys, certificates, database exports, or customer records. Report security issues through the repository owner's GitHub security contact or private vulnerability reporting channel.

## License

The [MIT license](LICENSE) applies to original ViCarTech project code. Third-party packages are not vendored in this repository; their license declarations and upstream metadata are retained in the Composer manifests and lock files. When dependencies or application images are installed or redistributed, preserve the license files and notices supplied with those packages. Their terms are separate from the project MIT license.

The lock files list `nette/schema` and `nette/utils` under alternative New BSD, GPL-2, or GPL-3 terms. Their upstream license files permit the New BSD option and recommend it for most projects; ViCarTech relies on that option. The dependency's copyright notice, conditions, and disclaimer must remain with any redistribution of its code.
