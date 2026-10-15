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

export const cardHtml = (s: IndexItem) => `
<article class="card">
  <a href="${BASE}manga/${s.slug}/" class="card-cover">
    <img src="${esc(s.cover)}" alt="${esc(s.title)} cover" width="175" height="238" loading="lazy" decoding="async">
    <span class="type-badge ${esc(s.type)}">${esc(s.type)}</span>
  </a>
  <div class="card-body">
    <h3 class="card-title"><a href="${BASE}manga/${s.slug}/">${esc(s.title)}</a></h3>
    <p class="muted small">${esc(s.genres.join(', '))}</p>
    ${s.latest ? `<ul class="chapter-pills"><li><a href="${BASE}manga/${s.slug}/chapter-${String(s.latest.number).replace('.', '-')}/">Chapter ${s.latest.number}</a></li></ul>` : ''}
  </div>
</article>`;
