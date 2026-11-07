# Coaching Tool

A small multi-tenant coaching management application for Synology Web Station. The coach area is server-rendered with PHP, HTML and CSS, with JavaScript used for small interactions. A more interactive coachee portal can be added separately later.

## Current database schema

The application uses the existing MariaDB database `coaching_tool` and expects these tables:

- `benutzer`: coach/admin accounts
- `klienten`: clients assigned to a user via `benutzer_id`
- `termine`: appointments associated with a client via `klient_id`
- `login_versuche`: login attempt records

The health check verifies connectivity and confirms that these four tables are visible to the application account. It does not read, edit, or delete coaching records. It returns only whether the database is connected and whether the expected tables exist.

This schema supports the current single-coach/user-owned client structure. Multi-coach workspaces, coachee accounts, coaching engagements, and per-note visibility are not represented yet. Those can be added later with versioned, non-destructive migrations.

## Run without SSH

The website does not need a development server process. Web Station serves PHP pages directly and starts PHP for each request.

1. In DSM Package Center, install/enable Web Station, PHP 8.2 and MariaDB 10.
2. Put the project files in a shared folder. In Web Station, create a PHP web service with document root set to this repository's `public/` folder and assign a PHP 8.2 profile.
3. In Web Station > Script Language Settings > PHP, enable `pdo_mysql` in that profile.
4. Configure the database using the steps below.
5. Open the website through the Web Station portal. The health check is at `/health.php`.

Only `public/` should be the document root. The private configuration and application code must stay outside it. Grant the Web Station `http` group read access to the website and app files.

## MariaDB connection

1. Confirm MariaDB 10 is running. Check its current port in the MariaDB 10 package settings; use that exact port.
2. In phpMyAdmin, create a dedicated application user with access to the existing `coaching_tool` database. Do not use the MariaDB administrator login.
3. Copy `config.example.php` to `config.local.php` in the project root on the NAS. Enter the MariaDB host/port and the dedicated username/password there.
4. Keep `config.local.php` private and outside `public/`. It is ignored by Git and must never be copied to GitHub.
5. Open the home page and select `Verbindung prüfen`. It should report that the database is connected and the coaching tables were detected. The health endpoint does not reveal credentials or raw errors.

## Local development

Requires PHP 8.2 with PDO MySQL and access to MariaDB. Copy `config.example.php` to `config.local.php`, set local credentials, then run:

```sh
php -S 127.0.0.1:8080 -t public
```

Open `http://127.0.0.1:8080/`. Stop the local server with Ctrl+C. This local command is only for development; Web Station keeps the NAS website available without SSH or a terminal.

## Data and security

- No real coaching data is included.
- Database credentials stay on the NAS in `config.local.php`.
- Database access runs in PHP on the server. Never connect to MariaDB from browser JavaScript.
- The SQL export provided for schema review included login attempt rows with IP addresses; remove such rows before sharing an export.
- Use synthetic data until authentication, tenant isolation and authorization are implemented.
- Keep the NAS private on the LAN during development. Do not create router port forwarding.
