# Architecture, and how Phase 2 plugs in

## Phase 1: static site (now)

```
src/content/series/*.json ──┐
src/content/blog/*.md ──────┼──> Astro build ──> deploy/ ──> cPanel copies to aduna.fit/manhuani/
src/pages, components, css ─┘
```

- Every page is pre-built HTML (`/manga/<slug>/index.html`). Apache serves the files directly, so refreshing any page works, pages load fast, and search engines see full content.
- Content is plain data files with a fixed schema (`src/content.config.ts`). The build fails with a clear message if a file is invalid.
- Browser-only features (search, bookmarks, theme, relative dates) use `/data/search-index.json` and `localStorage`. They need no server.

## Phase 2: private crawler dashboard (later)

Goal: paste website URLs, collect permitted manhua metadata and chapter updates, and show them on the site without rebuilding it.

Planned pieces. **None of this is built yet.**

```
aduna.fit/manhuani/   (the site folder)
  admin/            Password-protected PHP dashboard (runs on the existing shared hosting)
  data/live/        JSON written by the dashboard. The build never creates this folder,
                    so deploying the site never overwrites it.
    series/<slug>.json
```

### Integration points already in place

1. **Same data shape.** The crawler will produce series and chapter records matching `src/content.config.ts`, including the `source` field (name and URL) for attribution.
2. **Live chapter updates.** `src/scripts/live.ts` loads `/data/live/series/<slug>.json` on series pages and adds any newer chapters, marked NEW and linking to their source. It's switched off until Phase 2: set `liveUpdates: true` in `site.config.mjs`.
3. **Promoting crawled data into the site.** The dashboard can export a series as a JSON file for `src/content/series/`. Committing it gives the series a full static page with SEO. This can later be automated through the GitHub API.
4. **Reserved URL.** `/admin/` is blocked in `robots.txt` and isn't used by the site.
5. **Hosting fit.** Namecheap shared hosting runs PHP and MySQL with cron jobs. The dashboard and scheduled crawling can run there, with no Node.js server.

### Rules the crawler will follow

- Only sources you have permission to use, honouring `robots.txt` and each site's terms.
- Metadata and chapter links only, never copied chapter images unless you hold the rights.
- Rate-limited, identified requests.
