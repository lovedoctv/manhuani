import type { APIRoute } from 'astro';
import { allPosts, postUrl, category, country } from '../lib/site';

export const GET: APIRoute = async () => {
  const items = (await allPosts()).map((p) => ({
    title: p.data.title,
    description: p.data.description,
    url: postUrl(p.id),
    category: category(p.data.category).name,
    countries: p.data.countries.map((c) => country(c).name),
  }));
  return new Response(JSON.stringify(items), { headers: { 'Content-Type': 'application/json' } });
};
