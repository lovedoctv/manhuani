import type { APIRoute } from 'astro';
import { allPosts, absoluteUrl, adCode, BASE } from '../lib/site';
import site from '../../site.config.mjs';

// What the blog admin needs to know about the site: categories, countries, the in-article ad codes,
// offers, and the posts already in the code (so a new post can't take one of their addresses).
// make-deploy.mjs moves this file to admin/inc/site.json, which visitors can't open.
export const GET: APIRoute = async () => {
  const iso = (d?: Date) => d?.toISOString() ?? null;
  const data = {
    name: site.name,
    url: site.url,
    base: BASE,
    ogImage: absoluteUrl('/og-image.png'),
    categories: site.categories.map((c) => ({ slug: c.slug, name: c.name })),
    countries: site.countries.map((c) => ({ slug: c.slug, name: c.name, flag: c.flag })),
    ads: { inArticle: adCode('inArticle'), inArticle2: adCode('inArticle2'), afterArticle: adCode('afterArticle') },
    offers: site.offers,
    posts: (await allPosts()).map((p) => ({
      slug: p.id,
      title: p.data.title,
      description: p.data.description,
      category: p.data.category,
      countries: p.data.countries,
      date: iso(p.data.date),
      updated: iso(p.data.updated),
      deadline: iso(p.data.deadline),
    })),
  };
  return new Response(JSON.stringify(data, null, 1), { headers: { 'Content-Type': 'application/json' } });
};
