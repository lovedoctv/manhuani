// End-to-end test of the blog admin and post.php, run against a copy of deploy/ on PHP's built-in server.
// Needs PHP 8 with pdo_sqlite and dom on the PATH, and a built site: npm run build && npm test
import { test, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { spawn, execFileSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

const repo = path.resolve(import.meta.dirname, '..', '..');
const deploy = path.join(repo, 'deploy');
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'abroadpath-admin-'));
const site = path.join(tmp, 'web', 'AbroadPath');
const priv = path.join(tmp, 'private');
const port = 8600 + Math.floor(Math.random() * 300);
const origin = `http://127.0.0.1:${port}`;
const B = '/AbroadPath/';
let server;

let php = true;
try { execFileSync('php', ['-r', 'exit(extension_loaded("pdo_sqlite") && extension_loaded("dom") ? 0 : 1);']); } catch { php = false; }
const skip = !php ? 'PHP 8 with pdo_sqlite and dom is not installed' : !fs.existsSync(path.join(deploy, 'admin', 'inc', 'site.json')) ? 'run npm run build first' : false;

// A browser with its own cookies.
class Browser {
  cookies = new Map();
  async req(url, opts = {}) {
    const headers = { ...(opts.headers ?? {}) };
    if (this.cookies.size) headers.cookie = [...this.cookies].map(([k, v]) => `${k}=${v}`).join('; ');
    const res = await fetch(origin + url, { redirect: 'manual', ...opts, headers });
    for (const c of res.headers.getSetCookie()) {
      const [pair] = c.split(';');
      const [k, v] = pair.split('=');
      if (v === '' || /deleted/.test(v)) this.cookies.delete(k); else this.cookies.set(k, v);
    }
    const text = await res.text();
    return { status: res.status, location: res.headers.get('location'), text, headers: res.headers };
  }
  get(url) { return this.req(url); }
  async post(url, fields, files = {}) {
    const fd = new FormData();
    for (const [k, v] of Object.entries(fields)) for (const one of [v].flat()) fd.append(k, one);
    for (const [k, [buf, name, type]] of Object.entries(files)) fd.append(k, new Blob([buf], { type }), name);
    return this.req(url, { method: 'POST', body: fd });
  }
  // The form token from the last admin page
  csrf(html) { return html.match(/name="csrf" value="([a-f0-9]+)"/)?.[1] ?? ''; }
}

const png = (w, h) => execFileSync('php', ['-r', `$i=imagecreatetruecolor(${w},${h});imagefill($i,0,0,imagecolorallocate($i,13,110,90));imagepng($i);`]);
const future = new Date(Date.now() + 40 * 86400000).toISOString().slice(0, 10);
const today = new Date().toISOString().slice(0, 10);

before(async () => {
  if (skip) return;
  fs.cpSync(deploy, site, { recursive: true });
  server = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', path.join(tmp, 'web'), path.join(repo, 'blog', 'test', 'router.php')], {
    env: { ...process.env, ABROADPATH_PRIVATE_DIR: priv }, stdio: 'ignore',
  });
  for (let i = 0; i < 50; i++) {
    try { await fetch(origin + B); return; } catch { await new Promise((r) => setTimeout(r, 100)); }
  }
  throw new Error('PHP server did not start');
});
after(() => { server?.kill(); fs.rmSync(tmp, { recursive: true, force: true }); });

const ola = new Browser();
let postUrl = '';
let uploaded = '';

test('first visit asks for the setup code from the private folder', { skip }, async () => {
  const r = await ola.get(`${B}admin/`);
  assert.equal(r.status, 200);
  assert.match(r.text, /Set up your admin/);
  const code = fs.readFileSync(path.join(priv, 'setup-code.txt'), 'utf8').trim();
  assert.match(code, /^[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}$/);

  let s = await ola.post(`${B}admin/?a=setup`, { csrf: ola.csrf(r.text), code: 'WRONG-CODE-0000', password: 'a long password', repeat: 'a long password' });
  assert.match(s.text, /setup code is not right/);
  s = await ola.post(`${B}admin/?a=setup`, { csrf: ola.csrf(s.text), code, password: 'short', repeat: 'short' });
  assert.match(s.text, /at least 10 characters/);
  s = await ola.post(`${B}admin/?a=setup`, { csrf: ola.csrf(s.text), code: code.toLowerCase(), password: 'correct horse battery', repeat: 'correct horse battery' });
  assert.equal(s.status, 303);
  assert.ok(!fs.existsSync(path.join(priv, 'setup-code.txt')), 'setup code is used up');
  assert.match(fs.readFileSync(path.join(priv, 'config.php'), 'utf8'), /admin_password_hash/);

  const list = await ola.get(`${B}admin/`);
  assert.match(list.text, /Your posts/);
  assert.match(list.text, /Your password is set/);
  assert.deepEqual(JSON.parse(fs.readFileSync(path.join(site, 'live', 'posts.json'), 'utf8')), []);
  assert.ok(fs.existsSync(path.join(site, 'sitemap-posts.xml')));
});

