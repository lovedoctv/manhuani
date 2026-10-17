# Series importer

The importer brings chapters and their pages into the site from **sources that gave you permission to publish them**. It updates `src/content/series/<series>.json` and saves pages in `public/chapters/<series>/<chapter>/`. The next build then shows those pages on the chapter pages.

```
npm run crawler -- --source <source id> --series <series slug>
```

It is separate from the [dashboard crawler](CRAWLER.md). The dashboard only collects release news and links, and it never copies pages.

## The rule it enforces

Pages are only imported from a source listed in `crawler/sources.json` with an **authorization record**:
- who gave permission (the creator or publisher);
- where the written proof is kept;
- the date;
- what it allows.

Without that record the source is refused before anything is fetched.

Sites that re-host other people's scans can't give permission, so the importer never fetches from them, whatever a source entry says. Today these are mangaread.org, comix.to, mangafire.to and manhuaus.com. The list is in `scripts/crawler/lib/sources.mjs`.

## Set up a source

Add an entry to `crawler/sources.json`. A source can supply one or more series.

### Files a creator or publisher gave you (`folder`)

```json
{
  "sources": [
    {
      "id": "studio-name",
      "name": "Studio Name",
      "adapter": "folder",
      "authorization": {
        "grantedBy": "Studio Name (the creator)",
        "evidence": "Email from studio@example.com, 2026-10-20, saved in Google Drive > Permissions",
        "date": "2026-10-20",
        "allows": ["publish-pages"]
      },
      "credit": { "name": "Studio Name", "url": "https://studio.example/" },
      "series": {
        "my-series": { "dir": "imports/my-series" }
      }
    }
  ]
}
```

Lay the folder out as one folder per chapter. Pages are read in file-name order, so `2.jpg` comes before `10.jpg`.

```
imports/my-series/
  series.json        optional: title, type, status, genres, synopsis… (only used for a new series)
  cover.webp         optional
  1/01.jpg 02.jpg …
  1/chapter.json     optional: { "title": "…", "date": "2026-10-20", "sourceUrl": "https://…" }
  2/…
  10.5/…             "10-5", "chapter-10" and "Chapter 10" also work
```

`dir` is relative to the repository folder, or an absolute path when you run it on your own computer.

### A feed or API from a publisher (`feed`)

```json
{
  "id": "publisher-name",
  "name": "Publisher Name",
  "adapter": "feed",
  "authorization": { "grantedBy": "…", "evidence": "…", "date": "2026-10-20", "allows": ["publish-pages"] },
  "credit": { "name": "Publisher Name", "url": "https://publisher.example/" },
  "headers": { "Authorization": "env:PUBLISHER_TOKEN" },
  "rateLimitMs": 1000,
  "series": {
    "my-series": { "url": "https://publisher.example/feeds/my-series.json" }
  }
}
```

The feed must use this layout. Links may be relative to the feed's address, and only `https` is allowed:

```json
{
  "series": { "title": "…", "type": "manhwa", "status": "ongoing", "genres": [], "releaseYear": 2026, "synopsis": "…" },
  "cover": "https://…/cover.webp",
  "chapters": [
    { "number": 1, "title": "…", "date": "2026-10-20", "url": "https://… (chapter on their site)",
      "version": "changes when the pages change",
      "pages": ["https://…/1/001.webp", "…"] },
    { "number": 2, "pagesUrl": "https://…/chapters/2.json" }
  ]
}
```

If a publisher's API uses a different layout, add an adapter for it (see the last section).

### Settings every source can have

| Setting | What it does | Default |
|---|---|---|
| `assets` | `"copy"` saves pages on your site and needs `publish-pages`. `"link"` shows them from the source's servers and needs `link-pages`. | `"copy"` |
| `credit` | Shown as the series' source once its pages come from here. | none |
| `headers` | Request headers. Write secrets as `"env:NAME"`; they are never saved or logged. | none |
| `rateLimitMs` | The shortest gap between two requests to the same site. | 1000 |
| `timeoutMs` | How long to wait for one response. | 20000 |
| `retries` | Retries after a timeout, 429 or 5xx response, with growing waits. | 3 |
| `maxPageBytes` | The largest page file accepted. | 20 MB |

