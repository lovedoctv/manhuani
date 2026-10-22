// Search page: loads search.json once and filters it in the browser.
type Item = { title: string; description: string; url: string; category: string; countries: string[] };

const form = document.querySelector<HTMLFormElement>('.search-page-form');
const input = form?.querySelector<HTMLInputElement>('input[name="q"]');
const results = document.querySelector<HTMLElement>('.search-results');
const status = document.querySelector<HTMLElement>('.search-status');

const esc = (s: string) => s.replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]!);

async function run() {
  if (!input || !results || !status) return;
  const q = input.value.trim().toLowerCase();
  if (!q) {
    results.innerHTML = '';
    status.textContent = 'Type a country, scholarship or visa name.';
    return;
  }
  const items: Item[] = await fetch(results.dataset.index!).then((r) => r.json());
  const words = q.split(/\s+/);
  const hits = items.filter((it) => {
    const hay = `${it.title} ${it.description} ${it.category} ${it.countries.join(' ')}`.toLowerCase();
    return words.every((w) => hay.includes(w));
  });
  status.textContent = hits.length ? `${hits.length} result${hits.length === 1 ? '' : 's'} for "${input.value.trim()}"` : `Nothing found for "${input.value.trim()}".`;
  results.innerHTML = hits
    .map((it) => `<article class="post-card"><p class="card-meta"><span class="cat-label">${esc(it.category)}</span></p><h3><a href="${esc(it.url)}">${esc(it.title)}</a></h3><p class="muted">${esc(it.description)}</p></article>`)
    .join('');
}

if (input) {
  input.value = new URLSearchParams(location.search).get('q') ?? '';
  form!.addEventListener('submit', (e) => {
    e.preventDefault();
    history.replaceState(null, '', `?q=${encodeURIComponent(input.value.trim())}`);
    run();
  });
  run();
}
