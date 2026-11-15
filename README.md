# Cold River Data

AI chat archive and file storage for coldriverdata.net. PHP 8.1+ and MySQL 8 / MariaDB 10.6+, no Composer packages.

Users sign up, pick a plan (card or USDC through Stripe), upload files, and any ChatGPT or Claude
export they upload is filed into a readable, searchable chat archive.

## Layout

    public/      web root (index.php front controller, assets, .htaccess)
    src/         app code and page templates
    bin/         worker.php (cron), stripe-setup.php (one-time)
    schema.sql   database tables
    config.sample.php

## Install on CyberPanel

1. Put this folder at `/home/coldriverdata.net/app` and set the site's document root to
   `/home/coldriverdata.net/app/public` (same pattern as Tradewind). This replaces the page
   currently served from `public_html`.
2. Create a database and user in CyberPanel, then load the tables:
   `mysql -u USER -p DBNAME < schema.sql`
3. `cp config.sample.php config.php` and fill in the database, `app_url` and `storage_dir`.
   Create the storage folder outside the web root, owned by the site user:
   `mkdir -p /home/coldriverdata.net/storage && chmod 750 /home/coldriverdata.net/storage`
4. PHP extensions needed: pdo_mysql, curl, zip, mbstring. Uploads are sent in chunks sized to fit
   `upload_max_filesize`, so the PHP defaults work; raising it to 16M makes big uploads faster.
5. Cron, every minute, as the site user (files large exports into the archive):
   `* * * * * php /home/coldriverdata.net/app/bin/worker.php >> /home/coldriverdata.net/logs/worker.log 2>&1`

## Updating

    cd /home/coldriverdata.net/app
    git pull
    php bin/migrate.php        # applies any new database changes; safe to run every time
    chown -R coldr4180:coldr4180 .

A fresh install loads `schema.sql` first and then runs `php bin/migrate.php`.

## Admin area

`/admin` is visible only to accounts marked as admin. Mark one from the server:

    php bin/make-admin.php you@example.com          # add "remove" to take it away

- **Overview:** accounts, current plans, storage used, and anything waiting or failing.
- **Users:** search by email; per account you can set a plan by hand (no charge), send a password
  reset, suspend or reactivate, and delete the account with all its data (this also cancels its
  Stripe subscription). Admins see file names and sizes, never conversation text.
- **Storage:** add and manage where files are kept (below).
- **Activity log:** every admin action, with who did it and when.

## Storage locations

Out of the box, files go to `storage_dir` (shown in the admin as "Built-in folder"). In
Admin > Storage you can add more locations and choose which one receives new uploads. Files already
stored stay where they are.

- **Object storage** (Hetzner Object Storage, Backblaze B2, any S3-compatible service): create a
  private bucket and S3 credentials at the provider, then enter the endpoint (for Hetzner,
  `fsn1.your-objectstorage.com`, `nbg1...` or `hel1...`), the region (`fsn1`, `nbg1`, `hel1`), the
  bucket name and the two keys. The secret key is encrypted with `app_key` from `config.php`
  before it is saved, so set `app_key` first and never change it afterwards.
- **Folder on this server:** an extra disk or mounted volume. Give the full path; it must be
  writable by the site user.

How object storage is filled: an upload first lands in the built-in folder, any chat export in it
is filed, and then the cron worker moves the file to the bucket and removes the local copy once
the stored size is confirmed. Downloads from object storage use a private link that expires after
five minutes. If the bucket is unreachable, files simply wait locally and the Overview page shows
them as waiting or failing.

## Stripe

1. Put your secret key in `config.php` (use a test key first).
2. `php bin/stripe-setup.php` creates a product plus monthly and yearly prices per plan and prints
   the Price IDs. Paste them into the `plans` section of `config.php`.
3. In Stripe, add a webhook endpoint `https://coldriverdata.net/webhooks/stripe` with these events:
   `checkout.session.completed`, `checkout.session.async_payment_succeeded`,
   `customer.subscription.created`, `customer.subscription.updated`,
   `customer.subscription.deleted`, `invoice.payment_failed`.
   Put its signing secret in `stripe.webhook_secret`.
4. Turn on the customer portal (Settings > Billing > Customer portal) so people can change plan,
   update their card and cancel.
5. Crypto: turn on the Crypto payment method in Stripe (Settings > Payment methods). It accepts
   stablecoins (USDC), settles to you in USD. Set `crypto_enabled` to false to hide it.

How billing works here: card plans are Stripe subscriptions that renew by themselves. Crypto is a
one-time payment for 12 months that does not renew; paying again extends the date. Either way the
account has a plan and a paid-through date, and uploads stop when that date passes. Stored data
stays readable and downloadable after a plan lapses; nothing is deleted automatically.

## Changing plans and prices

Edit `plans` in `config.php`. Sizes are bytes, prices are cents. If you change a price, create a
new Stripe Price and update the ID (Stripe prices cannot be edited).

## Tested, and not yet

Tested locally against MariaDB 10.11 (object storage against an S3 emulator, with request
signatures checked against the AWS reference signer): sign-up, sign-in, chunked upload, quota, ChatGPT and Claude
import (including a 30 MB export through the worker), search, reader, download, delete, and the
webhook handler with signed sample events.

Not yet run against real Stripe: creating Checkout and portal sessions, and `bin/stripe-setup.php`.
Run the full flow in Stripe test mode before going live. Password-reset email uses PHP `mail()`
and depends on the server's mail setup.

## Not in this version

Email verification, account deletion by the user, moving existing files between storage
locations, and import of exports other than ChatGPT and Claude (those are stored as plain files).
