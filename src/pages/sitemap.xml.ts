import type { APIRoute } from 'astro';
import { getCollection } from 'astro:content';
import {
  allSeries, allGenres, allYears, seriesUrl, chapterUrl, genreUrl, yearUrl, lastUpdate, sortedChapters, absoluteUrl,
} from '../lib/data';

export const GET: APIRoute = async () => {
  const series = await allSeries();
  const posts = await getCollection('blog');
  const urls: { loc: string; lastmod?: Date }[] = [
    { loc: '/', lastmod: new Date(lastUpdate(series[0])) },
    { loc: '/manga/' },
    { loc: '/blog/' },
    { loc: '/about/' },
    { loc: '/contact/' },
    { loc: '/dmca/' },
    { loc: '/privacy/' },
    { loc: '/copyright/' },
    ...(await allGenres()).map((g) => ({ loc: genreUrl(g) })),
    ...(await allYears()).map((y) => ({ loc: yearUrl(y) })),
    ...posts.map((p) => ({ loc: `/blog/${p.id}/`, lastmod: p.data.updated ?? p.data.date })),
    ...series.flatMap((s) => [
      { loc: seriesUrl(s.id), lastmod: new Date(lastUpdate(s)) },
      ...sortedChapters(s).map((c) => ({ loc: chapterUrl(s.id, c.number), lastmod: c.date })),
    ]),
  ];
  const body =
    '<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n' +
    urls
      .map((u) => `  <url><loc>${absoluteUrl(u.loc)}</loc>${u.lastmod ? `<lastmod>${u.lastmod.toISOString()}</lastmod>` : ''}</url>`)
      .join('\n') +
    '\n</urlset>\n';
  return new Response(body, { headers: { 'Content-Type': 'application/xml' } });
};
