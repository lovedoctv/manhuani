import { defineConfig } from 'astro/config';
import site from './site.config.mjs';

const base = site.base ? `${site.base.replace(/\/$/, '')}/` : '/';

// Posts are written with site links like (/category/scholarships/).
// This adds the base folder to them, e.g. /manhuani/category/scholarships/.
function rehypeBaseLinks() {
  const walk = (node) => {
    if (node.type === 'element') {
      for (const attr of ['href', 'src']) {
        const v = node.properties?.[attr];
        if (typeof v === 'string' && v.startsWith('/') && !v.startsWith('//') && !v.startsWith(base)) {
          node.properties[attr] = base + v.slice(1);
        }
      }
    }
    node.children?.forEach(walk);
  };
  return (tree) => walk(tree);
}

// Official and other outside links in posts open in a new tab, so readers keep the post open.
function rehypeExternalLinks() {
  const walk = (node) => {
    if (node.type === 'element' && node.tagName === 'a') {
      const href = node.properties?.href;
      if (typeof href === 'string' && /^https?:\/\//.test(href) && !href.startsWith(site.url)) {
        node.properties.target = '_blank';
        node.properties.rel = ['noopener'];
      }
    }
    node.children?.forEach(walk);
  };
  return (tree) => walk(tree);
}

// Marks where the in-article ads go in each post: after the 2nd paragraph, and about two
// thirds of the way down in longer posts. The post page swaps each marker for the ad code
// from site.config.mjs, so changing a code never needs the posts to be re-rendered.
function rehypeAdMarkers() {
  const marker = (name) => ({ type: 'element', tagName: 'div', properties: { dataAdSlot: name }, children: [] });
  return (tree) => {
    const paras = tree.children.map((n, i) => [n, i]).filter(([n]) => n.type === 'element' && n.tagName === 'p');
    const at = [];
    if (paras.length >= 3) at.push([paras[1][1] + 1, 'inArticle']);
    if (paras.length >= 8) at.push([paras[Math.floor(paras.length * 0.66)][1] + 1, 'inArticle2']);
    for (const [index, name] of at.sort((a, b) => b[0] - a[0])) tree.children.splice(index, 0, marker(name));
  };
}

export default defineConfig({
  site: site.url,
  base,
  output: 'static',
  // Every page is written as folder/index.html, so URLs like /blog/slug/
  // work on Apache without any server-side routing.
  trailingSlash: 'always',
  build: { format: 'directory', assets: 'assets' },
  compressHTML: true,
  markdown: { rehypePlugins: [rehypeBaseLinks, rehypeExternalLinks, rehypeAdMarkers] },
});
