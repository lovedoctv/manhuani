// End to end: run `npm run crawler` on a copy of this repository with a folder source and a
// feed source of test pages, build the real site, and check the chapter pages show the pages.
// Takes a few seconds because it runs the full Astro build. Skip with SKIP_SITE_BUILD=1.
import assert from 'node:assert/strict';
import { execFile } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { test } from 'node:test';
import { promisify } from 'node:util';
import { colour, png, readJson, serve, writeChapters } from './helpers.mjs';

const run = promisify(execFile);
const REPO = path.resolve(import.meta.dirname, '..', '..', '..');
const SKIP = new Set(['.git', 'node_modules', 'deploy', 'dist', '.astro']);

function copyRepo() {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'manhuani-site-'));
  for (const name of fs.readdirSync(REPO)) {
    if (SKIP.has(name)) continue;
    fs.cpSync(path.join(REPO, name), path.join(root, name), {
      recursive: true,
      filter: (src) => !src.includes(`${path.sep}crawler${path.sep}logs`) && !src.includes(`${path.sep}crawler${path.sep}state`),
    });
  }
  fs.symlinkSync(path.join(REPO, 'node_modules'), path.join(root, 'node_modules'));
  return root;
}

const htmlOf = (root, slug, n) => fs.readFileSync(path.join(root, 'deploy', 'manga', slug, `chapter-${n}`, 'index.html'), 'utf8');
const imgSrcs = (html) => [...html.matchAll(/<figure class="reader-page"[^>]*>\s*<img src="([^"]+)"/g)].map((m) => m[1]);

test('imported pages reach the built chapter pages, in order, under /manhuani/', { skip: process.env.SKIP_SITE_BUILD === '1', timeout: 300000 }, async () => {
  const root = copyRepo();
  const srv = await serve({
    '/feed.json': {
      type: 'application/json',
      body: JSON.stringify({
        series: { title: 'Importer Feed Comic', type: 'manhua', status: 'completed', genres: [], releaseYear: 2026, synopsis: 'Test pages.' },
        chapters: [
          { number: 1, date: '2026-10-01', pages: ['p/1.png', 'p/2.png'] },
          { number: 2, date: '2026-10-02', url: 'https://example.com/publisher/importer-feed-comic/2' },
        ],
      }),
    },
    '/p/1.png': { body: png(colour(1)) },
    '/p/2.png': { body: png(colour(2)) },
  });
  try {
    writeChapters(path.join(root, 'test-pages'), { 1: [png(colour(11)), png(colour(12)), png(colour(13))], 2: [png(colour(21))] }, {
      'series.json': { title: 'Importer Test Comic', type: 'manhwa', status: 'ongoing', genres: ['fantasy'], releaseYear: 2026, synopsis: 'Pages drawn by the test suite.' },
    });
    const auth = { grantedBy: 'Manhuani test suite (pages it drew itself)', evidence: 'scripts/crawler/test', date: '2026-10-17', allows: ['publish-pages'] };
    fs.writeFileSync(path.join(root, 'crawler', 'sources.json'), JSON.stringify({
      sources: [
        { id: 'test-studio', name: 'Test Studio', adapter: 'folder', authorization: auth, series: { 'importer-test-comic': { dir: 'test-pages' } } },
        { id: 'test-publisher', name: 'Test Publisher', adapter: 'feed', authorization: auth, rateLimitMs: 0, series: { 'importer-feed-comic': { url: `${srv.base}/feed.json` } } },
      ],
    }));

    // Dry run first: it must not create anything.
    const dry = await run('npm', ['run', 'crawler', '--', '--source', 'test-studio', '--series', 'importer-test-comic', '--dry-run'], { cwd: root });
    assert.match(dry.stdout, /Dry run \(nothing was changed\)/);
    assert.ok(!fs.existsSync(path.join(root, 'src/content/series/importer-test-comic.json')));

    // The real import, through the same command a person would type.
    const out = await run('npm', ['run', 'crawler', '--', '--all', '--quiet'], { cwd: root });
    assert.match(out.stdout, /importer-test-comic from test-studio\n {2}Discovered 2 · imported or updated 2/);
    assert.match(out.stdout, /importer-feed-comic from test-publisher\n {2}Discovered 2 · imported or updated 1 · unchanged 0 · no pages 1/);
    assert.equal(readJson(path.join(root, 'src/content/series/importer-test-comic.json')).chapters.length, 2);

    await run('npm', ['run', 'build'], { cwd: root, maxBuffer: 64 * 1024 * 1024 });
    const check = await run('npm', ['run', 'check'], { cwd: root });
    assert.match(check.stdout, /No broken links/);

    const ch1 = htmlOf(root, 'importer-test-comic', 1);
    assert.deepEqual(imgSrcs(ch1), [
      '/manhuani/chapters/importer-test-comic/1/001.png',
      '/manhuani/chapters/importer-test-comic/1/002.png',
      '/manhuani/chapters/importer-test-comic/1/003.png',
    ]);
    assert.doesNotMatch(ch1, /reader-empty/, 'no source-link fallback when pages exist');
    for (const [i, n] of [11, 12, 13].entries()) {
      const built = fs.readFileSync(path.join(root, 'deploy', 'chapters', 'importer-test-comic', '1', `00${i + 1}.png`));
      assert.ok(built.equals(png(colour(n))), `built page ${i + 1} is the right file`);
    }
    assert.match(ch1, /href="\/manhuani\/manga\/importer-test-comic\/chapter-2\/"/, 'chapter navigation uses /manhuani/');

    assert.deepEqual(imgSrcs(htmlOf(root, 'importer-feed-comic', 1)), ['/manhuani/chapters/importer-feed-comic/1/001.png', '/manhuani/chapters/importer-feed-comic/1/002.png']);
    const ch2 = htmlOf(root, 'importer-feed-comic', 2);
    assert.deepEqual(imgSrcs(ch2), []);
    assert.match(ch2, /This chapter is published on another site\./, 'a chapter without pages shows the source link');
    assert.match(ch2, /href="https:\/\/example\.com\/publisher\/importer-feed-comic\/2"/);

    // Guilty Axe is untouched: no source is set up for it.
    assert.deepEqual(imgSrcs(htmlOf(root, 'guilty-axe', 27)), []);
  } finally {
    await srv.close();
    fs.rmSync(root, { recursive: true, force: true });
  }
});
