// Live chapter updates from the crawler dashboard (Phase 2), shown without rebuilding the site.
//
// The dashboard (admin/) writes JSON files to data/live/ on the server.
// The build never creates that folder, so deploying the site never overwrites it.
// If the files are missing (crawler not installed yet), the page simply stays as built.
//
//   data/live/latest.json          { updates: [{ series, slug, onSite, cover, chapter, title, date, sourceUrl, source, credit }] }
//   data/live/series/<slug>.json   { chapters: [{ number, title, date, sourceUrl, source, credit }] }
//
//   data/live/titles.json          { titles: [TitleSummary] }   titles found by the dashboard's connectors
//   data/live/title/<slug>.json    TitleDetail                  shown by title/?s=<slug>
//   data/live/library.json         { titles: [TitleSummary] }   your own published titles (dashboard > Library)
//
// "credit" is the scanlation group for chapters from MangaDex, whose terms require crediting them.
// "readable" is false for chapters only known from MangaUpdates: their link opens the release info.
// "titleSlug" means the chapter has a reading page on this site: chapter/?s=<titleSlug>&c=<number>.
// "pages" lists page images you uploaded for sources with hosting permission (data/media/...).
//   Without them, the chapter page says it isn't hosted here and links to the original source.
// "own" marks titles you added in the dashboard's Library: their cover and pages are in data/media/,
//   their chapters have no source and are always read here. Drafts are never in these files.

interface LiveChapter {
  number: number; title?: string | null; date: string; sourceUrl?: string; source?: string; credit?: string | null; readable?: boolean;
  titleSlug?: string | null; pages?: string[]; sizes?: [number, number][];
}
interface TitleSummary {
  slug: string; title: string; type: string | null; status: string | null; year: number | null; genres: string[];
  rating: number | null; latestChapter: number | null; latestDate: string | null; added: string; onSite: boolean;
  readable: boolean; sources: string[]; own?: boolean; cover?: string | null; chapterCount?: number;
}
interface TitleDetail extends TitleSummary {
  description?: string | null; author?: string | null; artist?: string | null;
  altTitles: string[]; links: { name: string; url: string; kind: 'read' | 'info' }[];
  credits: { name: string; url: string | null }[]; chapters: LiveChapter[];
}
interface LiveUpdate {
  series: string; slug: string; onSite: boolean; cover: string | null;
  chapter: number; title: string | null; date: string; sourceUrl: string | null; source: string | null; credit?: string | null;
  titlePage?: boolean; readable?: boolean; titleSlug?: string | null; hosted?: boolean;
}

const via = (source?: string, credit?: string | null) => [source, credit].filter(Boolean).map((s) => esc(s!)).join(' · ');

const BASE = import.meta.env.BASE_URL.replace(/\/?$/, '/');
const esc = (s: string) => s.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]!);
const safeUrl = (u: string) => (/^https?:\/\//i.test(u) ? u : '#');
/** Images you uploaded (covers and pages), as written by the dashboard. */
const MEDIA = /^data\/media\/[a-f0-9]{24}\.(jpg|png|webp|gif)$/;
const mediaUrl = (p?: string | null) => (p && MEDIA.test(p) ? BASE + p : null);
const chapterSlug = (n: number) => String(n).replace('.', '-');
const titleHref = (slug: string) => `${BASE}title/?s=${encodeURIComponent(slug)}`;
const chapterHref = (slug: string, n: number) => `${BASE}chapter/?s=${encodeURIComponent(slug)}&c=${n}`;
/** Where a crawled chapter opens: its reading page here, or (older data) the original source. */
const chapterLink = (titleSlug: string | null | undefined, n: number, sourceUrl?: string) =>
  titleSlug ? `href="${esc(chapterHref(titleSlug, n))}"` : `href="${esc(safeUrl(sourceUrl ?? '#'))}" rel="nofollow noopener" target="_blank"`;
const cap = (s: string) => s.charAt(0).toUpperCase() + s.slice(1);
const words = (s: string) => s.replace(/-/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());

function initialsCover(name: string, slug: string) {
  const initials = name.split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]).join('').toUpperCase();
  const hue = [...slug].reduce((a, c) => (a * 31 + c.charCodeAt(0)) % 360, 7);
  return `<span class="live-cover" style="--h:${hue}" aria-hidden="true"><b>${esc(initials)}</b><small>${esc(name)}</small></span>`;
}

