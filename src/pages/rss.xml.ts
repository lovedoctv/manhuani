import type { APIRoute } from 'astro';
import { getCollection } from 'astro:content';
import site from '../../site.config.mjs';
import { absoluteUrl } from '../lib/data';

const esc = (s: string) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

export const GET: APIRoute = async () => {
  const posts = (await getCollection('blog')).sort((a, b) => b.data.date.getTime() - a.data.date.getTime());
  const items = posts
    .map((p) => `<item><title>${esc(p.data.title)}</title><link>${absoluteUrl(`/blog/${p.id}/`)}</link><guid>${absoluteUrl(`/blog/${p.id}/`)}</guid><pubDate>${p.data.date.toUTCString()}</pubDate><description>${esc(p.data.description)}</description></item>`)
    .join('');
  const body = `<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>${esc(site.name)} Blog</title><link>${absoluteUrl('/blog/')}</link><description>${esc(site.description)}</description>${items}</channel></rss>`;
  return new Response(body, { headers: { 'Content-Type': 'application/rss+xml' } });
};
