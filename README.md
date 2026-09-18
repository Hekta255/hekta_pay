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

## Automatic deployment

The workflow at `.github/workflows/deploy.yml` deploys `main` to `https://pay.sebuleni.com` through GitHub Actions and FTP.

Configure these GitHub repository or production-environment secrets:

- `FTP_SERVER`: FTP hostname supplied by the hosting provider.
- `FTP_USERNAME`: deployment FTP user.
- `FTP_PASSWORD`: deployment FTP password.

The workflow assumes the FTP account can see the `pay.sebuleni.com/` directory, matching the Hekta ID deployer convention. If the FTP account logs directly into the subdomain document root, change both `server-dir` values in the workflow from `./pay.sebuleni.com/...` to `./...`.

The upload is intentionally split:

- `public/` -> `pay.sebuleni.com/` document root.
- `src/` -> `pay.sebuleni.com/src/`, required by `index.php` and protected by `.htaccess`.

Database migrations and runtime environment variables are never uploaded automatically. Configure `DB_*`, `PESAPAL_*`, and the Hekta Pay application secrets on the server, then run the migration from the server or database host. The workflow verifies `https://pay.sebuleni.com/health` after upload.

Deployment trigger:

```powershell
git add .
git commit -m "Deploy Hekta Pay"
git push origin main
```

For a controlled deployment, use GitHub Actions -> `Deploy Hekta Pay to pay.sebuleni.com` -> `Run workflow`.