async function getJson<T>(path: string): Promise<T | null> {
  try {
    const res = await fetch(BASE + path, { cache: 'no-cache' });
    return res.ok ? ((await res.json()) as T) : null;
  } catch {
    return null;
  }
}

export async function applyLiveUpdates() {
  await Promise.all([liveSeriesChapters(), liveLatestUpdates(), liveLibrary(), liveNewTitles(), liveTitlePage(), liveChapterPage()]);
}

/** Series page: add newer chapters found by the crawler (they open on the chapter page). */
async function liveSeriesChapters() {
  const list = document.querySelector<HTMLUListElement>('[data-live-series]');
  if (!list) return;
  const data = await getJson<{ chapters?: LiveChapter[] }>(`data/live/series/${list.dataset.liveSeries}.json`);
  if (!data) return;
  const known = new Set([...list.querySelectorAll('li')].map((li) => li.dataset.n));
  const fresh = (data.chapters ?? [])
    .filter((c) => !known.has(String(c.number)) && (c.sourceUrl || c.pages?.length))
    .sort((a, b) => a.number - b.number);
  for (const c of fresh) {
    const li = document.createElement('li');
    li.dataset.n = String(c.number);
    li.innerHTML = `<a ${chapterLink(c.titleSlug, c.number, c.sourceUrl)}><span>Chapter ${c.number}${c.title ? ' – ' + esc(c.title) : ''} <em class="new-badge">NEW</em>${c.source || c.credit ? ` <small class="live-credit">via ${via(c.source, c.credit)}</small>` : ''}</span><time class="ago" datetime="${esc(c.date)}">${new Date(c.date).toLocaleDateString()}</time></a>`;
    list.prepend(li);
  }
}

/** Home page: put the newest crawled updates at the top of "Latest Updates". */
async function liveLatestUpdates() {
  const grid = document.querySelector<HTMLElement>('[data-live-latest] .series-grid');
  if (!grid) return;
  const data = await getJson<{ updates?: LiveUpdate[] }>('data/live/latest.json');
  if (!data?.updates?.length) return;

  // One card per series, with its two newest chapters, like the built cards.
  const bySeries = new Map<string, LiveUpdate[]>();
  for (const u of data.updates) {
    if (!u.slug || !(u.sourceUrl || u.titleSlug)) continue;
    bySeries.set(u.slug, [...(bySeries.get(u.slug) ?? []), u]);
  }
  const cards = [...bySeries.values()].slice(0, 12).map((list) => {
    list.sort((a, b) => b.chapter - a.chapter);
    const first = list[0];
    // A series that already has a card on this page gets replaced by the fresher one.
    grid.querySelector(`[data-series="${CSS.escape(first.slug)}"]`)?.remove();
    return cardHtml(first, list.slice(0, 2));
  });
  grid.insertAdjacentHTML('afterbegin', cards.join(''));
}

function cardHtml(u: LiveUpdate, chapters: LiveUpdate[]) {
  const internal = u.onSite || u.titlePage;
  const seriesHref = u.onSite ? `${BASE}manga/${u.slug}/` : u.titlePage ? titleHref(u.slug) : safeUrl(u.sourceUrl ?? '#');
  const ext = internal ? '' : ' rel="nofollow noopener" target="_blank"';
  const src = u.onSite ? u.cover : mediaUrl(u.cover);
  const cover = src
    ? `<img src="${esc(src)}" alt="${esc(u.series)} cover" width="175" height="238" loading="lazy" decoding="async">`
    : initialsCover(u.series, u.slug);
  const credit = via(u.source ?? undefined, u.credit);
  const pills = chapters
    .map((c) => {
      // Crawled chapters open on their chapter page here, which links to the original source.
      return `<li><a ${chapterLink(c.titleSlug, c.chapter, c.sourceUrl ?? undefined)}>Chapter ${c.chapter}</a><time class="ago" datetime="${esc(c.date)}">${new Date(c.date).toLocaleDateString()}</time></li>`;
    })
    .join('');
  return `
<article class="card live-card" data-series="${esc(u.slug)}">
  <a href="${esc(seriesHref)}"${ext} class="card-cover">${cover}<span class="type-badge live">New</span></a>
  <div class="card-body">
    <h3 class="card-title"><a href="${esc(seriesHref)}"${ext}>${esc(u.series)}</a></h3>
    ${credit ? `<p class="live-source">via ${credit}</p>` : ''}
    <ul class="chapter-pills">${pills}</ul>
  </div>
</article>`;
}

