# Hekta Pay

Central payment API for Hekta ecosystem applications.

## Local setup

1. Create the `hekta_pay` MySQL database.
2. Copy `.env.example` to the runtime environment and set database, Pesapal, and app credentials.
3. Run `php database/migrations/run_all_migrations.php`.
4. Generate an app secret and store its password hash in `hekta_app_credentials`:

```php
password_hash('your-secret', PASSWORD_DEFAULT)
```

5. Serve `public/` as the web root. `public/index.php` uses Composer autoloading when available and the bundled PSR-4 fallback otherwise.

Payment API authentication uses `X-App-ID` and `X-App-Secret`. Gateway IPNs use `/api/ipn/{gateway}`. Outgoing app callbacks include `X-Hekta-Event-Id`, `X-Hekta-Timestamp`, and `X-Hekta-Signature`.
