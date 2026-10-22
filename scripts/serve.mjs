// Serves deploy/ the way Apache on Namecheap will: folder/index.html, and 404.html for missing pages.
// Usage: npm run preview   then open the address it prints.
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import site from '../site.config.mjs';

const root = path.resolve(import.meta.dirname, '..', 'deploy');
const base = site.base ? `${site.base.replace(/\/$/, '')}/` : '/';
const port = Number(process.env.PORT ?? 4321);
const types = {
  '.html': 'text/html; charset=utf-8', '.css': 'text/css', '.js': 'text/javascript', '.json': 'application/json',
  '.svg': 'image/svg+xml', '.png': 'image/png', '.jpg': 'image/jpeg', '.webp': 'image/webp', '.xml': 'application/xml',
  '.txt': 'text/plain', '.ico': 'image/x-icon',
};

if (!fs.existsSync(root)) {
  console.error('deploy/ not found. Run "npm run build:manhwa" first.');
  process.exit(1);
}

const notFound = (res) => {
  res.writeHead(404, { 'Content-Type': types['.html'] });
  fs.createReadStream(path.join(root, '404.html')).pipe(res);
};

http
  .createServer((req, res) => {
    const url = decodeURIComponent(new URL(req.url, 'http://x').pathname);
    if (url === '/' && base !== '/') return res.writeHead(302, { Location: base }).end();
    if (url === base.slice(0, -1)) return res.writeHead(301, { Location: base }).end();
    if (!url.startsWith(base)) return notFound(res);
    let file = path.join(root, url.slice(base.length));
    if (!file.startsWith(root)) return res.writeHead(403).end();
    if (fs.existsSync(file) && fs.statSync(file).isDirectory()) {
      if (!url.endsWith('/')) return res.writeHead(301, { Location: url + '/' }).end();
      file = path.join(file, 'index.html');
    }
    if (!fs.existsSync(file)) return notFound(res);
    res.writeHead(200, { 'Content-Type': types[path.extname(file)] ?? 'application/octet-stream' });
    fs.createReadStream(file).pipe(res);
  })
  .listen(port, () => console.log(`Previewing deploy/ at http://localhost:${port}${base}`));
