# Tools Rent API

Laravel API for tool listings, vendor onboarding, bookings, payments, refunds, tool images, and rental lock codes.

## Local setup

Requirements:

- PHP 8.3 or newer
- Composer
- SQLite for the default local setup, or MySQL/PostgreSQL with matching `.env` values

Install and initialize the application:

```bash
composer setup
```

The application uses database-backed queues and scheduled maintenance by default. Run all three processes in separate terminals:

```bash
composer dev
composer queue
composer schedule
```

The queue worker processes payment refunds. The scheduler expires unpaid bookings, reconciles pending Stripe refunds, and retries deferred tool-image deletions. Running only the HTTP server leaves those workflows incomplete.

## Testing and formatting

```bash
composer test
vendor/bin/pint --test
```

## Production workers

Keep a queue worker running under a process monitor such as systemd, Supervisor, or the platform's worker service:

```bash
php artisan queue:work --tries=5 --timeout=60
```

Run Laravel's scheduler every minute from cron or the platform scheduler:

```cron
* * * * * cd /path/to/tools-rent-api && php artisan schedule:run >> /dev/null 2>&1
```

Restart long-running workers after every deployment so they load the new code:

```bash
php artisan queue:restart
```

Production also requires a valid `APP_KEY`, mail configuration for account verification, and `STRIPE_SECRET` when Stripe payments are enabled. Keep `APP_DEBUG=false` outside local development.

Tool images are stored on the private `TOOL_IMAGE_DISK` and served through the authenticated `GET /api/v1/tool-images/{toolImage}/file` endpoint. The default `local` disk needs no public storage link.
Uploads default to 10 images per tool, 100 images per vendor, and 10 mutations per minute. These limits can be adjusted with `TOOL_IMAGE_MAX_PER_TOOL`, `TOOL_IMAGE_MAX_PER_VENDOR`, and `TOOL_IMAGE_UPLOADS_PER_MINUTE`.

## Demo data

Demo seeders are deliberately disabled by default. They may only run in the `local` or `testing` environments after setting:

```dotenv
ALLOW_DEMO_SEEDING=true
```

Then run:

```bash
php artisan db:seed
```
