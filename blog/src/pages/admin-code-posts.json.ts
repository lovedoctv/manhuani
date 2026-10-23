import type { APIRoute } from 'astro';
import { allPosts } from '../lib/site';

// The posts in the code, in full, so the blog admin can open one in its editor ("Edit" on a post
// in the code). Its in-article ad markers are taken out: the admin adds its own when it shows the post.
// make-deploy.mjs moves this file to admin/inc/code-posts.json, which visitors can't open.
export const GET: APIRoute = async () => {
  const day = (d?: Date) => d?.toISOString().slice(0, 10) ?? null;
  const posts = (await allPosts()).map((p) => ({
    slug: p.id,
    title: p.data.title,
    description: p.data.description,
    category: p.data.category,
    countries: p.data.countries,
    date: day(p.data.date),
    updated: day(p.data.updated),
    checked: day(p.data.checked),
    deadline: day(p.data.deadline),
    official: p.data.official ?? null,
    facts: p.data.facts,
    body: (p.rendered?.html ?? '').replace(/<div data-ad-slot="\w+"><\/div>/g, ''),
  }));
  return new Response(JSON.stringify(posts), { headers: { 'Content-Type': 'application/json' } });
};