/** Home page "New titles" and the new-titles page: titles found by the connectors. */
async function liveNewTitles() {
  const box = document.querySelector<HTMLElement>('[data-live-new-titles]');
  if (!box) return;
  const data = await getJson<{ titles?: TitleSummary[] }>('data/live/titles.json');
  const all = (data?.titles ?? []).filter((t) => !t.onSite);
  if (!all.length) return;
  const grid = box.querySelector<HTMLElement>('.series-grid')!;
  const limit = Number(box.dataset.limit || 0);
  const render = (type: string) => {
    const list = all.filter((t) => !type || t.type === type);
    grid.innerHTML = (limit ? list.slice(0, limit) : list).map(titleCardHtml).join('') || '<p class="muted">Nothing here yet.</p>';
  };
  box.querySelectorAll<HTMLButtonElement>('[data-type-filter]').forEach((btn) =>
    btn.addEventListener('click', () => {
      box.querySelectorAll('[data-type-filter]').forEach((b) => b.setAttribute('aria-pressed', String(b === btn)));
      render(btn.dataset.typeFilter!);
    }),
  );
  render('');
  box.hidden = false;
}

function titleCardHtml(t: TitleSummary) {
  const href = titleHref(t.slug);
  const when = t.latestDate ?? t.added;
  const src = mediaUrl(t.cover);
  const cover = src ? `<img src="${esc(src)}" alt="${esc(t.title)} cover" width="175" height="238" loading="lazy" decoding="async">` : initialsCover(t.title, t.slug);
  // Your own titles' latest chapter opens the chapter itself.
  const latestHref = t.own && t.latestChapter !== null ? chapterHref(t.slug, t.latestChapter) : href;
  const sub = t.own
    ? (t.chapterCount ? `${t.chapterCount} chapter${t.chapterCount === 1 ? '' : 's'}` : 'Coming soon') + (t.status ? ` · ${esc(cap(t.status))}` : '')
    : t.sources.length ? `via ${esc(t.sources.join(' · '))}` : '';
  return `
<article class="card live-card" data-series="${esc(t.slug)}">
  <a href="${href}" class="card-cover">${cover}${t.type ? `<span class="type-badge ${esc(t.type)}">${esc(t.type)}</span>` : ''}</a>
  <div class="card-body">
    <h3 class="card-title"><a href="${href}">${esc(t.title)}</a></h3>
    ${sub ? `<p class="live-source">${sub}</p>` : ''}
    <ul class="chapter-pills">${t.latestChapter !== null
      ? `<li><a href="${esc(latestHref)}">Chapter ${t.latestChapter}</a><time class="ago" datetime="${esc(when)}">${new Date(when).toLocaleDateString()}</time></li>`
      : `<li><a href="${href}">Details</a><time class="ago" datetime="${esc(t.added)}">${new Date(t.added).toLocaleDateString()}</time></li>`}</ul>
  </div>
</article>`;
}

/** Home page "Read on …": your own published titles, with search and Load more. */
async function liveLibrary() {
  const box = document.querySelector<HTMLElement>('[data-live-library]');
  if (!box) return;
  const data = await getJson<{ titles?: TitleSummary[] }>('data/live/library.json');
  const all = data?.titles ?? [];
  if (!all.length) return;
  const grid = box.querySelector<HTMLElement>('.series-grid')!;
  const input = box.querySelector<HTMLInputElement>('input[type=search]');
  const more = box.querySelector<HTMLButtonElement>('[data-load-more]');
  const empty = box.querySelector<HTMLElement>('[data-empty]');
  const per = Number(box.dataset.per || 12);
  let shown = per;
  const norm = (s: string) => s.toLowerCase().normalize('NFKD').replace(/[\u0300-\u036f]/g, '');
  const render = () => {
    const q = norm(input?.value.trim() ?? '');
    const list = q ? all.filter((t) => norm([t.title, ...((t as TitleDetail).altTitles ?? []), ...t.genres].join(' ')).includes(q)) : all;
    grid.innerHTML = list.slice(0, shown).map(titleCardHtml).join('');
    if (empty) empty.hidden = list.length > 0;
    if (more) more.hidden = list.length <= shown;
  };
  input?.addEventListener('input', () => { shown = per; render(); });
  box.querySelector('form')?.addEventListener('submit', (e) => e.preventDefault());
  more?.addEventListener('click', () => { shown += per; render(); });
  render();
  box.hidden = false;
}

