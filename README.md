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
| One-time import of all older videos (Google Takeout file, yt-dlp file or YouTube Data API) | Done |

## Layout

```
site/                  # contents go into public_html
├── index.php          # front controller for all public URLs
├── install.php        # one-time installer (delete after use)
├── .htaccess          # clean URLs
├── assets/            # CSS, JS
├── admin/             # admin panel (login, videos, pages, categories, settings, import, import-file)
├── cron/              # autoposter cron script (CLI only)
└── app/               # config, DB, helpers, queries, templates (web access denied)
```

## Setup

1. Upload the contents of `site/` to `public_html`. Make sure the hidden `.htaccess` files are included, including `app/.htaccess`.
2. In DirectAdmin, create a MariaDB database and user.
3. Copy `app/config.php` to `app/config.local.php` on the server. Enter your database details and real `base_url` (no trailing slash) in the local copy, which Git ignores. Set `db.port` if the database uses a custom TCP port. Existing settings in `app/config.php` still work. PHP needs `mbstring` and `pdo_mysql`. The autoposter needs DOM and either cURL or `allow_url_fopen`. API imports need cURL and ZIP uploads need `zip`.
4. For a fresh install, generate a secret with `php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'` and paste it into `install_token` in `app/config.local.php`. Open `/install.php` over HTTPS, enter the same token, and choose an admin username and password (at least 10 characters, up to 72 bytes with bcrypt).
5. Delete `install.php` and clear `install_token`. Existing installations can leave the token empty.

On existing installations, the first video write or import updates the YouTube ID columns to compare IDs case-sensitively. The database user needs `ALTER` on the three video tables for this upgrade. If a YouTube channel is configured and a file import was queued by an older version, cancel the remaining queue and upload the export again to verify its channel IDs. The importer skips videos already saved.

For a subdirectory deployment, merge the rules and sitemap URL from the blog’s generated `robots.txt` into the domain-root `/robots.txt`. Keep rules for any other applications on the host. Configure HSTS at the hosting level once the whole hostname is ready for HTTPS.

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

After the first scheduled interval, check *Ayarlar → Otomatik yayın durumu* for a run time and result. If it is still blank, check the DirectAdmin job and the PHP CLI path in its command.

**How it behaves**

- **First check imports nothing.** It only marks the videos already on your channel as seen, so your homepage isn't flooded with old videos. Only videos uploaded afterwards are added. Use **Son videoları içe aktar** (or `php cron/autopost.php --import-all`) to also import the latest ones (the feed holds at most 15).
- **No duplicates during routine checks.** The autoposter skips videos already on the site and remembers videos you delete. The explicit **Son videoları içe aktar** action can re-add deleted videos. Changing the channel ID starts a fresh baseline.
- **Imported posts** use the video's real upload date, `source = auto`, and the description as the body. The description stays plain text: hashtags, `-----` / `=====` separator lines, numbered or dashed lines and indentation are escaped so they don't turn into headings, lists or code blocks. Review them in the post list and add categories before publishing.
- **Shorts** can be included or skipped when YouTube identifies them as Shorts in the feed link. Ordinary watch links carry no format label.
- **Quiet cron:** the script prints output only when it adds videos or hits an error, so DirectAdmin doesn't email you every run. Use `-v` for a status line every time. The last result is also shown on the settings page.
- A feed error (wrong channel ID, YouTube unreachable) is reported but never changes existing posts.

### Importing all older videos (one time)

The channel feed only holds the latest 15 videos. To bring in the whole back catalogue (with real titles, descriptions and upload dates), use **Ayarlar → Tüm eski videoları içe aktar** (`/admin/import.php`). The page offers three methods and walks you through the one you pick. All three give the same result, and all of them need the real data: a bare list of video IDs is **not** accepted (it would only create empty posts).

**1. Google Takeout file (recommended; no key, no command)**

