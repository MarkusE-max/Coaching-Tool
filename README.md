# Coaching Tool

A small coaching management application for Synology Web Station. The coach area is server-rendered with PHP, HTML and CSS. A more interactive coachee portal can be added separately later.

## Current database schema

The application uses the existing MariaDB database `coaching_tool` and expects:

- `benutzer`: coach/admin accounts with `passwort_hash`
- `klienten`: clients assigned to a user via `benutzer_id`
- `termine`: appointments associated with a client via `klient_id`
- `login_versuche`: login attempt records

The health check verifies connectivity and confirms that the four existing tables plus the notes and goals tables are visible to the application account. The login uses `password_verify`, CSRF tokens, HTTP-only sessions, and a temporary IP rate limit. Coaches see only clients assigned to their user ID; admins see all clients. The dashboard links to appointment management, goals, and coach-only private notes. Appointment history is visible only for the user's clients; private notes are never available to Klient or Admin accounts. The private `/clients.php` page lets coaches manage their own clients and lets admins assign clients to active coach accounts. Existing coach/client records can be edited. Admins can create and edit coach accounts in `/coaches.php`; name, username, email, and active status can be changed, and admins can set a new password while editing. New passwords need at least 8 characters and a special character.

An active account is needed in `benutzer`. If the table is empty, use the one-time setup described below. The current schema is user-owned rather than tenant/workspace based; Klient login, stream, tasks, automatic email reminders, and the document workflow remain planned; see `docs/FUNKTIONEN.md` for F1–F8.

## Run without SSH

Web Station serves PHP pages directly and starts PHP for each request.

1. In DSM Package Center, install/enable Web Station, PHP 8.2 and MariaDB 10.
2. In Web Station, create a PHP web service with document root set to this repository's `public/` folder and assign a PHP 8.2 profile.
3. In Web Station > Script Language Settings > PHP, enable `pdo_mysql` in that profile.
4. Configure the database using the steps below.
5. Open the Web Station portal. The database health check is at `/health.php`.

Only `public/` should be the document root. Keep the private configuration and application code outside it. The Web Station `http` group needs read access to the website and app files. The PHP process also needs write access to the private document storage folder outside `public/` (by default, the repository-root `storage/documents/`).

## MariaDB connection

1. Confirm MariaDB 10 is running and check its current port in DSM.
2. The application account needs SELECT/INSERT/UPDATE on `benutzer`, `klienten`, `termine`, `coaching_sitzungsnotizen`, `coaching_ziele`, `coaching_dokumentvorlagen` and `coaching_klientendokumente`; SELECT/INSERT on `login_versuche` and `coaching_dokumentdateien`.
3. Copy `config.example.php` to `config.local.php` in the project root on the NAS and set the host, port, username and password.
4. Keep `config.local.php` private and outside `public/`; it is ignored by Git.
5. Create a full MariaDB backup before changing the schema.
6. Import `database/migrations/001_sitzungsnotizen_ziele.sql` and then `database/migrations/002_dokumente.sql` into `coaching_tool`. These additive migrations create the notes/goals and private document tables; they do not alter existing client or appointment tables.
7. In the PHP 8.2 profile, allow uploads up to the app's 15 MB limit (for example `upload_max_filesize=16M` and `post_max_size=18M`). Ensure the PHP process can write to the private `storage/documents/` folder outside the webroot. To use a different absolute folder, set `'document_storage_path' => '/absolute/private/path'` in `config.local.php`. Keep that folder out of `public/` and out of shared web folders.
8. Test `/health.php`. If `benutzer` is empty, add a private setup key to `config.local.php` and follow the first-admin steps below.
9. After signing in as admin, open `/coaches.php` to create an active coach account, then assign clients to it in `/clients.php`. Copy the updated files from `public/` to the NAS, including `documents.php`, `document-download.php`, `assets/layout.css` and `assets/layout.js`; copy `app/document_storage.php`, `app/navigation.php` into the private `app/` folder. Also copy the updated `app/schema.php`.

## First administrator setup

This setup is available only while `benutzer` is empty. It requires HTTPS and a private/loopback client IP. It also requires INSERT permission on `benutzer`.

1. In the private `config.local.php`, add `'initial_admin_setup_key' => '...'` at the top level of the returned array. Generate a unique random key with at least 32 characters; keep it only on the NAS.
2. Open `https://<your-Web-Station-address>/setup-admin.php` from a device on the local network.
3. Enter the setup key, admin name, username, email, and a password with at least 8 characters and at least one special character.
4. After the new admin account is created, remove `initial_admin_setup_key` from `config.local.php`. The setup page is also disabled automatically once any user exists.

The setup key and new password are submitted only over HTTPS. Never send them in chat or commit them to GitHub.

## Local development

Requires PHP 8.2 with PDO MySQL and access to MariaDB. Copy `config.example.php` to `config.local.php`, configure local credentials, then run:

```sh
php -S 127.0.0.1:8080 -t public
```

Open `http://127.0.0.1:8080/`. On the NAS, Web Station keeps the site available without SSH or an open terminal.

## Data and security

- No real coaching data is included in this repository.
- Database credentials stay on the NAS in `config.local.php`.
- Coaching documents are stored under random filenames outside `public/`; downloads require a coach login and an ownership check. Templates and returned files are limited to PDF/JPG/PNG and 15 MB per upload.
- Never connect to MariaDB from browser JavaScript.
- Use synthetic data until tenant separation, account recovery and the coachee portal have been reviewed.
- Use HTTPS before entering real coaching data. Keep the NAS private on the LAN during development and do not create router port forwarding.
- Login attempts store IP addresses in the existing `login_versuche` table; define a retention period before production use.
