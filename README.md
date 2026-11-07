# Coaching Tool

A small coaching management application for Synology Web Station. The coach area is server-rendered with PHP, HTML and CSS. A more interactive coachee portal can be added separately later.

## Current database schema

The application uses the existing MariaDB database `coaching_tool` and expects:

- `benutzer`: coach/admin accounts with `passwort_hash`
- `klienten`: clients assigned to a user via `benutzer_id`
- `termine`: appointments associated with a client via `klient_id`
- `login_versuche`: login attempt records

The health check verifies connectivity and confirms that these four tables are visible to the application account. The login uses `password_verify`, CSRF tokens, HTTP-only sessions, and a temporary IP rate limit. Coaches see only clients assigned to their user ID; admins see all clients. The read-only dashboard displays client names and upcoming appointment times, but not notes or coaching content.

At least one active account with a valid password hash must already exist in `benutzer`. No account is created automatically. This current schema is user-owned rather than tenant/workspace based; the coachee portal and multi-tenant access model are not implemented yet.

## Run without SSH

Web Station serves PHP pages directly and starts PHP for each request.

1. In DSM Package Center, install/enable Web Station, PHP 8.2 and MariaDB 10.
2. In Web Station, create a PHP web service with document root set to this repository's `public/` folder and assign a PHP 8.2 profile.
3. In Web Station > Script Language Settings > PHP, enable `pdo_mysql` in that profile.
4. Configure the database using the steps below.
5. Open the Web Station portal. The database health check is at `/health.php`.

Only `public/` should be the document root. Keep the private configuration and application code outside it. The Web Station `http` group needs read access to the website and app files.

## MariaDB connection

1. Confirm MariaDB 10 is running and check its current port in DSM.
2. The application account needs SELECT/UPDATE on `benutzer`, SELECT on `klienten` and `termine`, and SELECT/INSERT on `login_versuche`.
3. Copy `config.example.php` to `config.local.php` in the project root on the NAS and set the host, port, username and password.
4. Keep `config.local.php` private and outside `public/`; it is ignored by Git.
5. Test `/health.php`, then sign in with an active account from `benutzer`.

## Local development

Requires PHP 8.2 with PDO MySQL and access to MariaDB. Copy `config.example.php` to `config.local.php`, configure local credentials, then run:

```sh
php -S 127.0.0.1:8080 -t public
```

Open `http://127.0.0.1:8080/`. On the NAS, Web Station keeps the site available without SSH or an open terminal.

## Data and security

- No real coaching data is included in this repository.
- Database credentials stay on the NAS in `config.local.php`.
- Never connect to MariaDB from browser JavaScript.
- Use synthetic data until tenant separation, account recovery and the coachee portal have been reviewed.
- Keep the NAS private on the LAN during development. Do not create router port forwarding.
- Login attempts store IP addresses in the existing `login_versuche` table; define a retention period before production use.
