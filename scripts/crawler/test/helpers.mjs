// Shared test helpers: sample pages drawn by the tests themselves (so they are ours to
// publish), throwaway project folders, and a small local server that plays a permitted feed.
import fs from 'node:fs';
import http from 'node:http';
import os from 'node:os';
import path from 'node:path';
import zlib from 'node:zlib';
import { silentLogger } from '../lib/log.mjs';
import { createHttp } from '../lib/http.mjs';

/** A solid-colour PNG page. Different colours give different files. */
export function png(rgb = [200, 60, 90], width = 40, height = 60) {
  const chunk = (type, data) => {
    const len = Buffer.alloc(4);
    len.writeUInt32BE(data.length);
    const body = Buffer.concat([Buffer.from(type, 'latin1'), data]);
    const crc = Buffer.alloc(4);
    crc.writeUInt32BE(zlib.crc32(body) >>> 0);
    return Buffer.concat([len, body, crc]);
  };
  const ihdr = Buffer.alloc(13);
  ihdr.writeUInt32BE(width, 0);
  ihdr.writeUInt32BE(height, 4);
  ihdr[8] = 8; // bit depth
  ihdr[9] = 2; // RGB
  const row = Buffer.concat([Buffer.from([0]), Buffer.alloc(width * 3).map((_, i) => rgb[i % 3])]);
  const raw = Buffer.concat(Array.from({ length: height }, () => row));
  return Buffer.concat([
    Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]),
    chunk('IHDR', ihdr),
    chunk('IDAT', zlib.deflateSync(raw)),
    chunk('IEND', Buffer.alloc(0)),
  ]);
}

export const colour = (n) => [(n * 53) % 256, (n * 97) % 256, (n * 31) % 256];

/** An empty project folder with the parts of the site the importer touches. */
export function tempProject() {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'manhuani-import-'));
  fs.mkdirSync(path.join(root, 'src', 'content', 'series'), { recursive: true });
  fs.mkdirSync(path.join(root, 'public'), { recursive: true });
  fs.mkdirSync(path.join(root, 'crawler'), { recursive: true });
  return root;
}

/** Writes a folder of chapter folders: { "1": [page buffers], ... } */
export function writeChapters(dir, chapters, extra = {}) {
  fs.mkdirSync(dir, { recursive: true });
  for (const [folder, pages] of Object.entries(chapters)) {
    const d = path.join(dir, folder);
    fs.rmSync(d, { recursive: true, force: true });
    fs.mkdirSync(d, { recursive: true });
    pages.forEach((buf, i) => fs.writeFileSync(path.join(d, `${i + 1}.png`), buf));
  }
  for (const [name, content] of Object.entries(extra)) {
    fs.mkdirSync(path.dirname(path.join(dir, name)), { recursive: true });
    fs.writeFileSync(path.join(dir, name), typeof content === 'string' || Buffer.isBuffer(content) ? content : JSON.stringify(content));
  }
}

export const readJson = (file) => JSON.parse(fs.readFileSync(file, 'utf8'));

/** Every file under a folder with its modification time, to prove a rerun changed nothing. */
export function snapshot(root) {
  const out = {};
  const walk = (d) => {
    if (!fs.existsSync(d)) return;
    for (const e of fs.readdirSync(d, { withFileTypes: true })) {
      const p = path.join(d, e.name);
      if (e.isDirectory()) walk(p);
      else out[path.relative(root, p)] = `${fs.statSync(p).mtimeMs}:${fs.statSync(p).size}`;
    }
  };
  for (const part of ['src', 'public', 'crawler/state']) walk(path.join(root, part));
  return out;
}

export const folderSource = (slug, dir, extra = {}) => ({
  id: 'test-studio',
  name: 'Test Studio',
  adapter: 'folder',
  authorization: { grantedBy: 'Test Studio (our own test pages)', evidence: 'drawn by the test suite', date: '2026-10-17', allows: ['publish-pages'] },
  credit: { name: 'Test Studio', url: 'https://example.com/test-studio/' },
  series: { [slug]: { dir } },
  ...extra,
});

export const testHttp = (log = silentLogger()) => createHttp({ rateLimitMs: 0, backoffMs: 10, timeoutMs: 2000, retries: 3, log });

/** A local server. routes: { "/path": handler(req, res, hitCount) | { status, body, type, headers } } */
export async function serve(routes) {
  const hits = {};
  const server = http.createServer((req, res) => {
    const route = req.url.split('?')[0];
    hits[route] = (hits[route] ?? 0) + 1;
    const r = routes[route];
    if (!r) {
      res.writeHead(404).end('not found');
      return;
    }
    if (typeof r === 'function') return r(req, res, hits[route]);
    res.writeHead(r.status ?? 200, { 'content-type': r.type ?? 'application/octet-stream', ...(r.headers ?? {}) });
    res.end(r.body);
  });
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  const base = `http://127.0.0.1:${server.address().port}`;
  return { base, hits, close: () => new Promise((resolve) => server.close(resolve)) };
}
