import { defineConfig } from 'astro/config';
import site from './site.config.mjs';

const base = site.base ? `${site.base.replace(/\/$/, '')}/` : '/';

// Blog posts are written with site links like (/manga/some-series/).
// This adds the base folder to them, e.g. /manhuani/manga/some-series/.
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

export default defineConfig({
  site: site.url,
  base,
  output: 'static',
  // Every page is written as folder/index.html, so URLs like /manga/slug/
  // work on Apache without any server-side routing.
  trailingSlash: 'always',
  build: { format: 'directory', assets: 'assets' },
  compressHTML: true,
  markdown: { rehypePlugins: [rehypeBaseLinks] },
});
