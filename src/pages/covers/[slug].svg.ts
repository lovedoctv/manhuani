import type { APIRoute } from 'astro';
import { allSeries, type Series } from '../../lib/data';

// Placeholder cover art, generated for series that don't have a cover image yet.
export async function getStaticPaths() {
  return (await allSeries()).filter((s) => !s.data.cover).map((series) => ({ params: { slug: series.id }, props: { series } }));
}

const palettes = [
  ['#ff4d6d', '#7b2ff7'], ['#00c2a8', '#2d6cdf'], ['#ffb347', '#ff4d6d'], ['#7b2ff7', '#00c2a8'],
  ['#f72585', '#4361ee'], ['#06d6a0', '#118ab2'], ['#ef476f', '#ffd166'], ['#3a0ca3', '#f72585'],
];
const esc = (s: string) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;');

function wrap(title: string, max = 14) {
  const lines: string[] = [];
  let line = '';
  for (const w of title.split(' ')) {
    if ((line + ' ' + w).trim().length > max && line) { lines.push(line); line = w; } else line = (line + ' ' + w).trim();
  }
  if (line) lines.push(line);
  return lines.slice(0, 5);
}

export const GET: APIRoute = ({ props }) => {
  const s = (props as { series: Series }).series;
  const hash = [...s.id].reduce((a, c) => (a * 31 + c.charCodeAt(0)) >>> 0, 7);
  const mixed = Math.imul(hash ^ (hash >>> 13), 0x5bd1e995) >>> 0;
  const [a, b] = palettes[(mixed >>> 8) % palettes.length];
  const lines = wrap(s.data.title);
  const startY = 300 - lines.length * 17;
  const svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 350 476" width="350" height="476">
<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="${a}"/><stop offset="1" stop-color="${b}"/></linearGradient></defs>
<rect width="350" height="476" fill="url(#g)"/>
<circle cx="${60 + (hash % 230)}" cy="${80 + (hash % 120)}" r="${90 + (hash % 60)}" fill="#fff" opacity=".12"/>
<text x="175" y="170" text-anchor="middle" font-family="serif" font-size="120" fill="#fff" opacity=".25">漫</text>
${lines.map((l, i) => `<text x="175" y="${startY + i * 34}" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-weight="700" font-size="28" fill="#fff">${esc(l)}</text>`).join('\n')}
<text x="175" y="440" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-size="16" letter-spacing="4" fill="#fff" opacity=".8">${s.data.type.toUpperCase()}</text>
</svg>`;
  return new Response(svg, { headers: { 'Content-Type': 'image/svg+xml' } });
};
