import { loadIndex, cardHtml } from './cards';

const q = new URLSearchParams(location.search).get('q')?.trim() ?? '';
const input = document.getElementById('search-q') as HTMLInputElement;
const status = document.getElementById('search-status')!;
const out = document.getElementById('search-results')!;
input.value = q;

const norm = (s: string) => s.toLowerCase().normalize('NFKD').replace(/[^\w\s]/g, '');

if (q) {
  status.textContent = 'Searching...';
  const words = norm(q).split(/\s+/).filter(Boolean);
  const items = await loadIndex();
  const hits = items.filter((s) => {
    const hay = norm([s.title, ...s.alt, s.author, ...s.genres, s.type].join(' '));
    return words.every((w) => hay.includes(w));
  });
  status.textContent = hits.length ? `${hits.length} result${hits.length === 1 ? '' : 's'} for “${q}”` : `No series found for “${q}”.`;
  out.innerHTML = hits.map(cardHtml).join('');
  document.title = `Search: ${q} | ${document.title.split(' | ').pop()}`;
}
