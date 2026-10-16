import { isFollowed } from './store';

const BASE = import.meta.env.BASE_URL.replace(/\/?$/, '/');

export interface IndexItem {
  slug: string; title: string; alt: string[]; type: string; author: string; genres: string[];
  rating: number | null; cover: string; latest: { number: number; date: string } | null;
}

const esc = (s: string) => s.replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]!);

export async function loadIndex(): Promise<IndexItem[]> {
  const res = await fetch(`${BASE}data/search-index.json`);
  return res.ok ? res.json() : [];
}

export const cardHtml = (s: IndexItem) => {
  const on = isFollowed(s.slug);
  return `
<article class="card" data-series="${esc(s.slug)}">
  <div class="card-cover-wrap">
    <a href="${BASE}manga/${s.slug}/" class="card-cover">
      <img src="${esc(s.cover)}" alt="${esc(s.title)} cover" width="175" height="238" loading="lazy" decoding="async">
      <span class="type-badge ${esc(s.type)}">${esc(s.type)}</span>
    </a>
    <button type="button" class="follow-btn" data-follow="${esc(s.slug)}" aria-pressed="${on}" aria-label="${on ? 'Remove from' : 'Add to'} bookmarks">${on ? '✓' : '+'}</button>
  </div>
  ${s.latest ? `<div class="card-meta"><a href="${BASE}manga/${s.slug}/chapter-${String(s.latest.number).replace('.', '-')}/">Ch.${s.latest.number}</a><time class="ago" datetime="${esc(s.latest.date)}"></time></div>` : ''}
  <h3 class="card-title"><a href="${BASE}manga/${s.slug}/">${esc(s.title)}</a></h3>
  <p class="live-source">${esc(s.genres.join(', '))}</p>
</article>`;
};