test('image uploads are checked, renamed and made smaller', { skip }, async () => {
  const page = await ola.get(`${B}admin/?a=new`);
  const csrf = ola.csrf(page.text);
  let r = await ola.post(`${B}admin/?a=upload`, { csrf }, { image: [Buffer.from('<?php echo 1; ?>'), 'shell.png', 'image/png'] });
  assert.equal(r.status, 422);
  assert.match(JSON.parse(r.text).error, /not a JPG, PNG, WEBP or GIF/);
  r = await ola.post(`${B}admin/?a=upload`, { csrf: 'nope' }, { image: [png(10, 10), 'a.png', 'image/png'] });
  assert.equal(r.status, 403);
  r = await ola.post(`${B}admin/?a=upload`, { csrf }, { image: [png(2400, 1200), 'My Photo (1).png', 'image/png'] });
  assert.equal(r.status, 200, r.text);
  uploaded = JSON.parse(r.text).url;
  assert.match(uploaded, /^\/AbroadPath\/media\/[a-f0-9]{24}\.(webp|png)$/);
  const file = path.join(site, 'media', path.basename(uploaded));
  const size = execFileSync('php', ['-r', `echo json_encode(getimagesize('${file}'));`]).toString();
  assert.equal(JSON.parse(size)[0], 1600, 'wide images are scaled down to 1600 pixels');
});

