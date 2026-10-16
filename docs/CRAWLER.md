# Phase 2: the private crawler dashboard

The dashboard lives at **https://aduna.fit/manhuani/admin/** and is protected by a password. You paste in the websites you follow. It checks whether each site allows automated access, finds the site's official feed, and collects new chapter releases. Those releases then appear on the home page under **Latest Updates**, and on matching series pages, without rebuilding the site.

It runs on your existing Namecheap hosting with PHP. You don't need Node.js or a separate server.

## What it does, and what it never does

**It collects only:** the manhua title, the chapter number, the release date (when the feed gives one), and the link to the chapter on the original site. Readers who click a chapter get a chapter page on your site that names the source. It shows the pages only if you uploaded them for a source that gave you permission (see [Reading chapters on your site](#reading-chapters-on-your-site)); otherwise it says the chapter isn't hosted here and offers a **Visit original source** button.

**It only uses authorized methods:**

- It reads `robots.txt` first and obeys it, including `Crawl-delay`. It identifies itself as `ManhuaniBot`.
- It only reads official feeds (RSS, Atom or JSON Feed) and five official APIs that are published for programs to use: MangaDex, MangaUpdates, AniList, Bangumi and Anime News Network. It never scrapes ordinary web pages.
- It reports when another site offers an API, but never uses one automatically.
- You have to tick a box confirming you're allowed to use the source before it can be switched on.

**It never:**

- bypasses CAPTCHAs, Cloudflare checks, logins or paywalls. A source that shows one is switched off.
- downloads chapter pages, scans or cover images. Pages only reach your site when you upload them yourself. Cards for new series get a plain coloured tile with the series' initials.

**Built-in limits and protection:**

- At most 40 requests and 10 sources per run. It waits at least 3 seconds between requests to the same site, and skips responses bigger than 2 MB.
- It asks sites "has anything changed?" before downloading the feed again.
- If a site says "slow down" (429 or 503), it waits at least an hour. After repeated errors it waits longer each time, up to a day. After 10 errors in a row it switches the source off.
- Each chapter is stored only once, even when two sources or two feed items list it.
- It refuses to fetch addresses on private or internal networks.
- Login is locked for 15 minutes after 5 wrong passwords. Every form is protected against cross-site requests, and you're logged out after 2 hours without activity.
- Your password hash, settings and database are stored **outside** the website folder, in `manhuani_private`, where nobody can download them. The only part of the dashboard a browser can open is the login page.

## Install it (one time)

### 1. Publish the new version

Do this after the pull request is merged on GitHub.

1. On GitHub, open the repo's **Actions** tab. Wait for the green tick on **Build site**.
2. In cPanel, open **Git Version Control**, then click **Manage** next to `manhuani`.
3. Open the **Pull or Deploy** tab and click **Update from Remote**.
4. Click **Deploy HEAD Commit**. If the button stays grey, refresh the page.

This copies the new `admin` folder into `aduna.fit/manhuani/`.

### 2. Check your PHP version

1. In cPanel, open **Select PHP Version**. On some accounts it's called **MultiPHP Manager**.
2. Choose **PHP 8.1** or newer (8.0 also works).
3. If you're on the **Extensions** tab, make sure these are ticked: `curl`, `mbstring`, `pdo`, `pdo_sqlite`, `simplexml`, `json`. They're usually on already.

If something is missing, the dashboard and the setup command will tell you which one.

### 3. Run the setup

1. On the cPanel home page, open **Terminal** (under Advanced).
2. Type this and press Enter:

   ```bash
   php -v
   ```

   It should say PHP 8.0 or newer. If it shows an older version, use the full path to a newer PHP instead of `php` in the commands below, for example `/opt/alt/php81/usr/bin/php`. Run `ls /opt/alt/` to see which versions your server has.

3. Run the setup:

   ```bash
   php ~/aduna.fit/manhuani/admin/cli.php setup
   ```

4. It asks two things:
   - **"Use a MySQL database instead of the built-in SQLite file?"** Press Enter for No. SQLite is a single file and needs no setup. See "Using MySQL instead" below if you'd rather use MySQL.
   - **"Choose a dashboard password"**: type a password of at least 10 characters, then type it again. Nothing appears on screen while you type. That's normal.

5. It finishes with `Installed.` Your settings and database are now in the `manhuani_private` folder in your home folder (`~/manhuani_private`), next to `aduna.fit`, not inside it.

`~` means your cPanel home folder. You don't need to type your username.

### 4. Log in

Open **https://aduna.fit/manhuani/admin/** and enter your password.

### 5. Set up the scheduled check (cron job)

This makes the crawler check your sources automatically.

1. In the Terminal, run:

   ```bash
   which php
   ```

   It prints the path to PHP, usually `/usr/local/bin/php`. Use what it prints in the next step. If you used a different PHP path in step 3, use that one.

2. In cPanel, open **Cron Jobs**.
3. Under **Add New Cron Job**, set **Common Settings** to **Twice Per Hour** (`0,30 * * * *`).
4. In **Command**, paste this, replacing `/usr/local/bin/php` with your path from step 1 if it's different:

   ```bash
   /usr/local/bin/php $HOME/aduna.fit/manhuani/admin/cli.php run >/dev/null 2>&1
   ```

5. Click **Add New Cron Job**.

Each source still waits for its own interval (60 minutes by default), so running the job every 30 minutes doesn't check sites more often than you set. The dashboard shows a warning if the job hasn't run for 3 hours.

To get an email when a run fails, leave out `>/dev/null 2>&1` and fill in **Cron Email** at the top of the page. You'll then get an email after every run, so most people leave it out.

## Use it

### Add a source

1. Go to **Sources** and click **+ Add source**.
2. Paste the site's address. The home page is fine: the dashboard looks for its feed. A direct feed address also works.
3. Give it a name, and choose how often to check it (at least 30 minutes).
4. Only tick **"I have checked this site's terms and I'm allowed to collect its chapter metadata"** if the site's terms allow it, or the owner said yes.
5. Click **Save**.

### Test it

Click **Test** on the source. You'll see a list of checks:

| Result | Meaning |
| --- | --- |
| ✓ robots.txt allows this address | Automated access is allowed. |
| ✕ robots.txt blocks this address | The site doesn't allow bots there. The source can't be used. |
| ✕ This source shows a CAPTCHA or anti-bot check | It won't be bypassed. The source can't be used. |
| ✓ MangaDex API ready | A MangaDex title with numbered chapters was found. |
| ✓ Authorized feed found | An official RSS, Atom or JSON feed with chapter releases was found. When a site has several feeds, the one with the most chapter releases is used. |
| ✕ none look like chapter releases | The feed only has blog posts or news. Paste the address of the site's chapter or series feed instead, if it has one. |
| ✕ No authorized feed was found | The site has no feed, so there's no permitted way to follow it automatically. |
| i Found an API | Shown for information only. It's never used automatically. |

The test also shows a few examples of the chapter releases it understood.

### Turn it on, edit or remove it

- **Enable** only works after a passing test and your permission tick. It's then checked on the next scheduled run.
- **Check now** checks it straight away.
- **Disable** stops checking it but keeps its updates.
- **Edit** changes the name, address, interval or notes. Changing the address switches the source off until you test it again.
- **Remove** deletes the source. Tick "Also delete its updates" to remove its chapters from the site too.

## Connectors: five official APIs, fully automatic

**Connectors** in the dashboard menu lists five built-in sources. Each is an official API published for programs to use. Once you enable one, the cron job runs it on its own schedule. Nobody has to search for anything. New manhwa and manhua titles appear in **New Titles** on the home page and at `/manhuani/new-titles/`, each with its own page at `/manhuani/title/?s=…`. New chapters appear in **Latest Updates**. Series from your own feed and MangaDex sources get a title page too, so a visitor clicking a series lands on your site first, sees the chapter list, and only then opens a chapter on the site that publishes it.

| Connector | What it gives your site | Reading links | Checks every |
| --- | --- | --- | --- |
| **MangaDex** | New titles and new English chapters of Korean and Chinese series | Yes: each chapter opens on mangadex.org, plus the official English publisher when MangaDex lists one | 60 min |
| **MangaUpdates** | Chapter releases announced by scanlation groups | No: these chapters open the series page on MangaUpdates. When MangaDex has the same chapter, its reading link replaces it. | 30 min |
| **AniList** | Genres, status, year, rating, cover, popularity and official reading sites (Webtoon, Tapas, Bilibili and others) for titles found by the others, plus 15 trending manhwa and 15 trending manhua a day | Official sites only | 60 min |
| **Bangumi** | Matches manhua by their Chinese name and links their bgm.tv page | No | 180 min |
| **Anime News Network** | Genres, year and the official website from the ANN Encyclopedia | Official website only | 180 min |

Only MangaDex gives chapter pages that can be read. MangaUpdates gives release information. AniList, Bangumi and ANN give series details. No source is used to copy chapter pages or cover images.

Covers come from AniList. The website shows them straight from AniList's servers: they are never downloaded or saved on your server. Titles AniList doesn't know, or whose cover fails to load, get a coloured tile with their initials. MangaDex covers aren't used because MangaDex doesn't allow other sites to show them directly.

Covers fill in over the first runs after an update. Each AniList run adds covers for up to 75 titles it already knows and looks up about 6 more titles by name, starting with the ones that have the newest chapters. To speed this up, open **Connectors** and click **Run now** on AniList a few times. Titles that AniList doesn't list keep the tile.

### Turn them on

1. Open **Connectors**.
2. Read each connector's **Terms** line. Follow the "API terms and docs" link for the full terms.
3. Click **Test**. It makes one small request and shows what came back.
4. Tick **I accept … terms** and click **Enable**.

The next scheduled check runs it. To run it straight away, click **Run now**.

**Terms you accept by enabling them:**

- **MangaDex:** no ads or paid services on your site (donations are fine). MangaDex and the scanlation groups are credited automatically. Remove updates if a group asks.
- **MangaUpdates:** MangaUpdates is credited automatically, requests are spaced out and answers are cached.
- **AniList:** free for non-commercial sites, or ones earning under $150 a month. It must not be used to store or mass-collect its data, so only small batches are fetched.
- **Bangumi:** requests identify the site (done automatically).
- **Anime News Network:** ANN is credited and each title links its encyclopedia entry (done automatically). At most 1 request a second, with answers cached for a week.

Your About page lists all five sources, and every title page credits the ones it used.

### How duplicates are avoided

Each title is stored once, even when several sources find it:

1. **Same ID on the same site.**
2. **Cross-site IDs.** MangaDex lists a title's AniList and MangaUpdates IDs.
3. **Same name.** This includes alternative titles and the names scanlation groups use.

Each chapter is stored once per title, chapter number and scanlation group, so when two groups release the same chapter, the title page lists both. A release that names no group counts as any group's. If a chapter from MangaUpdates (no reading link) later appears on MangaDex, the MangaDex link replaces it.

Feed sources from **Sources** use the same catalogue. A feed chapter for a series a connector already found is filed under that title.

### Health, limits and failures

- Each connector page shows its last run, last success, next run, the last error, and what the last run found.
- The dashboard shows a yellow warning while a connector is failing.
- A connector that fails is logged, retried later (60 min, then 2, 4, 8 hours, up to a day), and switched off after 10 failures in a row. The other connectors and feed sources still run.
- If an API says "slow down" (HTTP 429), the connector waits as long as it asks, at least 10 minutes.
- Network errors and server errors are retried once, 2 seconds later.
- Requests to one API are at least 3 seconds apart, and each connector makes at most 12 requests per run.
- Lookups (AniList, Bangumi, ANN and MangaUpdates series details) are cached in the database for 7 to 30 days.
- **Titles** in the menu lists every title found. **Hide** removes a title and its chapters from the website.

### Commands

| Command | What it does |
| --- | --- |
| `php ~/aduna.fit/manhuani/admin/cli.php connectors` | Shows each connector's health |
| `php ~/aduna.fit/manhuani/admin/cli.php connector-test mangadex` | Tests one connector (`mangadex`, `mangaupdates`, `anilist`, `bangumi`, `ann`) |
| `php ~/aduna.fit/manhuani/admin/cli.php connector-run mangadex` | Runs one enabled connector now |

The normal cron job (`cli.php run`) already runs every connector that's due. You don't need a new cron job.

### Sources that were checked and not used

| Source | Why not |
| --- | --- |
| WEBTOON (RSS feeds) | Its terms forbid "unauthorized automated means to compile information". Add a WEBTOON series feed under **Sources** only if WEBTOON gives you permission. |
| Tapas (RSS feeds) | Its robots.txt blocks `/rss/` for all bots. |
| Kitsu | Its robots.txt blocks `/api/`. |
| MyAnimeList API | It needs a Client ID that you register, and its licence only covers "internal business purposes" without written permission. |
| Jikan, Consumet, Comick and other scrapers | These aren't official APIs. They scrape other sites, so they aren't permitted. |

### Follow a series on MangaDex

MangaDex has an [official API](https://api.mangadex.org/docs/) that programs are allowed to use. Each MangaDex source follows one series.

1. Open the series on mangadex.org and copy the address from your browser. It looks like `https://mangadex.org/title/a1c7c817-…/solo-leveling`.
2. In **Sources**, click **+ Add source** and paste it. The name can stay empty: it becomes "MangaDex".
3. Tick the permission box (read the rules below first), save, then click **Test** and **Enable**.

The test checks the title exists and has numbered chapters in English. Chapters without a number, such as oneshots, are skipped.

**MangaDex's rules, which you accept by ticking the box:**

- **Your site must not run ads or paid services** while it uses their API. Donations are fine. If you add ads to aduna.fit/manhuani later, remove your MangaDex sources first.
- **Credit MangaDex and the scanlation group.** This happens automatically: cards show "via MangaDex · Group name", and every chapter links to its page on mangadex.org.
- **Remove updates if a group asks.** Use **Hide** or **Delete** on the Updates page.

**Rate limits:** MangaDex allows about 5 requests a second. Each check makes one request, at least 3 seconds after the last one, so the dashboard stays far below that. If MangaDex answers "slow down" (HTTP 429), the source waits at least an hour, or longer if MangaDex says so, up to a day.

To follow another language, add `'mangadex_language' => 'fr',` (or another language code) to `config.php`.

### Look up series info on AniList

**Series info** in the menu searches [AniList](https://anilist.co)'s official API for a series' title, other titles, type (manhua, manhwa or manga), genres, status, year, author, artist, rating and synopsis. It shows the result as a ready-made series file for your site:

1. Search for the series and find the right result.
2. On GitHub, open `src/content/series`, click **Add file → Create new file**, and name it as shown (for example `solo-leveling.json`).
3. Paste the text. Add chapters if you have them, and rewrite the synopsis in your own words if it was quoted from a publisher (it often says "Source: …").
4. Commit, then deploy as usual. The series page shows "Source: AniList" with a link.

**AniList's rules:** it is free for non-commercial sites (or ones earning under $150 a month), and it must not be used to store or mass-collect its data. So searches only happen when you click **Search**, nothing is saved, and covers are never copied. AniList allows 90 requests a minute; if it says "slow down", the page asks you to wait.

### Updates and logs

- **Updates** lists every chapter found. **Hide** removes one from the website without deleting it. **Delete** removes it completely.
- **Logs** shows what each run did, with errors in red. Logs older than 30 days are removed automatically.
- **Dashboard** shows totals, the last run and the newest updates. **Check all enabled sources now** runs everything immediately.

### How updates appear on the site

After each run the dashboard writes two kinds of small files into `aduna.fit/manhuani/data/live/`:

- `latest.json` feeds the **Latest Updates** section on the home page. New chapters move their series to the front, marked **NEW**. Clicking a new chapter opens its chapter page on your site.
- `series/<series>.json` adds NEW chapters to the chapter list of series that already have a page on your site. They're matched by name: "Dungeon Chef" in a feed matches `/manga/dungeon-chef/`.

Deploying the site never overwrites these files.

### What visitors see

- **Home page:** **Most Recent Popular** is a row of the most followed titles (by AniList popularity) that had a chapter in the last 30 days, numbered #1, #2 and so on. **New Comics** is a row of the newest titles. Both scroll sideways with the ‹ › buttons, or by swiping on a phone. Until at least 4 titles have chapters, the built **Popular Series** row shows instead.
- **Cards** show the cover, the latest chapter with how long ago it came out ("Ch.49 · 1d ago"), and a **+** button that bookmarks the series.
- **Title pages** list every release with its group, date and a bookmark icon. Visitors can search by chapter number, pick one group in **All groups**, and sort by chapter or date. Chapters they've opened are dimmed, and **Continue** takes them back to the last one.
- **Bookmarks** (`/manhuani/bookmarks/`) lists bookmarked series and chapters. Nobody signs up: bookmarks and reading history are saved in the visitor's browser, on that device only. They aren't tied to an IP address, because many people share one (mobile networks, offices) and most people's address changes often.

### Reading chapters on your site

Every crawled chapter has its own page at `/manhuani/chapter/?s=<series>&c=<number>`. The title page, Latest Updates and series pages all link there. The chapter page shows the release date, **Source: (name)**, previous/next buttons and a chapter list.

Visitors can only read the chapter on your site when you have **permission to host it**. Without it, the page says "This chapter isn't hosted on Aduna.fit. You can read it on (source)." and shows a **Visit original source** button.

To host a source's chapters:

1. Get written permission from the publisher or rights holder, for example by email. Keep it.
2. In the dashboard open **Sources**, click **Edit** on that source, tick **Hosting permission**, and write where the permission came from. Save.
3. Open **Updates**. Chapters from that source now have a **Pages** link. Click it, choose the page images (JPG, PNG, WEBP or GIF), and upload. They're added in file-name order, so name them `001.jpg`, `002.jpg`, and so on. Upload in several batches if your server limits how many files go up at once.
4. The pages appear on the chapter page right away, with a **Visit original source** button above them.

Good to know:

- The crawler never downloads pages itself. Only upload pages the publisher gave you permission to host, not pages saved from their site without it.
- Your uploads are saved in `aduna.fit/manhuani/data/media/` with random names, like Library images. The website lists them only while the source has hosting permission and the chapter and its title are shown. If you untick the box or hide the chapter or title, readers no longer see them. **Delete all pages** removes the files.
- Settings: `max_pages_per_chapter` (default 300) and `max_page_bytes` (default 10 MB).

## Library: your own titles and chapters

Open **Library** in the dashboard to publish comics you own or have the rights to publish. Readers read them on your site, with no outside links.

### Add a title and its chapters

1. Click **Add title**. Fill in the title, other names, type, status, author, artist, year, genres (comma separated) and description. Choose a cover image (JPG, PNG, WEBP or GIF, up to 5 MB; a tall 2:3 image looks best). Leave **On the website** on **Draft** for now and click **Add title**.
2. Under **Chapters**, type the chapter number (1, 12 or 12.5) and an optional chapter title, then click **Add chapter**. New chapters start as drafts.
3. On the chapter screen, click **Choose Files**, select all the page images at once and click **Upload**. They're added in file-name order, so name them `001.jpg`, `002.jpg`, and so on.
4. Check the order under **Pages in reading order**. Use ↑ and ↓, or type new numbers in the boxes and click **Save order** (2.5 puts a page between 2 and 3). **Replace** swaps one image; tick pages and click **Delete ticked pages** to remove them.
5. Click **Preview** to read the chapter top to bottom as readers will.
6. Click **Publish chapter**. A chapter needs at least one page.
7. When the title is ready, open it, set **On the website** to **Published** and click **Save title**.

Readers see a chapter only when both the chapter and its title are published. You can edit everything later; the website updates as soon as you save.

### Where it shows on the website

- **Home page:** "Read on Manhuani" lists your published titles with their cover and latest chapter, with a search box and **Load more** (12 at a time). New chapters also appear in Latest Updates.
- **Title page:** `/manhuani/title/?s=<title>` shows the cover, details, synopsis, **Read First Chapter**, the latest chapter, and every published chapter, newest first.
- **Chapter page:** `/manhuani/chapter/?s=<title>&c=<number>` shows the pages in order, scrolling down, with Prev, Next, a chapter list and **Back to (title)**. The address can be shared and reloaded.
- **Drafts never show:** draft titles, draft chapters and deleted chapters are left out of the website's files. Their addresses say "not found".

### Storage and limits

- Covers and pages are saved in `aduna.fit/manhuani/data/media/` with random names such as `3f9c0a…e1.jpg`. Deploying a new version never touches that folder. Back it up together with `manhuani_private/` (the database).
- Each upload is checked: it must be a real JPG, PNG, WEBP or GIF, under `max_page_bytes` (10 MB, pages) or `max_cover_bytes` (5 MB, covers). Anything else is refused. Only you, logged in, can upload, edit, publish or delete.
- Your server also limits uploads. The chapter screen shows the limits: how many files go up at once (`max_file_uploads`) and how much per upload (`post_max_size`). If an upload is too big, the dashboard says so; upload fewer pages at a time. To raise them, open cPanel → **Select PHP Version** → **Options** and set `upload_max_filesize` (for example 10M), `post_max_size` (for example 64M) and `max_file_uploads` (for example 50).
- Page images use your hosting disk space. Check **Disk Usage** in cPanel now and then.
- Draft images are in the same folder. Nothing on the site links to them and their names can't be guessed, but don't upload anything you'd never want public.

## Command reference

Run these in cPanel Terminal. Replace `php` with your full PHP path if `php -v` showed an old version.

| Command | What it does |
| --- | --- |
| `php ~/aduna.fit/manhuani/admin/cli.php status` | Shows source and update totals, and the last run |
| `php ~/aduna.fit/manhuani/admin/cli.php run` | Checks every source that's due now (what the cron job runs) |
| `php ~/aduna.fit/manhuani/admin/cli.php run --all` | Checks every enabled source, even if it isn't due |
| `php ~/aduna.fit/manhuani/admin/cli.php test 3` | Tests source number 3 |
| `php ~/aduna.fit/manhuani/admin/cli.php publish` | Rewrites the website's update files |
| `php ~/aduna.fit/manhuani/admin/cli.php password` | Changes the dashboard password |
| `php ~/aduna.fit/manhuani/admin/cli.php setup` | First install. Updates upgrade the database by themselves when you next open the dashboard. |

## Settings

Settings live in `~/manhuani_private/config.php`. You don't need to change any of them. To open the file, go to cPanel **File Manager**, open your home folder, then `manhuani_private`.

| Setting | Default | Meaning |
| --- | --- | --- |
| `default_interval_minutes` | 60 | Check interval for new sources |
| `min_interval_minutes` | 30 | Shortest interval allowed |
| `min_delay_seconds` | 3 | Shortest wait between requests to one site |
| `max_requests_per_run` | 40 | Request limit for each run |
| `max_sources_per_run` | 10 | Most sources checked in one run |
| `published_updates` | 60 | How many recent updates the website receives |
| `disable_after_failures` | 10 | Errors in a row before a source is switched off |
| `log_retention_days` | 30 | How long logs are kept |
| `mangadex_language` | en | Which translation to follow on MangaDex |
| `discover_types` | `['manhua', 'manhwa']` | Which titles the connectors add. Add `'manga'` to include Japanese manga. |
| `mangadex_original_languages` | `['ko', 'zh', 'zh-hk']` | Original languages MangaDex discovery follows |
| `connector_intervals` | `[]` | Custom check intervals, e.g. `['mangadex' => 30]` (minutes) |
| `connector_max_requests` | 12 | Request limit per connector per run |
| `enrich_per_run` | 4 | Titles looked up per run on Bangumi and ANN. AniList looks up at least 6, as many as its request limit allows |
| `titles_published` | 300 | How many titles the website lists |
| `max_page_bytes` | 10485760 | Largest page image you can upload (10 MB) |
| `max_cover_bytes` | 5242880 | Largest cover image you can upload (5 MB) |

Never upload `config.php` or the database to GitHub, and never move the `manhuani_private` folder inside `aduna.fit`.

## Using MySQL instead (optional)

SQLite works well for this amount of data. If you prefer MySQL:

1. In cPanel, open **Manage My Databases**, also called **MySQL Databases**.
2. Create a database, for example `manhuani`. cPanel adds your account prefix, giving a name like `yourprefix_manhuani`.
3. Create a user with a strong password, and add it to the database with **ALL PRIVILEGES**.
4. Run the setup and answer **y** to MySQL. Enter the full database name, the full user name, the password, and `localhost` as the host. The names are shown on the MySQL Databases page.

## Troubleshooting

| Problem | Fix |
| --- | --- |
| "Dashboard not installed yet" | Run the setup in step 3. |
| "This server is missing something" | Turn on the listed PHP version or extension in **Select PHP Version**. |
| "Too many failed attempts" | Wait 15 minutes. If you forgot the password, run the `password` command. |
| Setup says "command not found" or shows a syntax error | Your Terminal's `php` is too old. Use a full path such as `/opt/alt/php81/usr/bin/php`. |
| The dashboard says the scheduled check hasn't run | Check the cron job's PHP path and command. Run the same command in Terminal, without `>/dev/null 2>&1`, to see any error. |
| A test says robots.txt blocks it, or it shows a CAPTCHA | That site doesn't allow automated access. Contact the site owner, or follow it by hand. |
| "No authorized feed was found" | Look for an RSS icon or a "Feed" link on the site and paste that address instead. If there's none, the site can't be followed automatically. |
| Updates don't show on the site | Click **Refresh website now** on the Updates page, then hard-refresh the site (Ctrl+F5). |
| A MangaDex test says "no numbered chapters" | MangaDex has no chapters in that language for the title. Some titles only link to official publishers. |
| "MangaDex asked us to slow down" | Nothing to do. The source waits and tries again later. |
| The dashboard warned that `admin/lib/pages.php` was missing | That file no longer exists: since version 1.4.1 page uploads are part of `library.php`, which your server has kept so far. Deploy the latest version and the warning goes away. If you have an old `aduna.fit/manhuani/data/pages/` folder, you can delete it. |
| The dashboard says `admin/lib/library.php` is missing | Your host's virus scanner (Imunify360 or ImunifyAV in cPanel) may have removed it. Look for `library.php` in Imunify's detected files, mark it as a false positive, then click **Deploy HEAD Commit** again. Until then the Library and page uploads are switched off and your own titles are not listed on the site; nothing is deleted. |
| A source keeps failing | Open **Logs** to see why. After 10 errors in a row it switches itself off. Fix it, test it, then enable it again. |

Check that the private parts are blocked: https://aduna.fit/manhuani/admin/cli.php should show **403 Forbidden**.
