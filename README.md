# Coaching Tool

A small coaching management application for Synology Web Station. The coach area and Coachee portal are server-rendered with PHP, HTML and CSS. Coaches can customize typography and colors.

## Current database schema

The application uses the existing MariaDB database `coaching_tool` and expects:

- `benutzer`: coach/admin accounts with `passwort_hash`
- `klienten`: clients assigned to a user via `benutzer_id`
- `termine`: appointments associated with a client via `klient_id`
- `login_versuche`: login attempt records

The health check verifies the required application tables and required note, invoice, and Coachee email columns. Import migrations 001 to 015 in order. Migrations 004-006 add client status, Coachee logins/tasks/design settings, messages and the coach-shared formats library. Migration 007 adds the Verwalter role, note-to-format links, and an audit log for client coach changes. Migration 008 adds optional before/after ratings (-10 to +10) to private session notes. Migration 009 adds centrally managed knowledge categories and assigns existing formats to their categories. Migration 010 adds client and separate invoice addresses, invoice recipient and email, customer reference number, hourly rate, price agreements, and payment term. Migration 011 adds permanent customer numbers and CoachKennungen (existing client references are kept; blank numbers are generated). Migration 012 adds separate coaching orders and optional Coachee archive release; existing entries are assigned to a historical order, and private Coach notes are never shown in the Coachee portal. New clients receive a permanent customer number and an initial order automatically. Migration 013 adds coach-specific invoice settings, tax rates, editable header/footer/service-text templates, private logo storage, one invoice per session, and printable summaries of invoices for an order. Migration 014 adds optional EC payment, bank name and payment-term snapshots, and centered bank/payment details in each invoice footer. Issued invoice amounts and sender/recipient/bank details are snapshots; changing settings affects only future invoices. The login uses `password_verify`, CSRF tokens, HTTP-only sessions, and a temporary IP rate limit. Coaches see only clients assigned to their user ID. Admins can view client records and manage coach accounts, but cannot change client-coach assignments. Verwalter can manage client assignment and basic client data but cannot open private session notes or coaching content. Only a Verwalter can reassign a client; every reassignment is logged. The dashboard links each client and appointment to that client's overview. The client overview offers quick actions to create an appointment or task with that client preselected, and each session has a link to its private documentation and optional before/after rating. Coaches can create or reset a Coachee login from that client's overview. Coachees can change their password, view their own appointments, goals, visible tasks, assigned documents, send messages with private PDF/JPG/PNG attachments, and update task status. Coaches exchange messages with the Coachee in the client overview. The NLP formats library can be private, shared with selected coaches, or shared with all active coaches. Font, size, and colors can be customized in the Darstellung menu. Appointment history is visible only for the user's clients; private notes are never available to Klient or Admin accounts. The private `/clients.php` page lets coaches manage their own clients. Verwalter can assign clients to active coaches; admins cannot change the assignment. Existing coach/client records can be edited. Admins can create and edit coach accounts in `/coaches.php` and create Verwalter accounts in `/managers.php`; name, username, email, and active status can be changed, and admins can set a new password while editing. New passwords need at least 8 characters and a special character.

An active account is needed in `benutzer`. If the table is empty, use the one-time setup described below. The current schema is user-owned rather than tenant/workspace based; shared messages and automatic email reminders remain planned; see `docs/FUNKTIONEN.md` for F1–F8.

## Run without SSH

Web Station serves PHP pages directly and starts PHP for each request.

1. In DSM Package Center, install/enable Web Station, PHP 8.2 and MariaDB 10.
2. In Web Station, create a PHP web service with document root set to this repository's `public/` folder and assign a PHP 8.2 profile.
3. In Web Station > Script Language Settings > PHP, enable `pdo_mysql` and `fileinfo` in that profile.
4. Configure the database using the steps below.
5. Open the Web Station portal. The database health check is at `/health.php`.

Only `public/` should be the document root. Keep the private configuration and application code outside it. The Web Station `http` group needs read access to the website and app files. The PHP process also needs write access to the private document storage folder outside `public/` (by default, the repository-root `storage/documents/`).

## MariaDB connection

