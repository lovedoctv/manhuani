// The import pipeline with the folder adapter: discovery, order, reruns, failures, dry runs.
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { test } from 'node:test';
import { adapters } from '../adapters/index.mjs';
import { importSeries } from '../lib/importer.mjs';
import { silentLogger } from '../lib/log.mjs';
import { colour, folderSource, png, readJson, snapshot, tempProject, testHttp, writeChapters } from './helpers.mjs';

const SLUG = 'test-own-comic';
const run = (root, source, opts = {}) => {
  const log = opts.log ?? silentLogger();
  return importSeries({ root, source, slug: SLUG, adapter: adapters[source.adapter], http: testHttp(log), log, runId: `t${Date.now()}`, ...opts });
};
const pages = (n, offset = 0) => Array.from({ length: n }, (_, i) => png(colour(i + offset)));
const seriesMeta = { title: 'Test Own Comic', type: 'manhua', status: 'ongoing', genres: ['fantasy'], releaseYear: 2026, synopsis: 'Pages drawn by the test suite.' };

test('a new series is discovered, ordered and saved', async () => {
  const root = tempProject();
  const dir = path.join(root, 'incoming');
  writeChapters(dir, { 'chapter-10': pages(2, 20), 1: pages(3), 'Chapter 2': pages(12, 5) }, {
    'series.json': seriesMeta,
    '1/chapter.json': { title: 'The Beginning', date: '2026-10-01' },
    '1/notes.txt': 'not a page',
  });
  const summary = await run(root, folderSource(SLUG, 'incoming'));

  assert.equal(summary.fatal, undefined);
  assert.deepEqual([summary.discovered, summary.imported, summary.failed], [3, 3, 0]);
  const series = readJson(path.join(root, 'src/content/series', `${SLUG}.json`));
  assert.equal(series.title, 'Test Own Comic');
  assert.deepEqual(series.source, { name: 'Test Studio', url: 'https://example.com/test-studio/' });
  assert.deepEqual(series.chapters.map((c) => c.number), [1, 2, 10]);
  const ch1 = series.chapters[0];
  assert.equal(ch1.title, 'The Beginning');
  assert.equal(ch1.date, '2026-10-01T00:00:00.000Z');
  assert.deepEqual(ch1.images, ['/chapters/test-own-comic/1/001.png', '/chapters/test-own-comic/1/002.png', '/chapters/test-own-comic/1/003.png']);
  // Pages 1.png … 12.png keep reading order (10, 11, 12 come last, not after 1).
  const ch2 = series.chapters[1];
  assert.equal(ch2.images.length, 12);
  for (let i = 0; i < 12; i++) {
    const saved = fs.readFileSync(path.join(root, 'public', ch2.images[i]));
    assert.ok(saved.equals(fs.readFileSync(path.join(dir, 'Chapter 2', `${i + 1}.png`))), `chapter 2 page ${i + 1} is in order`);
  }
  assert.ok(fs.existsSync(path.join(root, 'crawler/state/test-studio', `${SLUG}.json`)));
});

test('a rerun with nothing new changes nothing; new and changed chapters are picked up', async () => {
  const root = tempProject();
  const dir = path.join(root, 'incoming');
  writeChapters(dir, { 1: pages(3), 2: pages(4, 10) }, { 'series.json': seriesMeta });
  const source = folderSource(SLUG, 'incoming');
  await run(root, source);
  const before = snapshot(root);

  const again = await run(root, source);
  assert.deepEqual([again.imported, again.unchanged, again.failed], [0, 2, 0]);
  assert.deepEqual(snapshot(root), before, 'no file was rewritten');

  // Chapter 3 is new, chapter 2 now has 2 different pages instead of 4.
  writeChapters(dir, { 3: pages(2, 30), 2: pages(2, 50) });
  const third = await run(root, source);
  assert.deepEqual([third.imported, third.unchanged], [2, 1]);
  const series = readJson(path.join(root, 'src/content/series', `${SLUG}.json`));
  assert.deepEqual(series.chapters.map((c) => [c.number, c.images.length]), [[1, 3], [2, 2], [3, 2]]);
  assert.ok(!fs.existsSync(path.join(root, 'public/chapters', SLUG, '2', '003.png')), 'pages no longer used are removed');
  assert.ok(fs.readFileSync(path.join(root, 'public/chapters', SLUG, '2', '001.png')).equals(png(colour(50))));
});

