# Video Blog

A lightweight PHP + MariaDB blog for publishing YouTube videos. Built for DirectAdmin shared hosting (PHP 8.2, MariaDB 10.6), with no framework and no build step.

Each post embeds a video, uses its YouTube thumbnail as the cover image, and has a Markdown body (usually the video description plus any extra text).

## Features

- Homepage feed with thumbnail, title, excerpt and pagination
- Single post pages with a privacy-friendly embed (`youtube-nocookie.com`)
- Categories, search, draft posts, and static pages (e.g. About, Contact)
- SEO: meta descriptions, canonical URLs, Open Graph / Twitter tags, `VideoObject` JSON-LD, sitemap, RSS
- Dark mode that follows the visitor's system setting

## Status

| Part | State |
|---|---|
| Schema, router, public templates, CSS, RSS, sitemap, installer | Done |
| Admin panel (posts, pages, categories, settings) | Planned |
| Autoposter (cron, YouTube channel RSS feed) | Planned |

## Layout

```
site/                  # contents go into public_html
├── index.php          # front controller for all public URLs
├── install.php        # one-time installer (delete after use)
├── .htaccess          # clean URLs
├── assets/            # CSS, JS
├── admin/             # admin panel (planned)
├── cron/              # autoposter (planned)
└── app/               # config, DB, helpers, queries, templates (web access denied)
```

## Setup

1. Upload the contents of `site/` to `public_html`. Make sure the hidden `.htaccess` files are included, including `app/.htaccess`.
2. In DirectAdmin, create a MariaDB database and user.
3. Edit `app/config.php`: database details and your real `base_url` (no trailing slash).
4. Open `/install.php`, choose an admin username and password (10+ characters), and run it.
5. Delete `install.php`.

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

- Passwords are hashed with `password_hash`. All SQL uses prepared statements, and all output goes through `e()`.
- Markdown is rendered with Parsedown in safe mode, so raw HTML in post bodies is escaped.
- `app/` and `cron/` are denied by `.htaccess`. If DirectAdmin allows it, moving `app/` above `public_html` is even safer (update the `require` paths in `index.php` and `install.php`).
- Never commit real credentials. Keep `app/config.php` in the repo as placeholders only.

## Local testing

```
php -S 127.0.0.1:8099 router.php
```

where `router.php` returns `false` for existing files and otherwise requires `index.php` (mimicking the `.htaccess` rewrite). Set `base_url` to `http://127.0.0.1:8099` and `debug` to `true` in your local config.

## Credits

Markdown rendering by [Parsedown](https://github.com/erusev/parsedown) 1.7.4 (MIT), lightly patched for PHP 8.4 nullable-parameter deprecations.
