import { applyLiveUpdates } from './live';
import { getBookmarks, isFollowed, toggleFollow } from './store';
import { relativeTimes } from './time';

export { getBookmarks, relativeTimes };

// Mobile menu
const menuBtn = document.querySelector<HTMLButtonElement>('.menu-toggle');
const nav = document.getElementById('genre-nav');
menuBtn?.addEventListener('click', () => {
  const open = nav!.classList.toggle('open');
  menuBtn.setAttribute('aria-expanded', String(open));
});

// Light / dark theme (dark unless the reader picked light)
document.querySelector('.theme-toggle')?.addEventListener('click', () => {
  const root = document.documentElement;
  const current = root.dataset.theme ?? 'dark';
  const next = current === 'dark' ? 'light' : 'dark';
  root.dataset.theme = next;
  try { localStorage.setItem('theme', next); } catch {}
});

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

// Bookmarks (stored in this browser only, no account needed)
document.querySelectorAll<HTMLButtonElement>('.bookmark-btn').forEach((btn) => {
  const id = btn.dataset.bookmark!;
  const render = () => {
    const on = isFollowed(id);
    btn.textContent = on ? '★ Bookmarked' : '☆ Bookmark';
    btn.setAttribute('aria-pressed', String(on));
  };
  render();
  btn.addEventListener('click', () => {
    toggleFollow(id);
    render();
  });
});

// The "+" on cards, also on cards added later by the live updates.
export function renderFollowButtons(scope: ParentNode = document) {
  scope.querySelectorAll<HTMLButtonElement>('.follow-btn').forEach((btn) => {
    const on = isFollowed(btn.dataset.follow!);
    btn.setAttribute('aria-pressed', String(on));
    btn.setAttribute('aria-label', on ? 'Remove from bookmarks' : 'Add to bookmarks');
    btn.textContent = on ? '✓' : '+';
  });
}
document.addEventListener('click', (e) => {
  const btn = (e.target as Element).closest<HTMLButtonElement>('.follow-btn');
  if (!btn) return;
  e.preventDefault();
  toggleFollow(btn.dataset.follow!);
  document.querySelectorAll<HTMLButtonElement>(`.follow-btn[data-follow="${CSS.escape(btn.dataset.follow!)}"]`).forEach((b) => renderFollowButtons(b.parentElement!));
});
renderFollowButtons();

applyLiveUpdates().then(() => {
  relativeTimes();
  renderFollowButtons();
});
