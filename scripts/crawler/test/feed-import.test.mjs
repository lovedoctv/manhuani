// The import pipeline with the feed adapter, against a local server playing a permitted publisher.
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { test } from 'node:test';
import { adapters } from '../adapters/index.mjs';
import { createHttp } from '../lib/http.mjs';
import { importSeries } from '../lib/importer.mjs';
import { silentLogger } from '../lib/log.mjs';
import { checkSource } from '../lib/sources.mjs';
import { colour, png, readJson, serve, snapshot, tempProject } from './helpers.mjs';

const SLUG = 'feed-comic';
const feedSource = (url, extra = {}) => ({
  id: 'test-publisher',
  name: 'Test Publisher',
  adapter: 'feed',
  authorization: { grantedBy: 'Test Publisher', evidence: 'test fixture', date: '2026-10-17', allows: ['publish-pages', 'link-pages'] },
  credit: { name: 'Test Publisher', url: 'https://example.com/publisher/' },
  rateLimitMs: 0,
  series: { [SLUG]: { url } },
  ...extra,
});

function publisher(state) {
  const page = (n) => ({ type: 'image/png', body: png(colour(n)) });
  return {
    '/feed.json': (req, res) => {
      if (state.feedDown) return res.writeHead(500).end();
      res.writeHead(200, { 'content-type': 'application/json' }).end(JSON.stringify({
        series: { title: 'Feed Comic', type: 'manhwa', status: 'ongoing', genres: [], releaseYear: 2026, synopsis: 'Test.' },
        cover: 'img/cover.png',
        chapters: [
          { number: 2, title: 'Second', date: '2026-10-02', url: 'https://example.com/publisher/2', pagesUrl: 'pages/2.json' },
          { number: 1, title: 'First', date: '2026-10-01', pages: ['img/1-1.png', 'img/1-2.png', 'img/1-3.png'] },
          { number: 3, date: '2026-10-03', url: 'https://example.com/publisher/3', pages: [] },
          ...(state.extra ?? []),
        ],
      }));
    },
    '/pages/2.json': { type: 'application/json', body: JSON.stringify({ pages: ['../img/2-1.png', '../img/2-2.png'] }) },
    '/img/1-1.png': page(1), '/img/1-2.png': page(2), '/img/1-3.png': page(3), '/img/cover.png': page(9),
    '/img/2-1.png': (req, res, hit) => (hit === 1 ? res.writeHead(503).end() : res.writeHead(200, { 'content-type': 'image/png' }).end(png(colour(21)))),
    '/img/2-2.png': (req, res) => (state.missing ? res.writeHead(404).end() : res.writeHead(200).end(png(colour(22)))),
  };
}

const run = (root, source, opts = {}) => {
  const log = silentLogger();
  const http = createHttp({ rateLimitMs: 0, backoffMs: 5, retries: 2, log });
  return importSeries({ root, source, slug: SLUG, adapter: adapters.feed, http, log, runId: `t${Date.now()}`, ...opts }).then((s) => ({ ...s, requests: http.requestCount, log }));
};

test('a feed is discovered, pages are fetched with retries and saved in order', async () => {
  const state = {};
  const srv = await serve(publisher(state));
  const root = tempProject();
  try {
    const source = feedSource(`${srv.base}/feed.json`);
    assert.deepEqual(checkSource(source, adapters), []);
    const summary = await run(root, source);
    assert.deepEqual([summary.discovered, summary.imported, summary.noPages, summary.failed], [3, 2, 1, 0]);
    assert.ok(summary.log.events.some((e) => e.event === 'http.retry'), 'the 503 was retried');
    const series = readJson(path.join(root, 'src/content/series', `${SLUG}.json`));
    assert.equal(series.cover, `/covers/${SLUG}.png`);
    assert.deepEqual(series.chapters.map((c) => [c.number, c.title, c.images.length]), [[1, 'First', 3], [2, 'Second', 2], [3, undefined, 0]]);
    assert.equal(series.chapters[2].sourceUrl, 'https://example.com/publisher/3', 'a chapter without pages links to the publisher');
    for (const [i, n] of [1, 2, 3].entries()) {
      assert.ok(fs.readFileSync(path.join(root, 'public', series.chapters[0].images[i])).equals(png(colour(n))));
    }

    // Rerun: nothing changed, so only the feed and chapter 2's page list are requested.
    const before = snapshot(root);
    const again = await run(root, source);
    assert.deepEqual([again.imported, again.unchanged], [0, 2]);
    assert.equal(again.requests, 2);
    assert.deepEqual(snapshot(root), before);
  } finally {
    await srv.close();
  }
});

test('a missing page fails only its chapter; a feed outage changes nothing', async () => {
  const state = { missing: true };
  const srv = await serve(publisher(state));
  const root = tempProject();
  try {
    const source = feedSource(`${srv.base}/feed.json`);
    const first = await run(root, source);
    assert.equal(first.failed, 1);
    assert.match(first.chapters.find((c) => c.number === 2).reason, /page 2 .* couldn't be fetched: HTTP 404/);
    assert.equal(first.imported, 1);

    state.missing = false;
    const second = await run(root, source);
    assert.deepEqual([second.imported, second.unchanged, second.failed], [1, 1, 0]);

    state.feedDown = true;
    const before = snapshot(root);
    const down = await run(root, source);
    assert.match(down.fatal, /couldn't read the series from Test Publisher: HTTP 500/);
    assert.deepEqual(snapshot(root), before, 'an outage erases nothing');
  } finally {
    await srv.close();
  }
});

test('link mode keeps the publisher links; links to re-hosting sites fail', async () => {
  const state = { extra: [{ number: 4, pages: ['https://www.mangaread.org/wp-content/4.jpg'] }] };
  const srv = await serve(publisher(state));
  const root = tempProject();
  try {
    const summary = await run(root, feedSource(`${srv.base}/feed.json`, { assets: 'link' }));
    const series = readJson(path.join(root, 'src/content/series', `${SLUG}.json`));
    assert.deepEqual(series.chapters.find((c) => c.number === 1).images, [`${srv.base}/img/1-1.png`, `${srv.base}/img/1-2.png`, `${srv.base}/img/1-3.png`]);
    assert.ok(!fs.existsSync(path.join(root, 'public/chapters')), 'nothing was downloaded');
    const ch4 = summary.chapters.find((c) => c.number === 4);
    assert.equal(ch4.status, 'failed');
    assert.match(ch4.reason, /page 1 is on www\.mangaread\.org, a site that re-hosts scans without permission/);
  } finally {
    await srv.close();
  }
});
