# Architecture

## Phase 1: static site

```
src/content/series/*.json ──┐
src/content/blog/*.md ──────┼──> Astro build ──> deploy/ ──> cPanel copies to aduna.fit/manhuani/
src/pages, components, css ─┘
```

- Every page is pre-built HTML (`/manga/<slug>/index.html`). Apache serves the files directly, so refreshing any page works, pages load fast, and search engines see full content.
- Content is plain data files with a fixed schema (`src/content.config.ts`). The build fails with a clear message if a file is invalid.
- Browser-only features (search, bookmarks, theme, relative dates) use `/data/search-index.json` and `localStorage`. They need no server.

## Phase 2: private crawler dashboard

The dashboard is plain PHP in `public/admin/`, so the build copies it to `deploy/admin/` unchanged. It runs on the same shared hosting.

```
~/manhuani_private/          Outside the website folder (never downloadable, never deployed over)
  config.php                 Password hash, database choice, limits
  crawler.sqlite             Sources, updates, logs (or a MySQL database instead)

~/aduna.fit/manhuani/        The website folder
  admin/index.php            Dashboard (login, sources, updates, logs)
  admin/cli.php              Command line: setup, cron "run", test, publish, password
  admin/lib/*.php            Crawler code (blocked from the web); lib/apis.php holds MangaDex and AniList
  data/live/latest.json      Written by the crawler for the home page
  data/live/series/<slug>.json   Written by the crawler for series pages
```

### How a run works

1. **Cron** runs `cli.php run`. A lock file stops two runs overlapping.
2. Each due, enabled source is checked:
   - `robots.txt` is read (cached for 12 hours) and must allow the feed URL for `ManhuaniBot`, or `*`. A `Crawl-delay` is respected.
   - The feed is fetched with a conditional GET (ETag and Last-Modified), so unchanged feeds cost almost nothing.
   - CAPTCHA, Cloudflare challenge, login and paywall responses switch the source off.
   - A MangaDex source skips robots.txt (it is an official API, governed by its own terms) and makes one request to `/manga/{id}/feed`, newest chapters first, with scanlation group names. Its updates store the group in `updates.credit` and link to `https://mangadex.org/chapter/{id}`. See `lib/apis.php`.
3. Each feed item title is parsed into series, chapter number and optional chapter title (`lib/extract.php`). Items without a chapter number or an http(s) link are skipped.
4. Updates are inserted with a unique `dedupe_key` of series slug plus chapter number, so duplicates are ignored.
5. `lib/publish.php` writes `data/live/*.json` atomically.

### How the site shows updates

`src/scripts/live.ts` runs in the browser when `liveUpdates` is `true` in `site.config.mjs`:

- On the home page's first page, it reads `latest.json` and puts the newest series at the front of **Latest Updates**. Series already on the site keep their card and cover. Other series get a coloured tile with their initials. No images are copied.
- On a series page, it reads `series/<slug>.json` and adds newer chapters marked NEW, linking to the original source.

If the files don't exist, for example before the crawler is installed, nothing changes. The build never creates `data/live/`, so deploying never overwrites it.

Series are matched to site pages by slug. A feed title of "Dungeon Chef" becomes `dungeon-chef`, which matches `/manga/dungeon-chef/`. The list of site series comes from `data/search-index.json`.

### Database upgrades

`meta.schema_version` records the database version. When the code is newer (`DB_SCHEMA_VERSION` in `lib/db.php`), the dashboard and the CLI run `db_migrate()` once, which adds missing tables and columns. Version 2 added `updates.credit`.

### Security

- The password is stored as a bcrypt hash. The session cookie is HttpOnly, SameSite=Strict, and Secure on https. Sessions expire after 2 hours idle.
- Login is limited to 5 failures per 15 minutes per IP. Every POST carries a CSRF token. All output is escaped.
- Responses send a strict Content-Security-Policy (no scripts), `noindex` and `no-store` headers.
- `admin/.htaccess` only allows `index.php` and `admin.css`. `lib/` has its own deny rule, and each library file exits if it's opened directly. `cli.php` refuses to run from the web.
- Outgoing requests use http(s) on ports 80 and 443 only, reject private and internal IPs, pin the resolved IP, and re-check every redirect. Responses are capped at 2 MB.

### Ideas for later

- Export a crawled series as a JSON file for `src/content/series/`, giving it a full static page with SEO. This could be automated through the GitHub API.
- Other official APIs can be added like MangaDex in `lib/apis.php`: a test function, a crawl function, and a `method` name.
- The AniList lookup (dashboard → Series info) produces series files by hand. It could commit them through the GitHub API.
