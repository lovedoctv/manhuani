# Deploying to Namecheap (cPanel)

The website that goes online is the **`deploy/`** folder. It contains only finished HTML, CSS, JavaScript, images and the `.htaccess` file. Source code, `.git`, `node_modules`, `package.json` and any secrets are never copied to your website.

## How it works

1. You (or Claude) change the site and push to the `main` branch on GitHub.
2. A GitHub Action rebuilds `deploy/` and commits it, so you don't need Node.js installed.
3. In cPanel, **Git Version Control** pulls the latest commit from GitHub.
4. **Deploy HEAD Commit** runs `.cpanel.yml`, which copies `deploy/` into your website folder.

## One-time setup

You have already done steps 1 and 2 (SSH key and clone). They're listed here for reference.

1. **SSH key.** cPanel > SSH Access > Manage SSH Keys. The key at `~/.ssh/id_rsa` must have no passphrase. Its public key is added in GitHub under the repo's Settings > Deploy keys.
2. **Clone the repo.** cPanel > Git Version Control > Create.
   - Clone URL: `ssh://git@github.com/lovedoctv/manhuani.git`
   - Repository Path: `repositories/manhuani`
3. **Check your website folder (document root).**
   - Open cPanel > **Domains**. Find your domain and look at its **Document Root** column.
   - For your main domain this is usually `/home/YOUR-CPANEL-USERNAME/public_html`.
   - Your cPanel username and home folder are also shown in the right-hand **General Information** panel on the cPanel home page ("Home Directory").
   - `.cpanel.yml` uses `$HOME/public_html/`, which is correct for the main domain. If your domain's document root is different (an addon domain or subdomain), edit the `DEPLOYPATH` line in `.cpanel.yml` to match, keeping the slash at the end. For example: `export DEPLOYPATH=$HOME/manhuani.com/`
4. **Set your domain in the site.** In `site.config.mjs`, change `url: 'https://example.com'` to your real domain, such as `url: 'https://yourdomain.com'`. Commit the change and the site rebuilds automatically.
5. **Back up public_html** if it already has a website in it. Deploying copies files over the top. It doesn't delete anything, but files with the same names (like `index.html` or `.htaccess`) get replaced.

## Every time you publish

1. Make sure the change is on GitHub's `main` branch. Then open the repo's **Actions** tab and wait for the green tick on **Build site**.
2. In cPanel, open **Git Version Control** and click **Manage** next to `manhuani`.
3. Open the **Pull or Deploy** tab.
4. Click **Update from Remote**. This downloads the latest commit from GitHub.
5. Click **Deploy HEAD Commit**. This copies the new site into your website folder.
6. Open your domain in the browser and refresh. A hard refresh (Ctrl+F5) skips the browser cache.

## After the first deploy

- **Turn on HTTPS redirects.** Once your SSL certificate is active (cPanel > SSL/TLS Status), open `public/.htaccess` and uncomment the three lines under "Uncomment the next 3 lines". Commit and deploy again.
- **Tell Google about the site.** Add your domain in [Google Search Console](https://search.google.com/search-console) and submit `https://yourdomain.com/sitemap.xml`.

## Troubleshooting

| Problem | Fix |
| --- | --- |
| "Deploy HEAD Commit" is greyed out | The repo has uncommitted changes on the server. Don't edit files inside `repositories/manhuani` in File Manager. |
| Deploy fails right away | `.cpanel.yml` must be in the repo root, and the `deploy/` folder must exist on `main`. Check the Actions tab finished green. |
| Deploy fails mentioning the path | Replace `$HOME/public_html/` in `.cpanel.yml` with the full document root from cPanel > Domains, for example `/home/YOUR-CPANEL-USERNAME/public_html/`. |
| Pages show 404 or look unstyled | Make sure `.htaccess` and the `assets/` folder are in `public_html`. File Manager hides dotfiles: open Settings and tick "Show Hidden Files". |
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
