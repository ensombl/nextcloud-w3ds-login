# Installation

This plugin supports two install paths. Pick the one that matches your situation.

- If you're running Nextcloud yourself and just want to drop the plugin in, jump to [Manual install on an existing Nextcloud](#manual-install-on-an-existing-nextcloud).
- If you want a throwaway dev environment to play with the plugin, jump to [Local development with Docker](#local-development-with-docker).

If you eventually want to publish to the Nextcloud app store, see [Publishing to the app store](#publishing-to-the-app-store) at the bottom.

## Requirements

- Nextcloud 28 to 33
- PHP 8.1 or newer with the `gmp` and `openssl` extensions
- Optional: [Nextcloud Talk](https://github.com/nextcloud/spreed) (called `spreed` internally) if you want chat sync. Login works without it.
- Outbound HTTPS access to `https://registry.w3ds.metastate.foundation` (the W3DS Registry) and to whichever eVault hosts your users live on.

## Manual install on an existing Nextcloud

This is the path your Nextcloud admin would follow if the plugin isn't on the app store yet, or if they prefer to install from source.

### 1. Download the plugin into the apps directory

Nextcloud loads apps from `apps/` inside its install directory (always present) and, if configured, also from `custom_apps/`. A fresh tarball install only has `apps/`, so that's the safe default. The exact path depends on how Nextcloud was installed:

- Tarball install: usually `/var/www/nextcloud/apps/`
- Snap install: `/var/snap/nextcloud/common/nextcloud/extra-apps/`
- Docker official image: `/var/www/html/apps/` inside the container

If your instance is already set up with a separate `custom_apps/` directory (admins often do this to keep third-party apps outside the upgrade path), you can use that instead — everything below works the same way, just substitute the path.

Drop the plugin in there as a directory called `w3ds_login`:

```bash
cd /var/www/nextcloud/apps
git clone https://github.com/ensombl/nextcloud-w3ds-login.git w3ds_login-src
cp -r w3ds_login-src/app w3ds_login
rm -rf w3ds_login-src
```

The repo layout has the actual Nextcloud app under `app/`, so the app directory Nextcloud sees is the contents of that subfolder.

### 2. Install PHP dependencies

The plugin vendors its dependencies via Composer. The repo ships a `vendor/` directory but if you cloned without it, install:

```bash
composer install --no-dev --optimize-autoloader
```

Make sure the `vendor/` directory ends up inside the app folder so Nextcloud's autoloader picks it up.

### 3. Fix permissions

Nextcloud runs under a web-server user, and that user needs to own the app directory. The examples below use `www-data` (the Debian/Ubuntu default for both Apache and nginx), but your system may differ:

| Distro family | Typical user |
|---|---|
| Debian / Ubuntu | `www-data` |
| RHEL / CentOS / Rocky / Fedora (Apache) | `apache` |
| RHEL / CentOS / Rocky / Fedora (nginx) | `nginx` |
| Alpine | `nginx` or `apache` |
| Arch | `http` |

What actually matters is the **PHP-FPM pool user** (or mod_php user), not the web server's own process. Confirm it on your host:

```bash
ps -eo user,cmd | grep -E 'php-fpm|php_fpm' | head
# or
grep '^user' /etc/php*/fpm/pool.d/*.conf
```

Substitute that user wherever the commands below say `www-data`:

```bash
chown -R www-data:www-data /var/www/nextcloud/apps/w3ds_login
```

### 4. Enable the app

From the Nextcloud install directory, run the `occ` CLI as the web user:

```bash
sudo -u www-data php occ app:enable w3ds_login
```

This triggers the migrations, which create the `oc_w3ds_login_*` tables. You can re-run migrations explicitly if needed:

```bash
sudo -u www-data php occ migrations:migrate w3ds_login
```

### 5. Configure a shared cache (required)

Nextcloud's per-request memory cache is the default, and it isn't shared across PHP-FPM workers. The W3DS QR flow writes a session from one request (the wallet's callback) and reads it from another (the browser's poll) — so if there's no shared cache, the poll can't see the session and reports it as expired immediately.

If `sudo -u www-data php occ config:system:get memcache.distributed` prints an empty line, you need to pick one. The two options below are both supported by Nextcloud out of the box.

**APCu (simplest, single host):**

```bash
# Make sure the PHP extension is loaded
php -m | grep -i apcu

# Enable APCu in both CLI and FPM (package installs it disabled on some distros)
PHPVER=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
echo -e "apc.enabled=1\napc.enable_cli=1" | sudo tee /etc/php/$PHPVER/cli/conf.d/99-apcu.ini
echo -e "apc.enabled=1\napc.shm_size=64M" | sudo tee /etc/php/$PHPVER/fpm/conf.d/99-apcu.ini

# Wire Nextcloud to APCu
sudo -u www-data php occ config:system:set memcache.local       --value='\OC\Memcache\APCu'
sudo -u www-data php occ config:system:set memcache.distributed --value='\OC\Memcache\APCu'

sudo systemctl restart php*-fpm
```

`restart` (not `reload`) is needed the first time because FPM only picks up new `.ini` files on a full restart.

**Redis (multi-host, survives restarts):**

```bash
sudo apt install redis-server
sudo systemctl enable --now redis-server

sudo -u www-data php occ config:system:set memcache.local       --value='\OC\Memcache\Redis'
sudo -u www-data php occ config:system:set memcache.distributed --value='\OC\Memcache\Redis'
sudo -u www-data php occ config:system:set memcache.locking     --value='\OC\Memcache\Redis'
sudo -u www-data php occ config:system:set redis host --value='127.0.0.1'
sudo -u www-data php occ config:system:set redis port --value=6379 --type=integer

sudo systemctl restart php*-fpm
```

Confirm the change stuck:

```bash
sudo -u www-data php occ config:system:get memcache.distributed
```

Should print `\OC\Memcache\APCu` or `\OC\Memcache\Redis`, not an empty line.

### 6. Allow outbound requests to the registry and eVaults

The W3DS Registry sometimes returns an eVault URL whose host is a bare IP address rather than a DNS name. Nextcloud's built-in HTTP client has SSRF protection that rejects those by default, and you'll see this in the log when a user tries to sign in:

```
w3ds_login: Signature verification error: No DNS record found for <ip>
```

To allow it:

```bash
sudo -u www-data php occ config:system:set allow_local_remote_servers --value=true --type=boolean
```

Strictly speaking this loosens Nextcloud's outbound SSRF guard for all apps, not just this one — the trade-off is acceptable for a single-purpose server but weigh it against your threat model if this box also hosts other apps. The long-term fix lives with the registry/eVault operator: have them return a hostname.

### 7. Verify

Open the Nextcloud login page in a browser. You should see a "Sign in with W3DS" button alongside the password form. If you don't, check `nextcloud.log` for errors and confirm the app is enabled:

```bash
sudo -u www-data php occ app:list | grep w3ds
```

If you have Talk installed and a user has linked their W3DS identity from personal settings, sending a message should appear in their eVault within a couple of seconds. The plugin logs every sync attempt at info level, so `tail -f nextcloud.log | grep W3DS` is a good way to watch what it's doing the first time.

### Troubleshooting

**"Session expired" the moment the poll starts.** No shared cache — go back to step 5.

**`Class "chillerlan\QRCode\QROptions" not found` or similar autoload failure.** The app's `vendor/` directory isn't where the PHP autoloader expects it. Depending on how you laid the app out, either (a) run `composer install --no-dev --optimize-autoloader` inside `apps/w3ds_login/`, or (b) if you kept the repo outside `apps/` and symlinked `apps/w3ds_login → /opt/.../app/`, the vendor directory is at the repo root and the app directory needs its own link: `ln -s /opt/.../vendor /opt/.../app/vendor && chown -h www-data:www-data /opt/.../app/vendor && sudo systemctl restart php*-fpm`. Opcache caches the missing-class failure, so a **restart** (not reload) of FPM is required.

**`Config file has leading content, please remove everything before "<?php" in config.php`.** Something got prepended to `/var/www/nextcloud/config/config.php` — typically a BOM or a blank line introduced by a stray editor. Check the first bytes with `sudo head -c 200 /var/www/nextcloud/config/config.php | od -c | head`; everything before the first `<?php` needs to go. `sudo -u www-data sed -i '0,/<?php/{/<?php/!d}' /var/www/nextcloud/config/config.php` trims it.

**Sign-in button missing.** Either the app isn't enabled (`occ app:list | grep w3ds`) or the autoloader tripped during app registration. Check `nextcloud.log` for fatal errors from `w3ds_login`.

**403 on `/apps/dashboard` (or any other core app) after tightening nginx.** If your nginx `try_files` line includes `$uri/`, nginx tries to serve a directory, finds no `index.php` inside, and returns 403 since autoindex is off. Drop the `$uri/`: `try_files $uri /index.php$request_uri;`.

**Psalm failing in CI with `UndefinedInterfaceMethod` or `UndefinedClass` on internal `OC\User\Session`.** The plugin duck-types a method on `IUserSession` that only exists on the concrete `\OC\User\Session` class, which Psalm can't see. The check is `method_exists($this->userSession, 'setLoginName')`. Keep the guard; don't try to typehint the concrete class or use `instanceof` — both trip Psalm because `\OC\User\Session` isn't in OCP's public stub paths.

### Updating

Updates are git pull plus a migration run plus a cache flush:

```bash
cd /var/www/nextcloud/apps/w3ds_login
sudo -u www-data git pull
sudo -u www-data composer install --no-dev --optimize-autoloader
sudo -u www-data php occ migrations:migrate w3ds_login
sudo -u www-data php occ maintenance:repair
```

If you're running PHP-FPM or Apache mod_php with opcache, restart or graceful-reload the web server so the new code gets picked up. Opcache loves to serve stale files for a while.

## Local development with Docker

Want a sandbox to hack on the plugin? The repo ships a `docker-compose.yml` that gives you a working Nextcloud + MariaDB stack with the plugin volume-mounted, so changes to PHP files are reflected immediately.

### 1. Clone

```bash
git clone https://github.com/ensombl/nextcloud-w3ds-login.git
cd nextcloud-w3ds-login
cp .env.example .env
```

The defaults in `.env.example` work fine. If you already have something on port 8580, change `NEXTCLOUD_PORT`.

### 2. Start the stack

```bash
make dev
```

This builds the custom Nextcloud image (the only addition is `gmp` and `xdebug`) and starts both containers. First-run Nextcloud setup runs automatically, so when the container reports it's ready, the admin user is `admin` / `admin`.

Open `http://localhost:8580` and sign in.

### 3. Enable the app

```bash
make enable
```

This is just a shortcut for `occ app:enable w3ds_login`. The app's source is volume-mounted from `./app/` on the host, so editing files locally affects the running container without rebuilds.

### 4. Useful Make targets

```
make dev       # Start the stack
make down      # Stop it
make logs      # Tail the Nextcloud container logs
make occ CMD="user:list"   # Run any occ command
make shell     # Bash inside the container
make restart   # Just restart Nextcloud (after PHP changes if opcache is stale)
make clean     # Stop and wipe volumes (full reset)
make test      # Run PHPUnit
make lint      # Run php -l on every file
make cs        # PHP-CS-Fixer in dry-run mode
```

### 5. Watching sync activity

The plugin logs every meaningful sync event with the `[W3DS Sync]` prefix:

```bash
make logs | grep W3DS
```

### 6. Wiping state without rebuilding

```bash
make clean    # nukes Nextcloud's data volume + db
make dev
```

You'll get a fresh Nextcloud, but the plugin source is unchanged.

## Publishing to the app store

If you want this to be installable from the official Nextcloud app store, the steps are:

1. Bump the version in `app/appinfo/info.xml`.
2. Tag the release: `git tag v0.2.0 && git push --tags`.
3. Build the release artifact with [krankerl](https://github.com/ChristophWurst/krankerl): `make appstore` produces a signed tarball.
4. Upload it at https://apps.nextcloud.com/developer/apps. The first release also requires the app's signing key to be registered there.

Until that happens, manual install is the only way to get this plugin onto a Nextcloud instance.
