# Adding content

All content lives in `src/content/`. After any change, the site rebuilds when it's pushed to `main` (see [DEPLOYMENT.md](DEPLOYMENT.md)).

## Add a series

Create `src/content/series/<series-slug>.json`. The file name becomes the URL: `/manga/<series-slug>/`.

```json
{
  "title": "My Series Title",
  "altTitles": ["Original Title"],
  "type": "manhua",
  "genres": ["action", "cultivation"],
  "author": "Author Name",
  "artist": "Artist Name",
  "status": "ongoing",
  "releaseYear": 2026,
  "rating": 4.6,
  "featured": false,
  "cover": "/covers/my-series-title.webp",
  "synopsis": "One or two sentences about the story.",
  "chapters": [
    { "number": 2, "date": "2026-10-15T12:00:00Z" },
    { "number": 1, "date": "2026-10-08T12:00:00Z", "title": "The Beginning" }
  ]
}
```

| Field | Notes |
| --- | --- |
| `type` | `manhua`, `manhwa` or `manga` |
| `genres` | Genre slugs. The menu genres are set in `site.config.mjs`. New ones get their own page automatically. |
| `status` | `ongoing`, `completed`, `hiatus` or `cancelled` |
| `featured` | `true` puts it at the front of Popular Series |
| `cover` | Optional. Put the image in `public/covers/` and reference it as `/covers/file.webp`. Without one, a coloured placeholder cover is generated. Use a 350×476 image (WebP or JPG) to keep the site fast. |
| `rating` | Optional, 0 to 5 |

## Add a chapter

Add an entry at the top of the series' `chapters` list:

```json
{ "number": 25, "date": "2026-10-20T12:00:00Z" }
```

Optional chapter fields:

- `"title": "Chapter name"`
- `"images": ["/chapters/my-series/25/01.webp", "..."]` for pages you have the right to host. Put the files in `public/chapters/...`.
- `"sourceUrl": "https://official-site.example/..."` shows a "Read at the source" button instead.

## Add a blog post

Create `src/content/blog/<post-slug>.md`:

```markdown
---
title: My Post Title
description: One sentence shown in search results and link previews.
date: 2026-10-20
tags: [review, romance]
---

Write the post here in Markdown.
```

Link to other pages with site paths like `[Dungeon Chef](/manga/dungeon-chef/)`. The `/manhuani` folder is added automatically.

## Change the look

- Colours are at the top of `src/styles/global.css` (`--accent`, `--bg`, and so on).
- Site name, tagline, description, social links and genres are in `site.config.mjs`.
- Discord and Instagram buttons appear on the home page once you fill in their links in `site.config.mjs`.
- Replace `public/og-image.png` (1200×630) to change the image shown when links are shared.

## The sample content

The 16 series and 2 blog posts included are made-up examples to show the layout. Delete them from `src/content/` once you add your own.
