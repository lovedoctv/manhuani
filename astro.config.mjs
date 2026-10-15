import { defineConfig } from 'astro/config';
import site from './site.config.mjs';

export default defineConfig({
  site: site.url,
  output: 'static',
  // Every page is written as folder/index.html, so URLs like /manga/slug/
  // work on Apache without any server-side routing.
  trailingSlash: 'always',
  build: { format: 'directory', assets: 'assets' },
  compressHTML: true,
});
