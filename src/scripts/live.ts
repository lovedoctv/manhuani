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
//
// "credit" is the scanlation group for chapters from MangaDex, whose terms require crediting them.
// "readable" is false for chapters only known from MangaUpdates: their link opens the release info.
// "titleSlug" means the chapter has a reading page on this site: chapter/?s=<titleSlug>&c=<number>.
// "pages" lists page images you uploaded for sources with hosting permission (data/pages/...).
//   Without them, the chapter page says it isn't hosted here and links to the original source.

interface LiveChapter {
  number: number; title?: string | null; date: string; sourceUrl?: string; source?: string; credit?: string | null; readable?: boolean;
  titleSlug?: string | null; pages?: string[];
}
interface TitleSummary {
  slug: string; title: string; type: string | null; status: string | null; year: number | null; genres: string[];
  rating: number | null; latestChapter: number | null; latestDate: string | null; added: string; onSite: boolean;
  readable: boolean; sources: string[];
}
interface TitleDetail extends TitleSummary {
  altTitles: string[]; links: { name: string; url: string; kind: 'read' | 'info' }[];
  credits: { name: string; url: string | null }[]; chapters: LiveChapter[];
}
interface LiveUpdate {
  series: string; slug: string; onSite: boolean; cover: string | null;
  chapter: number; title: string | null; date: string; sourceUrl: string; source: string; credit?: string | null;
  titlePage?: boolean; readable?: boolean; titleSlug?: string | null; hosted?: boolean;
}

const via = (source?: string, credit?: string | null) => [source, credit].filter(Boolean).map((s) => esc(s!)).join(' · ');

const BASE = import.meta.env.BASE_URL.replace(/\/?$/, '/');
const esc = (s: string) => s.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]!);
const safeUrl = (u: string) => (/^https?:\/\//i.test(u) ? u : '#');
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
  await Promise.all([liveSeriesChapters(), liveLatestUpdates(), liveNewTitles(), liveTitlePage(), liveChapterPage()]);
}

