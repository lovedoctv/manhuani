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

export {};
