import type { APIRoute } from 'astro';
import site from '../../site.config.mjs';
import { allPosts, absoluteUrl, postUrl } from '../lib/site';

const esc = (s: string) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

export const GET: APIRoute = async () => {
  const items = (await allPosts())
    .map((p) => `<item><title>${esc(p.data.title)}</title><link>${absoluteUrl(postUrl(p.id))}</link><guid>${absoluteUrl(postUrl(p.id))}</guid><pubDate>${p.data.date.toUTCString()}</pubDate><description>${esc(p.data.description)}</description></item>`)
    .join('');
  const body = `<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>${esc(site.name)}</title><link>${absoluteUrl('/')}</link><description>${esc(site.description)}</description>${items}</channel></rss>`;
  return new Response(body, { headers: { 'Content-Type': 'application/rss+xml' } });
};
