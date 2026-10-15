import { loadIndex, cardHtml } from './cards';
import { getBookmarks } from './site';

const ids = getBookmarks();
const out = document.getElementById('bookmark-results')!;
const empty = document.getElementById('bookmark-empty')!;
if (!ids.length) empty.hidden = false;
else {
  const items = await loadIndex();
  const found = ids.map((id) => items.find((s) => s.slug === id)).filter((s) => !!s);
  out.innerHTML = found.map(cardHtml).join('');
  empty.hidden = found.length > 0;
}
