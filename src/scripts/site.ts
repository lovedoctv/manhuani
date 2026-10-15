import { applyLiveUpdates } from './live';

// Mobile menu
const menuBtn = document.querySelector<HTMLButtonElement>('.menu-toggle');
const nav = document.getElementById('genre-nav');
menuBtn?.addEventListener('click', () => {
  const open = nav!.classList.toggle('open');
  menuBtn.setAttribute('aria-expanded', String(open));
});

// Light / dark theme
document.querySelector('.theme-toggle')?.addEventListener('click', () => {
  const root = document.documentElement;
  const current = root.dataset.theme ?? (matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark');
  const next = current === 'dark' ? 'light' : 'dark';
  root.dataset.theme = next;
  try { localStorage.setItem('theme', next); } catch {}
});

// "3 hours ago" style dates for recent chapters
export function relativeTimes(scope: ParentNode = document) {
  const rtf = new Intl.RelativeTimeFormat('en', { numeric: 'auto' });
  const now = Date.now();
  scope.querySelectorAll<HTMLTimeElement>('time.ago').forEach((el) => {
    const t = Date.parse(el.dateTime);
    if (Number.isNaN(t)) return;
    const mins = Math.round((t - now) / 60000);
    if (mins > -60) el.textContent = rtf.format(Math.min(mins, 0), 'minute');
    else if (mins > -60 * 24) el.textContent = rtf.format(Math.round(mins / 60), 'hour');
    else if (mins > -60 * 24 * 7) el.textContent = rtf.format(Math.round(mins / 1440), 'day');
  });
}
relativeTimes();

// Chapter list filter on series pages
const filter = document.querySelector<HTMLInputElement>('.chapter-filter');
filter?.addEventListener('input', () => {
  const q = filter.value.trim();
  document.querySelectorAll<HTMLLIElement>('.chapter-list li').forEach((li) => {
    li.hidden = q !== '' && !li.dataset.n!.startsWith(q);
  });
});

// Chapter dropdown on reader pages
document.querySelectorAll<HTMLSelectElement>('.chapter-jump').forEach((sel) =>
  sel.addEventListener('change', () => (location.href = sel.value)),
);

// Bookmarks (stored in this browser only)
const KEY = 'bookmarks';
export const getBookmarks = (): string[] => {
  try { return JSON.parse(localStorage.getItem(KEY) ?? '[]'); } catch { return []; }
};
const setBookmarks = (list: string[]) => {
  try { localStorage.setItem(KEY, JSON.stringify(list)); } catch {}
};
document.querySelectorAll<HTMLButtonElement>('.bookmark-btn').forEach((btn) => {
  const id = btn.dataset.bookmark!;
  const render = () => {
    const on = getBookmarks().includes(id);
    btn.textContent = on ? '★ Bookmarked' : '☆ Bookmark';
    btn.setAttribute('aria-pressed', String(on));
  };
  render();
  btn.addEventListener('click', () => {
    const list = getBookmarks();
    setBookmarks(list.includes(id) ? list.filter((x) => x !== id) : [id, ...list]);
    render();
  });
});

applyLiveUpdates();
