// Page checks, file names, source rules and secrets.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { checkPage, detectImage, naturalSort, pageFileName } from '../lib/assets.mjs';
import { checkSource, isBlockedHost, resolveHeaders } from '../lib/sources.mjs';
import { adapters } from '../adapters/index.mjs';
import { folderSource, png } from './helpers.mjs';

test('pages are recognised by their content, not their name', () => {
  assert.equal(detectImage(png()).ext, 'png');
  assert.equal(detectImage(Buffer.from([0xff, 0xd8, 0xff, 0xe0, 0, 0, 0, 0, 0, 0, 0, 0])).ext, 'jpg');
  assert.equal(detectImage(Buffer.from('RIFF\0\0\0\0WEBPVP8 ', 'latin1')).ext, 'webp');
  assert.equal(detectImage(Buffer.from('GIF89a\0\0\0\0\0\0', 'latin1')).ext, 'gif');
  assert.equal(detectImage(Buffer.from('\0\0\0\x1cftypavif\0\0', 'latin1')).ext, 'avif');
  assert.equal(detectImage(Buffer.from('<svg xmlns="http://www.w3.org/2000/svg"></svg>')), null, 'SVG is refused');
  assert.equal(detectImage(Buffer.from('<!doctype html><html>not an image</html>')), null);
  assert.throws(() => checkPage(Buffer.alloc(0)), /empty/);
  assert.throws(() => checkPage(png(), { maxBytes: 10 }), /over the 10 byte limit/);
});

test('page files get ordered, safe names', () => {
  assert.equal(pageFileName(0, 12, 'png'), '001.png');
  assert.equal(pageFileName(11, 12, 'webp'), '012.webp');
  assert.equal(pageFileName(4, 1200, 'jpg'), '0005.jpg');
  assert.deepEqual(['10.png', '2.png', '1.png'].sort(naturalSort), ['1.png', '2.png', '10.png']);
});

test('a source needs a permission record that covers how pages are used', () => {
  const ok = folderSource('demo', 'pages');
  assert.deepEqual(checkSource(ok, adapters), []);

  const noAuth = { ...ok, authorization: undefined };
  assert.match(checkSource(noAuth, adapters).join(' '), /"authorization" is missing/);

  const linkOnly = { ...ok, authorization: { ...ok.authorization, allows: ['link-pages'] } };
  assert.match(checkSource(linkOnly, adapters).join(' '), /"copy" needs "publish-pages"/);

  const linkMode = { ...ok, assets: 'link' };
  assert.match(checkSource(linkMode, adapters).join(' '), /"link" needs "link-pages"/);

  const noDir = { ...ok, series: { demo: {} } };
  assert.match(checkSource(noDir, adapters).join(' '), /needs a "dir"/);
});

test('sites that re-host scans are refused, whatever the source entry says', () => {
  assert.ok(isBlockedHost('https://www.mangaread.org/manga/guilty-axe/'));
  assert.ok(!isBlockedHost('https://example.com/feed.json'));
  const feed = {
    ...folderSource('guilty-axe', 'x'),
    adapter: 'feed',
    series: { 'guilty-axe': { url: 'https://www.mangaread.org/manga/guilty-axe/feed.json' } },
  };
  assert.match(checkSource(feed, adapters).join(' '), /mangaread\.org re-hosts scans without permission/);
});

test('secrets for headers come from the environment and are required', () => {
  assert.deepEqual(resolveHeaders({ Authorization: 'env:TOKEN_A' }, { TOKEN_A: 'Bearer a' }), { Authorization: 'Bearer a' });
  assert.deepEqual(resolveHeaders({ 'X-Key': 'env:TOKEN_B' }, { CRAWLER_SECRETS: '{"TOKEN_B":"b"}' }), { 'X-Key': 'b' });
  assert.throws(() => resolveHeaders({ Authorization: 'env:MISSING' }, {}), /needs the secret MISSING/);
});
