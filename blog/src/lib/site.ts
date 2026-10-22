import { getCollection, type CollectionEntry } from 'astro:content';
import site from '../../site.config.mjs';

export type Post = CollectionEntry<'posts'>;
export type Offer = { id: string; title: string; text: string; cta: string; url: string; categories: string[] };

/** Base folder the site is served from, e.g. "/manhuani/" (always ends with "/"). */
export const BASE = import.meta.env.BASE_URL.endsWith('/') ? import.meta.env.BASE_URL : `${import.meta.env.BASE_URL}/`;

/** Turns a site path like "/blog/x/" into "/manhuani/blog/x/". Safe to call twice. */
export const url = (path: string) => {
  if (/^[a-z]+:/i.test(path) || path.startsWith('//')) return path;
  if (path.startsWith(BASE)) return path;
  return BASE + path.replace(/^\//, '');
};

export const absoluteUrl = (path: string) => new URL(url(path), site.url).toString();

export const postUrl = (slug: string) => url(`/blog/${slug}/`);
export const categoryUrl = (slug: string) => url(`/category/${slug}/`);
export const countryUrl = (slug: string) => url(`/country/${slug}/`);

export const category = (slug: string) => site.categories.find((c) => c.slug === slug)!;
export const country = (slug: string) => site.countries.find((c) => c.slug === slug)!;

export const formatDate = (d: Date) =>
  d.toLocaleDateString('en-GB', { year: 'numeric', month: 'short', day: 'numeric', timeZone: 'UTC' });

/** Midnight UTC today, so a deadline "today" still counts as open. */
const today = () => {
  const d = new Date();
  return Date.UTC(d.getUTCFullYear(), d.getUTCMonth(), d.getUTCDate());
};

let cache: Post[] | undefined;
/** Every published post, newest first. */
export async function allPosts(): Promise<Post[]> {
  if (!cache) {
    const list = await getCollection('posts', (p) => !p.data.draft);
    cache = list.sort((a, b) => b.data.date.getTime() - a.data.date.getTime());
  }
  return cache;
}

/** Posts whose deadline hasn't passed, closing soonest first. */
export async function openDeadlines(limit = Infinity) {
  const now = today();
  return (await allPosts())
    .filter((p) => p.data.deadline && p.data.deadline.getTime() >= now)
    .sort((a, b) => a.data.deadline!.getTime() - b.data.deadline!.getTime())
    .slice(0, limit);
}

/** Days left until a deadline (0 on the day itself). */
export const daysLeft = (d: Date) => Math.round((d.getTime() - today()) / 86_400_000);

/** Posts in the same category first, then the newest others. */
export async function relatedPosts(post: Post, limit = 4) {
  const others = (await allPosts()).filter((p) => p.id !== post.id);
  const same = others.filter((p) => p.data.category === post.data.category);
  return [...same, ...others.filter((p) => !same.includes(p))].slice(0, limit);
}

/** Offers for a category whose link has been filled in. */
export const offersFor = (cat: string): Offer[] =>
  (site.offers as Offer[]).filter((o) => o.url && o.categories.includes(cat));

/** Splits a list into pages of site.perPage. */
export function paginate<T>(list: T[]) {
  const pages: T[][] = [];
  for (let i = 0; i < list.length; i += site.perPage) pages.push(list.slice(i, i + site.perPage));
  return pages.length ? pages : [[]];
}
