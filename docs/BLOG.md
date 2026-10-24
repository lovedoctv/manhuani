# AbroadPath blog guide

https://aduna.fit/AbroadPath/ is **AbroadPath**: scholarships, study abroad and work visa guides for
Nigeria, South Africa, Kenya, Ghana, Zimbabwe and Zambia. It earns from Adsterra or Monetag ad codes
and CPA offers, with traffic from Meta and TikTok ads.

You can add posts in two ways:

- **The admin** at https://aduna.fit/AbroadPath/admin/: write in the browser, add images and
  publish. The post is live straight away, with no deploy. See [Write posts in the admin](#write-posts-in-the-admin).
- **In the code**: one Markdown file per post in `blog/src/content/posts/`, published with a deploy.
  See [Add a post in the code](#add-a-post-in-the-code).

Both kinds look the same to readers and show up in the same lists, search and deadlines, and both
can be edited in the admin (see [Editing the posts in the code](#editing-the-posts-in-the-code)).
Ad codes, CPA offers, pixel IDs, social links, countries and categories are in `blog/site.config.mjs`.

The old manhwa site's code (the crawler, the old dashboard and its pages) was removed in October 2026.

## Publish a change

1. Edit the files on GitHub (or merge a pull request) on `main`.
2. The **Build site** action rebuilds `deploy/` and commits it. Wait for its green tick on the
   Actions tab.
3. In cPanel, open **Git Version Control**, click **Manage** on `manhuani`, then
   **Update from Remote** and **Deploy HEAD Commit**.

## Write posts in the admin

### The first time

1. Deploy the site once (see [Publish a change](#publish-a-change)).
2. Open https://aduna.fit/AbroadPath/admin/. It asks for a **setup code** and a new password.
3. In cPanel **File Manager**, open the folder `abroadpath_private` in your home folder (next to
   `aduna.fit`, not inside it) and open `setup-code.txt`. Copy the code into the admin.
4. Choose a password of at least 10 characters. The admin deletes `setup-code.txt` once it's used.

`abroadpath_private` also holds the admin's settings (`config.php`) and its database of posts
(`blog.sqlite`). It sits outside the website folder, so no one can open it from a browser, and
deploys never touch it. Back it up now and then: download it from File Manager.

### Writing a post

1. Click **+ New post**.
2. Give it a title and write the post. The toolbar does headings, bold, italic, links, lists, quotes
   and images. Pasting from Word or Google Docs keeps headings, lists, links, bold, italic and tables, and drops
   fonts, colours and sizes.
3. Fill in the side boxes:
   - **Summary**: one or two sentences, shown in Google, on cards and when the link is shared.
   - **Category**, and the countries it's for (tick none if it suits everyone).
   - **Cover image** (optional).
   - **Key facts box**, **Deadline**, the **official page** and **Facts checked on**, like the posts
     in the code. A deadline puts the post on the Deadlines page and "Closing soon" on the home page.
   - **Web address**: filled in from the title. Don't change it after publishing, or links people
     already shared stop working.
4. **Save draft** keeps it private. **Preview the last saved version** shows it as readers will see
   it. **Publish** puts it on the site.

Published posts get the same ads, offer boxes, share buttons and related posts as the posts in the
code, at `/AbroadPath/blog/<web-address>/`. Edit one and click **Update**; **Unpublish** takes it off
the site and keeps it as a draft; **Delete post** removes it and its images for good.

### Editing the posts in the code

The admin lists the posts in the code under **Posts in the site's code**. Click **Edit** on one to
open it in the editor with its text, facts and deadline. Your changes are saved as a separate copy:

- **Save draft** keeps them private, and the site keeps showing the original.
  **Preview the last saved version** shows them as readers will see them.
- **Publish changes** shows your version at the post's usual address, in every list and in search.
  The address can't change, so links people shared keep working.
- **Show the original** or **Delete my changes** puts the version from the code back.

The original stays in `blog/src/content/posts/`, untouched. If you edit a post in the admin, make
later changes there too: an edited version hides changes made to the original in the code.

### Images

JPG, PNG, WEBP or GIF, up to 8 MB. Photos are made smaller in the browser before they're sent, and
the server resizes anything wider than 1600 pixels and saves it as WEBP, so pages stay fast on mobile
data. Images go in the `media` folder of the site. Only use photos you took, bought or have
permission to use.

### What the admin writes on the server

Besides `abroadpath_private`, the admin writes these into `aduna.fit/AbroadPath/`, and deploys
never overwrite them:

- `live/posts.json`, the list of admin posts that the home, category, country, deadline and search
  pages read,
- `live/edited/`, one small file for each post in the code whose edited version is published
  (`.htaccess` uses it to show that version),
- `sitemap-posts.xml`, the admin posts' sitemap (`robots.txt` points to it; submit it in Google Search
  Console next to `sitemap.xml`), and
- `media/`, the images.

### Sign-in and safety

- Five wrong passwords lock sign-in from that address for 15 minutes.
- You're signed out after 2 hours without using the admin. Changing the password (**Password**, at
  the top) signs out every other browser.
- **Forgot the password?** In File Manager, delete `config.php` in `abroadpath_private`, then open
  the admin again: it makes a new `setup-code.txt` for a new password. Your posts stay.
- The admin is hidden from search engines, and its own files can't be opened from a browser.

## Add a post in the code

Create `blog/src/content/posts/<slug>.md`. The file name becomes the URL: `/AbroadPath/blog/<slug>/`.

```markdown
---
title: "Chevening Scholarship 2028: who can apply and when"
description: "One or two sentences. Shown in Google, on cards and when the link is shared."
date: 2026-11-01
checked: 2026-11-01            # the day you checked the facts on the official page
category: scholarships         # scholarships, study-abroad, work-visas, jobs-abroad, exams or guides
countries: [nigeria, ghana]    # optional; leave out if the post is for all six countries
deadline: 2027-10-06           # optional; puts the post on the Deadlines page and home page
official: { name: Chevening, url: "https://www.chevening.org/" }
facts:                         # optional; the summary box at the top of the post
  - { label: Level, value: "One-year master's" }
  - { label: Covers, value: "Tuition, living allowance and flights" }
featured: true                 # optional; shows the post under "Start here" on the home page
---
The post itself, in Markdown. Link to other posts like [this](/blog/ielts-fee-nigeria-2026/).
```

- In-article ads are placed automatically after the 2nd paragraph, and again about two thirds of
  the way down in longer posts.
- Deadlines that have passed drop off the Deadlines page and home page on their own.
- When you re-check a post, change `checked:` (and `updated: 2026-12-01` if you changed the text).

## Google AdSense

1. Apply at [adsense.google.com](https://adsense.google.com) with the Google account you'll keep.
   Google allows one AdSense account per person, so if you already have one, add the site there.
   - **Website:** `aduna.fit`. AdSense takes the domain, not the `/AbroadPath/` folder.
   - **Country:** Nigeria. It sets how you're paid and can't be changed later. The payee name must
     match your bank account name exactly.
2. Copy your publisher ID (`ca-pub-` and 16 digits) into `adsense:` in `blog/site.config.mjs`, then
   publish the change. Every page gets the AdSense code, the privacy page gets the Google ad cookies
   section, and the deploy writes `https://aduna.fit/ads.txt` with Google's line.
3. In AdSense, open **Sites**, choose `aduna.fit`, tick that you've added the code and ads.txt, and
   click **Request review**.
4. Once approved, turn on **Auto ads** for the site in AdSense. To place a unit yourself instead, paste
   its code into one of the slots below.

While Google reviews the site, `adsPaused: true` hides every code in `ads:` (Monetag's In-Page Push,
Vignette and Push), without deleting them, because Google rejects sites with pop-ups and notification
prompts. Set it to `false` to bring them back. AdSense itself isn't affected by it.

`https://aduna.fit/` itself has a home page that sends visitors to `/AbroadPath/`
(`blog/server/root-index.php`, put there by the deploy). Before, it listed the domain's folders,
which AdSense reviews as an empty site.

## Ad codes (Adsterra or Monetag)

Paste each code between the backticks in `ads:` in `blog/site.config.mjs`, exactly as the dashboard
gives it. An empty slot shows nothing.

| Slot | Where it shows | Use it for |
|---|---|---|
| `head` | Inside `<head>`, every page | Site verification tags, or a Monetag tag that asks for `<head>` |
| `bodyEnd` | End of every page | Social Bar, In-Page Push, Vignette, Popunder |
| `belowHeader` | Under the menu, every page | A 320x50 or 468x60 banner |
| `inArticle` | After a post's 2nd paragraph | Native banner or 300x250 |
| `inArticle2` | Two thirds down long posts | Native banner or 300x250 |
| `afterArticle` | End of each post | Native banner |
| `home` | Middle of the home page | Native banner |

Put banner codes (the ones with `atOptions` and `invoke.js`) in the banner slots only, never in
`bodyEnd`.

### TikTok traffic

TikTok visitors currently get the same `bodyEnd` ads as everyone else (`quietSources: []`).
TikTok rejects landing pages with pop-ups or fake notification ads, so if TikTok rejects a landing
page, set `quietSources: ['tiktok']`. That switches off the `bodyEnd` code for anyone who arrives
with `utm_source=tiktok`, for their whole visit, so TikTok's reviewers and visitors see the page
with banners only. Add `'facebook'` to the list to do the same for Meta traffic.

### Tag your ad links

Add these to the end of the URL in each ad, so offers and the Meta and TikTok pixels can tell
which campaign sent the visitor:

- Meta: `?utm_source=facebook&utm_campaign={{campaign.name}}`
- TikTok: `?utm_source=tiktok&utm_campaign=__CAMPAIGN_NAME__`

### Monetag's sw.js

`blog/public/sw.js` is the file Monetag gave to verify the site and to run MultiTag and push ads. It is
served at `https://aduna.fit/AbroadPath/sw.js`, and each deploy also copies it to the domain root,
`https://aduna.fit/sw.js`, which is where Monetag looks for it. Don't delete either copy, or Monetag
stops serving. If Monetag gives you a new one, replace this file and deploy.

### ads.txt

`https://aduna.fit/ads.txt` is written by each deploy from `blog/site.config.mjs`: Google's line once
`adsense:` is set, plus every line in `adsTxt:`. If another network asks for an ads.txt line, add it to
`adsTxt:` (for example `'monetag.com, 12345, DIRECT'`), not in File Manager, because the next deploy
replaces the file.

## CPA offers

Add each offer to `offers:` in `blog/site.config.mjs`. It shows as a "Recommended" box on posts in
the categories you list.

```js
offers: [
  { id: 'ielts-prep', title: 'Prepare for IELTS online', text: 'Practice tests and lessons.',
    cta: 'Start practising',
    url: 'https://network.example/click?o=123&sub1={source}&sub2={campaign}&sub3={post}',
    categories: ['exams', 'study-abroad'] },
],
```

`{source}`, `{campaign}` and `{post}` are filled in from the visitor's `utm_source`, `utm_campaign`
and the post they clicked on, so your network's sub-ID report shows which ad and post converted.

## Pixels

Put the Meta pixel ID and TikTok pixel ID (just the numbers) in `pixels:`. The site sends:

- **PageView** (Meta) and **page** (TikTok) on every page,
- **ViewContent** once a reader scrolls a quarter of a post, and
- **OfferClick** (Meta custom event) and **ClickButton** (TikTok) when someone clicks an offer.

Optimise for landing page views first, then for ViewContent or OfferClick once each has enough events.

## What changed on the server

- The site moved from `/manhuani/` to `/AbroadPath/`. Each deploy puts `blog/server/old-folder.htaccess`
  into the old `manhuani` folder and a lowercase `abroadpath` folder, so old links and typed addresses
  redirect to the same page under `/AbroadPath/`, keeping any `utm_` tags.
- Old manhwa URLs (`/manhuani/manga/...`, `/manhuani/chapter/...` and the rest) redirect to the new
  home page through `.htaccess`. The old files stay on the server because the deploy only copies
  files and never deletes, which is harmless.
- `/AbroadPath/admin/` is now the blog admin. The old crawler dashboard's leftover files in that
  folder (`cli.php`, `lib/`) are locked by the new `admin/.htaccess`, so they can't be opened.

### Clean up the old manhwa files

The deploy never deletes files, so the old manhwa site is still on the server. In cPanel:

1. **Cron Jobs**: delete the job that runs `admin/cli.php` (the old crawler). It still calls
   MangaDex and the other APIs.
2. **File Manager**, in `aduna.fit/AbroadPath/`, delete the old manhwa folders: `bookmarks`,
   `chapter`, `copyright`, `covers`, `data`, `dmca`, `manga`, `manga-genre`, `manga-release`,
   `new-titles`, `page` and `title`, plus `admin/cli.php`, `admin/lib`, and the old posts
   `blog/welcome-to-manhuani` and `blog/best-cultivation-manhua-for-beginners`.
   Don't delete `live`, `media` or `sitemap-posts.xml`: the admin uses them.
3. In `aduna.fit/manhuani/`, delete everything **except `.htaccess`**, which sends old links to
   AbroadPath.
4. In your home folder, delete `manhuani_private` (the crawler's settings and database).
   Don't touch `abroadpath_private`.
