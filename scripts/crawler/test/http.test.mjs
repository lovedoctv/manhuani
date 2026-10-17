// Retries, timeouts, rate limits, size limits and redirects.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createHttp } from '../lib/http.mjs';
import { silentLogger } from '../lib/log.mjs';
import { serve } from './helpers.mjs';

test('errors that usually pass are retried, others are not', async () => {
  const srv = await serve({
    '/flaky': (req, res, hit) => (hit < 3 ? res.writeHead(503).end() : res.writeHead(200).end('ok')),
    '/limited': (req, res, hit) => (hit < 2 ? res.writeHead(429, { 'retry-after': '0' }).end() : res.writeHead(200).end('ok')),
    '/gone': { status: 404 },
    '/broken': { status: 500 },
  });
  const log = silentLogger();
  const http = createHttp({ rateLimitMs: 0, backoffMs: 5, retries: 3, log });
  try {
    assert.equal((await http.get(`${srv.base}/flaky`)).body.toString(), 'ok');
    assert.equal(srv.hits['/flaky'], 3);
    assert.equal((await http.get(`${srv.base}/limited`)).body.toString(), 'ok');
    await assert.rejects(http.get(`${srv.base}/gone`), /HTTP 404/);
    assert.equal(srv.hits['/gone'], 1, '404 is not retried');
    await assert.rejects(http.get(`${srv.base}/broken`), /HTTP 500/);
    assert.equal(srv.hits['/broken'], 4, '500 is tried once plus 3 retries');
    assert.ok(log.events.some((e) => e.event === 'http.retry'));
  } finally {
    await srv.close();
  }
});

test('slow responses time out and are retried', async () => {
  const srv = await serve({
    '/slow': (req, res, hit) => (hit === 1 ? setTimeout(() => res.writeHead(200).end('late'), 500) : res.writeHead(200).end('fast')),
  });
  const http = createHttp({ rateLimitMs: 0, backoffMs: 5, timeoutMs: 100, retries: 2 });
  try {
    assert.equal((await http.get(`${srv.base}/slow`)).body.toString(), 'fast');
    assert.equal(srv.hits['/slow'], 2);
  } finally {
    await srv.close();
  }
});

test('requests to the same host are spaced out', async () => {
  const srv = await serve({ '/a': { body: 'a' } });
  const http = createHttp({ rateLimitMs: 150 });
  try {
    const start = Date.now();
    for (let i = 0; i < 3; i++) await http.get(`${srv.base}/a`);
    assert.ok(Date.now() - start >= 290, `3 requests took ${Date.now() - start} ms`);
  } finally {
    await srv.close();
  }
});

test('oversized responses and redirects to blocked sites are refused', async () => {
  const srv = await serve({
    '/big': { body: Buffer.alloc(5000) },
    '/away': { status: 302, headers: { location: 'https://www.mangaread.org/page.jpg' } },
    '/moved': { status: 301, headers: { location: '/here' } },
    '/here': { body: 'arrived' },
  });
  const http = createHttp({ rateLimitMs: 0 });
  try {
    await assert.rejects(http.get(`${srv.base}/big`, { maxBytes: 1000 }), /over the 1000 byte limit/);
    await assert.rejects(http.get(`${srv.base}/away`), /redirected to www\.mangaread\.org/);
    assert.equal((await http.get(`${srv.base}/moved`)).body.toString(), 'arrived');
    await assert.rejects(http.get('https://www.mangaread.org/x.jpg'), /never fetched/);
  } finally {
    await srv.close();
  }
});
