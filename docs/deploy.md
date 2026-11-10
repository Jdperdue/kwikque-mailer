# Deployment notes (IONOS VPS, Ubuntu 22.04)

App path `/var/www/maillist`, owner `ubuntu`, group `www-data`. Public host: `app.kwikque.com`
(DNS A record already points at this VPS). The VPS also carries other sites until end of 2026: do not
change global nginx, PHP-FPM, MariaDB, Redis or firewall config; everything here is app-specific.

## Components installed for this app
| Piece | Detail |
|---|---|
| PHP | 8.4 (CLI + `php8.4-fpm`). Dedicated pool `maillist` (`deploy/maillist-fpm-pool.conf` -> `/etc/php/8.4/fpm/pool.d/`), runs as `ubuntu`, socket `/run/php/maillist-fpm.sock`, so `.env` stays chmod 600 and unreadable to the other www-data sites with intl, bcmath, redis, mysql, mbstring, gd, zip, xml |
| MariaDB | database `maillist` (and `maillist_test` for tests), user `maillist`@localhost limited to those |
| Redis | 6.0, 127.0.0.1 only, password in `/etc/redis/maillist-auth.conf`; app uses DB 3/4 and prefix `maillist:` |
| Node | 22 via nvm (`source ~/.nvm/nvm.sh`), used only to build assets (`npm ci && npm run build`) |
| Secrets | `.env` (chmod 600) and `~/.maillist-secrets/` (chmod 600). Never commit or print these |

## nginx
Server block file: `/etc/nginx/sites-available/maillist.conf` (symlinked in sites-enabled), basic auth via
`/etc/nginx/maillist.htpasswd` (user `jdss`) as the pre-login gate, `fastcgi_pass` to `/run/php/maillist-fpm.sock`.
Status: **live with TLS** (certbot issued 2026-11-10, expires 2027-02-08, auto-renewed by `certbot.timer`).
HTTP redirects to HTTPS. The basic-auth gate stays on until app login is confirmed working; then remove the
`auth_basic` lines from the server block, `sudo nginx -t`, `sudo systemctl reload nginx`.

## Processes
- Horizon: `maillist-horizon.service` (source copy in `deploy/`). Install:
  `sudo install -m 644 deploy/maillist-horizon.service /etc/systemd/system/ && sudo systemctl daemon-reload && sudo systemctl enable --now maillist-horizon`
- After each deploy: `php artisan horizon:terminate` (systemd restarts it) so workers load new code.
- Scheduler: `ubuntu` crontab runs `php artisan schedule:run` every minute (tag `maillist-scheduler`).

## Gotchas
- `.env` is chmod 600 owned by `ubuntu`; PHP-FPM must run as `ubuntu` (the `maillist` pool). Under the default
  `www-data` pool the app cannot read `.env` and fails with "No application encryption key has been specified".
- `APP_ENV=production`, `APP_DEBUG=false` on this public host.
- Do not run `php artisan` as root or www-data: it would create root/www-data-owned files in `storage/`.

## Deploy steps
```
cd /var/www/maillist && git pull
composer install --no-dev --optimize-autoloader
source ~/.nvm/nvm.sh && npm ci && npm run build
php artisan migrate --force
php artisan config:cache route:cache view:cache
php artisan horizon:terminate
```
First install only: put `ADMIN_PASSWORD` in `.env`, then `php artisan db:seed` (creates Rene Bates and the
super-admin from `ADMIN_EMAIL`).

## Backups (to be implemented in Phase 6, with a tested restore)
Nightly `mysqldump maillist` plus `.env`, kept off-box. Redis holds only queue/cache data.

## APP_KEY
Never regenerate it: per-tenant adapter settings are encrypted with it.
