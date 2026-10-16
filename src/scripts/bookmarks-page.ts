import { loadIndex, cardHtml } from './cards';
import { getBookmarks, getChapterMarks, lastRead, toggleMark } from './store';
import { relativeTimes } from './time';

const BASE = import.meta.env.BASE_URL.replace(/\/?$/, '/');
const esc = (s: string) => s.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]!);
const ANILIST_COVER = /^https:\/\/s4\.anilist\.co\/file\/anilistcdn\/media\/manga\/cover\/(large|medium)\/[A-Za-z0-9_.-]{1,120}$/;
const MEDIA = /^data\/media\/[a-f0-9]{24}\.(jpg|png|webp|gif)$/;

interface LiveTitle { slug: string; title: string; type: string | null; cover?: string | null; latestChapter: number | null; latestDate: string | null; added: string }

async function liveTitles(): Promise<LiveTitle[]> {
  const get = async (p: string) => {
    try {
      const r = await fetch(BASE + p, { cache: 'no-cache' });
      return r.ok ? (((await r.json()) as { titles?: LiveTitle[] }).titles ?? []) : [];
    } catch {
      return [];
    }
  };
  const [a, b] = await Promise.all([get('data/live/titles.json'), get('data/live/library.json')]);
  return [...a, ...b];
}

function liveCard(t: LiveTitle) {
  const href = `${BASE}title/?s=${encodeURIComponent(t.slug)}`;
  const src = t.cover && MEDIA.test(t.cover) ? BASE + t.cover : t.cover && ANILIST_COVER.test(t.cover) ? t.cover : null;
  const resume = lastRead(t.slug);
  return `<article class="card live-card" data-series="${esc(t.slug)}">
  <div class="card-cover-wrap">
    <a href="${href}" class="card-cover">${src ? `<img src="${esc(src)}" alt="${esc(t.title)} cover" width="175" height="238" loading="lazy" referrerpolicy="no-referrer">` : `<span class="live-cover" style="--h:${[...t.slug].reduce((a, c) => (a * 31 + c.charCodeAt(0)) % 360, 7)}"><b>${esc(t.title.slice(0, 2).toUpperCase())}</b><small>${esc(t.title)}</small></span>`}</a>
    <button type="button" class="follow-btn" data-follow="${esc(t.slug)}" aria-pressed="true">✓</button>
  </div>
  <div class="card-meta">${resume !== null ? `<a href="${BASE}chapter/?s=${encodeURIComponent(t.slug)}&c=${resume}">Continue Ch.${resume}</a>` : t.latestChapter !== null ? `<a href="${href}">Ch.${t.latestChapter}</a>` : ''}${t.latestDate ? `<time class="ago" datetime="${esc(t.latestDate)}"></time>` : ''}</div>
  <h3 class="card-title"><a href="${href}">${esc(t.title)}</a></h3>
</article>`;
}

const ids = getBookmarks();
const out = document.getElementById('bookmark-results')!;
const empty = document.getElementById('bookmark-empty')!;
if (!ids.length) empty.hidden = false;
else {
  const [built, live] = await Promise.all([loadIndex(), liveTitles()]);
  const cards = ids.map((id) => {
    const s = built.find((x) => x.slug === id);
    if (s) return cardHtml(s);
    const t = live.find((x) => x.slug === id);
    return t ? liveCard(t) : '';
  }).filter(Boolean);
  out.innerHTML = cards.join('');
  empty.hidden = cards.length > 0;
  relativeTimes(out);
}

// Bookmarked chapters, newest first.
const marks = document.getElementById('chapter-marks')!;
const noMarks = document.getElementById('chapter-empty')!;
const drawMarks = () => {
  const list = getChapterMarks();
  marks.innerHTML = list.map((m) => {
    const [n, g] = m.key.split('|');
    const href = `${BASE}chapter/?s=${encodeURIComponent(m.slug)}&c=${n}${g ? `&g=${g}` : ''}`;
    return `<li class="cx-ch"><a href="${esc(href)}"><b>${esc(m.title)}</b><span class="cx-meta">Saved <time class="ago" datetime="${new Date(m.at).toISOString()}"></time></span></a>
      <button type="button" class="ch-mark" aria-pressed="true" data-slug="${esc(m.slug)}" data-key="${esc(m.key)}" aria-label="Remove bookmark"><svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path stroke="currentColor" stroke-width="2" stroke-linejoin="round" d="M6 3.5h12v17l-6-4.2-6 4.2z"/></svg></button></li>`;
  }).join('');
  noMarks.hidden = list.length > 0;
  relativeTimes(marks);
};
marks.addEventListener('click', (e) => {
  const btn = (e.target as Element).closest<HTMLButtonElement>('.ch-mark');
  if (!btn) return;
  toggleMark(btn.dataset.slug!, btn.dataset.key!, '');
  drawMarks();
});
drawMarks();
