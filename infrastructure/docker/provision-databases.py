#!/usr/bin/env python3
"""Provision empty, isolated stage-one databases without touching the legacy server."""

import argparse
import subprocess
from pathlib import Path


SERVICE_NAMES = (
    "auth",
    "clients",
    "appointments",
    "interventions",
    "inventory",
    "billing",
    "accounting",
)
DATABASE_CONTAINER = "vicartech-microservices-stage1-database-1"


def build_provisioning_sql(secrets_directory: Path) -> str:
    statements = []

    for service_name in SERVICE_NAMES:
        password = (secrets_directory / f"{service_name}-db-password").read_text().strip()
        if not password.isascii() or not password.replace("_", "").replace("-", "").isalnum():
            raise ValueError(f"Invalid database password format for {service_name}")

        database_name = "auth_db" if service_name == "auth" else f"vicartech_{service_name}"
        user_name = f"vicartech_{service_name}_app"
        statements.extend(
            (
                f"CREATE DATABASE IF NOT EXISTS `{database_name}` "
                "CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;",
                f"CREATE USER IF NOT EXISTS '{user_name}'@'%' IDENTIFIED BY '{password}';",
                f"GRANT SELECT, INSERT, UPDATE, DELETE ON `{database_name}`.* "
                f"TO '{user_name}'@'%';",
            )
        )

    return "\n".join(statements) + "\n"


def main() -> None:
    argument_parser = argparse.ArgumentParser(description=__doc__)
    argument_parser.add_argument("secrets_directory", type=Path)
    arguments = argument_parser.parse_args()

    provisioning_sql = build_provisioning_sql(arguments.secrets_directory)
    root_client_command = (
        "secret=$(cat /run/secrets/stage1_root_password); "
        'MYSQL_PWD="$secret" mariadb --user=root --protocol=socket'
    )
    subprocess.run(
        ["docker", "exec", "-i", DATABASE_CONTAINER, "sh", "-c", root_client_command],
        input=provisioning_sql,
        text=True,
        check=True,
        capture_output=True,
    )
    print(f"Prepared {len(SERVICE_NAMES)} isolated databases in {DATABASE_CONTAINER}.")


if __name__ == "__main__":
    main()
