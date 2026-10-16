// What a reader saved on this device: followed series, bookmarked chapters and chapters
// they've opened. Kept in the browser (localStorage), so no account is needed. It stays
// on this device and browser only.

const read = <T>(key: string, empty: T): T => {
  try {
    const v = JSON.parse(localStorage.getItem(key) ?? 'null');
    return v ?? empty;
  } catch {
    return empty;
  }
};
const write = (key: string, value: unknown) => {
  try { localStorage.setItem(key, JSON.stringify(value)); } catch {}
};

// ---- Followed series (the "+" on cards and the Bookmark button), newest first.
const FOLLOW = 'bookmarks';
export const getBookmarks = (): string[] => read<string[]>(FOLLOW, []).filter((x) => typeof x === 'string');
export const isFollowed = (slug: string) => getBookmarks().includes(slug);
export function toggleFollow(slug: string): boolean {
  const list = getBookmarks();
  const on = !list.includes(slug);
  write(FOLLOW, on ? [slug, ...list] : list.filter((x) => x !== slug));
  return on;
}

// ---- Bookmarked chapters: { slug: ["24", "24|1832", ...] } ("number|release id").
const MARKS = 'chapterMarks';
export interface ChapterMark { slug: string; key: string; title: string; at: number }
const getMarkList = (): ChapterMark[] => read<ChapterMark[]>(MARKS, []).filter((m) => m && typeof m.slug === 'string');
export const chapterKey = (n: number, id?: number | null) => (id ? `${n}|${id}` : String(n));
export const getChapterMarks = (slug?: string) => getMarkList().filter((m) => !slug || m.slug === slug);
export const isMarked = (slug: string, key: string) => getMarkList().some((m) => m.slug === slug && m.key === key);
export function toggleMark(slug: string, key: string, title: string): boolean {
  const list = getMarkList();
  const on = !list.some((m) => m.slug === slug && m.key === key);
  write(MARKS, on ? [{ slug, key, title, at: Date.now() }, ...list].slice(0, 500) : list.filter((m) => !(m.slug === slug && m.key === key)));
  return on;
}

// ---- Chapters opened: { slug: { "24": time } }, so lists can dim what was read.
const HISTORY = 'readChapters';
type History = Record<string, Record<string, number>>;
const getHistory = (): History => read<History>(HISTORY, {});
export const readNumbers = (slug: string): Set<string> => new Set(Object.keys(getHistory()[slug] ?? {}));
export function markRead(slug: string, n: number) {
  const h = getHistory();
  h[slug] = { ...(h[slug] ?? {}), [String(n)]: Date.now() };
  // Keep the 300 most recently read series.
  const keep = Object.entries(h).sort((a, b) => Math.max(...Object.values(b[1])) - Math.max(...Object.values(a[1]))).slice(0, 300);
  write(HISTORY, Object.fromEntries(keep));
}
/** The chapter last opened in a series, or null. */
export function lastRead(slug: string): number | null {
  const e = Object.entries(getHistory()[slug] ?? {});
  if (!e.length) return null;
  e.sort((a, b) => b[1] - a[1]);
  return Number(e[0][0]);
}
