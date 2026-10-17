import { relativeTimes } from './time';
import { chapterKey, isFollowed, isMarked, lastRead, markRead, readNumbers, toggleFollow, toggleMark } from './store';
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
// "cover" on catalogue titles is AniList's cover address: the image is shown from AniList's servers.
// "id" tells apart several groups' releases of the same chapter: chapter/?s=<slug>&c=<number>&g=<id>.

interface LiveChapter {
  number: number; title?: string | null; date: string; sourceUrl?: string; source?: string; credit?: string | null; readable?: boolean;
  titleSlug?: string | null; pages?: string[]; sizes?: [number, number][]; id?: number;
}
interface TitleSummary {
  slug: string; title: string; type: string | null; status: string | null; year: number | null; genres: string[];
  rating: number | null; latestChapter: number | null; latestDate: string | null; added: string; onSite: boolean;
  readable: boolean; sources: string[]; own?: boolean; cover?: string | null; chapterCount?: number; popularity?: number | null;
}
interface TitleDetail extends TitleSummary {
  description?: string | null; author?: string | null; artist?: string | null;
  altTitles: string[]; links: { name: string; url: string; kind: 'read' | 'info' }[];
  credits: { name: string; url: string | null }[]; chapters: LiveChapter[];
}
interface LiveUpdate {
  series: string; slug: string; onSite: boolean; cover: string | null;
  chapter: number; title: string | null; date: string; sourceUrl: string | null; source: string | null; credit?: string | null;
  titlePage?: boolean; readable?: boolean; titleSlug?: string | null; hosted?: boolean; id?: number;
}

const via = (source?: string, credit?: string | null) => [source, credit].filter(Boolean).map((s) => esc(s!)).join(' · ');

