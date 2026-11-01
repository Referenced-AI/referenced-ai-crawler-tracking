# Referenced – AI Crawler Tracking

[![License: GPL v2+](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](LICENSE)

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
