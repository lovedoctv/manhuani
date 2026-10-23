# Deploying to Namecheap (cPanel)

The site lives at **https://aduna.fit/AbroadPath/**. The website that goes online is the **`deploy/`** folder. It contains only finished HTML, CSS, JavaScript, images, the `.htaccess` files and the blog admin's PHP code (which has no passwords or keys in it). Source code, `.git`, `node_modules`, `package.json` and any secrets are never copied to your website.

## How it works

1. You (or Claude) change the site and push to the `main` branch on GitHub.
2. A GitHub Action rebuilds `deploy/` and commits it, so you don't need Node.js installed.
3. In cPanel, **Git Version Control** pulls the latest commit from GitHub.
4. **Deploy HEAD Commit** runs `.cpanel.yml`, which copies `deploy/` into the `AbroadPath` folder inside aduna.fit's document root (`$HOME/aduna.fit/AbroadPath/`). It also puts a redirect `.htaccess` in the old `manhuani` folder and Monetag's `sw.js` at the domain root.

## One-time setup

You have already done steps 1 and 2 (SSH key and clone). They're listed here for reference.

1. **SSH key.** cPanel > SSH Access > Manage SSH Keys. The key at `~/.ssh/id_rsa` must have no passphrase. Its public key is added in GitHub under the repo's Settings > Deploy keys.
2. **Clone the repo.** cPanel > Git Version Control > Create.
   - Clone URL: `ssh://git@github.com/lovedoctv/manhuani.git`
   - Repository Path: `repositories/manhuani`
3. **Check aduna.fit's document root.**
   - Open cPanel > **Domains**. Find `aduna.fit` and look at its **Document Root** column.
   - It's `/aduna.fit` (an addon domain), so `.cpanel.yml` deploys to `$HOME/aduna.fit/AbroadPath/`. `$HOME` is your cPanel home folder, shown as "Home Directory" in the **General Information** panel on the cPanel home page.
   - If the document root ever changes (for example to `/public_html`), edit the `DEPLOYPATH` line in `.cpanel.yml` to match, such as `export DEPLOYPATH=$HOME/public_html/AbroadPath/`. Keep the slash at the end.
4. **Domain and folder settings.** `blog/site.config.mjs` has `url: 'https://aduna.fit'` and `base: '/AbroadPath'`. If you ever move the site, change these two lines and the `DEPLOYPATH` in `.cpanel.yml`.
5. **Nothing else on aduna.fit is touched.** Deploying only writes inside the `AbroadPath` folder, the old `manhuani` and `abroadpath` folders' `.htaccess`, and `aduna.fit/sw.js`.

## Every time you publish

1. Make sure the change is on GitHub's `main` branch. Then open the repo's **Actions** tab and wait for the green tick on **Build site**.
2. In cPanel, open **Git Version Control** and click **Manage** next to `manhuani`.
3. Open the **Pull or Deploy** tab.
4. Click **Update from Remote**. This downloads the latest commit from GitHub.
5. Click **Deploy HEAD Commit**. This copies the new site into your website folder.
6. Open https://aduna.fit/AbroadPath/ in the browser and refresh. A hard refresh (Ctrl+F5) skips the browser cache.

## The blog admin

`deploy/` also contains the blog admin in `admin/` and `post.php`, which is PHP code with no secrets. It needs PHP 8.0 or newer with the `pdo_sqlite`, `dom`, `mbstring` and `json` extensions (cPanel > **Select PHP Version**); if one is missing, the admin page says which. Deploying never touches:

- the admin's password and database in the `abroadpath_private` folder in your home folder (outside the website folder), or
- the posts list, sitemap and images it writes to `aduna.fit/AbroadPath/` (`live/`, `sitemap-posts.xml` and `media/`).

So you can deploy as often as you like, and posts written in the admin stay. The first time, follow [Write posts in the admin](BLOG.md#write-posts-in-the-admin).

## After the first deploy

- **HTTPS.** If aduna.fit doesn't already redirect to https://, and its SSL certificate is active (cPanel > SSL/TLS Status), open `blog/public/.htaccess` and uncomment the three lines under "Uncomment the next 3 lines". Then commit and deploy again.
- **Tell Google about the site.** Add aduna.fit in [Google Search Console](https://search.google.com/search-console) and submit `https://aduna.fit/AbroadPath/sitemap.xml` and, for the posts written in the admin, `https://aduna.fit/AbroadPath/sitemap-posts.xml`. Search engines only read `robots.txt` at the domain root, so the copy inside `/AbroadPath/` is just for reference.

## Troubleshooting

| Problem | Fix |
| --- | --- |
| "Deploy HEAD Commit" is greyed out | The repo has uncommitted changes on the server. Don't edit files inside `repositories/manhuani` in File Manager. |
| Deploy fails right away | `.cpanel.yml` must be in the repo root, and the `deploy/` folder must exist on `main`. Check the Actions tab finished green. |
| Deploy fails mentioning the path | Replace `$HOME/aduna.fit/AbroadPath/` in `.cpanel.yml` with the full path, for example `/home/YOUR-CPANEL-USERNAME/aduna.fit/AbroadPath/`. |
| Pages show 404 or look unstyled | Make sure `.htaccess` and the `assets/` folder are inside `aduna.fit/AbroadPath`. File Manager hides dotfiles: open Settings and tick "Show Hidden Files". |
| "Update from Remote" fails | Run `ssh -T git@github.com` in cPanel Terminal. It should say "successfully authenticated". If not, re-check the deploy key. |
| Old version still showing | Hard refresh (Ctrl+F5), or wait a few minutes for the browser cache. |
| A post written in the admin shows "Page not found" | Check `.htaccess` is in `aduna.fit/AbroadPath` (it sends `/blog/<web-address>/` to `post.php`), and that the post is published, not a draft. |
| A post from the code that you edited in the admin still shows the old version | Hard refresh (Ctrl+F5): pages are cached for up to 10 minutes. If the editor shows a red warning about it, deploy again so the newest `.htaccess` is in `aduna.fit/AbroadPath`. |

## Building without GitHub Actions (optional)

If you prefer to build on your own computer:

```bash
npm install
npm run build     # updates deploy/
npm run check     # makes sure no links are broken
git add deploy && git commit -m "Rebuild site" && git push
```

Then do steps 2–6 of "Every time you publish".
