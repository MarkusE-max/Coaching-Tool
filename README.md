# Coaching Tool

A small multi-tenant coaching management application for Synology Web Station. The coach area is server-rendered with PHP, HTML and CSS, with JavaScript used for small interactions. A more interactive coachee portal can be added separately later.

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
2. In phpMyAdmin, create a database called `coaching_tool` with `utf8mb4`, and a dedicated user restricted to that database. Do not use the MariaDB administrator login.
3. Copy `config.example.php` to `config.local.php` in the project root on the NAS. Enter the database port and the dedicated username/password there.
4. Keep `config.local.php` private and outside `public/`. It is ignored by Git and must never be copied to GitHub.
5. Open `/health.php`. A connected database returns HTTP 200 with `"database":"connected"`. HTTP 503 means the configuration, MariaDB service/port, user grants, or PHP `pdo_mysql` extension needs checking. The endpoint does not reveal credentials or raw errors.

The health check performs `SELECT 1`; no coaching tables or real data exist yet.

## Local development

Requires PHP 8.2 with PDO MySQL and MariaDB. Copy `config.example.php` to `config.local.php`, set local credentials, then run:

```sh
php -S 127.0.0.1:8080 -t public
```

Open `http://127.0.0.1:8080/`. Stop the local server with Ctrl+C. This local command is only for development; Web Station keeps the NAS website available without SSH or a terminal.

## Data and security

- No real coaching data is included.
- Database credentials stay on the NAS in `config.local.php`.
- Database access runs in PHP on the server. Never connect to MariaDB from browser JavaScript.
- Use synthetic data until authentication, tenant isolation and authorization are implemented.
- Keep the NAS private on the LAN during development. Do not create router port forwarding.
