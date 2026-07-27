# arcade-game / Diamonds Outta Dirt

PHP/MySQL streetwear e-commerce site (plus an arcade game), no framework. Built for
HostGator shared hosting. See `SKILL.md` for deep domain conventions and known gotchas.

## Cursor Cloud specific instructions

### What is in this repo vs. what runs

- This repo is a **partial upload**: only root-level `*.php` files (plus assets, `composer.json`).
  Many pages `require` sibling directories that are **not present** here:
  `includes/`, `partials/`, `arcade_app/`, `account/`, `views/`, `admin/`, and
  `config/stripe.php`. Any page that pulls those in will return HTTP 500. This is
  expected, not a broken environment.
- Pages that boot cleanly are the **self-contained** ones whose only dependency is
  `db_connect.php` and/or `vendor/autoload.php`. The most useful working end-to-end
  flow is the shopping cart (`cart.php`).

### Running the app (dev)

- Start the built-in PHP server from the repo root with the provided router:
  `php -S 0.0.0.0:8000 router.php` (see the header comment in `router.php`).
  The router mirrors the `.htaccess` clean-URL rules (`/shop`, `/product/{id}`, `/about`, etc.).
- There is no build step. PHP is interpreted; just restart the server if needed.

### Database (MySQL/MariaDB)

- `db_connect.php` reads `DB_HOST`/`DB_NAME`/`DB_USER`/`DB_PASS` from the environment
  (or a `/home2/asqrtyte/.env` file that does not exist here), falling back to:
  host `localhost`, db `asqrtyte_dod_db`, user `asqrtyte_dod_user`, empty password.
- The VM has MariaDB installed with a matching database + user (empty password) already
  created, so the defaults work with no config. Start it with `sudo service mariadb start`
  (it is not auto-started on boot in this VM). Recreate if ever missing:
  `CREATE DATABASE asqrtyte_dod_db; CREATE USER 'asqrtyte_dod_user'@'localhost' IDENTIFIED BY '';
  GRANT ALL ON asqrtyte_dod_db.* TO 'asqrtyte_dod_user'@'localhost';`
- Tables are auto-created on demand by the throttled schema-repair block in `db_connect.php`
  (`CREATE TABLE IF NOT EXISTS ...`), so an empty database is fine — no migrations to run.
- The app degrades gracefully when the DB is unreachable (`$pdo` becomes `null`); a 500 is
  usually a missing `require`, not the DB.

### Lint / test

- No automated test suite or linter config exists. Syntax-check changed files with
  `php -l <file>.php`.

### Stripe

- `composer install` pulls `stripe/stripe-php`. Live Stripe calls need real keys
  (normally from `config/stripe.php`, which is not in this repo), so checkout/webhook
  endpoints can't be exercised end-to-end here without adding keys.
