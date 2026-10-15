import { getCollection, type CollectionEntry } from 'astro:content';
import site from '../../site.config.mjs';

export type Series = CollectionEntry<'series'>;
export type Chapter = Series['data']['chapters'][number];

/** Base folder the site is served from, e.g. "/manhuani/" (always ends with "/"). */
export const BASE = import.meta.env.BASE_URL.endsWith('/') ? import.meta.env.BASE_URL : `${import.meta.env.BASE_URL}/`;

/** Turns a site path like "/manga/x/" into "/manhuani/manga/x/". Safe to call twice. */
export const url = (path: string) => {
  if (/^[a-z]+:/i.test(path) || path.startsWith('//')) return path;
  if (path.startsWith(BASE)) return path;
  return BASE + path.replace(/^\//, '');
};

export const seriesUrl = (slug: string) => url(`/manga/${slug}/`);
export const chapterUrl = (slug: string, n: number) => url(`/manga/${slug}/chapter-${chapterSlug(n)}/`);
export const genreUrl = (slug: string) => url(`/manga-genre/${slug}/`);
export const yearUrl = (y: number) => url(`/manga-release/${y}/`);
export const chapterSlug = (n: number) => String(n).replace('.', '-');

export const coverUrl = (s: Series) => url(s.data.cover ?? `/covers/${s.id}.svg`);

export const genreName = (slug: string) =>
  site.genres.find((g) => g.slug === slug)?.name ??
  slug.replace(/-/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());

/** Chapters newest first. */
export const sortedChapters = (s: Series) =>
  [...s.data.chapters].sort((a, b) => b.number - a.number);

export const lastUpdate = (s: Series) =>
  Math.max(0, ...s.data.chapters.map((c) => c.date.getTime()));

let cache: Series[] | undefined;
/** All series, most recently updated first. */
export async function allSeries(): Promise<Series[]> {
  if (!cache) {
    const list = await getCollection('series');
    cache = list.sort((a, b) => lastUpdate(b) - lastUpdate(a));
  }
  return cache;
}

export async function popularSeries(limit = 6) {
  const list = await allSeries();
  return [...list]
    .sort((a, b) => Number(b.data.featured) - Number(a.data.featured) || (b.data.rating ?? 0) - (a.data.rating ?? 0))
    .slice(0, limit);
}

export async function allGenres() {
  const list = await allSeries();
  const used = new Set(list.flatMap((s) => s.data.genres));
  const known = site.genres.map((g) => g.slug);
  return [...known, ...[...used].filter((g) => !known.includes(g)).sort()];
}

export async function allYears() {
  const list = await allSeries();
  return [...new Set(list.map((s) => s.data.releaseYear))].sort((a, b) => b - a);
}

export const formatDate = (d: Date) =>
  d.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric', timeZone: 'UTC' });

export const statusLabel = (s: string) => s.charAt(0).toUpperCase() + s.slice(1);

export const absoluteUrl = (path: string) => new URL(url(path), site.url).toString();