/** title/?s=<slug>: one title found by the connectors, with links to read it where that's allowed. */
async function liveTitlePage() {
  const root = document.querySelector<HTMLElement>('[data-live-title]');
  if (!root) return;
  const slug = new URLSearchParams(location.search).get('s') ?? '';
  const t = /^[a-z0-9-]{1,200}$/.test(slug) ? await getJson<TitleDetail>(`data/live/title/${slug}.json`) : null;
  if (!t) {
    root.innerHTML = `<div class="container block"><h1>Title not found</h1><p class="muted">It may have been removed. <a href="${BASE}new-titles/">See all new titles</a>.</p></div>`;
    return;
  }
  if (t.onSite) {
    location.replace(`${BASE}manga/${t.slug}/`);
    return;
  }
  document.title = `${t.title} – ${document.title.split(' | ').pop()}`;
  const reads = t.links.filter((l) => l.kind === 'read');
  const infos = t.links.filter((l) => l.kind === 'info');
  const latest = t.chapters[0];
  const first = t.chapters[t.chapters.length - 1];
  const link = (l: { name: string; url: string }, cls: string) => `<a class="${cls}" href="${esc(safeUrl(l.url))}" rel="nofollow noopener" target="_blank">${esc(l.name)}</a>`;
  const chapters = t.chapters.map((c) => {
    const from = via(c.source ?? undefined, c.credit);
    const note = [c.pages?.length && !t.own ? 'Read here' : c.readable === false ? 'Release info' : '', from ? `Source: ${from}` : ''].filter(Boolean).join(' · ');
    return `
    <li data-n="${c.number}"><a href="${esc(chapterHref(t.slug, c.number))}">
      <span>Chapter ${c.number}${c === latest ? ' <em class="new-badge">Latest</em>' : ''}${c.title ? ' – ' + esc(c.title) : ''}${note ? `<small class="live-credit">${note}</small>` : ''}</span>
      <time class="ago" datetime="${esc(c.date)}">${new Date(c.date).toLocaleDateString()}</time></a></li>`;
  }).join('');
  const coverSrc = mediaUrl(t.cover);
  const cover = coverSrc ? `<img class="series-cover" src="${esc(coverSrc)}" alt="${esc(t.title)} cover" width="220" height="300" decoding="async">` : `<div class="series-cover live-cover-wrap">${initialsCover(t.title, t.slug)}</div>`;
  const actions = t.own
    ? `${first ? `<a class="btn" href="${esc(chapterHref(t.slug, first.number))}">Read First Chapter</a>` : ''}${latest && latest !== first ? `<a class="btn btn-ghost" href="${esc(chapterHref(t.slug, latest.number))}">Latest: Chapter ${latest.number}</a>` : ''}`
    : `${first ? `<a class="btn" href="${esc(chapterHref(t.slug, first.number))}">Chapter ${first.number}</a>` : ''}${latest && latest !== first ? `<a class="btn" href="${esc(chapterHref(t.slug, latest.number))}">Latest: Chapter ${latest.number}</a>` : ''}`;
  root.innerHTML = `
<div class="series-hero"${coverSrc ? ` style="--cover:url('${esc(coverSrc)}')"` : ''}><div class="container">
  <nav class="breadcrumbs" aria-label="Breadcrumb"><ol><li><a href="${BASE}">Home</a></li>${t.own ? '' : `<li><a href="${BASE}new-titles/">New Titles</a></li>`}<li><span>${esc(t.title)}</span></li></ol></nav>
  <div class="series-head">
    ${cover}
    <div class="series-info">
      ${t.type ? `<span class="type-badge inline ${esc(t.type)}">${esc(t.type)}</span>` : ''}
      <h1>${esc(t.title)}</h1>
      ${t.altTitles.length ? `<p class="muted alt">${t.altTitles.slice(0, 6).map(esc).join(' · ')}</p>` : ''}
      <dl class="facts">
        ${t.status ? `<div><dt>Status</dt><dd>${esc(cap(t.status))}</dd></div>` : ''}
        ${t.type ? `<div><dt>Type</dt><dd class="cap">${esc(t.type)}</dd></div>` : ''}
        ${t.year ? `<div><dt>Released</dt><dd>${t.year}</dd></div>` : ''}
        ${t.rating !== null ? `<div><dt>Rating</dt><dd>${t.rating} / 5</dd></div>` : ''}
        ${t.author ? `<div><dt>Author</dt><dd>${esc(t.author)}</dd></div>` : ''}
        ${t.artist && t.artist !== t.author ? `<div><dt>Artist</dt><dd>${esc(t.artist)}</dd></div>` : ''}
        ${latest ? `<div><dt>Latest</dt><dd>Chapter ${latest.number}</dd></div>` : ''}
        <div><dt>Chapters</dt><dd>${t.chapters.length || '—'}</dd></div>
        ${t.sources.length ? `<div><dt>Source</dt><dd>${esc(t.sources.join(', '))}</dd></div>` : ''}
      </dl>
      ${t.genres.length ? `<div class="genre-tags">${t.genres.slice(0, 10).map((g) => `<span>${esc(words(g))}</span>`).join('')}</div>` : ''}
      <div class="series-actions">${actions}${reads.map((l) => link({ ...l, name: 'Visit original source' + (reads.length > 1 ? ` (${l.name})` : '') }, 'btn btn-ghost')).join('')}</div>
    </div>
  </div>
</div></div>
<div class="container block series-body">
  ${t.description ? `<section><h2>Synopsis</h2><p class="synopsis">${esc(t.description).replace(/\n+/g, '<br>')}</p></section>` : ''}
  <section>
    <div class="section-head"><h2>Chapters</h2></div>
    ${chapters ? `<ul class="chapter-list">${chapters}</ul>` : `<p class="muted">No chapters listed yet.${reads.length ? ' Use the reading links above.' : ''}</p>`}
  </section>
  ${t.credits.length ? `<section>
    <h2>Sources</h2>
    <p class="muted small">Details and chapter lists come from ${t.credits.map((c) => (c.url ? link({ name: c.name, url: c.url }, '') : esc(c.name))).join(', ')}.
    ${infos.filter((l) => !t.credits.some((c) => c.url === l.url)).map((l) => link(l, '')).join(' · ')}
    Each chapter page names its original source and links to it.</p>
  </section>` : ''}
</div>`;
}