## Run it

| Command | What it does |
|---|---|
| `npm run crawler -- --list` | Shows each source, its series and whether it can be used. |
| `npm run crawler -- --source S --series X --dry-run` | Fetches and checks everything and changes nothing. |
| `npm run crawler -- --source S --series X --preview` | Same as a dry run, and also prints the chapter records that would change. |
| `npm run crawler -- --source S --series X` | Imports. |
| `npm run crawler -- --all` | Imports every series of every source. |
| `… --chapters 1-5,8` | Imports only those chapters. |
| `… --force` | Fetches pages again even when nothing changed. |
| `… --update-metadata` | Lets the source replace chapter titles, dates and links you already have. |
| `… --json` | Prints the summary as JSON. |

The summary lists how many chapters were discovered, imported or updated, unchanged, had no pages, were skipped or failed, with the reason for each failure. A JSON Lines log of every step is saved in `crawler/logs/`.

The exit code is 0 when everything worked, 2 when some chapters failed, and 1 when nothing could run.

## What it does to your files

- **Pages** are checked by their content. Only JPEG, PNG, WebP, GIF and AVIF are accepted; SVG and anything else is refused. They are saved as `001.png`, `002.png`… in reading order.
- **Series details you already have are kept:** title, synopsis, chapter titles, dates and links. Only empty ones are filled in, unless you pass `--update-metadata`.
  - When pages come in, the series' `source` becomes the source's `credit`.
  - A cover is added if the series has none, or if its cover was hot-linked from a re-hosting site.
- **A new series** gets a new `src/content/series/<slug>.json`, built from the source's series details.
- **Safe to run again:**
  - Chapters whose pages haven't changed are skipped without downloading anything.
  - A chapter that fails keeps exactly what it had.
  - A source that is down changes nothing.
  - Each finished chapter is saved straight away, so an interrupted run carries on next time.
  - What was imported is recorded in `crawler/state/`. Keep that folder committed.
- **It never overwrites a page file it didn't create.** If one is in the way, that chapter fails and says so.

## Run it on GitHub (no Node.js needed on your computer or server)

1. Commit the source entry, and the page folder for a `folder` source.
2. On GitHub, open **Actions > Import series > Run workflow**. Enter the source and series, or leave both empty for all of them. Keep **Dry run** ticked the first time and read the summary.
3. Run it again with **Dry run** unticked. The workflow imports, builds `deploy/`, checks links and commits to `main`. It also runs once a day on its own.
4. Publish as usual: cPanel > Git Version Control > **Update from Remote**, then **Deploy HEAD Commit**.

If a source needs a token, add one repository secret named `CRAWLER_SECRETS` under Settings > Secrets and variables > Actions. Its value is a JSON object such as `{"PUBLISHER_TOKEN": "…"}`.

## Check the result

- `npm run build` then `npm run preview`, and open the chapter, for example `/manhuani/manga/my-series/chapter-1/`.
- `npm run test` runs the importer's tests. One of them imports test pages, builds the whole site and checks the chapter pages show those pages in order under `/manhuani/`. Set `SKIP_SITE_BUILD=1` to skip that one.

## Add another kind of source

Write an adapter in `scripts/crawler/adapters/` and add it to `adapters/index.mjs`. That file describes the four functions an adapter provides:
- `checkConfig`, which checks a source entry;
- `discover`, which returns the series and its chapters;
- `fetchChapter`, which returns a chapter's pages in order;
- optionally `cover`.

Everything else (permission checks, retries, page checks, saving and reruns) is shared.

## Guilty Axe

Guilty Axe (길티액스) is by 3 Star Jung and published by Naver Webtoon. MangaUpdates lists no English licence. No permitted source is set up for it, so the importer has nothing to import and its chapters keep linking out. To show its pages here, you need written permission from Naver Webtoon or the creator, plus the page files or a feed from them. Then add them as a source above.
