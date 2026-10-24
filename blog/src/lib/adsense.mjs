// The AdSense publisher ID from site.config.mjs as "pub-" and 16 digits, or '' while it isn't set.
// Takes it the way AdSense shows it, with or without "ca-" in front. Used by the pages and by make-deploy.mjs.
import site from '../../site.config.mjs';

export function adsensePub() {
  const id = (site.adsense ?? '').trim().replace(/^ca-/, '');
  if (!id) return '';
  if (!/^pub-\d{16}$/.test(id)) {
    throw new Error(`adsense in blog/site.config.mjs should look like 'ca-pub-1234567890123456', not '${site.adsense}'`);
  }
  return id;
}