/**
 * chapter/?s=<slug>&c=<number>: one chapter. Your own chapters and hosted pages are read here,
 * top to bottom; other chapters say where they are published and link there.
 */
async function liveChapterPage() {
  const root = document.querySelector<HTMLElement>('[data-live-chapter]');
  if (!root) return;
  const params = new URLSearchParams(location.search);
  const slug = params.get('s') ?? '';
  const num = params.get('c') ?? '';
  const valid = /^[a-z0-9-]{1,200}$/.test(slug) && /^\d{1,5}(\.\d{1,2})?$/.test(num);
  const res = valid ? await fetch(`${BASE}data/live/title/${slug}.json`, { cache: 'no-cache' }).catch(() => null) : null;
  if (res === null && valid) {
    root.innerHTML = `<div class="container block"><h1>Couldn't load this chapter</h1><p class="muted">Check your connection and try again.</p><p><button type="button" class="btn" data-retry>Try again</button></p></div>`;
    root.querySelector('[data-retry]')?.addEventListener('click', () => location.reload());
    return;
  }
  const t = res?.ok ? ((await res.json().catch(() => null)) as TitleDetail | null) : null;
  const list = t ? [...t.chapters].sort((a, b) => a.number - b.number) : [];
  const i = list.findIndex((c) => c.number === Number(num));
  if (!t || i < 0) {
    root.innerHTML = `<div class="container block"><h1>Chapter not found</h1><p class="muted">It may have been removed or not published yet. ${t ? `<a href="${esc(titleHref(t.slug))}">See all chapters of ${esc(t.title)}</a>.` : `<a href="${BASE}">Back to the home page</a>.`}</p></div>`;
    return;
  }
  const c = list[i];
  const prev = list[i - 1];
  const next = list[i + 1];
  const seriesHref = t.onSite ? `${BASE}manga/${t.slug}/` : titleHref(t.slug);
  const name = `${t.title} Chapter ${c.number}`;
  document.title = `${name} – ${document.title.split(' | ').pop()}`;
  const source = c.source || t.sources[0] || '';
  const original = c.sourceUrl ? safeUrl(c.sourceUrl) : null;
  const visit = (cls: string) => (original ? `<a class="${cls}" href="${esc(original)}" rel="nofollow noopener" target="_blank">Visit original source</a>` : '');
  const pages = (c.pages ?? []).map((p, n) => ({ src: mediaUrl(p), size: c.sizes?.[n] })).filter((p) => p.src);
  const nav = (jump: boolean) => `<nav class="reader-nav" aria-label="Chapter navigation">
      ${prev ? `<a class="btn btn-ghost" href="${esc(chapterHref(t.slug, prev.number))}" rel="prev">&laquo; Prev</a>` : '<span class="btn btn-ghost disabled">&laquo; Prev</span>'}
      ${jump ? `<select class="chapter-jump" aria-label="Jump to chapter">${[...list].reverse().map((x) => `<option value="${esc(chapterHref(t.slug, x.number))}"${x === c ? ' selected' : ''}>Chapter ${x.number}${x.title ? ' – ' + esc(x.title) : ''}</option>`).join('')}</select>` : ''}
      <a class="btn btn-ghost" href="${esc(seriesHref)}">Back to ${esc(t.title)}</a>
      ${next ? `<a class="btn" href="${esc(chapterHref(t.slug, next.number))}" rel="next">Next &raquo;</a>` : '<span class="btn disabled">Next &raquo;</span>'}
    </nav>`;
  const body = pages.length
    ? pages.map((p, n) => `<figure class="reader-page loading"${p.size ? ` style="aspect-ratio:${p.size[0]} / ${p.size[1]}"` : ''}>
        <img src="${esc(p.src!)}" alt="${esc(name)} page ${n + 1}"${p.size ? ` width="${p.size[0]}" height="${p.size[1]}"` : ''} loading="${n < 2 ? 'eager' : 'lazy'}" decoding="async">
        <figcaption class="page-status">Loading page ${n + 1}…</figcaption></figure>`).join('')
    : `<div class="reader-empty">
        <p>${c.readable === false
          ? `Only the release details of this chapter are known${source ? `, from ${esc(source)}` : ''}. It isn't hosted on Aduna.fit.`
          : `This chapter isn't hosted on Aduna.fit.${source ? ` You can read it on ${esc(source)}.` : ''}`}</p>
        ${visit('btn')}
      </div>`;
  const from = via(source || undefined, c.credit);
  root.innerHTML = `
