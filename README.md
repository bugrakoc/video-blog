# Video Blog

A lightweight PHP + MariaDB blog for publishing YouTube videos. Built for DirectAdmin shared hosting (PHP 8.2, MariaDB 10.6), with no framework and no build step.

Each post embeds a video, uses its YouTube thumbnail as the cover image, and has a Markdown body (usually the video description plus any extra text).

## Features

- Homepage feed with thumbnail, title, excerpt and pagination
- Single post pages with a privacy-friendly embed (`youtube-nocookie.com`)
- Categories, search, draft posts, and static pages (e.g. About, Contact)
- SEO: meta descriptions, canonical URLs, Open Graph / Twitter tags, `VideoObject` JSON-LD, sitemap, RSS
- Dark mode that follows the visitor's system setting
- Admin panel at `/admin/`: add, edit, delete, publish and schedule videos; static pages; categories; site settings
- Autoposter: new videos on your YouTube channel are added automatically as drafts (or published), via cron and the channel's public feed (no API key)

## Status

| Part | State |
|---|---|
| Schema, router, public templates, CSS, RSS, sitemap, installer | Done |
| Admin panel (posts, pages, categories, settings, password) | Done |
| Autoposter (cron + YouTube channel feed) | Done |

## Layout

```
site/                  # contents go into public_html
├── index.php          # front controller for all public URLs
├── install.php        # one-time installer (delete after use)
├── .htaccess          # clean URLs
├── assets/            # CSS, JS
├── admin/             # admin panel (login, videos, pages, categories, settings)
├── cron/              # autoposter cron script (CLI only)
└── app/               # config, DB, helpers, queries, templates (web access denied)
```

## Setup

1. Upload the contents of `site/` to `public_html`. Make sure the hidden `.htaccess` files are included, including `app/.htaccess`.
2. In DirectAdmin, create a MariaDB database and user.
3. Edit `app/config.php`: database details and your real `base_url` (no trailing slash).
4. Open `/install.php`, choose an admin username and password (10+ characters), and run it.
5. Delete `install.php`.

## Admin panel

Log in at `/admin/` with the account created by the installer.

- **Adding a video:** paste a YouTube link (watch, youtu.be, Shorts or embed URLs all work). The title is fetched automatically from YouTube's public oEmbed endpoint; paste the description into the text box yourself (the YouTube API isn't used, so no key is needed).
- **Drafts and scheduling:** a post is public only when it is *Yayında* and its publish date is not in the future. Draft and scheduled posts can be previewed while you are logged in.
- **Categories:** tick existing ones or type new names (comma separated) while editing a video.
- **Static pages:** add pages like Hakkında or İletişim; tick "Menüde göster" to put them in the top menu. Reserved addresses (`admin`, `search`, `category`, ...) are renamed automatically.
- **Settings:** site name, tagline, share image, footer text and the YouTube channel settings used by the future autoposter.

## Autoposter

New uploads on your channel are imported by `cron/autopost.php`, which reads the channel's public YouTube feed (`youtube.com/feeds/videos.xml?channel_id=...`). The feed includes the title, publish date and full description, so no API key is needed.

**Setup**

1. In the admin panel, go to *Ayarlar* → *Otomatik yayın*, enter your channel ID (the `UC...` ID from YouTube Studio → Settings → Channel → Advanced settings; a channel name or `@handle` won't work), choose *draft* or *publish directly*, and save.
2. In DirectAdmin → Advanced Features → **Cron Jobs**, add a job every 30 minutes (minute `*/30`, everything else `*`) with the command shown on the settings page, e.g. `php /home/USER/domains/EXAMPLE.COM/public_html/cron/autopost.php` (use the PHP path DirectAdmin gives you, e.g. `/usr/local/php82/bin/php`).

**How it behaves**

- **First check imports nothing.** It only marks the videos already on your channel as seen, so your homepage isn't flooded with old videos. Only videos uploaded afterwards are added. Use **Son videoları içe aktar** (or `php cron/autopost.php --import-all`) to also import the latest ones (the feed holds at most 15).
- **No duplicates, no resurrections.** A video already on the site (even one you added by hand) is skipped, and a video you delete won't come back on the next run. Changing the channel ID starts a fresh baseline.
- **Imported posts** use the video's real upload date, `source = auto`, and the description as the body (hashtags stay plain text rather than becoming headings). Review them in the post list and add categories before publishing.
- **Shorts** can be included or skipped (setting).
- **Quiet cron:** the script prints output only when it adds videos or hits an error, so DirectAdmin doesn't email you every run. Use `-v` for a status line every time. The last result is also shown on the settings page.
- A feed error (wrong channel ID, YouTube unreachable) is reported but never changes existing posts.

## Turkish text rules

Turkish content is the main reason for several design choices. Keep these when changing code:

- **Database:** `utf8mb4` with `utf8mb4_unicode_ci` for the connection, tables and columns.
- **PHP:** use `mb_*` functions for anything that cuts or measures text.
- **Casing:** never use `strtolower`/`mb_strtolower` directly on user text for comparisons. Use `tr_lower()`, since PHP lowercases `I` to `i` (Turkish expects `ı`) and `İ` to `i` plus a combining dot.
- **Slugs:** ASCII only, produced by `slugify()` (`ç→c, ğ→g, ı→i, İ→i, ö→o, ş→s, ü→u`).
- **Search:** posts keep an accent-folded copy of their text in `search_text`, so `cay` finds "çay" and the reverse. Rebuild it through `save_post()`, which does this automatically.
- **Sorting names:** use `tr_compare()`, so Ç sorts after C and Ş after S.
- **JSON output:** use `json_out()` so Turkish characters stay readable.

## Security notes

- Passwords are hashed with `password_hash`. Login is limited to 5 failed attempts per IP per 15 minutes, sessions end after 2 hours of inactivity, and every form (including logout and delete) needs a CSRF token. All SQL uses prepared statements, and all output goes through `e()`.
- Markdown is rendered with Parsedown in safe mode, so raw HTML in post bodies is escaped.
- `app/` and `cron/` are denied by `.htaccess`. If DirectAdmin allows it, moving `app/` above `public_html` is even safer (update the `require` paths in `index.php` and `install.php`).
- Never commit real credentials. Keep `app/config.php` in the repo as placeholders only.

## Local testing

```
php -S 127.0.0.1:8099 router.php
```

where `router.php` returns `false` for existing files and otherwise requires `index.php` (mimicking the `.htaccess` rewrite). Set `base_url` to `http://127.0.0.1:8099` and `debug` to `true` in your local config.

To test the autoposter without YouTube, add `'autopost_feed_url' => 'http://127.0.0.1:8098/feed.xml',` to the local `app/config.php` and serve a sample Atom feed there (same structure as YouTube's). Remove it afterwards.

## Credits

Markdown rendering by [Parsedown](https://github.com/erusev/parsedown) 1.7.4 (MIT), lightly patched for PHP 8.4 nullable-parameter deprecations.
