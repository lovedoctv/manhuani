import type { APIRoute } from 'astro';
import { absoluteUrl, url } from '../lib/site';

export const GET: APIRoute = () =>
  new Response(`User-agent: *\nAllow: /\nDisallow: ${url('/search/')}\nDisallow: ${url('/admin/')}\n\nSitemap: ${absoluteUrl('/sitemap.xml')}\n`, {
    headers: { 'Content-Type': 'text/plain' },
  });