test('a failing chapter keeps what it had, and the next run finishes the job', async () => {
  const root = tempProject();
  const dir = path.join(root, 'incoming');
  writeChapters(dir, { 1: pages(2), 2: pages(2, 10) }, { 'series.json': seriesMeta });
  const source = folderSource(SLUG, 'incoming');
  await run(root, source);
  const good = readJson(path.join(root, 'src/content/series', `${SLUG}.json`)).chapters[1].images;

  // Chapter 2's new page 2 is not an image; chapter 3 is fine.
  writeChapters(dir, { 2: [png(colour(70)), Buffer.from('<html>oops</html>')], 3: pages(2, 40) });
  const bad = await run(root, source);
  assert.equal(bad.failed, 1);
  assert.match(bad.chapters.find((c) => c.number === 2).reason, /page 2 \(2\.png\) was rejected: it isn't a JPEG, PNG/);
  let series = readJson(path.join(root, 'src/content/series', `${SLUG}.json`));
  assert.deepEqual(series.chapters.find((c) => c.number === 2).images, good, 'chapter 2 kept its pages');
  assert.ok(fs.readFileSync(path.join(root, 'public/chapters', SLUG, '2', '001.png')).equals(png(colour(10))), 'chapter 2 files untouched');
  assert.equal(series.chapters.find((c) => c.number === 3).images.length, 2, 'chapter 3 was still imported');

  writeChapters(dir, { 2: pages(2, 70) });
  const fixed = await run(root, source);
  assert.deepEqual([fixed.imported, fixed.unchanged, fixed.failed], [1, 2, 0]);
  series = readJson(path.join(root, 'src/content/series', `${SLUG}.json`));
  assert.ok(fs.readFileSync(path.join(root, 'public', series.chapters[1].images[0])).equals(png(colour(70))));
});

test('a dry run checks everything and writes nothing', async () => {
  const root = tempProject();
  writeChapters(path.join(root, 'incoming'), { 1: pages(2), 2: [Buffer.from('nope')] }, { 'series.json': seriesMeta });
  const before = snapshot(root);
  const summary = await run(root, folderSource(SLUG, 'incoming'), { dryRun: true });
  assert.deepEqual([summary.imported, summary.failed], [1, 1]);
  assert.deepEqual(snapshot(root), before);
  assert.ok(!fs.existsSync(path.join(root, 'src/content/series', `${SLUG}.json`)));
});

test('duplicates, --chapters and files the importer did not make', async () => {
  const root = tempProject();
  const dir = path.join(root, 'incoming');
  writeChapters(dir, { 1: pages(2), 'chapter-1': pages(2, 9), 2: pages(1, 20), 3: pages(1, 30) }, { 'series.json': seriesMeta });
  fs.mkdirSync(path.join(root, 'public/chapters', SLUG, '3'), { recursive: true });
  fs.writeFileSync(path.join(root, 'public/chapters', SLUG, '3', '001.png'), png([1, 2, 3]));

  const only = await run(root, folderSource(SLUG, 'incoming'), { chapterFilter: (n) => n !== 2 });
  assert.equal(only.chapters.filter((c) => /twice/.test(c.reason ?? '')).length, 1, 'chapter 1 listed twice is reported');
  assert.ok(only.chapters.some((c) => c.number === 2 && c.status === 'skipped'));
  const ch3 = only.chapters.find((c) => c.number === 3);
  assert.equal(ch3.status, 'failed');
  assert.match(ch3.reason, /already exists and wasn't made by the importer/);
  assert.ok(fs.readFileSync(path.join(root, 'public/chapters', SLUG, '3', '001.png')).equals(png([1, 2, 3])), 'that file was left alone');
});

test('an existing series keeps its details; an aggregator cover is replaced by the permitted one', async () => {
  const root = tempProject();
  const file = path.join(root, 'src/content/series', `${SLUG}.json`);
  fs.writeFileSync(file, JSON.stringify({
    title: 'Hand Written Title', altTitles: ['Alt'], type: 'manhwa', genres: ['action'], status: 'ongoing', releaseYear: 2021,
    cover: 'https://www.mangaread.org/wp-content/uploads/cover.jpg', synopsis: 'Kept.',
    source: { name: 'MangaRead', url: 'https://www.mangaread.org/manga/x/' },
    chapters: [
      { number: 2, title: 'Chapter 2', date: '2021-08-02T00:00:00.000Z', images: [], sourceUrl: 'https://example.com/old/2' },
      { number: 1, title: 'Chapter 1', date: '2021-08-01T00:00:00.000Z', images: [], sourceUrl: 'https://example.com/old/1' },
    ],
  }, null, 2) + '\n');
  writeChapters(path.join(root, 'incoming'), { 1: pages(2), 3: pages(1, 30) }, {
    'cover.png': png([9, 9, 9]),
    '1/chapter.json': { title: 'New Name', date: '2026-01-01' },
  });
  const summary = await run(root, folderSource(SLUG, 'incoming'));
  assert.equal(summary.imported, 2);
  const series = readJson(file);
  assert.equal(series.title, 'Hand Written Title');
  assert.deepEqual(series.altTitles, ['Alt']);
  assert.equal(series.synopsis, 'Kept.');
  assert.equal(series.cover, `/covers/${SLUG}.png`);
  assert.deepEqual(series.source.name, 'Test Studio');
  assert.deepEqual(series.chapters.map((c) => c.number), [3, 2, 1], 'newest-first order kept');
  const ch1 = series.chapters[2];
  assert.equal(ch1.title, 'Chapter 1', 'existing title kept');
  assert.equal(ch1.date, '2021-08-01T00:00:00.000Z', 'existing date kept');
  assert.equal(ch1.sourceUrl, 'https://example.com/old/1', 'existing link kept');
  assert.equal(ch1.images.length, 2);
  assert.deepEqual(series.chapters[1].images, [], 'chapter 2 has no pages from this source and stays as it was');

  await run(root, folderSource(SLUG, 'incoming'), { updateMetadata: true, force: true });
  const updated = readJson(file).chapters[2];
  assert.equal(updated.title, 'New Name');
  assert.equal(updated.date, '2026-01-01T00:00:00.000Z');
});
