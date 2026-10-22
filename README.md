# Manhuani

> **Now AbroadPath.** Since October 2026, the site is a scholarships, study abroad and
> work visa blog for Nigeria, South Africa, Kenya, Ghana, Zimbabwe and Zambia, at https://aduna.fit/AbroadPath/
> (old /manhuani/ links redirect there). Its code is in `blog/`, and
> `npm run dev`, `npm run build` and `npm run check` now work on it. Ad codes, offers and pixels go in
> `blog/site.config.mjs`; see [docs/BLOG.md](docs/BLOG.md). The manhwa site below is kept as it was and
> still builds with `npm run dev:manhwa`, `npm run build:manhwa` and `npm run check:manhwa`.

Live at **https://aduna.fit/manhuani/**. A fast, responsive manhua and manhwa blog. It's built as a **static site**, so it runs on any standard shared hosting (like Namecheap with cPanel) without a Node.js server.

- Home page with Popular Series, Latest Updates (paginated) and a sidebar with search, Hot Series, genres and years
- Series pages with cover, info, genres, chapter list, bookmarks and related series
- Chapter reader pages with previous/next navigation and a chapter dropdown
- Genre, release-year and A–Z archive pages
- Blog with Markdown posts and an RSS feed
- Search, bookmarks and light/dark theme, all handled in the browser
- SEO: page titles and descriptions, canonical links, Open Graph tags, JSON-LD structured data, `sitemap.xml` and `robots.txt`
- A private crawler dashboard at `/manhuani/admin/` (PHP). It follows official feeds of sites you have permission to use, and series on MangaDex through its official API, and shows new chapters in Latest Updates without a rebuild. Five official APIs (MangaDex, MangaUpdates, AniList, Bangumi, Anime News Network) add new titles and chapters automatically.

Built with [Astro](https://astro.build) (which uses Vite under the hood).

## Folder layout

```
site.config.mjs        Site name, domain, folder (base), genres and other settings
src/content/series/    One JSON file per series (metadata + chapters)
src/content/blog/      Blog posts as Markdown files
src/pages/             Page templates (URLs mirror this folder)
src/components/        Reusable parts (cards, sidebar, header...)
src/styles/global.css  All styling and colours
public/                Files copied as-is (favicon, .htaccess, social image, your covers)
public/admin/          The private crawler dashboard (PHP, runs on your hosting)
scripts/               Build, preview and link-check helpers
deploy/                The finished website: the only folder that goes online
.cpanel.yml            Tells cPanel to copy deploy/ into aduna.fit/manhuani
docs/                  Guides: deployment, adding content, crawler, architecture
```

## Run it on your computer

You need [Node.js](https://nodejs.org) 22 or newer.

```bash
npm install          # once, installs the build tools
npm run dev          # live-editing server at http://localhost:4321/manhuani/
```

To test the exact files that will go online:

```bash
npm run build        # builds the site into deploy/
npm run check        # checks every internal link in deploy/
npm run preview      # serves deploy/ at http://localhost:4321/manhuani/, like your hosting will
```

## Publish it

See **[docs/DEPLOYMENT.md](docs/DEPLOYMENT.md)** for beginner-friendly, step-by-step instructions.

In short: changes pushed to GitHub are rebuilt into `deploy/` automatically, then in cPanel you click **Update from Remote** and **Deploy HEAD Commit**.

## Add series, chapters and posts

See **[docs/CONTENT.md](docs/CONTENT.md)**.

## Crawler dashboard (Phase 2)

See **[docs/CRAWLER.md](docs/CRAWLER.md)** to install it, add sources and set up the cron job. **[docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)** explains how it fits with the static site.
