import type { APIRoute } from 'astro';
import { absoluteUrl } from '../lib/data';

export const GET: APIRoute = () =>
  new Response(`User-agent: *\nAllow: /\nDisallow: /search/\nDisallow: /bookmarks/\nDisallow: /admin/\n\nSitemap: ${absoluteUrl('/sitemap.xml')}\n`, {
    headers: { 'Content-Type': 'text/plain' },
  });