/** Series page: add newer chapters found by the crawler (they open on the chapter page). */
async function liveSeriesChapters() {
  const list = document.querySelector<HTMLUListElement>('[data-live-series]');
  if (!list) return;
  const data = await getJson<{ chapters?: LiveChapter[] }>(`data/live/series/${list.dataset.liveSeries}.json`);
  if (!data) return;
  const known = new Set([...list.querySelectorAll('li')].map((li) => li.dataset.n));
  const fresh = (data.chapters ?? [])
    .filter((c) => !known.has(String(c.number)) && c.sourceUrl)
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
    if (!u.slug || !u.sourceUrl) continue;
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
  const seriesHref = u.onSite ? `${BASE}manga/${u.slug}/` : u.titlePage ? titleHref(u.slug) : safeUrl(u.sourceUrl);
  const ext = internal ? '' : ' rel="nofollow noopener" target="_blank"';
  const cover = u.onSite && u.cover
    ? `<img src="${esc(u.cover)}" alt="${esc(u.series)} cover" width="175" height="238" loading="lazy" decoding="async">`
    : initialsCover(u.series, u.slug);
  const pills = chapters
    .map((c) => {
      // Crawled chapters open on their chapter page here, which links to the original source.
      return `<li><a ${chapterLink(c.titleSlug, c.chapter, c.sourceUrl)}>Chapter ${c.chapter}</a><time class="ago" datetime="${esc(c.date)}">${new Date(c.date).toLocaleDateString()}</time></li>`;
    })
    .join('');
  return `
<article class="card live-card" data-series="${esc(u.slug)}">
  <a href="${esc(seriesHref)}"${ext} class="card-cover">${cover}<span class="type-badge live">New</span></a>
  <div class="card-body">
    <h3 class="card-title"><a href="${esc(seriesHref)}"${ext}>${esc(u.series)}</a></h3>
    <p class="live-source">via ${via(u.source, u.credit)}</p>
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
  return `
<article class="card live-card" data-series="${esc(t.slug)}">
  <a href="${href}" class="card-cover">${initialsCover(t.title, t.slug)}${t.type ? `<span class="type-badge ${esc(t.type)}">${esc(t.type)}</span>` : ''}</a>
  <div class="card-body">
    <h3 class="card-title"><a href="${href}">${esc(t.title)}</a></h3>
    <p class="live-source">via ${esc(t.sources.join(' · '))}</p>
    <ul class="chapter-pills">${t.latestChapter !== null
      ? `<li><a href="${href}">Chapter ${t.latestChapter}</a><time class="ago" datetime="${esc(when)}">${new Date(when).toLocaleDateString()}</time></li>`
      : `<li><a href="${href}">Details</a><time class="ago" datetime="${esc(t.added)}">${new Date(t.added).toLocaleDateString()}</time></li>`}</ul>
  </div>
</article>`;
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
  const chapters = t.chapters.map((c) => `
    <li data-n="${c.number}"><a href="${esc(chapterHref(t.slug, c.number))}">
      <span>Chapter ${c.number}${c.title ? ' – ' + esc(c.title) : ''}<small class="live-credit">${c.pages?.length ? 'Read here · ' : c.readable === false ? 'Release info · ' : ''}Source: ${via(c.source, c.credit)}</small></span>
      <time class="ago" datetime="${esc(c.date)}">${new Date(c.date).toLocaleDateString()}</time></a></li>`).join('');
  root.innerHTML = `
<div class="series-hero"><div class="container">
  <nav class="breadcrumbs" aria-label="Breadcrumb"><ol><li><a href="${BASE}">Home</a></li><li><a href="${BASE}new-titles/">New Titles</a></li><li><span>${esc(t.title)}</span></li></ol></nav>
  <div class="series-head">
    <div class="series-cover live-cover-wrap">${initialsCover(t.title, t.slug)}</div>
    <div class="series-info">
      ${t.type ? `<span class="type-badge inline ${esc(t.type)}">${esc(t.type)}</span>` : ''}
      <h1>${esc(t.title)}</h1>
      ${t.altTitles.length ? `<p class="muted alt">${t.altTitles.slice(0, 6).map(esc).join(' · ')}</p>` : ''}
      <dl class="facts">
        ${t.status ? `<div><dt>Status</dt><dd>${esc(cap(t.status))}</dd></div>` : ''}
        ${t.type ? `<div><dt>Type</dt><dd class="cap">${esc(t.type)}</dd></div>` : ''}
        ${t.year ? `<div><dt>Released</dt><dd>${t.year}</dd></div>` : ''}
        ${t.rating !== null ? `<div><dt>Rating</dt><dd>${t.rating} / 5</dd></div>` : ''}
        <div><dt>Chapters</dt><dd>${t.chapters.length || '—'}</dd></div>
        ${t.sources.length ? `<div><dt>Source</dt><dd>${esc(t.sources.join(', '))}</dd></div>` : ''}
      </dl>
      ${t.genres.length ? `<div class="genre-tags">${t.genres.slice(0, 10).map((g) => `<span>${esc(words(g))}</span>`).join('')}</div>` : ''}
      <div class="series-actions">${first ? `<a class="btn" href="${esc(chapterHref(t.slug, first.number))}">Chapter ${first.number}</a>` : ''}${latest && latest !== first ? `<a class="btn" href="${esc(chapterHref(t.slug, latest.number))}">Latest: Chapter ${latest.number}</a>` : ''}${reads.map((l) => link({ ...l, name: 'Visit original source' + (reads.length > 1 ? ` (${l.name})` : '') }, 'btn btn-ghost')).join('')}</div>
    </div>
  </div>
</div></div>
<div class="container block series-body">
  <section>
    <div class="section-head"><h2>Chapters</h2></div>
    ${chapters ? `<ul class="chapter-list">${chapters}</ul>` : `<p class="muted">No chapters listed yet.${reads.length ? ' Use the reading links above.' : ''}</p>`}
  </section>
  <section>
    <h2>Sources</h2>
    <p class="muted small">Details and chapter lists come from ${t.credits.map((c) => (c.url ? link({ name: c.name, url: c.url }, '') : esc(c.name))).join(', ')}.
    ${infos.filter((l) => !t.credits.some((c) => c.url === l.url)).map((l) => link(l, '')).join(' · ')}
    Each chapter page names its original source and links to it.</p>
  </section>
</div>`;
}

/** chapter/?s=<slug>&c=<number>: one crawled chapter, read here when its pages are hosted. */
async function liveChapterPage() {
  const root = document.querySelector<HTMLElement>('[data-live-chapter]');
  if (!root) return;
  const params = new URLSearchParams(location.search);
  const slug = params.get('s') ?? '';
  const num = params.get('c') ?? '';
  const t = /^[a-z0-9-]{1,200}$/.test(slug) && /^\d{1,5}(\.\d{1,2})?$/.test(num)
    ? await getJson<TitleDetail>(`data/live/title/${slug}.json`) : null;
  const list = t ? [...t.chapters].sort((a, b) => a.number - b.number) : [];
  const i = list.findIndex((c) => c.number === Number(num));
  if (!t || i < 0) {
    root.innerHTML = `<div class="container block"><h1>Chapter not found</h1><p class="muted">It may have been removed. ${t ? `<a href="${esc(titleHref(t.slug))}">See all chapters of ${esc(t.title)}</a>.` : `<a href="${BASE}">Back to the home page</a>.`}</p></div>`;
    return;
  }
  const c = list[i];
  const prev = list[i - 1];
  const next = list[i + 1];
  const seriesHref = t.onSite ? `${BASE}manga/${t.slug}/` : titleHref(t.slug);
  const name = `${t.title} Chapter ${c.number}`;
  document.title = `${name} – ${document.title.split(' | ').pop()}`;
  const source = c.source || t.sources[0] || 'the original source';
  const original = c.sourceUrl ? safeUrl(c.sourceUrl) : '#';
  const visit = (cls: string) => `<a class="${cls}" href="${esc(original)}" rel="nofollow noopener" target="_blank">Visit original source</a>`;
  const pages = (c.pages ?? []).filter((p) => /^data\/pages\/\d+\/\d{3,4}\.(jpg|png|webp|gif)$/.test(p));
  const nav = (jump: boolean) => `<nav class="reader-nav" aria-label="Chapter navigation">
      ${prev ? `<a class="btn btn-ghost" href="${esc(chapterHref(t.slug, prev.number))}" rel="prev">&laquo; Prev</a>` : '<span class="btn btn-ghost disabled">&laquo; Prev</span>'}
      ${jump ? `<select class="chapter-jump" aria-label="Jump to chapter">${[...list].reverse().map((x) => `<option value="${esc(chapterHref(t.slug, x.number))}"${x === c ? ' selected' : ''}>Chapter ${x.number}</option>`).join('')}</select>` : `<a class="btn btn-ghost" href="${esc(seriesHref)}">All Chapters</a>`}
      ${next ? `<a class="btn" href="${esc(chapterHref(t.slug, next.number))}" rel="next">Next &raquo;</a>` : '<span class="btn disabled">Next &raquo;</span>'}
    </nav>`;
  const body = pages.length
    ? pages.map((p, n) => `<img src="${esc(BASE + p)}" alt="${esc(name)} page ${n + 1}" loading="${n < 2 ? 'eager' : 'lazy'}" decoding="async">`).join('')
    : `<div class="reader-empty">
        <p>${c.readable === false
          ? `Only the release details of this chapter are known, from ${esc(source)}. It isn't hosted on Aduna.fit.`
          : `This chapter isn't hosted on Aduna.fit. You can read it on ${esc(source)}.`}</p>
        ${visit('btn')}
      </div>`;
  root.innerHTML = `
<div class="container reader-wrap">
  <nav class="breadcrumbs" aria-label="Breadcrumb"><ol><li><a href="${BASE}">Home</a></li><li><a href="${esc(seriesHref)}">${esc(t.title)}</a></li><li><span>Chapter ${c.number}</span></li></ol></nav>
  <h1 class="reader-title">${esc(name)}${c.title ? `<small> – ${esc(c.title)}</small>` : ''}</h1>
  <p class="muted center">Released <time datetime="${esc(c.date)}">${new Date(c.date).toLocaleDateString()}</time> · Source: <strong>${via(source, c.credit)}</strong></p>
  ${pages.length ? `<p class="center">${visit('btn btn-ghost')}</p>` : ''}
  ${nav(true)}
  <div class="reader">${body}</div>
  ${nav(false)}
</div>`;
  root.querySelector<HTMLSelectElement>('.chapter-jump')?.addEventListener('change', (e) => (location.href = (e.target as HTMLSelectElement).value));
}
