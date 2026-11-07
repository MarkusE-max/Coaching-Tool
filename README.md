# Coaching Tool

Modular, multi-tenant coaching management application. The initial scaffold contains a responsive React/Vite frontend and a minimal PHP REST health endpoint. It has no database connection and contains no coaching records.

## Architecture

- Frontend: React + Vite
- API: PHP 8.2, intended for Synology Web Station
- Database: MariaDB, to be added through versioned migrations
- Initial route: `GET /api/health.php` through the development proxy, or `GET /health.php` when the PHP public directory is served directly

Tenant boundaries and object permissions must be enforced by the API for every read and write. UI visibility is never an authorization control. Private coaching notes and portal permissions require explicit server-side checks before those modules are introduced.

## Local development

Requirements: Node.js 22 or compatible current LTS, npm, and PHP 8.2.

1. Install frontend dependencies: `npm install`
2. Start PHP from the repository root: `php -S 127.0.0.1:8080 -t api/public`
3. Start Vite in another terminal: `npm run dev`
4. Open the local URL printed by Vite. The frontend proxies `/api/*` to PHP.
5. Create a production frontend build with `npm run build`.

The PHP endpoint currently has no database dependency. Do not put secrets in Git. Keep future application configuration, uploads, logs, and backups outside the public web root.

## Synology DS918+ test deployment

Use the DS918+ only as a private development/test target. Keep source control on GitHub and build the frontend on the Mac; deploy only the generated `dist/` files.

1. In Web Station, create a dedicated test portal and serve the frontend build from its web root.
2. Configure the PHP 8.2 profile and expose `api/public/health.php` through a deliberate API portal or reverse-proxy path. The Vite proxy is development-only; configure the production API base path in the web server before deployment.
3. Keep application configuration, uploads, logs, and backups outside all public web roots.
4. Use a dedicated least-privilege MariaDB account when database access is added. Never use the MariaDB administrator account.
5. Keep the test environment LAN-only. Do not add router port forwarding and do not use real coaching data.
6. Back up the NAS before changing its services and confirm current PHP extensions and available memory.

No credentials, production settings, personal data, or real coaching records belong in this repository.
