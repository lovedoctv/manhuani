// Phase 2 hook: live chapter updates without rebuilding the site.
//
// The future crawler dashboard will write JSON files to /data/live/ on the server.
// The build never creates that folder, so deploying the site never overwrites it.
// If a file is missing (as it is today), nothing happens.
//
//   /data/live/series/<slug>.json  ->  { "chapters": [{ "number": 25, "date": "...", "sourceUrl": "..." }] }

interface LiveChapter { number: number; title?: string; date: string; sourceUrl?: string }

const esc = (s: string) => s.replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]!);

export async function applyLiveUpdates() {
  const list = document.querySelector<HTMLUListElement>('[data-live-series]');
  if (!list) return;
  const slug = list.dataset.liveSeries!;
  try {
    const res = await fetch(`${import.meta.env.BASE_URL.replace(/\/?$/, '/')}data/live/series/${slug}.json`, { cache: 'no-cache' });
    if (!res.ok) return;
    const data: { chapters?: LiveChapter[] } = await res.json();
    const known = new Set([...list.querySelectorAll('li')].map((li) => li.dataset.n));
    const fresh = (data.chapters ?? [])
      .filter((c) => !known.has(String(c.number)) && c.sourceUrl)
      .sort((a, b) => b.number - a.number);
    for (const c of fresh.reverse()) {
      const li = document.createElement('li');
      li.dataset.n = String(c.number);
      li.innerHTML = `<a href="${esc(c.sourceUrl!)}" rel="nofollow noopener" target="_blank"><span>Chapter ${c.number}${c.title ? ' – ' + esc(c.title) : ''} <em class="new-badge">NEW</em></span><time class="ago" datetime="${esc(c.date)}">${new Date(c.date).toLocaleDateString()}</time></a>`;
      list.prepend(li);
    }
  } catch {
    // No live data yet. That's fine.
  }
}