test('publishing checks the form, cleans the text and puts the post on the site', { skip }, async () => {
  const page = await ola.get(`${B}admin/?a=new`);
  const fields = {
    csrf: ola.csrf(page.text), action: 'publish', title: 'Chevening Scholarship 2027: who can apply', slug: '',
    description: '', category: 'scholarships', 'countries[]': ['nigeria', 'ghana'], checked: today, deadline: future,
    official_name: 'Chevening', official_url: 'https://www.chevening.org/scholarships/',
    'fact_label[]': ['Level', '', 'Covers'], 'fact_value[]': ["Master's", '', 'Tuition, flights & living costs'],
    body: `<h1>Big heading</h1><p onclick="steal()">First <b>paragraph</b> with <a href="javascript:alert(1)">a bad link</a> and <a href="https://example.com/x">a good one</a>.</p>`
      + `<script>alert(1)</script><p>Second paragraph, see <a href="/deadlines/">deadlines</a>.</p><div>Third as a div</div>`
      + `<p><img src="x" onerror="alert(1)"><img src="${uploaded}" alt="Students"></p><p style="color:red">Fourth ₦ naira</p><p><br></p>`,
  };
  let r = await ola.post(`${B}admin/?a=save`, fields);
  assert.equal(r.status, 422);
  assert.match(r.text, /Write a short summary/);

  r = await ola.post(`${B}admin/?a=save`, { ...fields, csrf: ola.csrf(r.text), description: 'Who can apply for Chevening 2027, what it pays for and the deadline.', slug: 'ielts-fee-nigeria-2026' });
  assert.equal(r.status, 422);
  assert.match(r.text, /already uses this web address/);

  r = await ola.post(`${B}admin/?a=save`, { ...fields, csrf: ola.csrf(r.text), description: 'Who can apply for Chevening 2027, what it pays for and the deadline.' },
    { cover: [png(1200, 630), 'cover.png', 'image/png'] });
  assert.equal(r.status, 303, r.text.slice(0, 300));
  const edit = await ola.get(r.location.replace(origin, ''));
  assert.match(edit.text, /Published\. The post is live/);

  postUrl = `${B}blog/chevening-scholarship-2027-who-can-apply/`;
  const anon = new Browser();
  const p = await anon.get(postUrl);
  assert.equal(p.status, 200);
  const h = p.text;
  assert.match(h, /<title>Chevening Scholarship 2027: who can apply \| AbroadPath<\/title>/);
  assert.match(h, /<link rel="canonical" href="https:\/\/aduna\.fit\/AbroadPath\/blog\/chevening-scholarship-2027-who-can-apply\/">/);
  assert.match(h, /<meta property="og:image" content="https:\/\/aduna\.fit\/AbroadPath\/media\/[a-f0-9]{24}\.(webp|png)">/);
  assert.doesNotMatch(h, /ZZ[A-Z]+ZZ|zz-main|zzimage|zzcanonical/);
  assert.doesNotMatch(h, /alert\(1\)|onclick|onerror|javascript:|style="color/);
  assert.match(h, /<h2>Big heading<\/h2>/);
  assert.match(h, /<a href="https:\/\/example\.com\/x" target="_blank" rel="noopener">a good one<\/a>/);
  assert.match(h, /<a href="\/AbroadPath\/deadlines\/">deadlines<\/a>/);
  assert.match(h, /<p>Third as a div<\/p>/);
  assert.match(h, /Fourth ₦ naira/);
  assert.match(h, new RegExp(`<img src="${uploaded}" alt="Students" loading="lazy" decoding="async">`));
  assert.match(h, /<dl><dt>Level<\/dt><dd>Master&#039;s<\/dd><dt>Covers<\/dt><dd>Tuition, flights &amp; living costs<\/dd>/);
  assert.match(h, /Official page: Chevening/);
  assert.match(h, /days left/);
  assert.match(h, /<a href="\/AbroadPath\/category\/scholarships\/" class="active">/);
  assert.match(h, /🇳🇬 Nigeria<\/a><a href="\/AbroadPath\/country\/ghana\/">🇬🇭 Ghana/);
  assert.match(h, /"@type":"Article","headline":"Chevening Scholarship 2027: who can apply"/);
  assert.match(h, /Read next/);
  assert.match(h, /class="site-footer"/);

  const noSlash = await anon.get(postUrl.slice(0, -1));
  assert.equal(noSlash.status, 301);
  assert.equal(noSlash.location, postUrl);

  const live = JSON.parse(fs.readFileSync(path.join(site, 'live', 'posts.json'), 'utf8'));
  assert.equal(live.length, 1);
  assert.equal(live[0].url, postUrl);
  assert.equal(live[0].categoryName, 'Scholarships');
  assert.deepEqual(live[0].countryNames, ['Nigeria', 'Ghana']);
  assert.ok(live[0].deadline.startsWith(future));
  assert.match(fs.readFileSync(path.join(site, 'sitemap-posts.xml'), 'utf8'), /<loc>https:\/\/aduna\.fit\/AbroadPath\/blog\/chevening-scholarship-2027-who-can-apply\/<\/loc>/);
});

test('drafts are only visible to the signed-in admin', { skip }, async () => {
  const page = await ola.get(`${B}admin/?a=new`);
  const r = await ola.post(`${B}admin/?a=save`, { csrf: ola.csrf(page.text), action: 'draft', title: 'Half-written draft', category: 'guides', checked: today, body: 'Just a start.' });
  assert.equal(r.status, 303);
  const url = `${B}blog/half-written-draft/`;
  assert.equal((await new Browser().get(url)).status, 404);
  const mine = await ola.get(url);
  assert.equal(mine.status, 200);
  assert.match(mine.text, /<meta name="robots" content="noindex, nofollow">/);
  assert.match(mine.text, /Preview: this post is a draft/);
  assert.match(mine.text, /<p>Just a start\.<\/p>/);
  const live = JSON.parse(fs.readFileSync(path.join(site, 'live', 'posts.json'), 'utf8'));
  assert.ok(!live.some((p) => p.slug === 'half-written-draft'));
});

test('signing in is limited to 5 wrong passwords, and forms need their token', { skip }, async () => {
  const guest = new Browser();
  let page = await guest.get(`${B}admin/`);
  assert.match(page.text, /Sign in/);
  for (let i = 0; i < 5; i++) {
    page = await guest.post(`${B}admin/?a=login`, { csrf: guest.csrf(page.text), password: 'guess' + i });
    assert.equal(page.status, 401);
  }
  page = await guest.post(`${B}admin/?a=login`, { csrf: guest.csrf(page.text), password: 'correct horse battery' });
  assert.match(page.text, /Too many wrong passwords/);

  const before = fs.readFileSync(path.join(site, 'live', 'posts.json'), 'utf8');
  const r = await ola.post(`${B}admin/?a=save`, { action: 'publish', title: 'Sneaky post', category: 'guides', body: '<p>x</p>' });
  assert.equal(r.status, 303);
  assert.equal(fs.readFileSync(path.join(site, 'live', 'posts.json'), 'utf8'), before);
  assert.equal((await new Browser().get(`${B}admin/?a=new`)).text.includes('New post'), false, 'the editor needs a sign-in');
});

test('posts in the code are still plain files, and private files stay locked', { skip }, async () => {
  const anon = new Browser();
  const built = await anon.get(`${B}blog/ielts-fee-nigeria-2026/`);
  assert.equal(built.status, 200);
  assert.match(built.text, /IELTS fee in Nigeria/);
  assert.equal((await anon.get(`${B}admin/inc/site.json`)).status, 403);
  assert.equal((await anon.get(`${B}blog/no-such-post/`)).status, 404);
  assert.match(fs.readFileSync(path.join(site, 'media', '.htaccess'), 'utf8'), /Require all denied/);
});

test('deleting a post takes it and its images off the site', { skip }, async () => {
  const list = await ola.get(`${B}admin/`);
  const id = list.text.match(/\?a=edit&amp;id=(\d+)">Chevening/)[1];
  const edit = await ola.get(`${B}admin/?a=edit&id=${id}`);
  const r = await ola.post(`${B}admin/?a=delete&id=${id}`, { csrf: ola.csrf(edit.text) });
  assert.equal(r.status, 303);
  assert.equal((await new Browser().get(postUrl)).status, 404);
  assert.deepEqual(JSON.parse(fs.readFileSync(path.join(site, 'live', 'posts.json'), 'utf8')), []);
  assert.ok(!fs.existsSync(path.join(site, 'media', path.basename(uploaded))), 'its images are deleted');
});

// The editor form's fields as the browser would send them.
function formFields(html) {
  const start = html.indexOf('<form class="editor"');
  const form = html.slice(start, html.indexOf('</form>', start));
  const un = (s) => s.replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"').replace(/&#039;/g, "'").replace(/&amp;/g, '&');
  const f = {};
  const add = (k, v) => { if (k.endsWith('[]')) (f[k] ??= []).push(v); else f[k] = v; };
  for (const [, a] of form.matchAll(/<input ([^>]*)>/g)) {
    const name = a.match(/name="([^"]+)"/)?.[1];
    const type = a.match(/type="([^"]+)"/)?.[1] ?? 'text';
    if (!name || type === 'file' || (type === 'checkbox' && !/ checked/.test(a))) continue;
    add(name, un(a.match(/value="([^"]*)"/)?.[1] ?? ''));
  }
  for (const [, k, v] of form.matchAll(/<textarea name="([^"]+)"[^>]*>([\s\S]*?)<\/textarea>/g)) add(k, un(v));
  for (const [, k, v] of form.matchAll(/<select name="([^"]+)">([\s\S]*?)<\/select>/g)) add(k, v.match(/<option value="([^"]+)" selected>/)?.[1] ?? '');
  return f;
}

test('a post in the code can be edited in the admin, and the original brought back', { skip }, async () => {
  const slug = 'ielts-fee-nigeria-2026';
  const url = `${B}blog/${slug}/`;
  const marker = path.join(site, 'live', 'edited', slug);
  const anon = new Browser();
  const edited = () => anon.get(url).then((p) => /Edited in the admin/.test(p.text));

  let list = await ola.get(`${B}admin/`);
  assert.match(list.text, /Posts in the site's code \(12\)/);
  let page = await ola.get(`${B}admin/?a=new&from=${slug}`);
  assert.equal(page.status, 200);
  const fields = formFields(page.text);
  assert.equal(fields.title, 'IELTS fee in Nigeria (2026): British Council and IDP prices compared');
  assert.equal(fields.from_code, slug);
  assert.equal(fields.official_name, 'British Council Nigeria');
  assert.deepEqual(fields['fact_value[]'].slice(0, 1), ['₦299,000']);
  assert.match(fields.body, /<table>/);
  assert.doesNotMatch(fields.body, /data-ad-slot/);

  // Saved as a draft: the site keeps the original, the preview shows the changes. The address can't change.
  let r = await ola.post(`${B}admin/?a=save`, {
    ...fields, action: 'draft', slug: 'another-address', title: 'IELTS fee in Nigeria 2026: the new prices',
    body: `<p>Edited in the admin.</p>${fields.body}`,
  });
  assert.equal(r.status, 303, r.text.slice(0, 300));
  const id = r.location.match(/id=(\d+)/)[1];
  assert.ok(!fs.existsSync(marker));
  assert.equal(await edited(), false);
  const preview = await ola.get(`${B}post.php?slug=${slug}`);
  assert.equal(preview.status, 200);
  assert.match(preview.text, /Preview of your changes/);
  assert.match(preview.text, /Edited in the admin/);
  assert.equal((await anon.get(`${B}post.php?slug=${slug}`)).status, 404);

  // Published: the same address shows the edited version, everywhere the site lists it
  page = await ola.get(`${B}admin/?a=edit&id=${id}`);
  assert.match(page.text, /Publish changes/);
  r = await ola.post(`${B}admin/?a=save&id=${id}`, { ...formFields(page.text), action: 'publish' });
  assert.equal(r.status, 303);
  assert.ok(fs.existsSync(marker));
  const p = await anon.get(url);
  assert.equal(p.status, 200);
  assert.equal(p.headers.get('x-post-source'), 'admin');
  assert.match(p.text, /<h1>IELTS fee in Nigeria 2026: the new prices<\/h1>/);
  assert.match(p.text, /<p>Edited in the admin\.<\/p>/);
  assert.match(p.text, /<table><thead><tr><th>/);
  assert.match(p.text, /"datePublished":"2026-10-22/);
  assert.match(p.text, /Official page: British Council Nigeria/);
  const live = JSON.parse(fs.readFileSync(path.join(site, 'live', 'posts.json'), 'utf8'));
  assert.deepEqual(live.map((x) => [x.slug, x.edits]), [[slug, true]]);
  assert.doesNotMatch(fs.readFileSync(path.join(site, 'sitemap-posts.xml'), 'utf8'), /ielts/, 'it is in sitemap.xml already');
  list = await ola.get(`${B}admin/`);
  assert.match(list.text, /Edited post from the code/);
  assert.match(list.text, /Posts in the site's code \(11\)/);
  assert.equal((await ola.get(`${B}admin/?a=new&from=${slug}`)).location, `${B}admin/?a=edit&id=${id}`);

  // "Show the original" puts the code version back and keeps the changes as a draft
  page = await ola.get(`${B}admin/?a=edit&id=${id}`);
  assert.match(page.text, /data-check-live="\/AbroadPath\/blog\/ielts-fee-nigeria-2026\/"/);
  r = await ola.post(`${B}admin/?a=save&id=${id}`, { ...formFields(page.text), action: 'unpublish' });
  assert.equal(r.status, 303);
  assert.ok(!fs.existsSync(marker));
  assert.equal(await edited(), false);

  // Deleting the changes does the same
  page = await ola.get(`${B}admin/?a=edit&id=${id}`);
  r = await ola.post(`${B}admin/?a=save&id=${id}`, { ...formFields(page.text), action: 'publish' });
  assert.equal(await edited(), true);
  page = await ola.get(`${B}admin/?a=edit&id=${id}`);
  assert.match(page.text, /Delete my changes/);
  r = await ola.post(`${B}admin/?a=delete&id=${id}`, { csrf: ola.csrf(page.text) });
  assert.equal(r.status, 303);
  assert.ok(!fs.existsSync(marker));
  assert.equal(await edited(), false);
  assert.match((await anon.get(url)).text, /IELTS fee in Nigeria \(2026\)/);
  assert.deepEqual(JSON.parse(fs.readFileSync(path.join(site, 'live', 'posts.json'), 'utf8')), []);

  // Only posts that are really in the code can be opened this way
  assert.equal((await ola.get(`${B}admin/?a=new&from=no-such-post`)).status, 303);
});
