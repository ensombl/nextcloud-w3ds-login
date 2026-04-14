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

### 1. Download the plugin into custom_apps

Nextcloud loads apps from `custom_apps/` inside its install directory. The exact path depends on how Nextcloud was installed:

- Tarball install: usually `/var/www/nextcloud/custom_apps/`
- Snap install: `/var/snap/nextcloud/common/nextcloud/custom_apps/`
- Docker official image: `/var/www/html/custom_apps/` inside the container

Drop the plugin in there as a directory called `w3ds_login`:

```bash
cd /var/www/nextcloud/custom_apps
git clone https://github.com/ensombl/nextcloud-w3ds-login.git w3ds_login
cd w3ds_login
```

The repo layout has the actual app under `app/` with a symlink so this works directly. If your filesystem doesn't follow symlinks (rare), copy `app/` to a sibling directory and rename it:

```bash
cp -r app /var/www/nextcloud/custom_apps/w3ds_login
```

### 2. Install PHP dependencies

The plugin vendors its dependencies via Composer. The repo ships a `vendor/` directory but if you cloned without it, install:

```bash
composer install --no-dev --optimize-autoloader
```

Make sure the `vendor/` directory ends up inside the app folder so Nextcloud's autoloader picks it up.

### 3. Fix permissions

Whatever user runs Nextcloud (`www-data` on Debian/Ubuntu, `nginx` or `apache` on RHEL-likes) needs to own the app directory:

```bash
chown -R www-data:www-data /var/www/nextcloud/custom_apps/w3ds_login
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

### 5. Verify

Open the Nextcloud login page in a browser. You should see a "Sign in with W3DS" button alongside the password form. If you don't, check `nextcloud.log` for errors and confirm the app is enabled:

```bash
sudo -u www-data php occ app:list | grep w3ds
```

If you have Talk installed and a user has linked their W3DS identity from personal settings, sending a message should appear in their eVault within a couple of seconds. The plugin logs every sync attempt at info level, so `tail -f nextcloud.log | grep W3DS` is a good way to watch what it's doing the first time.

### Updating

Updates are git pull plus a migration run plus a cache flush:

```bash
cd /var/www/nextcloud/custom_apps/w3ds_login
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