1. Open [takeout.google.com](https://takeout.google.com), deselect everything, tick *YouTube and YouTube Music*, and under *All YouTube data included* keep only **video metadata** (Turkish: *video meta verileri*). Do not tick *videos*, which would export the video files themselves.
2. Export once as `.zip`. Google emails a download link.
3. Upload the zip as it is (no need to unzip). For split exports, select all parts within the file-count limit shown on the form. If the export exceeds the limit, combine its metadata CSVs in a single ZIP. The `.csv` inside works too.

The reader works with localized Takeout headers (Turkish and English are recognised by name; any other language falls back to Takeout's fixed 28-column layout after checking the first row), UTF-8 / UTF-16 / Windows-1254 text, `,` or `;` separators, multi-line descriptions and the stray blank rows and leading spaces Takeout is known to produce. Other files in the zip (transcripts, recordings) are ignored.

If the selected archives contain more than 10,000 entries in total, upload the video metadata CSVs directly.

**2. yt-dlp output**

The import page shows the exact command for your channel. It needs [yt-dlp](https://github.com/yt-dlp/yt-dlp) on your own computer:

```
yt-dlp --skip-download --ignore-no-formats-error --no-warnings \
  --print-to-file "%(.{id,title,description,upload_date,timestamp,channel_id,availability,live_status})j" \
  videolar.jsonl "https://www.youtube.com/playlist?list=UU<your channel ID without the UC>"
```

Upload the resulting `videolar.jsonl`. `--print-to-file` is used on purpose: shell redirection (`>`) can produce UTF-16 or mangled Turkish on Windows. A full `--dump-json` / `-J` dump also works. A `--flat-playlist` run is rejected, because it has no dates or descriptions.

**3. YouTube Data API key**

1. Create a project at [Google Cloud Console](https://console.cloud.google.com/projectcreate) and enable **YouTube Data API v3** (APIs & Services → Library).
2. Credentials → Create credentials → **API key**. Restrict it to *YouTube Data API v3* only. Do **not** add an HTTP-referrer restriction (requests come from your server, not a browser).
3. Paste the key into the import page and choose draft (recommended) or publish. Private and deleted videos are never imported; *unlisted* videos are skipped unless you tick *Liste dışı videoları da ekle* (they would become public posts).

The key is held in the server-side PHP admin session while the import runs. You can delete it in Google Cloud afterwards. The free quota (10,000 units/day) is plenty: 50 videos cost 1 unit.

**How file imports behave**

- **Validated before anything is created.** The uploaded file is read and checked, then a preview shows how many videos will be added, how many are already on the site, the date range, the five newest titles with thumbnails, and everything that was skipped and why. Nothing touches the site until you press *İçe aktarmayı başlat*.
- **Strict about content.** The preview reports unreadable rows and unknown privacy values. When a channel is configured, only rows with a matching channel ID are staged. Rows with a missing or different ID are counted separately. Private and deleted videos are never imported. *Unlisted* videos are listed in the preview and only imported if you tick the box. Live and not-yet-published entries are skipped when identified by the export’s status or timestamp. yt-dlp rows require a title, description field, date and known availability. If YouTube omits availability for some videos, use Takeout for those entries.
- **Real dates.** Takeout's publish time (or yt-dlp's upload timestamp) is converted to Istanbul time and used as the post date.
- **Resumable.** Videos wait in a database table (`import_queue`) and are turned into posts in time-limited steps of about 20 seconds that continue automatically, so large channels work on shared hosting. If something fails or you close the tab, you do not need to upload the file again: open the import page from any admin session and continue. A video leaves the queue in the same transaction that saves its post.
- Everything imported is marked as seen for the autoposter. Unlike the API import, a file import does not mark the autoposter's baseline as complete, so videos uploaded after the file was created are not lost: use **Son videoları içe aktar** once (the completion message reminds you).

Notes for all methods: videos already on the site are skipped; run the import once, because a video you deleted on purpose will come back if it is still in the file or on the channel; imported posts are drafts unless you choose to publish them.

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

- Passwords are hashed with `password_hash`. Login is limited to 5 failed attempts per IP per 15 minutes (each attempt is recorded before the password is checked, so parallel requests can't get around the limit), and an unknown username takes as long to reject as a wrong password. Sessions end after 2 hours of inactivity, and changing the password logs out every other session. Every form (including logout and delete) needs a CSRF token. Database queries bind user-provided values. HTML, JSON and XML use their respective escaping functions.
- `install.php` refuses to run once an admin account exists, even if `app/installed.lock` is lost (for example after re-uploading `app/`). Still delete `install.php` after installing.
- Public pages never start a session (no cookie for visitors); only `?preview=1` links opened by a logged-in admin do.
- Markdown is rendered with Parsedown in safe mode, so raw HTML in post bodies is escaped.
- `app/` and `cron/` are denied by `.htaccess`. If DirectAdmin allows it, moving `app/` above `public_html` is even safer (update the `require` paths in `index.php` and `install.php`).
- Never commit real credentials. Keep `app/config.php` in the repo as placeholders only.

## Local testing

```
php tests/regression.php
php -S 127.0.0.1:8099 -t site tests/router.php
```

The regression tests do not need a database. For the development server, set `base_url` to `http://127.0.0.1:8099` and `debug` to `true` in your local config.

To test the autoposter without YouTube, add `'autopost_feed_url' => 'http://127.0.0.1:8098/feed.xml',` to `app/config.local.php` and serve a sample Atom feed there (same structure as YouTube's). The API import can be pointed at a fake API with `'youtube_api_base' => 'http://127.0.0.1:8098/youtube/v3'`, and both import methods can be made to process one step per request with `'import_time_budget' => 0`. File imports need no fake server: upload a Takeout zip or a yt-dlp `.jsonl` through the admin page. Remove these keys afterwards. (`php -S` caches `config.php` for about 2 seconds, so wait a moment after editing it.)

## Credits

Markdown rendering by [Parsedown](https://github.com/erusev/parsedown) 1.8.0 (MIT).
