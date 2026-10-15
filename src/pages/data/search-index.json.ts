import type { APIRoute } from 'astro';
import { allSeries, coverUrl, sortedChapters, genreName } from '../../lib/data';

// Small index used by the search and bookmarks pages (loaded in the browser).
export const GET: APIRoute = async () => {
  const list = (await allSeries()).map((s) => {
    const latest = sortedChapters(s)[0];
    return {
      slug: s.id,
      title: s.data.title,
      alt: s.data.altTitles,
      type: s.data.type,
      author: s.data.author ?? '',
      genres: s.data.genres.map(genreName),
      rating: s.data.rating ?? null,
      cover: coverUrl(s),
      latest: latest ? { number: latest.number, date: latest.date.toISOString() } : null,
    };
  });
  return new Response(JSON.stringify(list), { headers: { 'Content-Type': 'application/json' } });
};
