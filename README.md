# Referenced – AI Crawler Tracking

WordPress plugin that counts visits from known AI crawlers and posts hourly day totals to Referenced.

It is a reporter only: it writes nothing to your pages, and records no visitor data (no IP addresses, cookies or user accounts). For each known crawler it keeps a count per bot, path and day in one small table of its own. You need a [Referenced](https://getreferenced.ai) account and a connection token; until a token is saved, nothing is sent anywhere.

## Install and connect

1. Download the plugin zip from Referenced, under Settings → Connections, and upload it under Plugins → Add New → Upload Plugin. Activate it.
2. In Referenced, open Settings → Connections and create a WordPress token.
3. In WordPress, open Settings → Referenced and paste the token.

The first report arrives within about an hour of your site's next visits (WordPress runs scheduled tasks on traffic). **Send now** on the settings screen sends at once.

## In WordPress

- **Settings → Referenced:** the connection (the token shows masked to its last four characters once saved), the status of the last send, **Send now**, the last 7 days of crawler visits and most visited pages, and **Disconnect**, which removes the token and stops all reporting.
- **Dashboard widget:** AI crawler visits over the last 7 days and the connection state.
- **Connect notice:** a dismissible prompt on the Plugins and Dashboard screens until a token is saved.

## What is sent

Only after a token is saved: the bot name, the page path, the date, the visit count, the plugin version, the WordPress version and your site's home URL, once an hour and on **Send now**. No visitor IP addresses, cookies, user agents or personal data. See the [privacy policy](https://getreferenced.ai/privacy) and [terms](https://getreferenced.ai/terms).

Pages served from a full-page cache or a CDN never reach PHP, so they cannot be counted: the numbers are a floor, not an exact total.

## Credits and licence

GPL-2.0-or-later (see `LICENSE`). Bundled third-party material is listed in `THIRD-PARTY-NOTICES.txt`: Geist fonts (SIL OFL 1.1), Heroicons and Lobe Icons (MIT) and a Simple Icons mark (CC0). The logos belong to their respective owners and are used only to identify crawlers.

## Development

Developer commands: `composer test` (Pest), `composer lint` (WordPress Coding Standards), `bin/build-zip` (release zip, built from the committed tree with `assets/` and `THIRD-PARTY-NOTICES.txt` included, so commit first; the shipped copy lives in the Referenced repo under `public/downloads/`).

## Follow-ups

- Add a schema-version check on `plugins_loaded` before any 1.0.x schema change, so dashboard updates (which do not fire the activation hook) can run `dbDelta`.
- Confirm `Tested up to` in `readme.txt` against the current WordPress release at submission.
- Consider `Requires at least: 6.2` and `%i` identifier placeholders, which would drop every `InterpolatedNotPrepared` ignore.
- `Contributors:` in `readme.txt` must be a real wordpress.org username before submission.
- Run the official Plugin Check in the live run.
- Run the live end-to-end check, which has not been run (no Docker in the build sandbox):

```bash
docker network create wpnet
docker run -d --name wpdb --network wpnet -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=wp -e MARIADB_USER=wp -e MARIADB_PASSWORD=wp mariadb:11
docker run -d --name wp --network wpnet -p 8081:80 \
  --add-host host.docker.internal:host-gateway \
  -e WORDPRESS_DB_HOST=wpdb -e WORDPRESS_DB_USER=wp -e WORDPRESS_DB_PASSWORD=wp -e WORDPRESS_DB_NAME=wp \
  -e WORDPRESS_CONFIG_EXTRA="define('REFERENCED_INGEST_URL', 'http://host.docker.internal:8000');" \
  -v "$PWD":/var/www/html/wp-content/plugins/referenced-ai-crawler-tracking \
  wordpress:php8.2-apache
# install WordPress and activate (wp-cli container sharing the volumes). Every wordpress:cli run needs the same
# -e flags: the image reads them at runtime, and without them `core install` cannot connect and `cron event run`
# would post the dev token to production.
docker run --rm --network wpnet --volumes-from wp -u 33:33 \
  -e WORDPRESS_DB_HOST=wpdb -e WORDPRESS_DB_USER=wp -e WORDPRESS_DB_PASSWORD=wp -e WORDPRESS_DB_NAME=wp \
  -e WORDPRESS_CONFIG_EXTRA="define('REFERENCED_INGEST_URL', 'http://host.docker.internal:8000');" \
  wordpress:cli core install --path=/var/www/html --url=http://localhost:8081 --title=Test --admin_user=admin --admin_password=admin --admin_email=a@example.com --skip-email
docker run --rm --network wpnet --volumes-from wp -u 33:33 \
  -e WORDPRESS_DB_HOST=wpdb -e WORDPRESS_DB_USER=wp -e WORDPRESS_DB_PASSWORD=wp -e WORDPRESS_DB_NAME=wp \
  -e WORDPRESS_CONFIG_EXTRA="define('REFERENCED_INGEST_URL', 'http://host.docker.internal:8000');" \
  wordpress:cli plugin activate referenced-ai-crawler-tracking --path=/var/www/html
# issue a token on the dev Connections page (php artisan serve on :8000), save it in Settings -> Referenced, then:
curl -A "Mozilla/5.0 (compatible; GPTBot/1.2; +https://openai.com/gptbot)" http://localhost:8081/hello-world/
curl -A "Mozilla/5.0 (compatible; OAI-SearchBot/1.0; +https://openai.com/searchbot)" http://localhost:8081/hello-world/
docker run --rm --network wpnet --volumes-from wp -u 33:33 \
  -e WORDPRESS_DB_HOST=wpdb -e WORDPRESS_DB_USER=wp -e WORDPRESS_DB_PASSWORD=wp -e WORDPRESS_DB_NAME=wp \
  -e WORDPRESS_CONFIG_EXTRA="define('REFERENCED_INGEST_URL', 'http://host.docker.internal:8000');" \
  wordpress:cli cron event run referenced_send_hits --path=/var/www/html
```

On a Linux host the dev server must run as `php artisan serve --host=0.0.0.0` for `host.docker.internal:8000` to be reachable from the containers.

Expected: the plugin status line reads "Sent N rows at ..."; `bot_hits_daily` in the dev DB has `gptbot` and `oai-searchbot` rows for `/hello-world/`; Connections shows connected with the plugin version; AI Access shows the visit on the ChatGPT tile (after the OAI-SearchBot request; GPTBot is training-only) and in the Crawler Permissions table.
