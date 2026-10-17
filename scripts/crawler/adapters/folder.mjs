// Page files a creator or publisher gave you, laid out as one folder per chapter:
//
//   <dir>/series.json           optional: title, type, status, genres, synopsis… (used for a new series)
//   <dir>/cover.webp            optional: cover image (jpg, png or webp)
//   <dir>/1/01.jpg, 02.jpg …    chapter 1, pages in file name order
//   <dir>/1/chapter.json        optional: { "title": "...", "date": "2026-10-20", "sourceUrl": "https://..." }
//   <dir>/10.5/…                chapter 10.5 ("10-5", "chapter-10" and "Chapter 10" also work)
import fs from 'node:fs';
import path from 'node:path';
import { IMAGE_NAME, naturalSort } from '../lib/assets.mjs';

const CHAPTER_NAME = /(\d+)(?:[.-](\d+))?\s*$/;

const readJsonIfThere = (file) => {
  if (!fs.existsSync(file)) return null;
  try {
    return JSON.parse(fs.readFileSync(file, 'utf8'));
  } catch (e) {
    throw new Error(`${path.basename(path.dirname(file))}/${path.basename(file)} isn't valid JSON: ${e.message}`);
  }
};

export default {
  name: 'folder',
  description: 'Page files a creator or publisher gave you, one folder per chapter',

  checkConfig(source) {
    return Object.entries(source.series ?? {})
      .filter(([, cfg]) => !cfg || typeof cfg.dir !== 'string' || !cfg.dir)
      .map(([slug]) => `series "${slug}" needs a "dir": the folder holding its chapter folders`);
  },

  async discover(ctx) {
    const dir = path.resolve(ctx.root, ctx.config.dir);
    if (!fs.existsSync(dir) || !fs.statSync(dir).isDirectory()) throw new Error(`folder not found: ${ctx.config.dir}`);
    const chapters = [];
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
      if (!entry.isDirectory() || entry.name.startsWith('.')) continue;
      const m = entry.name.match(CHAPTER_NAME);
      if (!m) {
        ctx.log.warn('folder.ignored', { folder: entry.name, reason: 'no chapter number in the folder name' });
        continue;
      }
      const meta = readJsonIfThere(path.join(dir, entry.name, 'chapter.json')) ?? {};
      chapters.push({
        number: Number(m[2] ? `${m[1]}.${m[2]}` : m[1]),
        title: meta.title,
        date: meta.date,
        sourceUrl: meta.sourceUrl,
        dir: path.join(dir, entry.name),
        folder: entry.name,
      });
    }
    return { series: readJsonIfThere(path.join(dir, 'series.json')) ?? undefined, chapters };
  },

  async fetchChapter(ctx, chapter) {
    const files = fs.readdirSync(chapter.dir, { withFileTypes: true }).filter((e) => e.isFile() && !e.name.startsWith('.'));
    const ignored = files.filter((e) => !IMAGE_NAME.test(e.name) && e.name !== 'chapter.json').map((e) => e.name);
    if (ignored.length) ctx.log.warn('folder.ignored', { chapter: chapter.number, files: ignored, reason: 'not an image file' });
    const pages = files
      .map((e) => e.name)
      .filter((name) => IMAGE_NAME.test(name))
      .sort(naturalSort)
      .map((name) => ({ file: path.join(chapter.dir, name), name }));
    return { pages, title: chapter.title, date: chapter.date, sourceUrl: chapter.sourceUrl };
  },

  async cover(ctx) {
    const dir = path.resolve(ctx.root, ctx.config.dir);
    const name = ['cover.webp', 'cover.jpg', 'cover.jpeg', 'cover.png'].find((n) => fs.existsSync(path.join(dir, n)));
    return name ? { file: path.join(dir, name) } : null;
  },
};
