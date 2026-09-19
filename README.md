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

### Required server `.env`

Create this file manually on the server at:

```text
/home/vijiweni/pay.sebuleni.com/.env
```

It is intentionally excluded from FTP deployment and Git. Use the database name, username, and password created by the hosting control panel; do not use `root` in production.

```env
APP_ENV=production
DB_HOST=localhost
DB_PORT=3306
DB_NAME=YOUR_CPANEL_DATABASE_NAME
DB_USER=YOUR_CPANEL_DATABASE_USER
DB_PASS=YOUR_CPANEL_DATABASE_PASSWORD

PESAPAL_BASE_URL_PROD=https://pay.pesapal.com/v3
PESAPAL_CONSUMER_KEY_PROD=YOUR_PRODUCTION_PESAPAL_CONSUMER_KEY
PESAPAL_CONSUMER_SECRET_PROD=YOUR_PRODUCTION_PESAPAL_CONSUMER_SECRET
PESAPAL_IPN_ID_PROD=YOUR_REGISTERED_IPN_ID

# Sandbox credentials are selected independently from production.
PESAPAL_BASE_URL_TEST=https://cybqa.pesapal.com/pesapalv3/api/
PESAPAL_CONSUMER_KEY_TEST=YOUR_TEST_PESAPAL_CONSUMER_KEY
PESAPAL_CONSUMER_SECRET_TEST=YOUR_TEST_PESAPAL_CONSUMER_SECRET
PESAPAL_IPN_ID_TEST=YOUR_SANDBOX_REGISTERED_IPN_ID
PESAPAL_CALLBACK_URL=https://pay.sebuleni.com/payment-callback

HEKTA_PAY_TEST_UI_PASSWORD=YOUR_STRONG_TEST_UI_PASSWORD
TEST_APP_ID=com.hekta.nafdex
TEST_APP_SECRET=YOUR_NAFDEX_HEKTA_PAY_APP_SECRET
TEST_CUSTOMER_EMAIL=your-test-email@example.com
```

Set file permissions so the PHP process can read it but visitors cannot download it:

```bash
chmod 600 /home/vijiweni/pay.sebuleni.com/.env
```

The application now loads this file automatically. Hosting-level environment variables take precedence over values in the file.

Environment aliases are supported: `test`, `testing`, and `sandbox` select the `*_TEST` Pesapal settings; `production`, `prod`, and `live` select the `*_PROD` settings. Pesapal URLs may be configured with or without the trailing `/api/`; Hekta Pay normalizes them.

After creating it, run the Hekta Pay migration against that database and confirm the `hekta_app_credentials` row exists. The previous error `Access denied for user 'root'@'localhost' (using password: NO)` means this file or the hosting environment variables were missing.

## Remote backend test UI

The deployed backend includes a protected diagnostic page:

```text
https://pay.sebuleni.com/remote_test.php
```

It returns `404` unless the server has `HEKTA_PAY_TEST_UI_PASSWORD` configured. Set these values server-side only:

- `HEKTA_PAY_TEST_UI_PASSWORD`: strong password for the browser test page.
- `TEST_APP_ID`: normally `com.hekta.nafdex`.
- `TEST_APP_SECRET`: NafDex Hekta Pay app secret used by authenticated checks.
- `TEST_CUSTOMER_EMAIL`: optional sandbox customer email.
- `TEST_GATEWAY_ENVIRONMENT`: use `testing` for the sandbox button; defaults to `testing`.

Run the safe suite first. It checks PHP bootstrap, MySQL, required tables, NafDex app registration, webhook signing, and unauthenticated API rejection. Use the separate sandbox action only after Pesapal sandbox credentials and IPN configuration are ready. Remove `HEKTA_PAY_TEST_UI_PASSWORD` or delete `public/remote_test.php` after backend verification.

Deployment trigger:

```powershell
git add .
git commit -m "Deploy Hekta Pay"
git push origin main
```

For a controlled deployment, use GitHub Actions -> `Deploy Hekta Pay to pay.sebuleni.com` -> `Run workflow`.
