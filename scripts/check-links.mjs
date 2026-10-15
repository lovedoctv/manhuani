// Checks every internal link and image in deploy/ points to a file that exists.
import fs from 'node:fs';
import path from 'node:path';
import site from '../site.config.mjs';

const root = path.resolve(import.meta.dirname, '..', 'deploy');
const base = site.base ? `${site.base.replace(/\/$/, '')}/` : '/';
const pages = [];
const walk = (d) => fs.readdirSync(d, { withFileTypes: true }).forEach((e) => {
  const p = path.join(d, e.name);
  e.isDirectory() ? walk(p) : p.endsWith('.html') && pages.push(p);
});
walk(root);

// Every internal link must start with the base folder (e.g. /manhuani/) and point to a real file.
const exists = (url) => {
  if (!url.startsWith(base)) return false;
  const clean = decodeURIComponent(url.slice(base.length - 1).split(/[?#]/)[0]);
  const p = path.join(root, clean);
  return fs.existsSync(p) && (fs.statSync(p).isFile() || fs.existsSync(path.join(p, 'index.html')));
};

let broken = 0;
for (const page of pages) {
  const html = fs.readFileSync(page, 'utf8');
  for (const [, url] of html.matchAll(/(?:href|src)="(\/[^"]*)"/g)) {
    if (url.startsWith('//') || url.startsWith(`${base}data/live/`)) continue;
    if (!exists(url)) {
      broken++;
      console.log(`Broken: ${url}  (in ${path.relative(root, page)})`);
    }
  }
}
console.log(`Checked ${pages.length} pages. ${broken ? broken + ' broken links.' : 'No broken links.'}`);
process.exit(broken ? 1 : 0);
