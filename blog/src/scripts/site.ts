declare global {
  interface Window {
    fbq?: (...args: unknown[]) => void;
    ttq?: { track: (...args: unknown[]) => void };
  }
}

// Mobile menu
const menuBtn = document.querySelector<HTMLButtonElement>('.menu-toggle');
const nav = document.getElementById('main-nav');
menuBtn?.addEventListener('click', () => {
  const open = nav!.classList.toggle('open');
  menuBtn.setAttribute('aria-expanded', String(open));
});

// Copy-link share button
document.querySelectorAll<HTMLButtonElement>('[data-copy]').forEach((btn) =>
  btn.addEventListener('click', async () => {
    try {
      await navigator.clipboard.writeText(btn.dataset.copy!);
      btn.textContent = 'Copied';
    } catch {
      prompt('Copy this link:', btn.dataset.copy);
    }
  }),
);

// Remember which ad sent this visitor (utm_source / utm_campaign) for the rest of the visit,
// and put it into the offer links, so the CPA network reports conversions by ad and by post.
const read = (key: string) => {
  try { return sessionStorage.getItem(key) ?? ''; } catch { return ''; }
};
const params = new URLSearchParams(location.search);
for (const key of ['utm_source', 'utm_campaign']) {
  const v = params.get(key);
  if (v) try { sessionStorage.setItem(key, v.slice(0, 100)); } catch {}
}
const source = read('utm_source') || params.get('utm_source') || 'direct';
const campaign = read('utm_campaign') || params.get('utm_campaign') || 'none';
document.querySelectorAll<HTMLAnchorElement>('a[data-offer]').forEach((a) => {
  a.href = a.dataset.href!
    .replaceAll('{source}', encodeURIComponent(source))
    .replaceAll('{campaign}', encodeURIComponent(campaign))
    .replaceAll('{post}', encodeURIComponent(a.dataset.post ?? ''));
  a.addEventListener('click', () => {
    // Lets Meta and TikTok optimise for people who click offers, not just page views.
    window.fbq?.('trackCustom', 'OfferClick', { offer: a.dataset.offer, post: a.dataset.post });
    window.ttq?.track('ClickButton', { content_id: a.dataset.offer, content_name: a.dataset.post });
  });
});

// A post counts as content viewed once the reader has scrolled past a quarter of it.
const article = document.querySelector<HTMLElement>('article[data-post]');
if (article) {
  let sent = false;
  const onScroll = () => {
    if (sent) return;
    const r = article.getBoundingClientRect();
    if (-r.top > r.height * 0.25) {
      sent = true;
      window.fbq?.('track', 'ViewContent', { content_name: article.dataset.post, content_category: article.dataset.category });
      window.ttq?.track('ViewContent', { content_id: article.dataset.post, content_category: article.dataset.category });
      removeEventListener('scroll', onScroll);
    }
  };
  addEventListener('scroll', onScroll, { passive: true });
}

// Days-left labels are worked out when the site is built; refresh them for today's date,
// and drop deadlines that have passed from the lists.
const now = new Date();
const today = Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), now.getUTCDate());
document.querySelectorAll<HTMLElement>('[data-deadline]').forEach((el) => {
  const left = Math.round((new Date(el.dataset.deadline!).getTime() - today) / 86_400_000);
  if (left < 0 && el.tagName === 'LI') return el.remove();
  const label = el.querySelector('[data-days-left]');
  if (label) label.textContent = left < 0 ? 'Closed' : left === 0 ? 'Closes today' : left === 1 ? '1 day left' : `${left} days left`;
  const pill = el.classList.contains('deadline-pill') ? el : el.querySelector('.deadline-pill');
  pill?.classList.toggle('soon', left >= 0 && left <= 14);
  pill?.classList.toggle('closed', left < 0);
});

// Posts written in the blog admin (/admin/) aren't part of the build: post.php shows their pages, and
// the admin keeps a list of them in live/posts.json. Lists marked data-live pick them up from there:
// "cards" (post grids, with data-category, data-country and data-limit) and "deadlines" (deadline lists).
// It also lists posts in the code that were edited in the admin (edits: true): their cards anywhere on
// the page are swapped for the edited title, summary and dates.
type LivePost = {
  slug: string; url: string; title: string; description: string; category: string; categoryName: string;
  categoryUrl: string; countries: string[]; flags: string; date: string; updated: string | null; deadline: string | null;
  edits?: boolean;
};
const liveLists = document.querySelectorAll<HTMLElement>('[data-live]');
if (liveLists.length || document.querySelector('.post-card, .deadline-list')) {
  const base = import.meta.env.BASE_URL.replace(/\/?$/, '/');
  fetch(`${base}live/posts.json`, { cache: 'no-cache' })
    .then((r) => (r.ok ? r.json() : []))
    .then((posts: LivePost[]) => {
      showEdits(posts);
      liveLists.forEach((list) => addLivePosts(list, posts));
    })
    .catch(() => {});
}