const BASE = import.meta.env.BASE_URL.replace(/\/?$/, '/');
const esc = (s: string) => s.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]!);
/** The chapter's own title, or '' when it only repeats "Chapter N". */
const subtitle = (c: { number: number; title?: string | null }) => {
  const t = c.title?.trim();
  return t && t.toLowerCase() !== `chapter ${c.number}` ? t : '';
};
const safeUrl = (u: string) => (/^https?:\/\//i.test(u) ? u : '#');
/** Images you uploaded (covers and pages), as written by the dashboard. */
const MEDIA = /^data\/media\/[a-f0-9]{24}\.(jpg|png|webp|gif)$/;
const mediaUrl = (p?: string | null) => (p && MEDIA.test(p) ? BASE + p : null);
/** Covers from AniList, linked from AniList's own servers. */
const ANILIST_COVER = /^https:\/\/s4\.anilist\.co\/file\/anilistcdn\/media\/manga\/cover\/(large|medium)\/[A-Za-z0-9_.-]{1,120}$/;
const coverSrc = (p?: string | null) => mediaUrl(p) ?? (p && ANILIST_COVER.test(p) ? p : null);
const chapterSlug = (n: number) => String(n).replace('.', '-');
const titleHref = (slug: string) => `${BASE}title/?s=${encodeURIComponent(slug)}`;
const chapterHref = (slug: string, n: number, id?: number) => `${BASE}chapter/?s=${encodeURIComponent(slug)}&c=${n}${id ? `&g=${id}` : ''}`;
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

/** A cover image, or the coloured tile when there is none or it fails to load. */
function coverHtml(src: string | null, name: string, slug: string, cls = '') {
  return src
    ? `<img${cls ? ` class="${cls}"` : ''} src="${esc(src)}" alt="${esc(name)} cover" width="175" height="238" loading="lazy" decoding="async" referrerpolicy="no-referrer" data-tile="${esc(name)}" data-slug="${esc(slug)}">`
    : initialsCover(name, slug);
}
document.addEventListener('error', (e) => {
  const img = e.target as HTMLImageElement;
  if (img.tagName !== 'IMG' || !img.dataset.tile) return;
  const box = document.createElement(img.classList.contains('series-cover') ? 'div' : 'span');
  box.innerHTML = initialsCover(img.dataset.tile, img.dataset.slug ?? '');
  if (box.tagName === 'DIV') {
    // The title page's big cover keeps its size.
    box.className = 'series-cover live-cover-wrap';
    img.closest<HTMLElement>('.series-hero')?.style.removeProperty('--cover');
    img.replaceWith(box);
  } else img.replaceWith(box.firstElementChild!);
}, true);

const followBtn = (slug: string) => {
  const on = isFollowed(slug);
  return `<button type="button" class="follow-btn" data-follow="${esc(slug)}" aria-pressed="${on}" aria-label="${on ? 'Remove from' : 'Add to'} bookmarks">${on ? '✓' : '+'}</button>`;
};
const ago = (date: string) => `<time class="ago" datetime="${esc(date)}">${new Date(date).toLocaleDateString()}</time>`;
const typeBadge = (type?: string | null, extra = '') => (type ? `<span class="type-badge ${esc(type)}${extra}">${esc(type)}</span>` : '');

async function getJson<T>(path: string): Promise<T | null> {
  try {
    const res = await fetch(BASE + path, { cache: 'no-cache' });
    return res.ok ? ((await res.json()) as T) : null;
  } catch {
    return null;
  }
}

export async function applyLiveUpdates() {
  await Promise.all([liveSeriesChapters(), liveLatestUpdates(), liveLibrary(), liveCatalogue(), liveTitlePage(), liveChapterPage()]);
  initCarousels();
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
    li.innerHTML = `<a ${chapterLink(c.titleSlug, c.number, c.sourceUrl)}><span>Chapter ${c.number}${subtitle(c) ? ' – ' + esc(subtitle(c)) : ''} <em class="new-badge">NEW</em>${c.source || c.credit ? ` <small class="live-credit">via ${via(c.source, c.credit)}</small>` : ''}</span><time class="ago" datetime="${esc(c.date)}">${new Date(c.date).toLocaleDateString()}</time></a>`;
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
  const cover = coverHtml(u.onSite ? u.cover : coverSrc(u.cover), u.series, u.slug);
  // Crawled chapters open on their chapter page here, which links to the original source.
  const rows = chapters
    .map((c) => `<div class="card-meta"><a ${c.titleSlug ? `href="${esc(chapterHref(c.titleSlug, c.chapter, c.id))}"` : chapterLink(null, c.chapter, c.sourceUrl ?? undefined)}>Ch.${c.chapter}</a>${ago(c.date)}</div>`)
    .join('');
  const credit = via(u.credit ?? u.source ?? undefined);
  return `
<article class="card live-card" data-series="${esc(u.slug)}">
  <div class="card-cover-wrap">
    <a href="${esc(seriesHref)}"${ext} class="card-cover">${cover}<span class="type-badge live">New</span></a>
    ${internal ? followBtn(u.titleSlug ?? u.slug) : ''}
  </div>
  ${rows}
  <h3 class="card-title"><a href="${esc(seriesHref)}"${ext}>${esc(u.series)}</a></h3>
  ${credit ? `<p class="live-source">${credit}</p>` : ''}
</article>`;
}

/**
 * Home page "Popular" and "New comics", and the new-titles page: titles found by the
 * connectors and your own, from data/live/titles.json.
 */
async function liveCatalogue() {
  const popular = document.querySelector<HTMLElement>('[data-live-popular]');
  const box = document.querySelector<HTMLElement>('[data-live-new-titles]');
  if (!popular && !box) return;
  const data = await getJson<{ titles?: TitleSummary[] }>('data/live/titles.json');
  const titles = data?.titles ?? [];
  if (popular) {
    // Most followed on AniList among titles with a recent chapter (last 30 days), then the rest.
    const recent = (t: TitleSummary) => t.latestDate !== null && Date.now() - Date.parse(t.latestDate) < 30 * 86400000;
    const list = titles.filter((t) => t.latestChapter !== null && !t.onSite)
      .sort((a, b) => Number(recent(b)) - Number(recent(a)) || (b.popularity ?? -1) - (a.popularity ?? -1) || (b.latestDate ?? '').localeCompare(a.latestDate ?? ''))
      .slice(0, Number(popular.dataset.limit || 12));
    if (list.length >= 4) {
      popular.querySelector<HTMLElement>('.car-track')!.innerHTML = list.map((t, i) => titleCardHtml(t, i + 1)).join('');
      popular.hidden = false;
      document.querySelectorAll<HTMLElement>('[data-static-popular]').forEach((el) => (el.hidden = true));
    }
  }
  if (!box) return;
  const all = titles.filter((t) => !t.onSite).sort((a, b) => b.added.localeCompare(a.added));
  if (!all.length) return;
  const grid = box.querySelector<HTMLElement>('.series-grid, .car-track')!;
  const limit = Number(box.dataset.limit || 0);
  const render = (type: string) => {
    const list = all.filter((t) => !type || t.type === type);
    grid.innerHTML = (limit ? list.slice(0, limit) : list).map((t) => titleCardHtml(t)).join('') || '<p class="muted">Nothing here yet.</p>';
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

function titleCardHtml(t: TitleSummary, rank = 0) {
  const href = titleHref(t.slug);
  const when = t.latestDate ?? t.added;
  // The latest chapter opens the chapter itself.
  const latestHref = t.latestChapter !== null ? chapterHref(t.slug, t.latestChapter) : href;
  const sub = t.own
    ? (t.chapterCount ? `${t.chapterCount} chapter${t.chapterCount === 1 ? '' : 's'}` : 'Coming soon') + (t.status ? ` · ${esc(cap(t.status))}` : '')
    : '';
  return `
<article class="card live-card" data-series="${esc(t.slug)}">
  <div class="card-cover-wrap">
    <a href="${href}" class="card-cover">${coverHtml(coverSrc(t.cover), t.title, t.slug)}${rank ? `<span class="rank-badge">#${rank}</span>` : ''}${typeBadge(t.type, rank ? ' bottom' : '')}</a>
    ${followBtn(t.slug)}
  </div>
  <div class="card-meta">${t.latestChapter !== null ? `<a href="${esc(latestHref)}">Ch.${t.latestChapter}</a>` : '<a href="' + href + '">Details</a>'}${ago(when)}</div>
  <h3 class="card-title"><a href="${href}">${esc(t.title)}</a></h3>
  ${sub ? `<p class="live-source">${sub}</p>` : ''}
</article>`;
}

/** Horizontal rows with ‹ › buttons (Popular, New comics). */
function initCarousels() {
  document.querySelectorAll<HTMLElement>('[data-carousel]').forEach((box) => {
    const track = box.querySelector<HTMLElement>('.car-track');
    if (!track || box.dataset.ready) return;
    box.dataset.ready = '1';
    const update = () => {
      box.querySelectorAll<HTMLButtonElement>('[data-dir]').forEach((b) => {
        b.disabled = b.dataset.dir === '-1' ? track.scrollLeft <= 2 : track.scrollLeft + track.clientWidth >= track.scrollWidth - 2;
      });
    };
    box.querySelectorAll<HTMLButtonElement>('[data-dir]').forEach((b) =>
      b.addEventListener('click', () => track.scrollBy({ left: Number(b.dataset.dir) * track.clientWidth * 0.9, behavior: 'smooth' })),
    );
    track.addEventListener('scroll', update, { passive: true });
    addEventListener('resize', update);
    update();
  });
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
    grid.innerHTML = list.slice(0, shown).map((t) => titleCardHtml(t)).join('');
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
  const src = coverSrc(t.cover);
  const cover = src
    ? `<img class="series-cover" src="${esc(src)}" alt="${esc(t.title)} cover" width="220" height="300" decoding="async" referrerpolicy="no-referrer" data-tile="${esc(t.title)}" data-slug="${esc(t.slug)}">`
    : `<div class="series-cover live-cover-wrap">${initialsCover(t.title, t.slug)}</div>`;
  const groups = [...new Set(t.chapters.map(groupOf).filter(Boolean))].sort((a, b) => a.localeCompare(b));
  const resume = lastRead(t.slug);
  const resumeCh = resume !== null ? t.chapters.find((c) => c.number === resume) : undefined;
  const actions = t.own
    ? `${first ? `<a class="btn" href="${esc(chapterHref(t.slug, first.number))}">Read First Chapter</a>` : ''}${latest && latest !== first ? `<a class="btn btn-ghost" href="${esc(chapterHref(t.slug, latest.number))}">Latest: Chapter ${latest.number}</a>` : ''}`
    : `${first ? `<a class="btn" href="${esc(chapterHref(t.slug, first.number))}">Chapter ${first.number}</a>` : ''}${latest && latest !== first ? `<a class="btn" href="${esc(chapterHref(t.slug, latest.number))}">Latest: Chapter ${latest.number}</a>` : ''}`;
  root.innerHTML = `
<div class="series-hero"${src ? ` style="--cover:url('${esc(src)}')"` : ''}><div class="container">
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
        <div><dt>Chapters</dt><dd>${new Set(t.chapters.map((c) => c.number)).size || '—'}</dd></div>
        ${t.sources.length ? `<div><dt>Source</dt><dd>${esc(t.sources.join(', '))}</dd></div>` : ''}
      </dl>
      ${t.genres.length ? `<div class="genre-tags">${t.genres.slice(0, 10).map((g) => `<span>${esc(words(g))}</span>`).join('')}</div>` : ''}
      <div class="series-actions">${resumeCh ? `<a class="btn" href="${esc(chapterHref(t.slug, resumeCh.number, resumeCh.id))}">Continue: Ch.${resumeCh.number}</a>` : ''}${actions}<button type="button" class="btn btn-ghost bookmark-follow" data-follow="${esc(t.slug)}" aria-pressed="false"></button>${reads.map((l) => link({ ...l, name: 'Visit original source' + (reads.length > 1 ? ` (${l.name})` : '') }, 'btn btn-ghost')).join('')}</div>
    </div>
  </div>
</div></div>
<div class="container block series-body">
  ${t.description ? `<section><h2>Synopsis</h2><p class="synopsis">${esc(t.description).replace(/\n+/g, '<br>')}</p></section>` : ''}
  <section class="cx-panel">
    <div class="section-head"><h2>Chapters</h2><span class="muted small">${t.chapters.length} release${t.chapters.length === 1 ? '' : 's'}</span></div>
    ${t.chapters.length ? `<div class="cx-tools">
      <input type="search" class="cx-search" placeholder="Search chapter…" aria-label="Search chapter" inputmode="decimal">
      ${groups.length > 1 ? `<select class="cx-group" aria-label="Scanlation group"><option value="">All groups</option>${groups.map((g) => `<option>${esc(g)}</option>`).join('')}</select>` : ''}
      <div class="cx-sort" role="group" aria-label="Sort chapters"><button type="button" data-sort="number" aria-pressed="true">↓ Chapter</button><button type="button" data-sort="date" aria-pressed="false">Date</button></div>
    </div>
    <ul class="cx-chapters"></ul>
    <p class="empty" data-empty hidden>No chapters match.</p>` : `<p class="muted">No chapters listed yet.${reads.length ? ' Use the reading links above.' : ''}</p>`}
  </section>
  ${t.credits.length ? `<section>
    <h2>Sources</h2>
    <p class="muted small">Details and chapter lists come from ${t.credits.map((c) => (c.url ? link({ name: c.name, url: c.url }, '') : esc(c.name))).join(', ')}.
    ${infos.filter((l) => !t.credits.some((c) => c.url === l.url)).map((l) => link(l, '')).join(' · ')}
    Each chapter page names its original source and links to it.</p>
  </section>` : ''}
</div>`;
  wireFollowToggle(root);
  if (t.chapters.length) chapterList(root.querySelector<HTMLElement>('.cx-panel')!, t, latest);
}

/** Who released a chapter: its scanlation group, or else its source. */
const groupOf = (c: LiveChapter) => (c.credit || c.source || '').trim();

const USER_ICON = '<svg viewBox="0 0 24 24" width="15" height="15" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M16 20v-1.5a3.5 3.5 0 0 0-3.5-3.5h-5A3.5 3.5 0 0 0 4 18.5V20M10 11.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7M20 20v-1.4a3.4 3.4 0 0 0-2.5-3.3M15.5 4.6a3.5 3.5 0 0 1 0 6.8"/></svg>';
const MARK_ICON = '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path stroke="currentColor" stroke-width="2" stroke-linejoin="round" d="M6 3.5h12v17l-6-4.2-6 4.2z"/></svg>';

/** The comix-style chapter list: search, group filter, sort, read marks and bookmarks. */
function chapterList(panel: HTMLElement, t: TitleDetail, latest?: LiveChapter) {
  const ul = panel.querySelector<HTMLUListElement>('.cx-chapters')!;
  const search = panel.querySelector<HTMLInputElement>('.cx-search')!;
  const group = panel.querySelector<HTMLSelectElement>('.cx-group');
  const empty = panel.querySelector<HTMLElement>('[data-empty]')!;
  let sort: 'number' | 'date' = 'number';
  let desc = true;
  const render = () => {
    const read = readNumbers(t.slug);
    const q = search.value.trim().replace(/^ch(apter)?\.?\s*/i, '');
    const g = group?.value ?? '';
    const list = t.chapters
      .filter((c) => (!q || String(c.number).startsWith(q) || (c.title ?? '').toLowerCase().includes(q.toLowerCase())) && (!g || groupOf(c) === g))
      .sort((a, b) => (sort === 'number' ? a.number - b.number || a.date.localeCompare(b.date) : a.date.localeCompare(b.date) || a.number - b.number) * (desc ? -1 : 1));
    ul.innerHTML = list.map((c) => {
      const key = chapterKey(c.number, c.id);
      const note = c.pages?.length && !t.own ? 'Read here' : c.readable === false ? 'Release info' : '';
      const who = groupOf(c);
      return `<li class="cx-ch${read.has(String(c.number)) ? ' read' : ''}">
        <a href="${esc(chapterHref(t.slug, c.number, c.id))}">
          <b>Ch.${c.number}${subtitle(c) ? ` <span class="cx-title">– ${esc(subtitle(c))}</span>` : ''}${c === latest ? ' <em class="new-badge">Latest</em>' : ''}</b>
          <span class="cx-meta">${who ? `${USER_ICON}<span>${esc(who)}</span><i>·</i>` : ''}${ago(c.date)}${note ? `<i>·</i><span>${note}</span>` : ''}</span>
        </a>
        <button type="button" class="ch-mark" data-key="${esc(key)}" data-n="${c.number}" data-g="${esc(who)}" aria-pressed="${isMarked(t.slug, key)}" aria-label="Bookmark chapter ${c.number}">${MARK_ICON}</button>
      </li>`;
    }).join('');
    empty.hidden = list.length > 0;
    relativeTimes(ul);
  };
  search.addEventListener('input', render);
  group?.addEventListener('change', render);
  panel.querySelectorAll<HTMLButtonElement>('[data-sort]').forEach((btn) =>
    btn.addEventListener('click', () => {
      const next = btn.dataset.sort as 'number' | 'date';
      desc = next === sort ? !desc : true;
      sort = next;
      panel.querySelectorAll<HTMLButtonElement>('[data-sort]').forEach((b) => {
        const on = b.dataset.sort === sort;
        b.setAttribute('aria-pressed', String(on));
        b.textContent = (on ? (desc ? '↓ ' : '↑ ') : '') + (b.dataset.sort === 'number' ? 'Chapter' : 'Date');
      });
      render();
    }),
  );
  ul.addEventListener('click', (e) => {
    const btn = (e.target as Element).closest<HTMLButtonElement>('.ch-mark');
    if (!btn) return;
    const on = toggleMark(t.slug, btn.dataset.key!, `${t.title} Ch.${btn.dataset.n}${btn.dataset.g ? ` · ${btn.dataset.g}` : ''}`);
    btn.setAttribute('aria-pressed', String(on));
  });
  render();
}

/** The "Bookmark" button on title pages. */
function wireFollowToggle(root: ParentNode) {
  root.querySelectorAll<HTMLButtonElement>('.bookmark-follow').forEach((btn) => {
    const draw = () => {
      const on = isFollowed(btn.dataset.follow!);
      btn.textContent = on ? '★ Bookmarked' : '☆ Bookmark';
      btn.setAttribute('aria-pressed', String(on));
    };
    draw();
    btn.addEventListener('click', () => { toggleFollow(btn.dataset.follow!); draw(); });
  });
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
  // One entry per chapter number; several groups' releases of a number are told apart by g=<id>.
  const all = t ? [...t.chapters].sort((a, b) => a.number - b.number || a.date.localeCompare(b.date)) : [];
  const numbers = [...new Set(all.map((c) => c.number))];
  const releases = (n: number) => all.filter((c) => c.number === n);
  const gid = Number(params.get('g') || 0);
  const here = releases(Number(num));
  const c0 = here.find((c) => c.id === gid) ?? here[0];
  if (!t || !c0) {
    root.innerHTML = `<div class="container block"><h1>Chapter not found</h1><p class="muted">It may have been removed or not published yet. ${t ? `<a href="${esc(titleHref(t.slug))}">See all chapters of ${esc(t.title)}</a>.` : `<a href="${BASE}">Back to the home page</a>.`}</p></div>`;
    return;
  }
  const c = c0;
  const i = numbers.indexOf(c.number);
  // Prev and Next stay with the same group when it released that chapter too.
  const pick = (n?: number) => (n === undefined ? undefined : releases(n).find((x) => groupOf(x) === groupOf(c)) ?? releases(n)[0]);
  const prev = pick(numbers[i - 1]);
  const next = pick(numbers[i + 1]);
  markRead(t.slug, c.number);
  const key = chapterKey(c.number, c.id);
  const seriesHref = t.onSite ? `${BASE}manga/${t.slug}/` : titleHref(t.slug);
  const name = `${t.title} Chapter ${c.number}`;
  document.title = `${name} – ${document.title.split(' | ').pop()}`;
  const source = c.source || t.sources[0] || '';
  const original = c.sourceUrl ? safeUrl(c.sourceUrl) : null;
  const visit = (cls: string) => (original ? `<a class="${cls}" href="${esc(original)}" rel="nofollow noopener" target="_blank">Visit original source</a>` : '');
  const pages = (c.pages ?? []).map((p, n) => ({ src: mediaUrl(p), size: c.sizes?.[n] })).filter((p) => p.src);
  const nav = (jump: boolean) => `<nav class="reader-nav" aria-label="Chapter navigation">
      ${prev ? `<a class="btn btn-ghost" href="${esc(chapterHref(t.slug, prev.number, prev.id))}" rel="prev">&laquo; Prev</a>` : '<span class="btn btn-ghost disabled">&laquo; Prev</span>'}
      ${jump ? `<select class="chapter-jump" aria-label="Jump to chapter">${[...numbers].reverse().map((n) => { const x = pick(n)!; return `<option value="${esc(chapterHref(t.slug, n, x.id))}"${n === c.number ? ' selected' : ''}>Chapter ${n}${x.title ? ' – ' + esc(x.title) : ''}</option>`; }).join('')}</select>` : ''}
      <a class="btn btn-ghost" href="${esc(seriesHref)}">Back to ${esc(t.title)}</a>
      ${next ? `<a class="btn" href="${esc(chapterHref(t.slug, next.number, next.id))}" rel="next">Next &raquo;</a>` : '<span class="btn disabled">Next &raquo;</span>'}
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
  <h1 class="reader-title">${esc(name)}${subtitle(c) ? `<small> – ${esc(subtitle(c))}</small>` : ''}</h1>
  <p class="muted center">Released <time datetime="${esc(c.date)}">${new Date(c.date).toLocaleDateString()}</time>${from ? ` · Source: <strong>${from}</strong>` : ''}${pages.length ? ` · ${pages.length} pages` : ''}</p>
  <p class="center reader-tools">${pages.length && original ? visit('btn btn-ghost') : ''}<button type="button" class="btn btn-ghost ch-mark-btn" aria-pressed="${isMarked(t.slug, key)}"></button></p>
  ${here.length > 1 ? `<p class="center releases">Also released by: ${here.filter((x) => x !== c).map((x) => `<a href="${esc(chapterHref(t.slug, x.number, x.id))}">${esc(groupOf(x) || 'another source')}</a>`).join(' · ')}</p>` : ''}
  ${nav(true)}
  <div class="reader">${body}</div>
  ${nav(false)}
</div>`;
  root.querySelector<HTMLSelectElement>('.chapter-jump')?.addEventListener('change', (e) => (location.href = (e.target as HTMLSelectElement).value));
  const markBtn = root.querySelector<HTMLButtonElement>('.ch-mark-btn')!;
  const drawMark = () => (markBtn.textContent = markBtn.getAttribute('aria-pressed') === 'true' ? '★ Chapter bookmarked' : '☆ Bookmark chapter');
  drawMark();
  markBtn.addEventListener('click', () => {
    markBtn.setAttribute('aria-pressed', String(toggleMark(t.slug, key, `${t.title} Ch.${c.number}${groupOf(c) ? ` · ${groupOf(c)}` : ''}`)));
    drawMark();
  });
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
