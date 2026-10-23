# AbroadPath

Live at **https://aduna.fit/AbroadPath/**. Scholarships, study abroad and work visa guides for
Nigeria, South Africa, Kenya, Ghana, Zimbabwe and Zambia. Old https://aduna.fit/manhuani/ links
redirect there.

It's a fast **static site** built with [Astro](https://astro.build), so it runs on standard shared
hosting (Namecheap with cPanel) without a Node.js server. A small PHP admin at
**https://aduna.fit/AbroadPath/admin/** lets you write, publish and delete posts from the browser.

- Home page with "Start here", Latest posts and "Closing soon" deadlines
- Category, country and deadline pages, search and an RSS feed
- Ad slots (Adsterra or Monetag), CPA offer boxes and the Meta and TikTok pixels
- SEO: titles and descriptions, canonical links, Open Graph tags, JSON-LD, `sitemap.xml` and `robots.txt`

## Folder layout

```
blog/site.config.mjs        Ad codes, CPA offers, pixel IDs, social links, countries, categories
blog/src/content/posts/     Posts kept in the code, one Markdown file each
blog/src/                   Page templates, components, styles and browser scripts
blog/public/                Files copied as they are (.htaccess, sw.js, favicon, social image)
blog/public/admin/          The blog admin (PHP, runs on your hosting)
blog/public/post.php        Shows the posts written in the admin
blog/scripts/               Build, preview and link-check helpers
blog/test/                  Tests for the admin
deploy/                     The finished website: the only folder that goes online
.cpanel.yml                 Tells cPanel to copy deploy/ into aduna.fit/AbroadPath
docs/                       Guides: the blog and its admin, and deployment
```

## Run it on your computer

You need [Node.js](https://nodejs.org) 22 or newer. `npm test` also needs PHP 8 with SQLite.

```bash
npm install          # once, installs the build tools
npm run dev          # live-editing server at http://localhost:4321/AbroadPath/
npm run build        # builds the site into deploy/
npm run check        # checks every internal link in deploy/
npm run preview      # serves deploy/ like your hosting will
npm test             # runs the admin tests on a local PHP server
```

## Write and publish

- **[docs/BLOG.md](docs/BLOG.md)**: the admin, posts, ad codes, offers and pixels.
- **[docs/DEPLOYMENT.md](docs/DEPLOYMENT.md)**: putting a change online with cPanel.

Posts written in the admin go live as soon as you publish them. Changes to the code are rebuilt into
`deploy/` by GitHub automatically, then in cPanel you click **Update from Remote** and
**Deploy HEAD Commit**.
