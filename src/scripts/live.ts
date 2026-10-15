// Live chapter updates from the crawler dashboard (Phase 2), shown without rebuilding the site.
//
// The dashboard (admin/) writes JSON files to data/live/ on the server.
// The build never creates that folder, so deploying the site never overwrites it.
// If the files are missing (crawler not installed yet), the page simply stays as built.
//
//   data/live/latest.json          { updates: [{ series, slug, onSite, cover, chapter, title, date, sourceUrl, source }] }
//   data/live/series/<slug>.json   { chapters: [{ number, title, date, sourceUrl }] }

interface LiveChapter { number: number; title?: string | null; date: string; sourceUrl?: string }
interface LiveUpdate {
  series: string; slug: string; onSite: boolean; cover: string | null;
  chapter: number; title: string | null; date: string; sourceUrl: string; source: string;
}

const BASE = import.meta.env.BASE_URL.replace(/\/?$/, '/');
const esc = (s: string) => s.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]!);
const safeUrl = (u: string) => (/^https?:\/\//i.test(u) ? u : '#');
const chapterSlug = (n: number) => String(n).replace('.', '-');

async function getJson<T>(path: string): Promise<T | null> {
  try {
    const res = await fetch(BASE + path, { cache: 'no-cache' });
    return res.ok ? ((await res.json()) as T) : null;
  } catch {
    return null;
  }
}

export async function applyLiveUpdates() {
  await Promise.all([liveSeriesChapters(), liveLatestUpdates()]);
}

/** Series page: add newer chapters found by the crawler (they link to the original source). */
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
    li.innerHTML = `<a href="${esc(safeUrl(c.sourceUrl!))}" rel="nofollow noopener" target="_blank"><span>Chapter ${c.number}${c.title ? ' – ' + esc(c.title) : ''} <em class="new-badge">NEW</em></span><time class="ago" datetime="${esc(c.date)}">${new Date(c.date).toLocaleDateString()}</time></a>`;
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
  const seriesHref = u.onSite ? `${BASE}manga/${u.slug}/` : safeUrl(u.sourceUrl);
  const ext = u.onSite ? '' : ' rel="nofollow noopener" target="_blank"';
  const initials = u.series.split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]).join('').toUpperCase();
  const hue = [...u.slug].reduce((a, c) => (a * 31 + c.charCodeAt(0)) % 360, 7);
  const cover = u.onSite && u.cover
    ? `<img src="${esc(u.cover)}" alt="${esc(u.series)} cover" width="175" height="238" loading="lazy" decoding="async">`
    : `<span class="live-cover" style="--h:${hue}" aria-hidden="true"><b>${esc(initials)}</b><small>${esc(u.series)}</small></span>`;
  const pills = chapters
    .map((c) => {
      // Chapters on your own site open there; crawled ones open at their original source.
      const href = esc(safeUrl(c.sourceUrl));
      return `<li><a href="${href}" rel="nofollow noopener" target="_blank">Chapter ${c.chapter}</a><time class="ago" datetime="${esc(c.date)}">${new Date(c.date).toLocaleDateString()}</time></li>`;
    })
    .join('');
  return `
<article class="card live-card" data-series="${esc(u.slug)}">
  <a href="${esc(seriesHref)}"${ext} class="card-cover">${cover}<span class="type-badge live">New</span></a>
  <div class="card-body">
    <h3 class="card-title"><a href="${esc(seriesHref)}"${ext}>${esc(u.series)}</a></h3>
    <p class="live-source">via ${esc(u.source)}</p>
    <ul class="chapter-pills">${pills}</ul>
  </div>
</article>`;
}
