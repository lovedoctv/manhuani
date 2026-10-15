# Phase 2: the private crawler dashboard

The dashboard lives at **https://aduna.fit/manhuani/admin/** and is protected by a password. You paste in the websites you follow. It checks whether each site allows automated access, finds the site's official feed, and collects new chapter releases. Those releases then appear on the home page under **Latest Updates**, and on matching series pages, without rebuilding the site.

It runs on your existing Namecheap hosting with PHP. You don't need Node.js or a separate server.

## What it does, and what it never does

**It collects only:** the manhua title, the chapter number, the release date (when the feed gives one), and the link to the chapter on the original site. Readers who click a chapter go to the original site to read it.

**It only uses authorized methods:**

- It reads `robots.txt` first and obeys it, including `Crawl-delay`. It identifies itself as `ManhuaniBot`.
- It only reads official feeds: RSS, Atom or JSON Feed. It never scrapes ordinary web pages.
- It reports when a site offers an API, but never uses one automatically. Use an API only if the site's terms allow it.
- You have to tick a box confirming you're allowed to use the source before it can be switched on.

**It never:**

- bypasses CAPTCHAs, Cloudflare checks, logins or paywalls. A source that shows one is switched off.
- downloads chapter pages, scans or cover images. Cards for new series get a plain coloured tile with the series' initials.

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

### Updates and logs

- **Updates** lists every chapter found. **Hide** removes one from the website without deleting it. **Delete** removes it completely.
- **Logs** shows what each run did, with errors in red. Logs older than 30 days are removed automatically.
- **Dashboard** shows totals, the last run and the newest updates. **Check all enabled sources now** runs everything immediately.

### How updates appear on the site

After each run the dashboard writes two kinds of small files into `aduna.fit/manhuani/data/live/`:

- `latest.json` feeds the **Latest Updates** section on the home page. New chapters move their series to the front, marked **NEW**. Clicking a new chapter opens it on the original site.
- `series/<series>.json` adds NEW chapters to the chapter list of series that already have a page on your site. They're matched by name: "Dungeon Chef" in a feed matches `/manga/dungeon-chef/`.

Deploying the site never overwrites these files.

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
| `php ~/aduna.fit/manhuani/admin/cli.php setup` | First install. Run again after an update to upgrade the database. |

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
| A source keeps failing | Open **Logs** to see why. After 10 errors in a row it switches itself off. Fix it, test it, then enable it again. |

Check that the private parts are blocked: https://aduna.fit/manhuani/admin/cli.php should show **403 Forbidden**.
