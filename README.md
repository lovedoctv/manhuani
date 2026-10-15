# Manhuani

A fast, responsive manhua and manhwa blog. It's built as a **static site**, so it runs on any standard shared hosting (like Namecheap with cPanel) without a Node.js server.

- Home page with Popular Series, Latest Updates (paginated) and a sidebar with search, Hot Series, genres and years
- Series pages with cover, info, genres, chapter list, bookmarks and related series
- Chapter reader pages with previous/next navigation and a chapter dropdown
- Genre, release-year and A–Z archive pages
- Blog with Markdown posts and an RSS feed
- Search, bookmarks and light/dark theme, all handled in the browser
- SEO: page titles and descriptions, canonical links, Open Graph tags, JSON-LD structured data, `sitemap.xml` and `robots.txt`

Built with [Astro](https://astro.build) (which uses Vite under the hood).

## Folder layout

```
site.config.mjs        Site name, domain, genres and other settings  ← edit this first
src/content/series/    One JSON file per series (metadata + chapters)
src/content/blog/      Blog posts as Markdown files
src/pages/             Page templates (URLs mirror this folder)
src/components/        Reusable parts (cards, sidebar, header...)
src/styles/global.css  All styling and colours
public/                Files copied as-is (favicon, .htaccess, social image, your covers)
scripts/               Build, preview and link-check helpers
deploy/                The finished website: the only folder that goes online
.cpanel.yml            Tells cPanel to copy deploy/ into your public_html
docs/                  Guides: deployment, adding content, Phase 2 plan
```

## Run it on your computer

You need [Node.js](https://nodejs.org) 22 or newer.

```bash
npm install          # once, installs the build tools
npm run dev          # live-editing server at http://localhost:4321
```

To test the exact files that will go online:

```bash
npm run build        # builds the site into deploy/
npm run check        # checks every internal link in deploy/
npm run preview      # serves deploy/ at http://localhost:4321, like your hosting will
```

## Publish it

See **[docs/DEPLOYMENT.md](docs/DEPLOYMENT.md)** for beginner-friendly, step-by-step instructions.

In short: changes pushed to GitHub are rebuilt into `deploy/` automatically, then in cPanel you click **Update from Remote** and **Deploy HEAD Commit**.

## Add series, chapters and posts

See **[docs/CONTENT.md](docs/CONTENT.md)**.

## Phase 2 (crawler dashboard)

See **[docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)** for how the private crawler dashboard will plug into this site without a rebuild.