const esc = (s: string) => s.replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]!);
const shortDate = (iso: string) =>
  new Date(iso).toLocaleDateString('en-GB', { year: 'numeric', month: 'short', day: 'numeric', timeZone: 'UTC' });
const daysUntil = (iso: string) => Math.round((new Date(iso).getTime() - today) / 86_400_000);
const daysLabel = (n: number) => (n === 0 ? 'Closes today' : n === 1 ? '1 day left' : `${n} days left`);

// The same markup as components/PostCard.astro and DeadlineList.astro.
function liveCard(p: LivePost) {
  const left = p.deadline ? daysUntil(p.deadline) : -1;
  const foot = p.deadline && left >= 0
    ? `<span class="deadline-pill${left <= 14 ? ' soon' : ''}">Deadline ${shortDate(p.deadline)}</span>`
    : `<span class="muted">Updated ${shortDate(p.updated ?? p.date)}</span>`;
  const el = document.createElement('article');
  el.className = 'post-card';
  el.dataset.date = p.date;
  el.innerHTML = `<p class="card-meta"><a class="cat-label" href="${esc(p.categoryUrl)}">${esc(p.categoryName)}</a>${p.flags ? ` <span aria-hidden="true">${esc(p.flags)}</span>` : ''}</p>`
    + `<h3><a href="${esc(p.url)}">${esc(p.title)}</a></h3><p class="muted">${esc(p.description)}</p><p class="card-foot small">${foot}</p>`;
  return el;
}

function liveDeadline(p: LivePost) {
  const left = daysUntil(p.deadline!);
  const el = document.createElement('li');
  el.dataset.deadline = p.deadline!;
  el.innerHTML = `<a href="${esc(p.url)}">${esc(p.title)}</a> <span class="deadline-pill${left <= 14 ? ' soon' : ''}"><time datetime="${esc(p.deadline!)}">${shortDate(p.deadline!)}</time> · <span data-days-left>${daysLabel(left)}</span></span>`;
  return el;
}

const pathOf = (url: string) => new URL(url, location.href).pathname;

// Posts in the code with an edited version: data-live lists drop the original (the edited one is
// added back in its place in the order, if it still belongs there), and other cards are swapped.
function showEdits(posts: LivePost[]) {
  const edited = new Map(posts.filter((p) => p.edits).map((p) => [pathOf(p.url), p]));
  if (!edited.size) return;
  document.querySelectorAll<HTMLAnchorElement>('.post-card h3 a, .deadline-list li > a').forEach((a) => {
    const p = edited.get(a.pathname);
    const item = a.closest<HTMLElement>('.post-card, li');
    if (!p || !item) return;
    if (item.parentElement?.closest('[data-live]')) item.remove();
    else if (item.matches('.post-card')) item.replaceWith(liveCard(p));
    else if (p.deadline && daysUntil(p.deadline) >= 0) item.replaceWith(liveDeadline(p));
    else item.remove();
  });
}

function addLivePosts(list: HTMLElement, posts: LivePost[]) {
  const { live, category, country, limit } = list.dataset;
  const shown = new Set([...list.querySelectorAll<HTMLAnchorElement>('h3 a, li > a')].map((a) => a.pathname));
  let picked = posts.filter((p) => !shown.has(pathOf(p.url))
    && (!category || p.category === category)
    && (!country || !p.countries.length || p.countries.includes(country)));
  if (live === 'deadlines') picked = picked.filter((p) => p.deadline && daysUntil(p.deadline) >= 0);
  if (!picked.length) return;

  // Newest first for cards, closing soonest first for deadlines, slotted in among the built ones.
  const key = live === 'deadlines' ? 'deadline' : 'date';
  const items = [...list.children] as HTMLElement[];
  for (const p of picked) {
    const el = live === 'deadlines' ? liveDeadline(p) : liveCard(p);
    const mine = new Date((p[key] as string)).getTime();
    const before = items.find((it) => {
      const theirs = new Date(it.dataset[key] ?? '').getTime();
      return live === 'deadlines' ? theirs > mine : theirs < mine;
    });
    list.insertBefore(el, before ?? null);
    items.splice(before ? items.indexOf(before) : items.length, 0, el);
  }
  if (limit) [...list.children].slice(Number(limit)).forEach((el) => el.remove());
  list.closest('[hidden]')?.removeAttribute('hidden');
  list.parentElement?.querySelector('[data-live-empty]')?.remove();
}

export {};
