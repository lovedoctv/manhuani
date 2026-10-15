// Checks every internal link and image in deploy/ points to a file that exists.
import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve(import.meta.dirname, '..', 'deploy');
const pages = [];
const walk = (d) => fs.readdirSync(d, { withFileTypes: true }).forEach((e) => {
  const p = path.join(d, e.name);
  e.isDirectory() ? walk(p) : p.endsWith('.html') && pages.push(p);
});
walk(root);

const exists = (url) => {
  const clean = decodeURIComponent(url.split(/[?#]/)[0]);
  const p = path.join(root, clean);
  return fs.existsSync(p) && (fs.statSync(p).isFile() || fs.existsSync(path.join(p, 'index.html')));
};

let broken = 0;
for (const page of pages) {
  const html = fs.readFileSync(page, 'utf8');
  for (const [, url] of html.matchAll(/(?:href|src)="(\/[^"]*)"/g)) {
    if (url.startsWith('//') || url.startsWith('/data/live/')) continue;
    if (!exists(url)) {
      broken++;
      console.log(`Broken: ${url}  (in ${path.relative(root, page)})`);
    }
  }
}
console.log(`Checked ${pages.length} pages. ${broken ? broken + ' broken links.' : 'No broken links.'}`);
process.exit(broken ? 1 : 0);
