# Eluth Community Server

Self-hosted community server software for the [Eluth](https://eluth.io) platform. Provides real-time messaging, voice and video calls, member management, and role-based permissions — all connected to the Eluth central identity system.

---

## Requirements

- PHP 8.2+
- MySQL 8.0+
- Node.js 22+ (build only)
- Composer 2+
- An active [Eluth operator subscription](https://sol.eluth.io/operator/register)

## Installation

### 1. Download

Download the latest release zip from the [Releases](https://github.com/Eluthio/eluth-community/releases) page and extract it to your server's web root.

### 2. Configure

```bash
cp .env.example .env
```

Edit `.env` and fill in your database credentials, your Eluth operator ID, and your central server URL (`https://sol.eluth.io`).

### 3. Install and migrate

```bash
composer install --no-dev --optimize-autoloader
php artisan key:generate
php artisan migrate
php artisan optimize
```

### 4. Web server

Point your web server's document root at the `public/` directory. A `.htaccess` is included for Apache/LiteSpeed. For Nginx, use a standard Laravel Nginx config.

### 5. Set up the update manager

```bash
php artisan eluth:setup-updater
```

This creates a private update backend at a randomised URL. Save the URL and password it outputs — you will need them to apply future updates.

### 6. Schedule the sync

Add the Laravel scheduler to your crontab to keep your server in sync with Eluth central:

```
* * * * * php /path/to/artisan schedule:run >> /dev/null 2>&1
```

---

## Updating

Visit your update backend URL (generated during setup) and follow the on-screen steps. The process will:

1. Download the new release from GitHub
2. Show you exactly what will change
3. Take a full database dump and site file backup
4. Install the update
5. Run any database migrations automatically

A rollback option is available on the Rollback page if anything goes wrong.

---

## Configuration reference

Key `.env` values specific to Eluth:

| Key | Description |
|-----|-------------|
| `CENTRAL_SERVER_URL` | URL of the Eluth central server (`https://sol.eluth.io`) |
| `OPERATOR_ID` | Your operator ID from the central dashboard |
| `VITE_CENTRAL_SERVER_URL` | Same as above, used at frontend build time |
| `VITE_REVERB_APP_KEY` | WebSocket app key |
| `VITE_REVERB_HOST` | WebSocket host |
| `VITE_REVERB_PORT` | WebSocket port |
| `VITE_REVERB_SCHEME` | `https` or `http` |

---

## Plugins

Custom plugins placed in the `plugins/` directory are never touched during updates. You can also protect additional paths by creating a `custom-files.json` in the root:

```json
[
  "public/custom-theme.css",
  "resources/custom/"
]
```

---

## License

Copyright © 2026 Eluth.io. All rights reserved. Use is subject to your operator agreement.