<div class="container reader-wrap">
  <nav class="breadcrumbs" aria-label="Breadcrumb"><ol><li><a href="${BASE}">Home</a></li><li><a href="${esc(seriesHref)}">${esc(t.title)}</a></li><li><span>Chapter ${c.number}</span></li></ol></nav>
  <h1 class="reader-title">${esc(name)}${c.title ? `<small> – ${esc(c.title)}</small>` : ''}</h1>
  <p class="muted center">Released <time datetime="${esc(c.date)}">${new Date(c.date).toLocaleDateString()}</time>${from ? ` · Source: <strong>${from}</strong>` : ''}${pages.length ? ` · ${pages.length} pages` : ''}</p>
  ${pages.length && original ? `<p class="center">${visit('btn btn-ghost')}</p>` : ''}
  ${nav(true)}
  <div class="reader">${body}</div>
  ${nav(false)}
</div>`;
  root.querySelector<HTMLSelectElement>('.chapter-jump')?.addEventListener('change', (e) => (location.href = (e.target as HTMLSelectElement).value));
  // Each page shows "Loading…" until it arrives, and a retry button if it fails.
  root.querySelectorAll<HTMLElement>('.reader-page').forEach((fig, n) => {
    const img = fig.querySelector('img')!;
    const status = fig.querySelector<HTMLElement>('.page-status')!;
    const done = () => { fig.classList.remove('loading', 'failed'); fig.style.aspectRatio = ''; };
    const failed = () => {
      fig.classList.remove('loading');
      fig.classList.add('failed');
      status.innerHTML = `Page ${n + 1} didn't load. <button type="button" class="btn btn-ghost">Try again</button>`;
      status.querySelector('button')!.addEventListener('click', () => {
        fig.classList.replace('failed', 'loading');
        status.textContent = `Loading page ${n + 1}…`;
        img.src = img.src.split('?')[0] + '?retry=' + Date.now();
      });
    };
    if (img.complete) (img.naturalWidth ? done() : img.getAttribute('loading') === 'eager' && failed());
    img.addEventListener('load', done);
    img.addEventListener('error', failed);
  });
}
