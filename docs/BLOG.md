# AbroadPath blog guide

https://aduna.fit/manhuani/ is now **AbroadPath**: scholarships, study abroad and work visa guides for
Nigeria, South Africa, Kenya, Ghana, Zimbabwe and Zambia. It earns from Adsterra or Monetag ad codes
and CPA offers, with traffic from Meta and TikTok ads.

Everything you'll normally change is in two places:

```
blog/site.config.mjs          Ad codes, CPA offers, pixel IDs, social links, countries, categories
blog/src/content/posts/       One Markdown file per post
```

The old manhwa site's code is still in the repository (`src/`, `site.config.mjs`, `public/admin/`,
the crawler). `npm run build:manhwa`, `npm run dev:manhwa` and `npm run check:manhwa` still build it.

## Publish a change

1. Edit the files on GitHub (or merge a pull request) on `main`.
2. The **Build site** action rebuilds `deploy/` and commits it. Wait for its green tick on the
   Actions tab.
3. In cPanel, open **Git Version Control**, click **Manage** on `manhuani`, then
   **Update from Remote** and **Deploy HEAD Commit**.

## Add a post

Create `blog/src/content/posts/<slug>.md`. The file name becomes the URL: `/manhuani/blog/<slug>/`.

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

TikTok rejects landing pages with pop-ups or fake notification ads. `quietSources: ['tiktok']`
switches off the `bodyEnd` code for anyone who arrives with `utm_source=tiktok`, for their whole
visit, so TikTok's reviewers and visitors see the page with banners only. Add `'facebook'` to the
list to do the same for Meta traffic.

### Tag your ad links

Add these to the end of the URL in each ad, so offers and the Meta and TikTok pixels can tell
which campaign sent the visitor:

- Meta: `?utm_source=facebook&utm_campaign={{campaign.name}}`
- TikTok: `?utm_source=tiktok&utm_campaign=__CAMPAIGN_NAME__`

### ads.txt

If a network asks for an `ads.txt` file, it must sit at `https://aduna.fit/ads.txt`, which is the
`aduna.fit` folder in cPanel File Manager, not the `manhuani` folder. The deploy never touches
that folder, so upload it there once with File Manager.

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

- Old manhwa URLs (`/manhuani/manga/...`, `/manhuani/chapter/...` and the rest) redirect to the new
  home page through `.htaccess`. The old files stay on the server because the deploy only copies
  files and never deletes, which is harmless.
- The crawler dashboard at `/manhuani/admin/` still deploys. If you don't need it any more, delete
  its cron job in cPanel **Cron Jobs** so it stops calling MangaDex and the other APIs.
