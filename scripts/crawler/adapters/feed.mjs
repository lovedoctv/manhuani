// A JSON feed or API from a source that gave you permission (a publisher, a creator's own
// site, or a file you host for them). It must use this layout:
//
//   {
//     "series": { "title": "...", "type": "manhwa", "status": "ongoing", ... },   (optional)
//     "cover": "https://.../cover.webp",                                              (optional)
//     "chapters": [
//       {
//         "number": 1,
//         "title": "...", "date": "2026-10-20", "url": "https://... (chapter page)",  (optional)
//         "version": "anything that changes when the pages change",               (optional)
//         "pages": ["https://.../1/001.webp", ...]          or
//         "pagesUrl": "https://.../chapters/1.json"   returning { "pages": [...] }
//       }
//     ]
//   }
//
// Links may be relative to the feed's own address. Only https is allowed, except
// http://localhost for testing.
const allowedUrl = (u) => u.protocol === 'https:' || (u.protocol === 'http:' && ['localhost', '127.0.0.1'].includes(u.hostname));

const toUrl = (value, base, what) => {
  let u;
  try {
    u = new URL(typeof value === 'string' ? value : value?.url, base);
  } catch {
    throw new Error(`${what} isn't a valid link`);
  }
  if (!allowedUrl(u)) throw new Error(`${what} must be an https link: ${u.origin}`);
  return u.href;
};

export default {
  name: 'feed',
  description: 'A JSON feed or API from a source that gave you permission',

  checkConfig(source) {
    const problems = [];
    for (const [slug, cfg] of Object.entries(source.series ?? {})) {
      try {
        toUrl(cfg?.url, undefined, `series "${slug}" url`);
      } catch (e) {
        problems.push(`series "${slug}" needs a "url" for its feed (${e.message})`);
      }
    }
    return problems;
  },

  async discover(ctx) {
    const { data, url } = await ctx.http.json(ctx.config.url);
    if (!data || !Array.isArray(data.chapters)) throw new Error('the feed has no "chapters" list');
    const chapters = data.chapters.map((c, i) => {
      if (!c || typeof c !== 'object') throw new Error(`chapter entry ${i + 1} isn't an object`);
      return {
        number: Number(c.number),
        title: typeof c.title === 'string' ? c.title : undefined,
        date: c.date,
        sourceUrl: c.url ? toUrl(c.url, url, `chapter ${c.number} url`) : undefined,
        version: c.version,
        pages: c.pages,
        pagesUrl: c.pagesUrl,
        base: url,
      };
    });
    return { series: data.series, chapters, coverUrl: data.cover ? toUrl(data.cover, url, 'cover') : undefined };
  },

  async fetchChapter(ctx, chapter) {
    let list = chapter.pages;
    let base = chapter.base;
    if (!Array.isArray(list) && chapter.pagesUrl) {
      const res = await ctx.http.json(toUrl(chapter.pagesUrl, base, `chapter ${chapter.number} pagesUrl`));
      list = res.data?.pages;
      base = res.url;
    }
    if (!Array.isArray(list)) list = [];
    const pages = list.map((p, i) => ({ url: toUrl(p, base, `chapter ${chapter.number} page ${i + 1}`) }));
    return { pages, title: chapter.title, date: chapter.date, sourceUrl: chapter.sourceUrl, version: chapter.version };
  },

  async cover(ctx, discovered) {
    return discovered.coverUrl ? { url: discovered.coverUrl } : null;
  },
};