1. Confirm MariaDB 10 is running and check its current port in DSM.
2. The application account needs SELECT/INSERT/UPDATE on `coaching_auftraege`, `coaching_rechnungseinstellungen`, `coaching_steuersaetze`, `coaching_rechnungsvorlagen`, `coaching_rechnungen`, existing account/client/appointment tables and `coaching_sitzungsnotizen`, `coaching_ziele`, `coaching_dokumentvorlagen`, `coaching_klientendokumente`, `coaching_dokument_signaturanfragen`, `coaching_klientenstatus`, `coaching_portal_zugaenge`, `coaching_aufgaben`, `coaching_design_einstellungen`, and `coaching_formate`. Give `coaching_rechnungsvorlagen` DELETE as well for template removal. Give `coaching_nachrichten` and `coaching_nachrichten_dateien` SELECT/INSERT; `coaching_format_freigaben` SELECT/INSERT/DELETE; `coaching_klientenzuweisungen` SELECT/INSERT; and `login_versuche` and `coaching_dokumentdateien` SELECT/INSERT.
3. Copy `config.example.php` to `config.local.php` in the project root on the NAS and set the host, port, username and password.
4. Keep `config.local.php` private and outside `public/`; it is ignored by Git.
5. Create a full MariaDB backup before changing the schema.
6. Import migrations in order: `001_sitzungsnotizen_ziele.sql`, `002_dokumente.sql`, `003_qr_signaturen.sql`, `004_klientenstatus.sql`, `005_portal_aufgaben_design.sql`, then `006_nachrichten_formate.sql`, then `007_verwalter_notiz_formate.sql`, then `008_sitzungs_skala.sql`, then `009_wissensbank_kategorien.sql`, `010_klienten_rechnungsdaten.sql`, `011_klienten_coach_kennungen.sql`, `012_coaching_auftraege_archiv.sql`, `013_rechnungen_coach_einstellungen.sql`, and finally `014_rechnungszahlungsarten_und_bankdaten.sql` followed by `015_email_und_passwort_zuruecksetzung.sql`. Migration 010 adds client and invoice address fields and billing terms; 011 adds permanent customer and coach IDs; 012 separates coaching orders and supports explicit archive release for Coachees. Migration 013 adds coach-owned invoice settings (logo, address, bank details, cash option), configurable tax rates and editable/selectable templates, one invoice per completed session, and a printable order-level summary that does not create a second claim. Migration 014 adds the coach-controlled EC option, payment notes for cash/EC, transfer due days, bank name snapshots, and a centered invoice footer. In the client overview, coaches can see created, paid, and overdue invoices and prepare a payment reminder in their email program. Invoice and summary pages use the browser print dialog to save as PDF; structured electronic invoice formats are not included. Migration 006 adds the shared message stream with private attachments and the NLP formats library with selective coach sharing. Migration 007 adds the Verwalter role, private-note links to visible NLP formats, and a log of client coach changes. Migration 008 adds the optional before/after session rating. Import all required migrations before copying the updated application files.
7. In the private `config.local.php`, set `app_base_url` to the HTTPS address of the Coaching Tool, including its path if it runs in a subfolder. Add `mail_encryption_key` as a unique 64-character hexadecimal key; generate it on a trusted computer, for example with `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`. In the app, an administrator opens `/email-settings.php`, enters the SMTP host, port, TLS mode, sender address and credentials, saves the settings, and sends a test email. SMTP works with Gmail, WEB.DE, GMX and local SMTP relays; providers may require an app password. The password is encrypted before it is stored in MariaDB. Keep both values in `config.local.php` private. Coachees need an email address saved under their Coachee access settings. The login page offers password reset by username and email; reset links expire after 60 minutes and work once. Update `app_base_url` if the NAS address or web path changes. The PHP process needs outbound network access to the chosen SMTP host and port.

8. In the PHP 8.2 profile, allow uploads up to the app's 15 MB limit (for example `upload_max_filesize=16M` and `post_max_size=18M`). Ensure the PHP process can write to the private `storage/documents/` folder outside the webroot. To use a different absolute folder, set `'document_storage_path' => '/absolute/private/path'` in `config.local.php`. Keep that folder out of `public/` and out of shared web folders.
8. Test `/health.php`. If `benutzer` is empty, add a private setup key to `config.local.php` and follow the first-admin steps below.
9. After signing in as admin, open `/coaches.php` to create an active coach account, then assign clients in `/clients.php`. For updated application files, copy each changed file from GitHub into the matching folder under `/web/Coaching-Tool`; keep `config.local.php` and `storage/` private.

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
- Coaching documents are stored under random filenames outside `public/`; downloads require an authenticated coach or the exact Coachee account tied to that client. Templates and returned files are limited to PDF/JPG/PNG and 15 MB per upload.
- QR signing is HTTPS-only. Each link expires after 24 hours, can be revoked, allows one completed signature, and requires a six-digit code given separately by the coach; five wrong codes lock the link. The QR-code generator is bundled locally, and a pinned pdf-lib 1.17.1 script from jsDelivr appends the drawn signature page; the PDF is processed in the browser and submitted only to this app. The signing browser needs internet access for that script. Check that this signature method suits each document.
- Never connect to MariaDB from browser JavaScript.
- Use synthetic data until tenant separation and account recovery have been reviewed.
- Use HTTPS before entering real coaching data. Keep the NAS private on the LAN during development and do not create router port forwarding.
- Login attempts store IP addresses in the existing `login_versuche` table; define a retention period before production use.
