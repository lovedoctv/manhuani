import type { APIRoute } from 'astro';
import site from '../../site.config.mjs';
import { allPosts, absoluteUrl, postUrl, categoryUrl, countryUrl } from '../lib/site';

export const GET: APIRoute = async () => {
  const posts = await allPosts();
  const urls: { loc: string; lastmod?: Date }[] = [
    { loc: '/', lastmod: posts[0]?.data.updated ?? posts[0]?.data.date },
    { loc: '/blog/' },
    { loc: '/deadlines/' },
    ...site.categories.map((c) => ({ loc: categoryUrl(c.slug) })),
    ...site.countries.map((c) => ({ loc: countryUrl(c.slug) })),
    ...posts.map((p) => ({ loc: postUrl(p.id), lastmod: p.data.updated ?? p.data.date })),
    { loc: '/about/' },
    { loc: '/contact/' },
    { loc: '/privacy/' },
    { loc: '/terms/' },
    { loc: '/disclaimer/' },
  ];
  const body =
    '<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n' +
    urls
      .map((u) => `  <url><loc>${absoluteUrl(u.loc)}</loc>${u.lastmod ? `<lastmod>${u.lastmod.toISOString()}</lastmod>` : ''}</url>`)
      .join('\n') +
    '\n</urlset>\n';
  return new Response(body, { headers: { 'Content-Type': 'application/xml' } });
};
