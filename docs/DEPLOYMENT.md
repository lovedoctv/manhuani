# Deploying to Namecheap (cPanel)

The site lives at **https://aduna.fit/manhuani/**. The website that goes online is the **`deploy/`** folder. It contains only finished HTML, CSS, JavaScript, images and the `.htaccess` file. Source code, `.git`, `node_modules`, `package.json` and any secrets are never copied to your website.

## How it works

1. You (or Claude) change the site and push to the `main` branch on GitHub.
2. A GitHub Action rebuilds `deploy/` and commits it, so you don't need Node.js installed.
3. In cPanel, **Git Version Control** pulls the latest commit from GitHub.
4. **Deploy HEAD Commit** runs `.cpanel.yml`, which copies `deploy/` into the `manhuani` folder inside aduna.fit's document root.

## One-time setup

You have already done steps 1 and 2 (SSH key and clone). They're listed here for reference.

1. **SSH key.** cPanel > SSH Access > Manage SSH Keys. The key at `~/.ssh/id_rsa` must have no passphrase. Its public key is added in GitHub under the repo's Settings > Deploy keys.
2. **Clone the repo.** cPanel > Git Version Control > Create.
   - Clone URL: `ssh://git@github.com/lovedoctv/manhuani.git`
   - Repository Path: `repositories/manhuani`
3. **Check aduna.fit's document root.**
   - Open cPanel > **Domains**. Find `aduna.fit` and look at its **Document Root** column.
   - If it shows `/public_html` (the main domain), nothing to change: `.cpanel.yml` deploys to `$HOME/public_html/manhuani/`. `$HOME` is your cPanel home folder, shown as "Home Directory" in the **General Information** panel on the cPanel home page.
   - If it shows something else, for example `/aduna.fit`, edit the `DEPLOYPATH` line in `.cpanel.yml` to `export DEPLOYPATH=$HOME/aduna.fit/manhuani/`. Keep the slash at the end.
4. **Domain and folder settings.** `site.config.mjs` already has `url: 'https://aduna.fit'` and `base: '/manhuani'`. If you ever move the site, change these two lines and the `DEPLOYPATH` in `.cpanel.yml`.
5. **Nothing else on aduna.fit is touched.** Deploying only writes inside the `manhuani` folder.

## Every time you publish

1. Make sure the change is on GitHub's `main` branch. Then open the repo's **Actions** tab and wait for the green tick on **Build site**.
2. In cPanel, open **Git Version Control** and click **Manage** next to `manhuani`.
3. Open the **Pull or Deploy** tab.
4. Click **Update from Remote**. This downloads the latest commit from GitHub.
5. Click **Deploy HEAD Commit**. This copies the new site into your website folder.
6. Open https://aduna.fit/manhuani/ in the browser and refresh. A hard refresh (Ctrl+F5) skips the browser cache.

## After the first deploy

- **HTTPS.** If aduna.fit doesn't already redirect to https://, and its SSL certificate is active (cPanel > SSL/TLS Status), open `public/.htaccess` and uncomment the three lines under "Uncomment the next 3 lines". Then commit and deploy again.
- **Tell Google about the site.** Add aduna.fit in [Google Search Console](https://search.google.com/search-console) and submit `https://aduna.fit/manhuani/sitemap.xml`. Search engines only read `robots.txt` at the domain root, so the copy inside `/manhuani/` is just for reference.

## Troubleshooting

| Problem | Fix |
| --- | --- |
| "Deploy HEAD Commit" is greyed out | The repo has uncommitted changes on the server. Don't edit files inside `repositories/manhuani` in File Manager. |
| Deploy fails right away | `.cpanel.yml` must be in the repo root, and the `deploy/` folder must exist on `main`. Check the Actions tab finished green. |
| Deploy fails mentioning the path | Replace `$HOME/public_html/manhuani/` in `.cpanel.yml` with the full path, for example `/home/YOUR-CPANEL-USERNAME/public_html/manhuani/`. |
| Pages show 404 or look unstyled | Make sure `.htaccess` and the `assets/` folder are inside `public_html/manhuani`. File Manager hides dotfiles: open Settings and tick "Show Hidden Files". |
| "Update from Remote" fails | Run `ssh -T git@github.com` in cPanel Terminal. It should say "successfully authenticated". If not, re-check the deploy key. |
| Old version still showing | Hard refresh (Ctrl+F5), or wait a few minutes for the browser cache. |

## Building without GitHub Actions (optional)

If you prefer to build on your own computer:

```bash
npm install
npm run build     # updates deploy/
npm run check     # makes sure no links are broken
git add deploy && git commit -m "Rebuild site" && git push
```

Then do steps 2–6 of "Every time you publish".
